<?php

namespace App\Modules\Backoffice\Application;

use App\Support\Tenancy\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `REQ-BO-004`, sub-paso `1.6d`. `api.md §2.10.1`, `funcional.md §5.9.2`.
 * El bloque «trabajos del centro»: recuento de `jobs` pendientes y de
 * `failed_jobs`, filtrados por `payload.tenant_id` (`ADR-033 §8`,
 * `RN-BO-90` — **no** alcanza a los cuatro trabajos que el backoffice
 * despacha sin tenant activo). Reutilizado por la ficha de salud
 * (`TenantHealthService`) y por la respuesta del reintento
 * (`FailedJobRetryService`, `api.md §2.10.3`: «200 con el bloque jobs
 * actualizado») — una sola implementación para las dos.
 *
 * Consultas directas por `pgsql_platform`, sin pasar por
 * `runAsPlatform()`: ni `jobs` ni `failed_jobs` son `TenantModel`, así
 * que no hay `TenantScope` del que escapar (mismo criterio que
 * `CloseOrphanedPlatformSessions`, `operacion.md §6.3`).
 */
final class TenantJobsSummary
{
    /**
     * `RN-BO-83`: `last_failed_at` se omite (no `null`) cuando el centro
     * no ha tenido nunca un trabajo fallido — no hay «medido y es
     * ninguno» para una marca de tiempo, a diferencia de un recuento.
     * `queued`/`failed`/`failed_recent` sí se devuelven aunque valgan 0
     * (es un recuento medido).
     *
     * @return array{queued: int, failed: int, failed_recent: int, failed_recent_hours: int, last_failed_at?: string}
     */
    public function forTenant(Tenant $tenant, int $windowHours): array
    {
        $tenantId = (string) $tenant->id;

        $queued = DB::connection('pgsql_platform')->table('jobs')
            ->whereRaw("payload::jsonb ->> 'tenant_id' = ?", [$tenantId])
            ->count();

        $failedQuery = fn () => DB::connection('pgsql_platform')->table('failed_jobs')
            ->whereRaw("payload::jsonb ->> 'tenant_id' = ?", [$tenantId]);

        $failed = $failedQuery()->count();
        $failedRecent = $failedQuery()->where('failed_at', '>=', now()->subHours($windowHours))->count();
        $lastFailedAt = $failedQuery()->max('failed_at');

        return array_filter([
            'queued' => $queued,
            'failed' => $failed,
            'failed_recent' => $failedRecent,
            'failed_recent_hours' => $windowHours,
            'last_failed_at' => $lastFailedAt !== null ? Carbon::parse($lastFailedAt)->toJSON() : null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
