<?php

namespace App\Modules\FamUnit\Infrastructure\Jobs;

use App\Modules\FamUnit\Application\PickupListService;
use App\Modules\FamUnit\Application\PickupPhotoService;
use App\Support\Audit\AuditActor;
use App\Support\Files\PrivateObjectStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * REQ-FAM-UNIT/datos.md §8.8, operacion.md §12.3 (1.14b-a, `ADR-068 §5.3`, `OPEN-FAM-UNIT-37`): barrido de las fotografías de
 * recogida huérfanas de un centro, con el patrón de `PurgeOrphanBrandingAssets`. Dos pasos, con el plazo de gracia
 * `fam_unit.pickup.photo.orphan_grace_days` (30 por defecto):
 *
 * 1. **Terceros sin autorización vigente ni futura** pasado el plazo (y que no son tutores): se da de baja su fotografía viva
 *    (baja lógica, auditada). Una fotografía de un tutor no se purga por esto: se retira al darse de baja su faceta
 *    (`funcional.md §21.10`).
 * 2. **Objetos del *bucket* sin fila viva** (la fotografía sustituida o dada de baja, o una escritura cuya alta en base de
 *    datos falló) con más de `orphan_grace_days` días: se borran del almacenamiento. Un objeto referenciado por una
 *    fotografía viva no se toca nunca.
 *
 * Todo el acceso a las tablas va por `PickupListService` (`AR-10`). El tenant viaja en el payload y el listener de
 * `TenancyServiceProvider` lo fija antes de `handle()`. Hoy se lanza a mano con `php artisan fam_unit:purge-orphan-photos`
 * (el planificador aún no tiene servicio desplegado, issue #453; `RUNBOOK.md`).
 */
class PurgeOrphanPickupPhotos implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $tenantId,
    ) {
        $this->onQueue('default');
    }

    public function handle(PickupListService $list, PrivateObjectStore $objects): void
    {
        $grace = max(1, (int) config('fam_unit.pickup.photo.orphan_grace_days', 30));

        AuditActor::actingAs('system', function () use ($list, $objects, $grace): void {
            foreach ($list->personIdsWithExpiredPhoto($grace) as $personId) {
                $list->removePhoto($personId);
            }

            $live = array_flip($list->liveObjectKeys());
            $cutoff = now()->subDays($grace)->getTimestamp();

            foreach ($objects->keysIn(PickupPhotoService::AREA) as $key) {
                if (! isset($live[$key]) && $objects->lastModified($key) < $cutoff) {
                    $objects->delete($key);
                }
            }
        });
    }
}
