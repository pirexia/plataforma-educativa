<?php

namespace App\Modules\Alum\Application;

use App\Models\User;
use App\Modules\Acad\Domain\GroupDirectory;
use App\Modules\Alum\Domain\Models\GroupChangeRequest;
use App\Modules\Core\Domain\UserDirectory;
use App\Modules\Curso\Domain\AcademicYearDirectory;
use App\Modules\Curso\Domain\AcademicYearReadAccess;
use App\Support\Api\ApiException;
use App\Support\Authorization\PermissionDecision;
use App\Support\Authorization\ScopedQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ALUM/funcional.md §4.4, §5.3 (`RN-ALUM-40` a `-46`), 1.15-c T2a: solicitudes de cambio de grupo de una matrícula
 * (`OPEN-ALUM-13` = A): `pendiente` → `aprobada` / `rechazada` / `cancelada`, con doble control.
 *
 * Servicio sancionado por `AR-10` para consultar `GroupChangeRequest` (recurso `matricula`, ámbitos `grupo` y
 * `unidad_familiar`): listado y detalle pasan por `ScopedQuery` (`ADR-044 §4.2`); lo no alcanzado es `404` (`RN-ALUM-46`,
 * `-64`). Nunca consulta `Enrollment`: pide la matrícula, su bloqueo y el cambio de grupo a `EnrollmentService`, que sigue
 * siendo el único que escribe matrículas (`RN-ALUM-28`, `-43`).
 *
 * Escrituras: todas en transacción y, siempre en el mismo orden (el de `EnrollmentService`, sin interbloqueos), (1) curso
 * académico `FOR SHARE` (cerrado ⇒ `AcademicYearReadOnlyException` ⇒ `409 academic-year-closed`), (2) matrícula `FOR UPDATE`,
 * (3) solicitud `FOR UPDATE` releída (una transición concurrente ya confirmó ⇒ `409 request_closed`). Aprobar cambia el grupo
 * en esa misma transacción por `EnrollmentService::moveToGroup()`. Motivo y nota (1-500) los valida este servicio
 * (`INV-010`) y la auditoría (`INV-003`) la hace el modelo sin registrar su texto (`RN-ALUM-45`, `ADR-035`).
 */
final class GroupChangeService
{
    public const STATUSES = ['pendiente', 'aprobada', 'rechazada', 'cancelada'];

    private const TEXT_MAX = 500;

    public function __construct(
        private readonly ScopedQuery $scopedQuery,
        private readonly EnrollmentService $enrollments,
        private readonly GroupDirectory $groups,
        private readonly AcademicYearDirectory $years,
        private readonly AcademicYearReadAccess $readAccess,
        private readonly UserDirectory $users,
    ) {}

    /** Detalle sin acotar por ámbito (escrituras, que exigen ámbito `todos`): `404` si no existe, es de otro centro o su curso no es legible. */
    private function findWritable(string $publicId, User $actor): GroupChangeRequest
    {
        $request = GroupChangeRequest::query()->where('public_id', $publicId)->firstOrFail();
        $this->assertYearReadable((int) $request->academic_year_id, $actor);

        return $request;
    }

    /**
     * Detalle acotado por ámbito y por curso legible: `404` si no existe, es de otro centro, no se alcanza (`grupo`
     * fuera de sus grupos, `unidad_familiar` siempre) o es de un curso cerrado sin `curso_historico.leer`.
     */
    public function findScoped(string $publicId, PermissionDecision $decision, User $subject): GroupChangeRequest
    {
        $request = $this->scopedQuery->constrain(GroupChangeRequest::query(), 'matricula', $decision, $subject)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $this->assertYearReadable((int) $request->academic_year_id, $subject);

        return $request;
    }

