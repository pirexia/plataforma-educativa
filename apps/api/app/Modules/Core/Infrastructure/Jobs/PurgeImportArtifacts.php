<?php

namespace App\Modules\Core\Infrastructure\Jobs;

use App\Modules\Core\Domain\Models\UserImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * operacion.md §4/§5, `RN-CORE-21`, `CA-CORE-035`: el CSV fuente y el
 * informe de errores contienen datos personales de todo el personal del
 * centro. Se purgan los **objetos**, no la fila: `user_imports` conserva
 * el registro de que hubo una importación (datos.md §A.9).
 */
class PurgeImportArtifacts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $tenantId,
    ) {
        $this->onQueue('core-maintenance');
    }

    public function handle(): void
    {
        $disk = Storage::disk(config('filesystems.default'));
        $cutoff = now()->subDays((int) config('core.import_retention_days'));

        UserImport::query()
            ->where('created_at', '<', $cutoff)
            ->where(function ($query): void {
                $query->whereNotNull('source_object_key')->orWhereNotNull('report_object_key');
            })
            ->get()
            ->each(function (UserImport $import) use ($disk): void {
                foreach ([$import->source_object_key, $import->report_object_key] as $objectKey) {
                    if ($objectKey !== null) {
                        $disk->delete($objectKey);
                    }
                }

                $import->update(['source_object_key' => null, 'report_object_key' => null]);
            });
    }
}
