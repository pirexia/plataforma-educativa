<?php

namespace App\Modules\Core\Infrastructure;

use App\Modules\Core\Domain\ExportJobContext;
use App\Modules\Core\Domain\ExportJobLifecycle;
use App\Modules\Core\Domain\Models\DataExport;

/**
 * `ExportJobLifecycle` (1.14-b) sobre `data_exports`. Mismos estados y mismo tratamiento del solicitante borrado que
 * `GenerateUserExport`/`GenerateAuditLogExport` (issue #280): sin solicitante no hay ámbito que aplicar, así que no
 * se exporta nada.
 */
final class EloquentExportJobLifecycle implements ExportJobLifecycle
{
    public function start(int $dataExportId): ?ExportJobContext
    {
        $export = DataExport::query()->with('requester')->find($dataExportId);

        if ($export === null) {
            return null;
        }

        if ($export->requester === null) {
            $export->update(['status' => 'fallida', 'error_code' => 'core.export.generation_failed']);

            return null;
        }

        $export->update(['status' => 'generando']);

        return new ExportJobContext((string) $export->public_id, $export->requester, (array) ($export->filters ?? []));
    }

    public function complete(int $dataExportId, string $objectKey, int $rowCount): void
    {
        DataExport::query()->find($dataExportId)?->update([
            'status' => 'completada',
            'object_key' => $objectKey,
            'row_count' => $rowCount,
            'completed_at' => now(),
        ]);
    }

    public function fail(int $dataExportId, string $errorCode = 'core.export.generation_failed'): void
    {
        DataExport::query()->find($dataExportId)?->update(['status' => 'fallida', 'error_code' => $errorCode]);
    }
}
