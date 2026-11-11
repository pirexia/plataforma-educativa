<?php

namespace App\Modules\Alum\Application;

use App\Models\User;
use App\Modules\Alum\Domain\Models\Student;
use App\Modules\Core\Domain\PersonDataCorrection;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\Core\Domain\PersonNotCorrectableException;
use App\Modules\Core\Domain\PersonRef;
use App\Modules\Core\Domain\PersonRegistrar;
use App\Modules\Core\Domain\PersonRegistration;
use App\Modules\FamUnit\Domain\FamilyUnitStudents;
use App\Support\Api\ApiException;
use App\Support\Authorization\PermissionDecision;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\ScopedQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ALUM/funcional.md §4 (`RN-ALUM-01` a `-10`, `-62` a `-64`), 1.15-a: alumnado.
 *
 * Único servicio sancionado por `AR-10` para consultar `Student` (recurso `alumno`, ámbito `unidad_familiar`): el
 * listado y el detalle pasan por `ScopedQuery` (`ADR-044 §4.2`); lo no alcanzado es `404` (`RN-ALUM-64`). `alum` no
 * guarda nombre, documento, fecha de nacimiento ni contacto (`RN-ALUM-02`): los lee y escribe por `Core\Domain`, y la
 * pertenencia a una unidad familiar la escribe `fam_unit` por `FamilyUnitStudents`. Auditoría automática del modelo
 * (`RecordsAuditTrail`, `INV-003`); `Core` audita los cambios de la persona.
 */
final class StudentService
{
    public const NAME_SORTS = ['name', '-name'];

    public function __construct(
        private readonly ScopedQuery $scopedQuery,
        private readonly PersonDirectory $people,
        private readonly PersonRegistrar $registrar,
        private readonly FamilyUnitStudents $familyUnitStudents,
        private readonly PermissionResolver $permissions,
    ) {}

    /** Detalle sin acotar por ámbito (escrituras, que exigen ámbito `todos`): `404` si no existe o es de otro centro. */
    public function find(string $publicId): Student
    {
        return Student::query()->where('public_id', $publicId)->firstOrFail();
    }

