<?php

namespace App\Modules\Curso\Infrastructure;

use App\Modules\Curso\Domain\AcademicYearReadDeniedException;
use App\Modules\Curso\Domain\AcademicYearReadOnlyException;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Api\ApiException;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * ADR-057 §5.5, RN-CURSO-21, `api.md §4`: traduce a la forma de error de la
 * API lo que Curso produce por tres caminos:
 *
 * - el error de motor `SQLSTATE YC001` del disparador
 *   `academic_year_write_guard` (`academic_year_closed:<public_id>`), que
 *   llega como `QueryException` desde cualquier módulo y cualquier camino de
 *   escritura;
 * - `AcademicYearReadOnlyException`, de la comprobación previa
 *   `AcademicYearWriteGuard`;
 * - `AcademicYearReadDeniedException`, de `AcademicYearReadAccess` (`404`,
 *   no `403`: RN-CURSO-25).
 *
 * El núcleo no conoce el `SQLSTATE`: el `CursoServiceProvider` registra este
 * mapeo en el manejador de excepciones. Cualquier otra excepción se devuelve
 * intacta.
 */
final class AcademicYearClosedTranslator
{
    public const SQLSTATE = 'YC001';

    public function translate(Throwable $e): Throwable
    {
        if ($e instanceof AcademicYearReadOnlyException) {
            return $this->closed($e->year?->code, $e->year?->status->value);
        }

        if ($e instanceof AcademicYearReadDeniedException) {
            return ApiException::notFound();
        }

        if ($e instanceof QueryException && $this->isGuardViolation($e)) {
            return $this->fromEngineError($e);
        }

        return $e;
    }

    public static function isGuardViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === self::SQLSTATE || (string) $e->getCode() === self::SQLSTATE;
    }

    private function fromEngineError(QueryException $e): ApiException
    {
        $publicId = $this->publicIdFrom($e);

        if ($publicId !== null) {
            try {
                $year = AcademicYear::query()->where('public_id', $publicId)->first();

                if ($year !== null) {
                    return $this->closed($year->code, $year->status->value);
                }
            } catch (Throwable) {
                // Transacción abortada sin revertir, etc.: se responde igual,
                // sin el código del curso (api.md §4).
            }
        }

        return $this->closed(null, null);
    }

    private function closed(?string $code, ?string $status): ApiException
    {
        if ($code === null || $status === null) {
            $item = ['code' => 'curso.academic_year_closed', 'message' => __('curso.academic_year_closed_anonymous'), 'params' => []];

            return ApiException::academicYearClosed('curso.errors.academic_year_closed.title', 'curso.errors.academic_year_closed.detail_anonymous', [], ['academic_year' => [$item]]);
        }

        // `params` (lo que ve el cliente): el código y el valor de estado sin
        // traducir. El texto lleva además la etiqueta traducida del estado.
        $params = ['code' => $code, 'status' => $status];
        $replacements = [...$params, 'status_label' => mb_strtolower(__("curso.status.{$status}"))];
        $item = ['code' => 'curso.academic_year_closed', 'message' => __('curso.academic_year_closed', $replacements), 'params' => $params];

        return ApiException::academicYearClosed('curso.errors.academic_year_closed.title', 'curso.errors.academic_year_closed.detail', $replacements, ['academic_year' => [$item]]);
    }

    private function publicIdFrom(QueryException $e): ?string
    {
        return preg_match('/academic_year_closed:([0-9A-HJKMNP-TV-Z]{26})/', $e->getMessage(), $matches) === 1 ? $matches[1] : null;
    }
}
