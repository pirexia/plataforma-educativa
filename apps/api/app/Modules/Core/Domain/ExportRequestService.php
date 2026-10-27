<?php

namespace App\Modules\Core\Domain;

use App\Models\User;
use App\Modules\Core\Domain\Models\DataExport;

/**
 * funcional.md §7: primitiva de exportación asíncrona reutilizable por
 * otros módulos (REQ-PRIV y demás) sin importar código interno de Core
 * (INV-007). `kind`: `audit_logs` (1.1), `users` (1.9b) y `image_consents` (1.14-b, REQ-FAM-UNIT/api.md §4). El
 * artefacto de un `kind` cuyos datos no son de `Core` lo genera el trabajo en cola de su módulo, que recorre los estados
 * de la solicitud con `ExportJobLifecycle`.
 */
interface ExportRequestService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function request(string $kind, string $format, array $filters, User $requestedBy): DataExport;
}
