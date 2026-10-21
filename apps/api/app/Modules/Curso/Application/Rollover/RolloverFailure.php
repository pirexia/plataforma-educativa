<?php

namespace App\Modules\Curso\Application\Rollover;

use App\Modules\Curso\Domain\AcademicYearRolloverIssue;
use RuntimeException;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-69`, `-75` (1.12b): un fallo previsto de la ejecución de una
 * copia o de una reversión, con el `failure.code` que se persistirá (`rollover_blocked`,
 * `target_not_planning`, `target_changed`, `actor_unavailable`, `cancelled`, `internal_error`) y las
 * incidencias que lo explican. Revierte la transacción entera (todo o nada).
 */
final class RolloverFailure extends RuntimeException
{
    /**
     * @param  list<AcademicYearRolloverIssue>  $issues
     */
    public function __construct(public readonly string $failureCode, public readonly array $issues = [])
    {
        parent::__construct("Fallo de la copia: {$failureCode}");
    }

    /**
     * La forma que se guarda en `year_rollovers.failure` / `revert_failure`: solo `code` y `params`,
     * nunca mensajes traducidos (`RN-CURSO-73`, revisión de documentación M-3).
     *
     * @param  list<AcademicYearRolloverIssue>  $issues
     * @return array{code: string, errors: list<array{code: string, params: array<string, mixed>, element: string|null}>}
     */
    public static function payload(string $code, array $issues = []): array
    {
        return [
            'code' => $code,
            'errors' => array_map(static fn (AcademicYearRolloverIssue $issue): array => [
                'code' => $issue->code,
                'params' => $issue->params,
                'element' => $issue->element ?? ($issue->params['element'] ?? null),
            ], $issues),
        ];
    }
}