    /**
     * Página del listado (`api.md §3`), acotada por ámbito. `academic_year` (`public_id`) es obligatorio y legible (inexistente
     * o ilegible ⇒ `404`, ausente ⇒ `422 required`); `status` y `group` (origen o destino) filtran, y un `group` que no
     * resuelve a algo de ese curso da lista vacía. Orden: la más reciente primero, desempate por `id`.
     *
     * @param  array{academic_year?: ?string, status?: ?string, group?: ?string}  $filters
     * @return LengthAwarePaginator<int, GroupChangeRequest>
     */
    public function page(PermissionDecision $decision, User $subject, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $yearPublicId = $filters['academic_year'] ?? null;

        if ($yearPublicId === null || $yearPublicId === '') {
            throw AlumErrors::invalid('academic_year', 'alum.validation.required');
        }

        $year = $this->years->findByPublicId($yearPublicId) ?? throw ApiException::notFound();
        $this->readAccess->assertCanRead($year, $subject);

        $query = $this->scopedQuery->constrain(GroupChangeRequest::query(), 'matricula', $decision, $subject)
            ->where('academic_year_id', $year->id);

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        if (($filters['group'] ?? null) !== null) {
            $group = $this->groups->findByPublicId($filters['group']);

            $group !== null && $group->academicYearId === $year->id
                ? $query->where(fn (Builder $q) => $q->where('from_group_id', $group->id)->orWhere('to_group_id', $group->id))
                : $query->whereRaw('1 = 0');
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Pone en cada solicitud los atributos efímeros con los que `GroupChangeRequestResource` la presenta (`enrollment_view`,
     * `year_view`, `from_group_view`, `to_group_view`, `requested_by_view`, `decided_by_view`), con una consulta por tipo
     * para toda la lista.
     *
     * @param  iterable<GroupChangeRequest>  $requests
     */
    public function attachViews(iterable $requests): void
    {
        $list = [];

        foreach ($requests as $request) {
            $list[] = $request;
        }

        if ($list === []) {
            return;
        }

        $enrollments = $this->enrollments->byIds(array_values(array_unique(array_map(static fn (GroupChangeRequest $r): int => (int) $r->enrollment_id, $list))));
        $this->enrollments->attachStudents($enrollments);

        $years = $this->years->findManyById(array_values(array_unique(array_map(static fn (GroupChangeRequest $r): int => (int) $r->academic_year_id, $list))));
        $groups = $this->groups->findManyById(array_values(array_unique(array_merge(
            array_map(static fn (GroupChangeRequest $r): int => (int) $r->from_group_id, $list),
            array_map(static fn (GroupChangeRequest $r): int => (int) $r->to_group_id, $list),
        ))));
        $userIds = [];

        foreach ($list as $request) {
            $userIds[(int) $request->requested_by] = (int) $request->requested_by;

            if ($request->decided_by !== null) {
                $userIds[(int) $request->decided_by] = (int) $request->decided_by;
            }
        }

        $users = $this->users->refsByIds(array_values($userIds));
        $groupView = static fn (?int $id) => $id === null || ! isset($groups[$id]) ? null : [
            'public_id' => $groups[$id]->publicId,
            'code' => $groups[$id]->code,
            'name' => $groups[$id]->name,
        ];
        $userView = static fn (?int $id) => $id === null || ! isset($users[$id]) ? null : [
            'public_id' => $users[$id]->publicId,
            'display_name' => $users[$id]->displayName,
        ];

        foreach ($list as $request) {
            $enrollment = $enrollments[(int) $request->enrollment_id] ?? null;
            $year = $years[(int) $request->academic_year_id] ?? null;

            $request->setAttribute('enrollment_view', [
                'public_id' => $enrollment?->public_id,
                'student' => $enrollment?->getAttribute('student_view') ?? ['public_id' => null, 'display_name' => ''],
            ]);
            $request->setAttribute('year_view', ['public_id' => $year?->publicId, 'code' => $year?->code]);
            $request->setAttribute('from_group_view', $groupView((int) $request->from_group_id));
            $request->setAttribute('to_group_view', $groupView((int) $request->to_group_id));
            $request->setAttribute('requested_by_view', $userView((int) $request->requested_by));
            $request->setAttribute('decided_by_view', $userView($request->decided_by === null ? null : (int) $request->decided_by));
        }
    }

    /**
     * Alta (`RN-ALUM-40`, `-41`). El cuerpo trae `enrollment` y `to_group` (`public_id`) y `reason` (1-500). Matrícula o grupo
     * inexistentes, de otro centro o ilegibles para el actor ⇒ `404` la matrícula, `422 parent_not_found` el grupo (como
     * en la matrícula, `RN-ALUM-24`). Bajo el bloqueo de la matrícula: `activa` y con grupo; destino distinto del origen,
     * vivo y del mismo curso de etapa (`422 group_not_in_course`); sin otra solicitud pendiente (`422 request_already_pending`).
     *
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, array $input): GroupChangeRequest
    {
        $enrollmentPublicId = $this->requiredString($input, 'enrollment');
        $groupPublicId = $this->requiredString($input, 'to_group');
        $reason = $this->text($input, 'reason', 'alum.validation.reason_invalid', required: true);

        $found = $this->enrollments->findForGroupChange($enrollmentPublicId, $actor);

        $target = $this->groups->findByPublicId($groupPublicId);
        $targetYear = $target === null ? null : $this->years->findById($target->academicYearId);

        if ($target === null || $targetYear === null || ! $this->readAccess->canRead($targetYear, $actor)) {
            throw AlumErrors::invalid('to_group', 'alum.validation.parent_not_found');
        }

        try {
            return DB::transaction(function () use ($found, $target, $reason, $actor): GroupChangeRequest {
                [$year, $enrollment] = $this->enrollments->lockForGroupChange((int) $found->id, (int) $found->academic_year_id);

                if ($enrollment === null) {
                    throw ApiException::notFound();
                }

                if ($enrollment->status !== 'activa') {
                    throw AlumErrors::invalid('enrollment', 'alum.validation.enrollment_not_active');
                }

                if ($enrollment->group_id === null) {
                    throw AlumErrors::invalid('enrollment', 'alum.validation.enrollment_without_group');
                }

                if ($target->courseId !== (int) $enrollment->course_id || $target->id === (int) $enrollment->group_id) {
                    throw AlumErrors::invalid('to_group', 'alum.validation.group_not_in_course');
                }

                $this->groups->lockAliveForShare($target->id) ?? throw AlumErrors::invalid('to_group', 'alum.validation.parent_not_found');

                if (GroupChangeRequest::query()->where('enrollment_id', $enrollment->id)->where('status', 'pendiente')->exists()) {
                    throw AlumErrors::invalid('enrollment', 'alum.validation.request_already_pending');
                }

                $request = new GroupChangeRequest;
                $request->setAttribute('enrollment_id', $enrollment->id);
                $request->setAttribute('academic_year_id', $year->id);
                $request->setAttribute('from_group_id', $enrollment->group_id);
                $request->setAttribute('to_group_id', $target->id);
                $request->setAttribute('reason', $reason);
                $request->setAttribute('status', 'pendiente');
                $request->setAttribute('requested_by', $actor->id);
                $request->save();

                return $request;
            });
        } catch (QueryException $e) {
            // `RN-ALUM-41`: el índice único parcial es la última barrera; las FK compuestas, si la estructura cambió.
            $state = $e->errorInfo[0] ?? null;

            if ($state === '23505') {
                throw AlumErrors::invalid('enrollment', 'alum.validation.request_already_pending');
            }

            if ($state === '23503') {
                throw AlumErrors::invalid('to_group', 'alum.validation.parent_not_found');
            }

            throw $e;
        }
    }

    /**
     * Aprobación (`RN-ALUM-43`, `-44`): el solicitante no puede aprobar la suya (`403 forbidden.self_approval`). En una
     * transacción, con la matrícula `FOR UPDATE`: si sigue `activa` y en el grupo de origen se cambia el grupo y se cierra la
     * solicitud; si no, `409 enrollment_changed` y no se escribe nada. `decision_note` opcional (1-500).
     *
     * @param  array<string, mixed>  $input
     */
    public function approve(User $actor, string $publicId, array $input): GroupChangeRequest
    {
        $found = $this->findWritable($publicId, $actor);

        if ((int) $found->requested_by === (int) $actor->id) {
            throw ApiException::forbidden('alum.forbidden.self_approval');
        }

        $note = $this->text($input, 'decision_note', 'alum.validation.decision_note_invalid', required: false);

        return $this->transition($actor, $found, 'aprobada', $note);
    }

    /**
     * Rechazo (`RN-ALUM-42`): `decision_note` **obligatoria** (1-500, `alum.validation.decision_note_required`). El solicitante
     * puede rechazar la suya.
     *
     * @param  array<string, mixed>  $input
     */
    public function reject(User $actor, string $publicId, array $input): GroupChangeRequest
    {
        $found = $this->findWritable($publicId, $actor);
        $note = $this->text($input, 'decision_note', 'alum.validation.decision_note_invalid', required: true, requiredCode: 'alum.validation.decision_note_required');

        return $this->transition($actor, $found, 'rechazada', $note);
    }

    /**
     * Cancelación (`RN-ALUM-42`): solo `pendiente`; `decided_by` es quien cancela (puede ser el solicitante). `decision_note`
     * opcional (1-500).
     *
     * @param  array<string, mixed>  $input
     */
    public function cancel(User $actor, string $publicId, array $input): GroupChangeRequest
    {
        $found = $this->findWritable($publicId, $actor);
        $note = $this->text($input, 'decision_note', 'alum.validation.decision_note_invalid', required: false);

        return $this->transition($actor, $found, 'cancelada', $note);
    }

    private function transition(User $actor, GroupChangeRequest $found, string $to, ?string $note): GroupChangeRequest
    {
        return DB::transaction(function () use ($actor, $found, $to, $note): GroupChangeRequest {
            [, $enrollment] = $this->enrollments->lockForGroupChange((int) $found->enrollment_id, (int) $found->academic_year_id);

            $request = GroupChangeRequest::query()->whereKey($found->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== 'pendiente') {
                throw AlumErrors::conflict('request', 'alum.conflict.request_closed');
            }

            if ($to === 'aprobada') {
                $enrollment !== null
                    ? $this->enrollments->moveToGroup($enrollment, (int) $request->from_group_id, (int) $request->to_group_id)
                    : throw AlumErrors::conflict('enrollment', 'alum.conflict.enrollment_changed');
            }

            $request->setAttribute('status', $to);
            $request->setAttribute('decided_by', $actor->id);
            $request->setAttribute('decided_at', now());
            $request->setAttribute('decision_note', $note);
            $request->save();

            return $request;
        });
    }

    private function assertYearReadable(int $yearId, User $subject): void
    {
        $year = $this->years->findById($yearId) ?? throw ApiException::notFound();
        $this->readAccess->assertCanRead($year, $subject);
    }

    /** @param  array<string, mixed>  $input */
    private function requiredString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw AlumErrors::invalid($key, 'alum.validation.required');
        }

        return $value;
    }

    /**
     * Texto libre acotado (`RN-ALUM-45`, `INV-010`): 1 a 500 caracteres tras recortar, UTF-8 válido y sin caracteres de
     * control salvo el salto de línea y el tabulador. Ausente o `null`: `required` ⇒ error con `$requiredCode`, si no `null`.
     *
     * @param  array<string, mixed>  $input
     */
    private function text(array $input, string $key, string $invalidCode, bool $required, string $requiredCode = 'alum.validation.required'): ?string
    {
        $value = $input[$key] ?? null;

        if ($value === null) {
            return $required ? throw AlumErrors::invalid($key, $requiredCode) : null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed !== ''
                && mb_check_encoding($trimmed, 'UTF-8')
                && mb_strlen($trimmed) <= self::TEXT_MAX
                && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $trimmed) !== 1) {
                return $trimmed;
            }
        }

        throw AlumErrors::invalid($key, $invalidCode);
    }
}
