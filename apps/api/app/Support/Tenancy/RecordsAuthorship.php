<?php

namespace App\Support\Tenancy;

use App\Support\Audit\AuditActor;

/**
 * ADR-034 §6: created_by/updated_by (INV-005), rellenos desde la sesión
 * autenticada cuando la hay. Sin REQ-AUTH todavía (1.2), no hay usuario
 * autenticado y las dos columnas se quedan en null — mismo comportamiento
 * para consola, jobs del sistema y seeders.
 *
 * Pasa por `AuditActor::resolveUserId()`, no por `Auth::id()` directamente
 * (bug real de `1.6b`, issue #196): un actor con `override('console')`
 * activo (`ProvisionTenantDefaults`, `GrantRoleAdministrationCommand`)
 * puede tener de todos modos un `Auth::id()` no nulo — el de un
 * administrador de plataforma autenticado por el *guard* `platform` — que
 * no es una fila de `users` del tenant y viola la FK compuesta. Las
 * importaciones (`ExecuteUserImport`) siguen resolviendo su actor real:
 * fijan `Auth::setUser($actor)` antes de `actingAs('import', ...)`, y
 * `resolveUserId()` solo fuerza `null` para `'console'`.
 */
trait RecordsAuthorship
{
    public static function bootRecordsAuthorship(): void
    {
        static::creating(function ($model): void {
            if (! isset($model->created_by)) {
                $model->created_by = AuditActor::resolveUserId();
            }

            if (! isset($model->updated_by)) {
                $model->updated_by = AuditActor::resolveUserId();
            }
        });

        static::updating(function ($model): void {
            $model->updated_by = AuditActor::resolveUserId();
        });
    }
}
