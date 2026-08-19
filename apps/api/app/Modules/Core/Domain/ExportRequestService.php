<?php

namespace App\Modules\Core\Domain;

use App\Models\User;
use App\Modules\Core\Domain\Models\DataExport;

/**
 * funcional.md §7: primitiva de exportación asíncrona reutilizable por
 * otros módulos (REQ-PRIV y demás) sin importar código interno de Core
 * (INV-007). En 1.1 solo existe `kind = 'audit_logs'`.
 */
interface ExportRequestService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function request(string $kind, string $format, array $filters, User $requestedBy): DataExport;
}
