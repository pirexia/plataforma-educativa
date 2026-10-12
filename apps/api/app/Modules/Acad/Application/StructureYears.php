<?php

namespace App\Modules\Acad\Application;

use App\Models\User;
use App\Modules\Curso\Domain\AcademicYearContext;
use App\Modules\Curso\Domain\AcademicYearDirectory;
use App\Modules\Curso\Domain\AcademicYearReadAccess;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Support\Api\ApiException;

/**
 * REQ-ACAD/api.md (cabecera), `RN-ACAD-08`, `RN-CURSO-25/-33`, `OPEN-ACAD-12`:
 * resolución del curso académico de cada petición por las interfaces de
 * `Curso\Domain` (`AR-01`), nunca por su modelo. Toda lectura pasa por
 * `AcademicYearReadAccess::assertCanRead`: un curso `cerrado`/`archivado`
 * sin `curso_historico.leer` es `404` (la excepción la traduce `curso`).
 */
final class StructureYears
{
    public function __construct(
        private readonly AcademicYearDirectory $directory,
        private readonly AcademicYearContext $context,
        private readonly AcademicYearReadAccess $readAccess,
    ) {}

    /**
     * Curso de un listado de nivel superior: `?academic_year=` o el activo.
     * Inexistente o de otro centro ⇒ `404`; sin activo ⇒ `404 curso.no_active_year`.
     */
    public function forListing(?string $publicId, User $actor): AcademicYearSummary
    {
        if ($publicId === null || $publicId === '') {
            $year = $this->context->active() ?? throw StructureErrors::noActiveYear();
        } else {
            $year = $this->directory->findByPublicId($publicId) ?? throw ApiException::notFound();
        }

        $this->readAccess->assertCanRead($year, $actor);

        return $year;
    }

    /** Curso de una fila ya cargada (detalle, listados anidados, escrituras). */
    public function forRow(int $academicYearId, User $actor): AcademicYearSummary
    {
        $year = $this->directory->findById($academicYearId) ?? throw ApiException::notFound();

        $this->readAccess->assertCanRead($year, $actor);

        return $year;
    }

    /**
     * Curso que llega en el cuerpo del alta de una etapa. Inexistente o de
     * otro centro ⇒ `null` (el llamador responde `422 academic_year_not_found`);
     * de solo lectura sin `curso_historico.leer` ⇒ `404`.
     */
    public function fromBody(string $publicId, User $actor): ?AcademicYearSummary
    {
        $year = $this->directory->findByPublicId($publicId);

        if ($year === null) {
            return null;
        }

        $this->readAccess->assertCanRead($year, $actor);

        return $year;
    }

    /** `public_id` del curso de una fila, para las representaciones. */
    public function publicIdOf(int $academicYearId): ?string
    {
        return $this->directory->findById($academicYearId)?->publicId;
    }
}
