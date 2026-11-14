<?php

namespace App\Modules\Core\Domain;

use App\Models\User;

/**
 * REQ-FAM-UNIT/api.md §4 (1.14-b, `OPEN-FAM-UNIT-11`): lo que el trabajo en cola de un módulo necesita de la solicitud
 * de exportación que le toca generar. Valor inmutable: el módulo nunca ve el modelo `DataExport` (`INV-007`).
 */
final class ExportJobContext
{
    /**
     * @param  array<string, mixed>  $filters  los filtros tal y como se guardaron al solicitar la exportación
     */
    public function __construct(
        public readonly string $publicId,
        public readonly User $requester,
        public readonly array $filters,
    ) {}
}
