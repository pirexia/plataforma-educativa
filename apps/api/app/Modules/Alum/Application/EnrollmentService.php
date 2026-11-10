<?php

namespace App\Modules\Alum\Application;

use App\Models\User;
use App\Modules\Acad\Domain\CourseDirectory;
use App\Modules\Acad\Domain\GroupDirectory;
use App\Modules\Acad\Domain\TeachingMembership;
use App\Modules\Alum\Domain\Models\Enrollment;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\Curso\Domain\AcademicYearDirectory;
use App\Modules\Curso\Domain\AcademicYearReadAccess;
use App\Modules\Curso\Domain\AcademicYearReadOnlyException;
use App\Modules\Curso\Domain\AcademicYearSharedLock;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Support\Api\ApiException;
use App\Support\Authorization\PermissionDecision;
use App\Support\Authorization\ScopedQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ALUM/funcional.md §4.2, §4.3, §5.2 (`RN-ALUM-20` a `-29`, `-60` a `-64`), 1.15-b T2a: matrícula.
 *
 * Servicio sancionado por `AR-10` para consultar `Enrollment` (recurso `matricula`, ámbitos `grupo` y
 * `unidad_familiar`): listado y detalle pasan por `ScopedQuery` (`ADR-044 §4.2`); lo no alcanzado es `404`
 * (`RN-ALUM-64`). Los resolutores de `alumno` y `matricula` no consultan `Enrollment` ni `Student`: piden aquí y en
 * `StudentService` la subconsulta o los ids que necesitan.
 *
 * Escrituras (alta, edición, baja, anulación): todas en transacción y, en este orden, (1) `AcademicYearSharedLock` del
 * curso académico (`FOR SHARE`, precedente `RN-ACAD-57`; un curso `cerrado`/`archivado` ⇒ `AcademicYearReadOnlyException`,
 * que `curso` traduce a `409 academic-year-closed`, y el disparador del motor lo rechaza igual), (2) el alumno
 * `FOR SHARE` en el alta o la matrícula `FOR UPDATE` en el resto, (3) validación del estado resultante. Las
 * violaciones de unicidad (`RN-ALUM-21`) o de FK por carrera se traducen a `422`, nunca `500`. La auditoría
 * (`INV-003`) la hace el modelo (`RecordsAuditTrail`).
 */
final class EnrollmentService
{
    public const NAME_SORTS = ['name', '-name'];

    public const WITHDRAWAL_REASONS = ['traslado', 'baja_voluntaria', 'otro'];

    /** Claves del cuerpo que `PATCH` nunca admite (`RN-ALUM-28`). */
    private const IMMUTABLE_KEYS = ['student', 'academic_year', 'course'];

    public function __construct(
        private readonly ScopedQuery $scopedQuery,
        private readonly StudentService $students,
        private readonly GroupDirectory $groups,
        private readonly CourseDirectory $courses,
        private readonly AcademicYearDirectory $years,
        private readonly AcademicYearSharedLock $sharedLock,
        private readonly AcademicYearReadAccess $readAccess,
        private readonly TeachingMembership $membership,
        private readonly PersonDirectory $people,
    ) {}

