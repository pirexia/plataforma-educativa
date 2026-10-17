<?php

namespace App\Modules\Acad\Application;

use App\Modules\Acad\Domain\Models\Facility;
use App\Modules\Acad\Domain\Models\TimetableSession;
use App\Modules\Curso\Domain\AcademicYearDirectory;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §18.4.3 (`RN-ACAD-100` a `-103`), 1.12-a: espacios
 * (aulas y recursos). **Del centro, sin curso académico** (`OPEN-ACAD-33`): no
 * los congela el cierre de un curso, así que ni el disparador ni
 * `AcademicYearReadAccess` intervienen.
 */
final class FacilityService
{
    use WritesStructure;

    /** `RN-ACAD-100`: vocabulario cerrado de `facility_type`. */
    public const TYPES = ['aula', 'recurso'];

    public function __construct(private readonly AcademicYearDirectory $years) {}

    /** Detalle: `404` si no existe o es de otro centro (RLS + *scope* de tenant). */
    public function find(string $publicId): Facility
    {
        return Facility::query()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @param  array{facility_type: string, code: string, name: string}  $input
     */
    public function create(array $input): Facility
    {
        return $this->inTransaction(function () use ($input): Facility {
            $this->assertCodeFree(Facility::query(), $input['code']);

            return Facility::create([
                'facility_type' => $input['facility_type'],
                'code' => $input['code'],
                'name' => $input['name'],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $input  solo `code` y `name` (el tipo es inmutable, `RN-ACAD-100`)
     */
    public function update(string $publicId, array $input): Facility
    {
        $facility = $this->find($publicId);

        if (array_key_exists('code', $input)) {
            $this->assertCodeFree(Facility::query(), $input['code'], $facility->id);
        }

        return $this->inTransaction(function () use ($facility, $input): Facility {
            $facility->fill(array_intersect_key($input, array_flip(['code', 'name'])))->save();

            return $facility;
        });
    }

    /**
     * `RN-ACAD-102`: borrado lógico. Bloqueado (`409 has_dependents` con
     * `timetable_sessions` —y `facility_bookings` en `1.12-b`—) solo si lo
     * referencian sesiones vivas de cursos `planificacion` o `activo`; las de
     * cursos `cerrado`/`archivado` conservan la referencia (borrado lógico, FK
     * `NO ACTION`) y se representan con `facility.deleted: true`.
     *
     * `FOR UPDATE` sobre el espacio: una sesión concurrente toma `FOR SHARE`
     * sobre él (`RN-ACAD-128`), así que o la ve el borrado o ella ve el espacio
     * borrado.
     */
    public function delete(string $publicId): void
    {
        DB::transaction(function () use ($publicId): void {
            $facility = Facility::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

            $yearIds = TimetableSession::query()->where('facility_id', $facility->id)->distinct()->pluck('academic_year_id')->all();
            $writable = array_filter(
                $this->years->findManyById(array_map('intval', $yearIds)),
                static fn ($year): bool => ! $year->isReadOnly(),
            );

            if ($writable !== []) {
                throw StructureErrors::hasDependents('facility', $facility->code, ['timetable_sessions']);
            }

            $facility->delete();
        });
    }
}
