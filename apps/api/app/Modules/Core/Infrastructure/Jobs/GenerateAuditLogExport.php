<?php

namespace App\Modules\Core\Infrastructure\Jobs;

use App\Models\AuditLog;
use App\Modules\Core\Domain\Models\DataExport;
use App\Modules\Core\Infrastructure\AuditLogFilter;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\ScopedQuery;
use App\Support\Csv\CsvColumnType;
use App\Support\Csv\CsvWriter;
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
 *
 * REQ-PERM/funcional.md §6.3, `RN-PERM-15`, operacion.md §5.1 (1.5): el
 * artefacto se acota con la misma restricción que el listado, dentro del
 * propio trabajo — un trabajo en cola no tiene sesión, así que si la
 * acotación viviera solo en el controlador, el fichero saldría completo.
 * El sujeto viaja como `requested_by` (ya existía desde 1.1, columna de
 * `DataExport`) y el ámbito se **vuelve a resolver** aquí, con el contexto
 * de tenant propio del trabajo — nunca un objeto `PermissionDecision`
 * serializado en la cola.
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

    public function handle(PermissionResolver $permissions, ScopedQuery $scopedQuery): void
    {
        $export = DataExport::query()->with('requester')->find($this->dataExportId);

        if ($export === null) {
            return;
        }

        // INV-002, denegar por defecto (issue #280): sin solicitante (borrado entre la
        // solicitud y la ejecución) no hay ámbito que aplicar, así que no se exporta nada.
        if ($export->requester === null) {
            $export->update([
                'status' => 'fallida',
                'error_code' => 'core.export.generation_failed',
            ]);

            return;
        }

        $export->update(['status' => 'generando']);

        $filters = $export->filters ?? [];
        // Issue #267: los mismos filtros, con el mismo código, que el
        // listado (ADR-054 §8.2). El ámbito se aplica después, aparte.
        $query = AuditLogFilter::apply(AuditLog::query()->with('actor.person'), $filters);

        $decision = $permissions->decide($export->requester, 'auditoria.exportar');

        // INV-002 (issue #340): el permiso se puede revocar entre la solicitud y la
        // ejecución; sin él la exportación falla en vez de completarse con 0 filas.
        if (! $decision->permitted) {
            $export->update([
                'status' => 'fallida',
                'error_code' => 'core.export.generation_failed',
            ]);

            return;
        }

        $query = $scopedQuery->constrain($query, 'auditoria', $decision, $export->requester);

        $objectKey = "tenants/{$this->tenantPublicId}/exports/{$export->public_id}.csv";
        $disk = Storage::disk(config('filesystems.default'));

        $rowCount = 0;
        $csv = new CsvWriter([
            'occurred_at' => CsvColumnType::Instant,
            'actor' => CsvColumnType::Text,
            'actor_type' => CsvColumnType::Text,
            'auditable_type' => CsvColumnType::Text,
            'auditable_public_id' => CsvColumnType::Text,
            'event' => CsvColumnType::Text,
            'request_id' => CsvColumnType::Text,
        ]);

        $query->orderBy('occurred_at')->orderBy('id')->chunk(1000, function ($chunk) use ($csv, &$rowCount): void {
            foreach ($chunk as $log) {
                $actorLabel = $log->actor?->person !== null
                    ? trim($log->actor->person->given_name.' '.$log->actor->person->family_name_1)
                    : '';

                $csv->writeRow([
                    $log->occurred_at, $actorLabel, $log->actor_type,
                    $log->auditable_type, $log->auditable_public_id, $log->event, $log->request_id,
                ]);
                $rowCount++;
            }
        });

        $disk->put($objectKey, $csv->finish());

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