    /** Detalle acotado por ámbito (`ADR-044 §4.2`, `RN-ALUM-64`): `404` si no existe, es de otro centro o no se alcanza. */
    public function findScoped(string $publicId, PermissionDecision $decision, User $subject): Student
    {
        return $this->scopedQuery->constrain(Student::query(), 'alumno', $decision, $subject)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /**
     * Página del listado, acotada por ámbito. `q` (prefijo de nombre o apellidos, mínimo 2 caracteres: lo valida la
     * petición) se resuelve con `PersonDirectory::searchByNameAmong()` sobre las personas del ámbito del sujeto. El
     * nombre no vive en `alum`, así que `sort=name` ordena en memoria tras leer los nombres de `Core` (conjuntos
     * acotados por centro, `api.md §5`). `$enrollmentFilters` (`academic_year`, `course`, `group`, `enrollment_status`,
     * desde `1.15-b`) los resuelve `EnrollmentService::studentIdsMatching()`.
     *
     * @param  array{academic_year?: ?string, course?: ?string, group?: ?string, enrollment_status?: ?string}  $enrollmentFilters
     * @return LengthAwarePaginator<int, Student>
     */
    public function page(PermissionDecision $decision, User $subject, ?string $q, bool $includeDeleted, string $sort, int $page, int $perPage, array $enrollmentFilters = []): LengthAwarePaginator
    {
        $query = $this->scopedQuery->constrain(Student::query(), 'alumno', $decision, $subject);

        if ($includeDeleted) {
            $query->withTrashed();
        }

        // Perezoso: `EnrollmentService` depende de este servicio (sin dependencia circular de construcción).
        $enrolled = app(EnrollmentService::class)->studentIdsMatching($subject, $this->permissions->decide($subject, 'matricula.leer'), $enrollmentFilters);

        if ($enrolled !== null) {
            $query->whereIn('id', $enrolled);
        }

        if ($q !== null && $q !== '') {
            $query->whereIn('person_id', $this->matchingPersonIds($query, $q));
        }

        if (in_array($sort, self::NAME_SORTS, true)) {
            return $this->pageByName($query, str_starts_with($sort, '-'), $page, $perPage);
        }

        return $query->orderBy('created_at', str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Pone en cada alumno, como atributo efímero `person_view`, lo que sale de `Core`: `public_id` de la persona,
     * nombre visible y fecha de nacimiento; con `$detail`, además los nombres por partes, el idioma y el documento
     * **enmascarado** (nunca completo, decisión del 2026-11-09).
     *
     * @param  iterable<Student>  $students
     */
    public function attachPeople(iterable $students, bool $detail): void
    {
        $byPerson = [];

        foreach ($students as $student) {
            $byPerson[(int) $student->person_id] = $student;
        }

        if ($byPerson === []) {
            return;
        }

        $ids = array_keys($byPerson);
        $refs = $this->people->refsByIds($ids);
        $births = $this->people->birthDatesByIds($ids);
        $contacts = $detail ? $this->people->contactDetailsByIds($ids) : [];

        foreach ($byPerson as $personId => $student) {
            $ref = $refs[$personId] ?? null;
            $contact = $contacts[$personId] ?? null;

            $view = [
                'person_public_id' => $ref?->publicId,
                'display_name' => $ref === null ? '' : $ref->displayName,
                'birth_date' => ($births[$personId] ?? null)?->birthDate,
            ];

            if ($detail) {
                $view += [
                    'given_name' => $contact?->givenName,
                    'family_name_1' => $contact?->familyName1,
                    'family_name_2' => $contact?->familyName2,
                    'locale' => $contact?->locale,
                    'document_type' => $contact?->documentType,
                    'document_number' => self::maskDocument($contact?->documentNumber),
                ];
            }

            $student->setAttribute('person_view', $view);
        }
    }

    /**
     * Pone en la ficha, como atributo efímero `enrollments_view`, sus matrículas vivas de cursos legibles para el sujeto
     * (`enrollments[]`, `RN-ALUM-23`), acotadas por el ámbito de `matricula.leer` (sin el permiso, ninguna).
     */
    public function attachEnrollments(Student $student, User $subject): void
    {
        $student->setAttribute('enrollments_view', app(EnrollmentService::class)->forStudent((int) $student->id, $subject, $this->permissions->decide($subject, 'matricula.leer')));
    }

    /**
     * Pone en cada alumno del listado su matrícula vigente (`enrollment_view`, `null` si no hay filtro de curso o año, no
     * hay matrícula o el sujeto no tiene `matricula.leer`), acotada por el ámbito de ese permiso.
     *
     * @param  iterable<Student>  $students
     * @param  array{academic_year?: ?string, course?: ?string, group?: ?string, enrollment_status?: ?string}  $filters
     */
    public function attachCurrentEnrollments(iterable $students, User $subject, array $filters): void
    {
        app(EnrollmentService::class)->attachCurrent($students, $subject, $this->permissions->decide($subject, 'matricula.leer'), $filters);
    }

    /**
     * Alta (`RN-ALUM-03`, `-08`, `-09`): en una transacción, la persona en `Core`, su fecha de nacimiento e idioma, la
     * faceta y, si llega, la pertenencia a la unidad familiar. Un fallo en cualquier paso deshace los demás.
     *
     * `RN-ALUM-09`: un documento que coincide con una persona viva del centro ⇒ `person_exists`, con el mismo cuerpo sea
     * quien sea. Se comprueba **antes** de `registerOrReuse()` (que reutiliza en silencio); con esa precedencia
     * `person_already_student` no se alcanza desde el alta (queda la guarda del índice único por si una carrera lo
     * provocara).
     *
     * @param  array{given_name: string, family_name_1: string, family_name_2: ?string, birth_date: string, document_type: ?string, document_number: ?string, locale: ?string}  $data
     */
    public function create(array $data, ?string $familyUnit): Student
    {
        try {
            return DB::transaction(function () use ($data, $familyUnit): Student {
                $type = $data['document_type'];
                $number = $data['document_number'];

                if ($type !== null && $number !== null && $this->people->findByDocument($type, $number) !== null) {
                    throw AlumErrors::invalid('document_number', 'alum.validation.person_exists');
                }

                $outcome = $this->registrar->registerOrReuse(new PersonRegistration(
                    $data['given_name'],
                    $data['family_name_1'],
                    $data['family_name_2'],
                    $type,
                    $number,
                ));

                // Otra alta simultánea creó antes la persona con ese documento: mismo resultado que si ya existiera.
                if ($outcome->reused) {
                    throw AlumErrors::invalid('document_number', 'alum.validation.person_exists');
                }

                // Decisión del 2026-11-09: `PersonRegistration` no lleva fecha de nacimiento ni idioma; se escriben con
                // la corrección de `Core` en la misma transacción (un `updated` más en la auditoría de la persona).
                $changes = ['birth_date' => $data['birth_date']];

                if ($data['locale'] !== null) {
                    $changes['locale'] = $data['locale'];
                }

                $this->registrar->correctPerson($outcome->personId, new PersonDataCorrection($changes));

                $student = new Student(['person_id' => $outcome->personId]);
                $student->save();

                if ($familyUnit !== null) {
                    $this->addToFamilyUnit($familyUnit, $outcome->personId);
                }

                return $student;
            });
        } catch (QueryException $e) {
            // `RN-ALUM-01`: índice único de la faceta viva por persona.
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw AlumErrors::invalid('person', 'alum.validation.person_already_student');
            }

            throw $e;
        }
    }

    /**
     * Corrección de los datos personales (`RN-ALUM-07`, `-10`, `OPEN-ALUM-19` = A): se reenvía a `Core`, con o sin
     * usuario, que valida y audita. Nunca toca la cuenta.
     *
     * @param  array<string, ?string>  $changes  columna de `people` ⇒ valor nuevo
     */
    public function update(string $publicId, array $changes): Student
    {
        return DB::transaction(function () use ($publicId, $changes): Student {
            $student = Student::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

            try {
                $this->registrar->correctPerson((int) $student->person_id, new PersonDataCorrection($changes));
            } catch (PersonNotCorrectableException) {
                throw (new ModelNotFoundException)->setModel(Student::class);
            } catch (ApiException $e) {
                throw $this->withAlumFieldNames($e);
            }

            return $student;
        });
    }

    /**
     * `Core` nombra los campos de persona como en `PATCH /users` (`person.document_number`); el alumnado los expone
     * planos, como el alta (`document_number`). Solo se renombra la clave: código y mensaje de `Core` siguen intactos.
     */
    private function withAlumFieldNames(ApiException $e): ApiException
    {
        if ($e->status !== 422 || $e->type !== 'validation') {
            return $e;
        }

        $errors = [];

        foreach ($e->errors as $field => $list) {
            $errors[str_starts_with($field, 'person.') ? substr($field, 7) : $field] = $list;
        }

        return ApiException::validation($errors, $e->detailKey, $e->detailParams);
    }

    /**
     * `RN-ALUM-05` (completa en `1.15-b`): baja lógica; no borra la persona ni su pertenencia. Un alumno con alguna
     * matrícula `activa` viva no se da de baja ⇒ `409 has_active_enrollments`. La comprobación se hace con la fila del
     * alumno bloqueada `FOR UPDATE`; el alta de matrícula la bloquea `FOR SHARE`, así que no se cruzan.
     */
    public function delete(string $publicId): void
    {
        DB::transaction(function () use ($publicId): void {
            $student = Student::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

            // Perezoso: `EnrollmentService` depende de este servicio (sin dependencia circular de construcción).
            if (app(EnrollmentService::class)->hasActiveEnrollments((int) $student->id)) {
                throw AlumErrors::conflict('student', 'alum.conflict.has_active_enrollments');
            }

            $student->delete();
        });
    }

    /**
     * Alumno vivo por `public_id` bloqueado `FOR SHARE` hasta el final de la transacción (`funcional.md §4.2`): si otra
     * conexión lo está borrando sin confirmar, espera y, al confirmar, devuelve `null`. Exige transacción abierta.
     */
    public function lockAliveForShare(string $publicId): ?Student
    {
        return Student::query()->where('public_id', $publicId)->sharedLock()->first();
    }

    /** Id interno de un alumno vivo por su `public_id`; `null` si no existe o es de otro centro. */
    public function idByPublicId(string $publicId): ?int
    {
        $id = Student::query()->where('public_id', $publicId)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Alumnos (incluidos los dados de baja: una matrícula vive aunque el alumno se dé de baja después de anularla) por
     * id interno, indexados por id.
     *
     * @param  list<int>  $ids
     * @return array<int, Student>
     */
    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Student::withTrashed()->whereIn('id', $ids)->get()->keyBy(static fn (Student $s): int => (int) $s->id)->all();
    }

    /**
     * Subconsulta (`SELECT id`) de los alumnos vivos de esas personas, para los resolutores de `matricula`
     * (`RN-ALUM-62`): los resolutores no consultan `Student` directamente (`AR-10`).
     *
     * @param  list<int>  $personIds
     * @return Builder<Student>
     */
    public function idsQueryOfPersons(array $personIds): Builder
    {
        return Student::query()->whereIn('person_id', $personIds)->select('id');
    }

    /** `RN-ALUM-06`: solo si la persona sigue viva y no tiene otra faceta de alumno viva; si no, `409 cannot_restore`. */
    public function restore(string $publicId): Student
    {
        try {
            return DB::transaction(function () use ($publicId): Student {
                $student = Student::onlyTrashed()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

                $person = $this->people->refsByIds([(int) $student->person_id])[(int) $student->person_id] ?? null;

                if ($person === null || $person->removed || Student::query()->where('person_id', $student->person_id)->exists()) {
                    throw AlumErrors::conflict('student', 'alum.conflict.cannot_restore');
                }

                $student->restore();

                return $student;
            });
        } catch (QueryException $e) {
            // Carrera con otra restauración o alta de la misma persona: el índice único de la faceta viva (`RN-ALUM-01`)
            // la rechaza; es el mismo conflicto que la comprobación previa, no un 500.
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw AlumErrors::conflict('student', 'alum.conflict.cannot_restore');
            }

            throw $e;
        }
    }

    /**
     * Pertenencia a la unidad familiar (`RN-ALUM-03`, `-04`). Una unidad inexistente o de otro centro es `404` en
     * `fam_unit`; aquí es un error de validación del cuerpo (`family_unit_not_found`, indistinguible). Lanza dentro de la
     * transacción del alta, que se deshace entera.
     */
    private function addToFamilyUnit(string $familyUnit, int $personId): void
    {
        try {
            $this->familyUnitStudents->add($familyUnit, $personId);
        } catch (ModelNotFoundException) {
            throw AlumErrors::invalid('family_unit', 'alum.validation.family_unit_not_found');
        } catch (ApiException $e) {
            if ($e->status !== 422) {
                throw $e;
            }

            // Reglas de `fam_unit` (§5.2) sobre una persona recién creada: no deberían darse; si se dieran, no se escribe nada.
            throw AlumErrors::invalid('family_unit', 'alum.validation.family_unit_not_found');
        }
    }

    /**
     * Ids de persona de los alumnos de la consulta cuyo nombre empieza por `$q`.
     *
     * @param  Builder<Student>  $query
     * @return list<int>
     */
    private function matchingPersonIds(Builder $query, string $q): array
    {
        $ids = array_map('intval', (clone $query)->pluck('person_id')->all());

        if ($ids === []) {
            return [];
        }

        return array_map(
            static fn (PersonRef $ref): int => $ref->id,
            $this->people->searchByNameAmong($q, $ids, count($ids)),
        );
    }

    /**
     * @param  Builder<Student>  $query
     * @return LengthAwarePaginator<int, Student>
     */
    private function pageByName(Builder $query, bool $descending, int $page, int $perPage): LengthAwarePaginator
    {
        $students = $query->get();
        $refs = $this->people->refsByIds(array_map(static fn (Student $s): int => (int) $s->person_id, $students->all()));

        $key = static fn (Student $s): string => isset($refs[(int) $s->person_id]) ? mb_strtolower($refs[(int) $s->person_id]->displayName) : '';

        $sorted = $students->sort(static function (Student $a, Student $b) use ($key, $descending): int {
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

    /** Solo los últimos cuatro caracteres a la vista; un número de cuatro o menos, entero oculto. */
    private static function maskDocument(?string $number): ?string
    {
        if ($number === null || $number === '') {
            return null;
        }

        $length = mb_strlen($number);

        return $length <= 4 ? str_repeat('•', $length) : str_repeat('•', $length - 4).mb_substr($number, -4);
    }
}
