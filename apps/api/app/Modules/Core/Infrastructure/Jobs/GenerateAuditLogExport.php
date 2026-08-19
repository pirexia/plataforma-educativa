<?php

namespace App\Modules\Core\Infrastructure\Jobs;

use App\Models\AuditLog;
use App\Modules\Core\Domain\Models\DataExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * operacion.md §4: cola `core-exports`, 3 reintentos. INV-012: la
 * generación nunca ocurre en la petición HTTP. El contexto de tenant lo
 * gestiona TenancyServiceProvider a partir del `tenant_id` estampado al
 * despachar (issue #49).
 */
class GenerateAuditLogExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $dataExportId,
        public readonly string $tenantPublicId,
    ) {
        $this->onQueue('core-exports');
    }

    public function handle(): void
    {
        $export = DataExport::query()->find($this->dataExportId);

        if ($export === null) {
            return;
        }

        $export->update(['status' => 'generando']);

        $filters = $export->filters ?? [];
        $query = AuditLog::query()->with('actor.person');

        if (isset($filters['from'])) {
            $query->where('occurred_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('occurred_at', '<=', $filters['to']);
        }

        if (! empty($filters['event'])) {
            $query->whereIn('event', (array) $filters['event']);
        }

        if (! empty($filters['auditable_type'])) {
            $query->whereIn('auditable_type', (array) $filters['auditable_type']);
        }

        $objectKey = "tenants/{$this->tenantPublicId}/exports/{$export->public_id}.csv";
        $disk = Storage::disk(config('filesystems.default'));

        $rowCount = 0;
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['occurred_at', 'actor', 'actor_type', 'auditable_type', 'auditable_public_id', 'event', 'request_id']);

        $query->orderBy('occurred_at')->orderBy('id')->chunk(1000, function ($chunk) use ($handle, &$rowCount): void {
            foreach ($chunk as $log) {
                $actorLabel = $log->actor?->person !== null
                    ? trim($log->actor->person->given_name.' '.$log->actor->person->family_name_1)
                    : '';

                fputcsv($handle, [
                    $log->occurred_at->toJSON(), $actorLabel, $log->actor_type,
                    $log->auditable_type, $log->auditable_public_id, $log->event, $log->request_id,
                ]);
                $rowCount++;
            }
        });

        rewind($handle);
        $disk->put($objectKey, stream_get_contents($handle));
        fclose($handle);

        $export->update([
            'status' => 'completada',
            'object_key' => $objectKey,
            'row_count' => $rowCount,
            'completed_at' => now(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        DataExport::query()->find($this->dataExportId)?->update([
            'status' => 'fallida',
            'error_code' => 'core.export.generation_failed',
        ]);
    }
}
