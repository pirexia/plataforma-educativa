<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Core\Domain\ExportRequestService;
use App\Modules\Core\Domain\Models\DataExport;
use App\Modules\Core\Infrastructure\Jobs\GenerateAuditLogExport;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * funcional.md §4.6, §7. En 1.1 solo despacha el generador de
 * `audit_logs`; un `kind` futuro añadirá su propio `match` sin tocar el
 * contrato de la interfaz.
 */
final class EloquentExportRequestService implements ExportRequestService
{
    public function request(string $kind, string $format, array $filters, User $requestedBy): DataExport
    {
        if ($kind === 'audit_logs') {
            $this->assertWithinRowLimit($filters);
        }

        $export = DataExport::create([
            'kind' => $kind,
            'format' => $format,
            'filters' => $filters,
            'status' => 'pendiente',
            'requested_by' => $requestedBy->id,
            'expires_at' => now()->addDays((int) config('core.export_retention_days')),
        ]);

        // funcional.md §4.6 paso 4: la propia solicitud se audita con
        // event 'exported' — además del 'created' automático de
        // RecordsAuditTrail (ADR-035 §4), no en su lugar: el vocabulario
        // de `event` ya contempla ambos y no hay motivo para perder el
        // rastro genérico de creación por tener el específico de negocio.
        app(AuditRecorder::class)->record($export, 'exported');

        $tenant = Tenant::query()->find(app(TenantContext::class)->tenantId());

        match ($kind) {
            'audit_logs' => GenerateAuditLogExport::dispatch($export->id, $tenant->public_id ?? ''),
            default => null,
        };

        return $export;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function assertWithinRowLimit(array $filters): void
    {
        $query = AuditLog::query();

        if (isset($filters['from'])) {
            $query->where('occurred_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('occurred_at', '<=', $filters['to']);
        }

        if (! empty($filters['event'])) {
            $query->whereIn('event', (array) $filters['event']);
        }

        if ($query->count() > (int) config('core.export_max_rows')) {
            throw ApiException::validation([
                'filters' => [[
                    'code' => 'core.validation.export_range_too_large',
                    'message' => __('core.validation.export_range_too_large'),
                    'params' => ['max_rows' => (int) config('core.export_max_rows')],
                ]],
            ]);
        }
    }
}