    /** Detalle sin acotar por ámbito (escrituras, que exigen ámbito `todos`): `404` si no existe o es de otro centro. */
    public function find(string $publicId): Enrollment
    {
        return Enrollment::query()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * Detalle acotado por ámbito y por curso legible (`ADR-044 §4.2`, `RN-ALUM-23`, `-64`): `404` si no existe, es de
     * otro centro, no se alcanza o es de un curso cerrado sin `curso_historico.leer` (`AcademicYearReadDeniedException`,
     * que `curso` traduce a `404`).
     */
    public function findScoped(string $publicId, PermissionDecision $decision, User $subject): Enrollment
    {
        $enrollment = $this->scopedQuery->constrain(Enrollment::query(), 'matricula', $decision, $subject)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $this->assertYearReadable((int) $enrollment->academic_year_id, $subject);

        return $enrollment;
    }

    /**
     * Página del listado, acotada por ámbito. `academic_year` (`public_id`) es obligatorio y se comprueba con
     * `AcademicYearReadAccess` (`RN-ALUM-23`): inexistente o ilegible ⇒ `404`. Un filtro (`course`, `group`, `student`)
     * que no resuelve a nada del curso pedido da lista vacía, no error. `sort`: `name` (defecto) o `enrolled_on`, con
     * prefijo `-` para descendente; desempate por `id`.
     *
     * @param  array{academic_year?: ?string, course?: ?string, group?: ?string, student?: ?string, status?: ?string}  $filters
     * @return LengthAwarePaginator<int, Enrollment>
     */
    public function page(PermissionDecision $decision, User $subject, array $filters, string $sort, int $page, int $perPage): LengthAwarePaginator
    {
        $yearPublicId = $filters['academic_year'] ?? null;

        if ($yearPublicId === null || $yearPublicId === '') {
            throw AlumErrors::invalid('academic_year', 'alum.validation.required');
        }

        $year = $this->years->findByPublicId($yearPublicId) ?? throw ApiException::notFound();
        $this->readAccess->assertCanRead($year, $subject);

        $query = $this->scopedQuery->constrain(Enrollment::query(), 'matricula', $decision, $subject)
            ->where('academic_year_id', $year->id);

        $this->applyFilters($query, $year, $filters);

        if (in_array($sort, self::NAME_SORTS, true)) {
            return $this->pageByName($query, str_starts_with($sort, '-'), $page, $perPage);
        }

        return $query->orderBy('enrolled_on', str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Pone en cada matrícula, como atributo efímero `student_view`, el `public_id` y el nombre visible de su alumno
     * (una consulta de alumnos y una de personas para toda la página, sin N+1).
     *
     * @param  iterable<Enrollment>  $enrollments
     */
    public function attachStudents(iterable $enrollments): void
    {
        $list = [];

        foreach ($enrollments as $enrollment) {
            $list[] = $enrollment;
        }

        $students = $this->students->byIds(array_values(array_unique(array_map(static fn (Enrollment $e): int => (int) $e->student_id, $list))));
        $refs = $this->people->refsByIds(array_values(array_unique(array_map(static fn ($s): int => (int) $s->person_id, $students))));

        foreach ($list as $enrollment) {
            $student = $students[(int) $enrollment->student_id] ?? null;
            $ref = $student === null ? null : ($refs[(int) $student->person_id] ?? null);

            $enrollment->setAttribute('student_view', [
                'public_id' => $student?->public_id,
                'display_name' => $ref === null ? '' : $ref->displayName,
            ]);
        }
    }

    /**
     * Alta (`RN-ALUM-20` a `-24`, `-29`). El cuerpo trae `public_id`: `student`, `academic_year`, `course`, `group`
     * (opcional) y `enrolled_on`. Curso académico, curso de etapa o grupo inexistentes, de otro centro o ilegibles para
     * el actor son indistinguibles (`RN-ALUM-24`).
     *
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, array $input): Enrollment
    {
        $studentPublicId = $this->requiredString($input, 'student');
        $yearPublicId = $this->requiredString($input, 'academic_year');
        $coursePublicId = $this->requiredString($input, 'course');
        $enrolledOn = $this->date($input, 'enrolled_on');
        $groupPublicId = $this->optionalString($input, 'group');

        $year = $this->years->findByPublicId($yearPublicId);

        if ($year === null || ! $this->readAccess->canRead($year, $actor)) {
            throw AlumErrors::invalid('academic_year', 'alum.validation.academic_year_not_found');
        }

        $course = $this->courses->findByPublicId($coursePublicId);

        if ($course === null || ! $this->yearReadable($course->academicYearId, $actor)) {
            throw AlumErrors::invalid('course', 'alum.validation.parent_not_found');
        }

        if ($course->academicYearId !== $year->id) {
            throw AlumErrors::invalid('course', 'alum.validation.course_not_in_academic_year');
        }

        $groupId = null;

        if ($groupPublicId !== null) {
            $groupId = $this->resolveGroup($groupPublicId, $course->id, $actor);
        }

        try {
            return DB::transaction(function () use ($year, $course, $groupId, $studentPublicId, $enrolledOn): Enrollment {
                $locked = $this->lockedYear($year->id);

                if ($locked->isReadOnly()) {
                    throw new AcademicYearReadOnlyException($locked);
                }

                $student = $this->students->lockAliveForShare($studentPublicId)
                    ?? throw AlumErrors::invalid('student', 'alum.validation.student_not_found');

                $this->assertDateInYear($enrolledOn, $locked);

                if (Enrollment::query()->where('student_id', $student->id)->where('academic_year_id', $locked->id)->where('status', 'activa')->exists()) {
                    throw AlumErrors::invalid('student', 'alum.validation.already_enrolled');
                }

                $enrollment = new Enrollment([
                    'student_id' => $student->id,
                    'academic_year_id' => $locked->id,
                    'course_id' => $course->id,
                    'group_id' => $groupId,
                    'status' => 'activa',
                    'enrolled_on' => $enrolledOn,
                ]);
                $enrollment->save();

                return $enrollment;
            });
        } catch (QueryException $e) {
            // `RN-ALUM-21`: índice único parcial (carrera entre dos altas); FK compuestas de curso y grupo (`RN-ALUM-20`)
            // si la estructura cambió entre la lectura y la escritura.
            $state = $e->errorInfo[0] ?? null;

            if ($state === '23505') {
                throw AlumErrors::invalid('student', 'alum.validation.already_enrolled');
            }

            if ($state === '23503') {
                throw AlumErrors::invalid('course', 'alum.validation.parent_not_found');
            }

            throw $e;
        }
    }

    /**
     * Edición (`RN-ALUM-28`, `-29`): `enrolled_on` y la asignación de un **primer** grupo (`group`). Alumno, curso
     * académico y curso de etapa son inmutables: su presencia en el cuerpo ⇒ `422 immutable` (aunque el valor coincida).
     * Cambiar un grupo ya asignado ⇒ `422 group_change_requires_request`. Sin ninguna clave editable ⇒ `no_changes`.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, string $publicId, array $input): Enrollment
    {
        $found = $this->find($publicId);

        foreach (self::IMMUTABLE_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                throw AlumErrors::invalid($key, 'alum.validation.immutable');
            }
        }

        $hasDate = array_key_exists('enrolled_on', $input);
        $hasGroup = array_key_exists('group', $input);

        if (! $hasDate && ! $hasGroup) {
            throw AlumErrors::invalid('enrolled_on', 'alum.validation.no_changes');
        }

        $enrolledOn = $hasDate ? $this->date($input, 'enrolled_on') : null;
        $groupPublicId = $hasGroup ? $this->optionalString($input, 'group') : null;
        $newGroupId = null;

        if ($groupPublicId !== null) {
            $newGroupId = $this->resolveGroup($groupPublicId, (int) $found->course_id, $actor);
        }

        return DB::transaction(function () use ($found, $enrolledOn, $hasGroup, $newGroupId): Enrollment {
            [$year, $enrollment] = $this->lockForWrite($found);

            if ($hasGroup && (int) $enrollment->group_id !== (int) $newGroupId && $enrollment->group_id !== null) {
                throw AlumErrors::invalid('group', 'alum.validation.group_change_requires_request');
            }

            if ($enrolledOn !== null) {
                $this->assertDateInYear($enrolledOn, $year);

                if ($enrollment->withdrawn_on !== null && $enrolledOn > $enrollment->withdrawn_on->toDateString()) {
                    throw AlumErrors::invalid('enrolled_on', 'alum.validation.withdrawn_before_enrolled');
                }

                $enrollment->enrolled_on = $enrolledOn;
            }

            if ($hasGroup && $enrollment->group_id === null && $newGroupId !== null) {
                $enrollment->group_id = $newGroupId;
            }

            $enrollment->save();

            return $enrollment;
        });
    }

    /**
     * Baja (`RN-ALUM-22`, §4.3): la matrícula pasa a `baja` con fecha (≥ `enrolled_on`) y motivo del vocabulario. Sobre
     * una matrícula ya en `baja` ⇒ `409 already_withdrawn`, también ante el reintento de la misma petición (`RN-ALUM-27`).
     *
     * @param  array<string, mixed>  $input  `withdrawn_on`, `withdrawal_reason`
     */
    public function withdraw(User $actor, string $publicId, array $input): Enrollment
    {
        $found = $this->find($publicId);

        return DB::transaction(function () use ($found, $input): Enrollment {
            [, $enrollment] = $this->lockForWrite($found);

            if ($enrollment->status === 'baja') {
                throw AlumErrors::conflict('enrollment', 'alum.conflict.already_withdrawn');
            }

            $on = $this->date($input, 'withdrawn_on');
            $reason = $this->requiredString($input, 'withdrawal_reason');

            if (! in_array($reason, self::WITHDRAWAL_REASONS, true)) {
                throw AlumErrors::invalid('withdrawal_reason', 'alum.validation.withdrawal_reason_invalid');
            }

            if ($on < $enrollment->enrolled_on->toDateString()) {
                throw AlumErrors::invalid('withdrawn_on', 'alum.validation.withdrawn_before_enrolled');
            }

            $enrollment->status = 'baja';
            $enrollment->withdrawn_on = $on;
            $enrollment->withdrawal_reason = $reason;
            $enrollment->save();

            return $enrollment;
        });
    }

    /**
     * Anulación (`RN-ALUM-25`): borrado lógico de una matrícula creada por error. El registro de vetos de `alum` no
     * tiene consumidores en `1.15` (`funcional.md §7.2`): siempre se permite.
     */
    public function annul(User $actor, string $publicId): void
    {
        $found = $this->find($publicId);

        DB::transaction(function () use ($found): void {
            [, $enrollment] = $this->lockForWrite($found);
            $enrollment->delete();
        });
    }

    /** `RN-ALUM-05`: ¿tiene el alumno alguna matrícula `activa` viva? */
    public function hasActiveEnrollments(int $studentId): bool
    {
        return Enrollment::query()->where('student_id', $studentId)->where('status', 'activa')->exists();
    }

    /**
     * Ids de los grupos de `groupIdsFor(u, Y)` para cada curso académico `Y` en que hay matrículas con grupo
     * (`RN-ALUM-60`: «del curso de la matrícula»). Un grupo pertenece a un solo curso, así que la unión por cursos es
     * exactamente la restricción por fila. Sin caché entre peticiones (`RN-ALUM-63`); `groupIdsFor` memoiza por petición.
     *
     * @return list<int>
     */
    public function groupIdsOf(User $subject): array
    {
        $ids = [];

        foreach (Enrollment::query()->whereNotNull('group_id')->distinct()->pluck('academic_year_id') as $yearId) {
            foreach ($this->membership->groupIdsFor((int) $subject->id, (int) $yearId) as $groupId) {
                $ids[$groupId] = $groupId;
            }
        }

        return array_values($ids);
    }

    /**
     * Subconsulta (`SELECT student_id`) de las matrículas `activas` vivas del curso `$yearId` en esos grupos, para el
     * resolutor (`grupo`, `alumno`) (`RN-ALUM-61`).
     *
     * @param  list<int>  $groupIds
     * @return Builder<Enrollment>
     */
    public function activeStudentIdsInGroups(array $groupIds, int $yearId): Builder
    {
        return Enrollment::query()
            ->where('academic_year_id', $yearId)
            ->where('status', 'activa')
            ->whereIn('group_id', $groupIds)
            ->select('student_id');
    }

    /**
     * @param  Builder<Enrollment>  $query
     * @param  array{academic_year?: ?string, course?: ?string, group?: ?string, student?: ?string, status?: ?string}  $filters
     */
    private function applyFilters(Builder $query, AcademicYearSummary $year, array $filters): void
    {
        if (($filters['course'] ?? null) !== null) {
            $course = $this->courses->findByPublicId($filters['course']);

            $course !== null && $course->academicYearId === $year->id
                ? $query->where('course_id', $course->id)
                : $query->whereRaw('1 = 0');
        }

        if (($filters['group'] ?? null) !== null) {
            $group = $this->groups->findByPublicId($filters['group']);

            $group !== null && $group->academicYearId === $year->id
                ? $query->where('group_id', $group->id)
                : $query->whereRaw('1 = 0');
        }

        if (($filters['student'] ?? null) !== null) {
            $studentId = $this->students->idByPublicId($filters['student']);

            $studentId !== null ? $query->where('student_id', $studentId) : $query->whereRaw('1 = 0');
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }
    }

    /**
     * El nombre vive en `Core`: se ordena en memoria tras leerlo (conjuntos acotados por centro, `api.md §5`).
     *
     * @param  Builder<Enrollment>  $query
     * @return LengthAwarePaginator<int, Enrollment>
     */
    private function pageByName(Builder $query, bool $descending, int $page, int $perPage): LengthAwarePaginator
    {
        $enrollments = $query->get();
        $students = $this->students->byIds(array_values(array_unique(array_map(static fn (Enrollment $e): int => (int) $e->student_id, $enrollments->all()))));
        $refs = $this->people->refsByIds(array_values(array_unique(array_map(static fn ($s): int => (int) $s->person_id, $students))));

        $key = static function (Enrollment $e) use ($students, $refs): string {
            $student = $students[(int) $e->student_id] ?? null;
            $ref = $student === null ? null : ($refs[(int) $student->person_id] ?? null);

            return $ref === null ? '' : mb_strtolower($ref->displayName);
        };

        $sorted = $enrollments->sort(static function (Enrollment $a, Enrollment $b) use ($key, $descending): int {
            $order = strcmp($key($a), $key($b)) ?: ((int) $a->id <=> (int) $b->id);

            return $descending ? -$order : $order;
        })->values();

        return new LengthAwarePaginator(
            $sorted->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            $sorted->count(),
            $perPage,
            $page,
        );
    }

    /**
     * Orden de bloqueo común a las escrituras sobre una matrícula existente: curso `FOR SHARE` (cerrado ⇒ 409), luego la
     * matrícula `FOR UPDATE`, releída (una anulación o edición concurrente ya confirmó ⇒ `404`).
     *
     * @return array{0: AcademicYearSummary, 1: Enrollment}
     */
    private function lockForWrite(Enrollment $found): array
    {
        $year = $this->lockedYear((int) $found->academic_year_id);

        if ($year->isReadOnly()) {
            throw new AcademicYearReadOnlyException($year);
        }

        $enrollment = Enrollment::query()->whereKey($found->getKey())->lockForUpdate()->firstOrFail();

        return [$year, $enrollment];
    }

    private function lockedYear(int $yearId): AcademicYearSummary
    {
        return $this->sharedLock->lockForShareById($yearId)
            ?? throw AlumErrors::invalid('academic_year', 'alum.validation.academic_year_not_found');
    }

    private function yearReadable(int $yearId, User $actor): bool
    {
        $year = $this->years->findById($yearId);

        return $year !== null && $this->readAccess->canRead($year, $actor);
    }

    private function assertYearReadable(int $yearId, User $subject): void
    {
        $year = $this->years->findById($yearId) ?? throw ApiException::notFound();
        $this->readAccess->assertCanRead($year, $subject);
    }

    /** Grupo del cuerpo: vivo, legible y del curso de etapa dado (`RN-ALUM-20`, `-24`). */
    private function resolveGroup(string $publicId, int $courseId, User $actor): int
    {
        $group = $this->groups->findByPublicId($publicId);

        if ($group === null || ! $this->yearReadable($group->academicYearId, $actor)) {
            throw AlumErrors::invalid('group', 'alum.validation.parent_not_found');
        }

        if ($group->courseId !== $courseId) {
            throw AlumErrors::invalid('group', 'alum.validation.group_not_in_course');
        }

        return $group->id;
    }

    /** `RN-ALUM-29`: bordes incluidos, con las fechas del curso leídas bajo `FOR SHARE`. */
    private function assertDateInYear(string $date, AcademicYearSummary $year): void
    {
        if ($date < $year->startsOn->toDateString() || $date > $year->endsOn->toDateString()) {
            throw AlumErrors::invalid('enrolled_on', 'alum.validation.date_outside_academic_year');
        }
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

    /** @param  array<string, mixed>  $input */
    private function optionalString(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw AlumErrors::invalid($key, 'alum.validation.required');
        }

        return $value;
    }

    /**
     * Fecha civil `AAAA-MM-DD` real. Falta ⇒ `required`; formato o fecha imposible ⇒ `date_invalid`.
     *
     * @param  array<string, mixed>  $input
     */
    private function date(array $input, string $key): string
    {
        $value = $this->requiredString($input, $key);
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw AlumErrors::invalid($key, 'alum.validation.date_invalid');
        }

        return $value;
    }
}
