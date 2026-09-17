<?php

namespace App\Modules\Backoffice\Infrastructure\Console;

use App\Modules\Backoffice\Application\FailedJobRetryService;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * REQ-BO-001, funcional.md §5.3.5, operacion.md §5.1. Reencola el mismo
 * trabajo de aprovisionamiento (`ProvisionTenant` o `CloneTenant`) que
 * agotó sus reintentos para un tenant atascado en `en_alta`. Es
 * idempotente porque el trabajo lo es (`TenantProvisioner`, `ADR-048
 * §4.3`/`§5.3`): reintentar un alta o una clonación a medias no duplica
 * roles, concesiones, personas ni invitaciones (`CA-BO-108`).
 *
 * **Nunca** se arregla escribiendo `status = 'activo'` a mano
 * (`RN-BO-52`): dejaría un centro marcado como listo sin roles, sin
 * configuración y sin administrador.
 *
 * No es un *endpoint*: no hace falta capacidad nueva ni pantalla, el
 * camino de recuperación de este módulo ya es la consola
 * (`operacion.md §5.1`). Localiza en `failed_jobs` el trabajo cuyo
 * `payload` serializado nombra el `public_id` de este tenant —
 * `ProvisionTenant`/`CloneTenant` declaran sus propiedades públicas para
 * hacer esa búsqueda posible sin reflexión sobre propiedades privadas.
 *
 * **`1.6d` (hallazgo 2 de `funcional.md §5.9.6`, severidad Media,
 * `CA-BO-164`): este comando estaba mal conectado.** Buscaba por la
 * conexión por defecto (`pgsql`, rol `plataforma_app`) y delegaba en
 * `Artisan::call('queue:retry', …)`, que usa `config('queue.failed.database')`
 * — la misma conexión —, y ese rol tiene `REVOKE SELECT, UPDATE, DELETE`
 * sobre `failed_jobs` desde `0.7` (`RN-BO-86`): el camino no podía
 * funcionar en ningún entorno con los tres roles de `ADR-033 §5`
 * aprovisionados. Pasa a buscar por `pgsql_platform` y a reencolar con
 * `FailedJobRetryService::retryByUuid()`, la misma implementación que usa
 * `POST /tenants/{public_id}/failed-jobs/{uuid}/retry` (funcional.md
 * §5.9.4 última línea: «dos caminos, una sola implementación»).
 *
 * **Segundo hallazgo, propio de esta sesión y distinto del anterior**:
 * el filtro `LIKE` original comparaba contra `payload` entero, esperando
 * encontrar literalmente `"tenantPublicId";s:` — pero `payload` es JSON
 * (`json_encode` del *array* del *job*) y el PHP serializado de
 * `data.command` viaja **como cadena JSON**, con sus comillas escapadas
 * (`\"tenantPublicId\";s:`, no `"tenantPublicId";s:`). El `LIKE` contra
 * el texto entero nunca podía casar con ninguna fila real — verificado
 * despachando un trabajo de verdad con una propiedad pública y
 * comprobando el `payload` almacenado. Se arregla extrayendo primero
 * `data.command` con el operador `->>` de `jsonb` (que sí devuelve el
 * texto ya desescapado) y aplicando el `LIKE` sobre ese valor, no sobre
 * la columna entera. Documentado como hallazgo aparte porque no es el
 * mismo defecto que el de privilegios de conexión: éste habría seguido
 * roto aunque `queue:retry` no tuviera ningún problema de `GRANT`.
 */
class RetryProvisioningCommand extends Command
{
    protected $signature = 'bo:retry-provisioning {slug : Slug del tenant atascado en en_alta}';

    protected $description = 'Reencola el trabajo de aprovisionamiento fallido de un tenant en en_alta (funcional.md §5.3.5)';

    public function __construct(private readonly FailedJobRetryService $retryService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            $this->error("No existe ningún tenant vivo con el slug «{$slug}».");

            return self::FAILURE;
        }

        if ($tenant->status !== TenantStatus::EnAlta) {
            $this->error("El tenant «{$slug}» no está en_alta (está «{$tenant->status->value}»): nada que reparar.");

            return self::FAILURE;
        }

        $failedJob = DB::connection('pgsql_platform')->table('failed_jobs')
            ->whereRaw(
                "payload::jsonb -> 'data' ->> 'command' LIKE ? OR payload::jsonb -> 'data' ->> 'command' LIKE ?",
                [
                    '%"tenantPublicId";s:%:"'.$tenant->public_id.'"%',
                    '%"targetTenantPublicId";s:%:"'.$tenant->public_id.'"%',
                ],
            )
            ->orderByDesc('id')
            ->first();

        if ($failedJob === null) {
            $this->error(
                "No hay ningún trabajo de aprovisionamiento fallido para «{$slug}» en failed_jobs. ".
                'Si el trabajo sigue en curso o en reintento automático, espera; si el problema '.
                'persiste, revisa los registros del worker.'
            );

            return self::FAILURE;
        }

        $this->retryService->retryByUuid($failedJob->uuid, $tenant->id);

        $this->info("Trabajo de aprovisionamiento de «{$slug}» reencolado (uuid {$failedJob->uuid}).");

        return self::SUCCESS;
    }
}
