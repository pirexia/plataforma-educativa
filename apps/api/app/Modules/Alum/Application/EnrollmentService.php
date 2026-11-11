<?php

namespace App\Modules\Alum\Application;

use App\Models\User;
use App\Modules\Acad\Domain\CourseDirectory;
use App\Modules\Acad\Domain\GroupDirectory;
use App\Modules\Acad\Domain\TeachingMembership;
use App\Modules\Alum\Domain\Models\Enrollment;
use App\Modules\Alum\Domain\Models\Student;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\Curso\Domain\AcademicYearContext;
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

    /** @var array<int, list<int>> usuario => ids de grupo; vive lo que la petición (`scoped()`), `RN-ALUM-63` */
    private array $groupIdsMemo = [];

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
        private readonly AcademicYearContext $yearContext,
    ) {}

    /** Detalle sin acotar por ámbito (escrituras, que exigen ámbito `todos`): `404` si no existe o es de otro centro. */
    public function find(string $publicId): Enrollment
    {
        return Enrollment::query()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * Matrícula para escribir (edición, baja, anulación; ámbito `todos`): como `findScoped()`, un curso académico que el
     * actor no puede leer (cerrado sin `curso_historico.leer`) es `404`, no `409 academic-year-closed` (#598, `RN-ALUM-23`,
     * `-64`: no revelar que existe).
     */
    private function findWritable(string $publicId, User $actor): Enrollment
    {
        $enrollment = $this->find($publicId);
        $this->assertYearReadable((int) $enrollment->academic_year_id, $actor);

        return $enrollment;
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
        $query = $this->filteredQuery($decision, $subject, $filters);

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
            // Efímero y no serializado (`EnrollmentResource` lee solo `student_view`): lo reutiliza la exportación.
            $enrollment->setAttribute('student_person_id', $student === null ? null : (int) $student->person_id);
        }
    }

    /**
     * Pone en cada matrícula los atributos efímeros con los que `EnrollmentResource` la presenta: `student_view`
     * (alumno), `year_view`, `course_view` y `group_view` (resúmenes de `curso` y `acad`, memoizados por petición).
     *
     * @param  iterable<Enrollment>  $enrollments
     */
    public function attachViews(iterable $enrollments): void
    {
        $list = [];

        foreach ($enrollments as $enrollment) {
            $list[] = $enrollment;
        }

        $this->attachStudents($list);

        $years = $this->years->findManyById(array_values(array_unique(array_map(static fn (Enrollment $e): int => (int) $e->academic_year_id, $list))));

        // Una consulta por tipo para toda la lista (#601), no una por matrícula.
        $courses = $this->courses->findManyById(array_values(array_unique(array_map(static fn (Enrollment $e): int => (int) $e->course_id, $list))));
        $groups = $this->groups->findManyById(array_values(array_unique(array_filter(array_map(static fn (Enrollment $e): ?int => $e->group_id === null ? null : (int) $e->group_id, $list)))));

        foreach ($list as $enrollment) {
            $year = $years[(int) $enrollment->academic_year_id] ?? null;
            $course = $courses[(int) $enrollment->course_id] ?? null;
            $group = $enrollment->group_id === null ? null : ($groups[(int) $enrollment->group_id] ?? null);

            $enrollment->setAttribute('year_view', ['public_id' => $year?->publicId, 'code' => $year?->code]);
            $enrollment->setAttribute('course_view', [
                'public_id' => $course?->publicId,
                'code' => $course?->code,
                'name_translations' => $course === null ? [] : $course->nameTranslations,
            ]);
            $enrollment->setAttribute('group_view', $group === null ? null : [
                'public_id' => $group->publicId,
                'code' => $group->code,
                'name' => $group->name,
            ]);
        }
    }

    /**
     * Matrículas vivas de un alumno para su ficha (`enrollments[]`, `api.md §1`), acotadas por el ámbito de
     * `matricula.leer` del sujeto (`ScopedQuery`, recurso `matricula`); las de cursos que no puede leer se omiten
     * (`RN-ALUM-23`). Sin `matricula.leer`, ninguna. Orden: la más reciente primero.
     *
     * @return list<Enrollment>
     */
    public function forStudent(int $studentId, User $subject, PermissionDecision $decision): array
    {
        if (! $decision->permitted) {
            return [];
        }

        $enrollments = $this->scopedQuery->constrain(Enrollment::query(), 'matricula', $decision, $subject)
            ->where('student_id', $studentId)->orderByDesc('enrolled_on')->orderByDesc('id')->get()->all();
        $years = $this->years->findManyById(array_values(array_unique(array_map(static fn (Enrollment $e): int => (int) $e->academic_year_id, $enrollments))));

        $readable = array_values(array_filter($enrollments, function (Enrollment $e) use ($years, $subject): bool {
            $year = $years[(int) $e->academic_year_id] ?? null;

            return $year !== null && $this->readAccess->canRead($year, $subject);
        }));

        $this->attachViews($readable);

        return $readable;
    }

    /**
     * Matrícula vigente de cada alumno del listado en el curso académico pedido (`academic_year`, o el activo si hay algún
     * filtro de matrícula), como atributo efímero `enrollment_view` (`Enrollment` o `null`). Acotada por el ámbito de
     * `matricula.leer` (sin el permiso, `null`) y por los mismos `course`, `group` y `enrollment_status` del listado. Sin
     * `academic_year` ni filtros de matrícula, o sin matrícula, `null`. Si hay varias del curso, la `activa` y, después,
     * la más reciente.
     *
     * @param  iterable<Student>  $students
     * @param  array{academic_year?: ?string, course?: ?string, group?: ?string, enrollment_status?: ?string}  $filters
     */
    public function attachCurrent(iterable $students, User $subject, PermissionDecision $decision, array $filters): void
    {
        $list = [];

        foreach ($students as $student) {
            $student->setAttribute('enrollment_view', null);
            $list[] = $student;
        }

        $yearPublicId = $filters['academic_year'] ?? null;
        $filtered = ($filters['course'] ?? null) !== null || ($filters['group'] ?? null) !== null || ($filters['enrollment_status'] ?? null) !== null;

        if ($list === [] || ! $decision->permitted || (($yearPublicId === null || $yearPublicId === '') && ! $filtered)) {
            return;
        }

        $year = $yearPublicId !== null && $yearPublicId !== '' ? $this->years->findByPublicId($yearPublicId) : $this->yearContext->active();

        if ($year === null || ! $this->readAccess->canRead($year, $subject)) {
            return;
        }

        $query = $this->scopedQuery->constrain(Enrollment::query(), 'matricula', $decision, $subject)
            ->where('academic_year_id', $year->id)
            ->whereIn('student_id', array_map(static fn ($s): int => (int) $s->id, $list));
        $this->applyFilters($query, $year, ['course' => $filters['course'] ?? null, 'group' => $filters['group'] ?? null, 'status' => $filters['enrollment_status'] ?? null]);

        $byStudent = [];

        // Última en ganar: se recorre de menos a más preferida (baja antes que activa, antigua antes que reciente).
        $found = $query->orderByRaw("case when status = 'activa' then 1 else 0 end")->orderBy('enrolled_on')->orderBy('id')->get();

        foreach ($found as $enrollment) {
            $byStudent[(int) $enrollment->student_id] = $enrollment;
        }

        $this->attachViews(array_values($byStudent));

        foreach ($list as $student) {
            $student->setAttribute('enrollment_view', $byStudent[(int) $student->id] ?? null);
        }
    }

    /**
     * Filtros de matrícula de `GET /students` (`api.md §1`, desde `1.15-b`): `academic_year` (`public_id`; por defecto el
     * del `AcademicYearContext`), `course`, `group` y `enrollment_status`. Devuelve la subconsulta `SELECT student_id` de
     * las matrículas vivas que cumplen todos, o `null` si no hay ni `course`, ni `group`, ni `enrollment_status` (el
     * `academic_year` solo no filtra). Un `academic_year` explícito inexistente o ilegible (curso cerrado sin
     * `curso_historico.leer`) ⇒ `404` (`RN-ALUM-23`). Sin curso activo ni `academic_year`, o con un `course`/`group` que no
     * resuelve a algo de ese curso, la subconsulta no devuelve nada.
     *
     * Los filtros `course`, `group` y `enrollment_status` revelan datos de matrícula: exigen `matricula.leer`
     * (`$matricula`, la decisión de ese permiso) y, si se tiene, la subconsulta se acota por su ámbito (`ScopedQuery`,
     * recurso `matricula`); sin el permiso, `403 alum.validation.filter_forbidden` (`api.md §1`, revisión de seguridad M1).
     *
     * @param  array{academic_year?: ?string, course?: ?string, group?: ?string, enrollment_status?: ?string}  $filters
     * @return Builder<Enrollment>|null
     */
    public function studentIdsMatching(User $subject, PermissionDecision $matricula, array $filters): ?Builder
    {
        $yearPublicId = $filters['academic_year'] ?? null;
        $year = null;

        if ($yearPublicId !== null && $yearPublicId !== '') {
            $year = $this->years->findByPublicId($yearPublicId) ?? throw ApiException::notFound();
            $this->readAccess->assertCanRead($year, $subject);
        }

        $filtered = ($filters['course'] ?? null) !== null || ($filters['group'] ?? null) !== null || ($filters['enrollment_status'] ?? null) !== null;

        if (! $filtered) {
            return null;
        }

        if (! $matricula->permitted) {
            throw AlumErrors::filterForbidden();
        }

        $year ??= $this->yearContext->active();
        $query = $this->scopedQuery->constrain(Enrollment::query(), 'matricula', $matricula, $subject)->select('student_id');

        if ($year === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('academic_year_id', $year->id);
        $this->applyFilters($query, $year, ['course' => $filters['course'] ?? null, 'group' => $filters['group'] ?? null, 'status' => $filters['enrollment_status'] ?? null]);

        return $query;
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

                $this->assertStructureAlive((int) $course->id, $groupId);
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
        $found = $this->findWritable($publicId, $actor);

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

            $this->assertStructureAlive((int) $enrollment->course_id, $hasGroup && $enrollment->group_id === null ? $newGroupId : null);

            if ($hasGroup && (int) $enrollment->group_id !== (int) $newGroupId && $enrollment->group_id !== null) {
                throw AlumErrors::invalid('group', 'alum.validation.group_change_requires_request');
            }

            if ($enrolledOn !== null) {
                $this->assertDateInYear($enrolledOn, $year);

                if ($enrollment->withdrawn_on !== null && $enrolledOn > $enrollment->withdrawn_on->toDateString()) {
                    throw AlumErrors::invalid('enrolled_on', 'alum.validation.withdrawn_before_enrolled');
                }

                $enrollment->setAttribute('enrolled_on', $enrolledOn);
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
        $found = $this->findWritable($publicId, $actor);

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
            $enrollment->setAttribute('withdrawn_on', $on);
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
        $found = $this->findWritable($publicId, $actor);

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
     * exactamente la restricción por fila. Memoizado **por petición** (el servicio se registra con `scoped()`, y el
     * resolutor lo consulta una vez por consulta acotada: sin esto, cada una barría `enrollments`); **sin caché entre
     * peticiones** (`RN-ALUM-63`, la asignación retirada se nota en la petición siguiente); `groupIdsFor` también
     * memoiza por petición.
     *
     * @return list<int>
     */
    public function groupIdsOf(User $subject): array
    {
        return $this->groupIdsMemo[(int) $subject->id] ??= $this->computeGroupIdsOf($subject);
    }

    /** @return list<int> */
    private function computeGroupIdsOf(User $subject): array
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
     * Consulta del listado y de la exportación (mismos filtros y mismo ámbito, `ADR-054 §8.2`): acotada por ámbito de
     * `matricula` (`ScopedQuery`) y por `academic_year`, obligatorio y legible (`RN-ALUM-23`: inexistente o ilegible ⇒
     * `404`; ausente ⇒ `422 required`).
     *
     * @param  array{academic_year?: ?string, course?: ?string, group?: ?string, student?: ?string, status?: ?string}  $filters
     * @return Builder<Enrollment>
     */
    private function filteredQuery(PermissionDecision $decision, User $subject, array $filters): Builder
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

        return $query;
    }

    /**
     * Validación síncrona de `POST /enrollments/exports` (`api.md §6`): el curso académico existe y es legible; si no,
     * la misma respuesta que el listado. Aplica además el tope `core.export_max_rows` (#599): `422
     * core.validation.export_range_too_large`, como `audit_logs` y `users`.
     *
     * @param  array{academic_year?: ?string, course?: ?string, group?: ?string, status?: ?string}  $filters
     */
    public function assertExportable(PermissionDecision $decision, User $subject, array $filters): void
    {
        $query = $this->filteredQuery($decision, $subject, $filters);

        // #599, `RNF-LIM-004` / `RN-CORE-85` (mismo código, mismo momento y mismo tope que los `kind` de `Core`): el tope
        // cuenta lo que se exporta, antes de encolar.
        $max = (int) config('core.export_max_rows');

        if ($query->count() > $max) {
            throw ApiException::validation([
                'filters' => [[
                    'code' => 'core.validation.export_range_too_large',
                    'message' => __('core.validation.export_range_too_large'),
                    'params' => ['max_rows' => $max],
                ]],
            ]);
        }
    }

    /**
     * Filas del CSV de `alum_enrollments` (`api.md §6`, esquema cerrado), en lotes de `$size` por id ascendente, con el
     * mismo ámbito y filtros que el listado. Cada fila: `enrollment_public_id`, `student_public_id`, `family_name`
     * (apellidos 1 y 2 unidos por un espacio), `given_name`, `birth_date`, `academic_year_code`, `course_code`,
     * `group_name`, `status`, `enrolled_on`, `withdrawn_on`, `withdrawal_reason`. Nunca el documento de identidad.
     * Los datos de personas se leen en una consulta por lote (`PersonDirectory`).
     *
     * @param  array{academic_year?: ?string, course?: ?string, group?: ?string, status?: ?string}  $filters
     * @return \Generator<int, list<list<?string>>>
     */
    public function exportBatches(PermissionDecision $decision, User $subject, array $filters, int $size = 500): \Generator
    {
        $query = $this->filteredQuery($decision, $subject, $filters);
        $last = 0;

        while (true) {
            $batch = (clone $query)->where('id', '>', $last)->orderBy('id')->limit($size)->get()->all();

            if ($batch === []) {
                return;
            }

            // `attachViews()` ya leyó alumnos y personas del lote: aquí solo quedan fecha de nacimiento y nombres por partes.
            $this->attachViews($batch);
            $personIds = array_values(array_unique(array_filter(array_map(static fn (Enrollment $e): ?int => $e->getAttribute('student_person_id'), $batch))));
            $births = $this->people->birthDatesByIds($personIds);
            $contacts = $this->people->contactDetailsByIds($personIds);

            $rows = [];

            foreach ($batch as $enrollment) {
                $personId = $enrollment->getAttribute('student_person_id');
                $contact = $personId === null ? null : ($contacts[$personId] ?? null);
                $family = trim(($contact === null ? '' : ($contact->familyName1 ?? '')).' '.($contact === null ? '' : ($contact->familyName2 ?? '')));

                $rows[] = [
                    $enrollment->public_id,
                    $enrollment->getAttribute('student_view')['public_id'] ?? null,
                    $family === '' ? null : $family,
                    $contact?->givenName,
                    $personId === null ? null : ($births[$personId] ?? null)?->birthDate,
                    $enrollment->getAttribute('year_view')['code'] ?? null,
                    $enrollment->getAttribute('course_view')['code'] ?? null,
                    $enrollment->getAttribute('group_view')['name'] ?? null,
                    $enrollment->status,
                    $enrollment->enrolled_on->format('Y-m-d'),
                    $enrollment->withdrawn_on?->format('Y-m-d'),
                    $enrollment->withdrawal_reason,
                ];
            }

            yield $rows;

            $last = (int) end($batch)->id;
        }
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

    /**
     * `RN-ALUM-26`, `RN-ACAD-21` (revisión de seguridad M2): dentro de la transacción de escritura, vuelve a leer el curso
     * de etapa (y el grupo, si lo hay) **vivos y con `FOR SHARE`**, en ese orden. Así un borrado concurrente de `acad`
     * (que bloquea la fila `FOR UPDATE` y consulta después el veto) espera a esta transacción y ve la matrícula, o ya
     * confirmó y aquí se rechaza. Ya no existen ⇒ `422 parent_not_found` (el mismo código que un curso o grupo inexistente).
     */
    private function assertStructureAlive(int $courseId, ?int $groupId): void
    {
        $this->courses->lockAliveForShare($courseId) ?? throw AlumErrors::invalid('course', 'alum.validation.parent_not_found');

        if ($groupId !== null) {
            $this->groups->lockAliveForShare($groupId) ?? throw AlumErrors::invalid('group', 'alum.validation.parent_not_found');
        }
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

        if ($parsed === null || $parsed->format('Y-m-d') !== $value) {
            throw AlumErrors::invalid($key, 'alum.validation.date_invalid');
        }

        return $value;
    }
}
