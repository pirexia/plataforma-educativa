<?php

namespace App\Modules\Backoffice\Infrastructure\Console;

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
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
 * hacer esa búsqueda posible sin reflexión sobre propiedades privadas —
 * y usa el mecanismo nativo `queue:retry` de Laravel para reencolar
 * exactamente el mismo trabajo, con el mismo `payload` original.
 */
class RetryProvisioningCommand extends Command
{
    protected $signature = 'bo:retry-provisioning {slug : Slug del tenant atascado en en_alta}';

    protected $description = 'Reencola el trabajo de aprovisionamiento fallido de un tenant en en_alta (funcional.md §5.3.5)';

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

        $failedJob = DB::table('failed_jobs')
            ->where('payload', 'like', '%"tenantPublicId";s:%:"'.$tenant->public_id.'"%')
            ->orWhere('payload', 'like', '%"targetTenantPublicId";s:%:"'.$tenant->public_id.'"%')
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

        Artisan::call('queue:retry', ['id' => [$failedJob->uuid]]);

        $this->info("Trabajo de aprovisionamiento de «{$slug}» reencolado (uuid {$failedJob->uuid}).");

        return self::SUCCESS;
    }
}
