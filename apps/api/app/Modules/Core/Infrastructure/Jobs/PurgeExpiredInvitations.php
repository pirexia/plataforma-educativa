<?php

namespace App\Modules\Core\Infrastructure\Jobs;

use App\Modules\Core\Domain\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * operacion.md §4: cola `core-maintenance`, programado a diario, **por
 * tenant** (el contexto lo fija `TenancyServiceProvider` a partir del
 * `tenant_id` estampado al despachar). Borrado **lógico**: marca
 * `deleted_at` en invitaciones caducadas hace más de 30 días. No borra el
 * hash antes de caducar — la traza de que se invitó a alguien es
 * relevante (datos.md §A.9).
 */
class PurgeExpiredInvitations implements ShouldQueue
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
        UserInvitation::query()
            ->where('expires_at', '<', now()->subDays(30))
            ->get()
            ->each(fn (UserInvitation $invitation) => $invitation->delete());
    }
}
