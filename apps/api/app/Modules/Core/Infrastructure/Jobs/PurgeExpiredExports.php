<?php

namespace App\Modules\Core\Infrastructure\Jobs;

use App\Modules\Core\Domain\Models\DataExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * operacion.md §4/datos.md §A.9: el artefacto es regenerable. Se borra el
 * objeto **y** la fila (borrado físico: una exportación vencida no es un
 * registro de negocio que conservar, a diferencia de `user_imports`).
 */
class PurgeExpiredExports implements ShouldQueue
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

        DataExport::query()
            ->where('expires_at', '<', now())
            ->get()
            ->each(function (DataExport $export) use ($disk): void {
                if ($export->object_key !== null) {
                    $disk->delete($export->object_key);
                }

                $export->forceDelete();
            });
    }
}
