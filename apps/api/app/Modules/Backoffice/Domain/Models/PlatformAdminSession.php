<?php

namespace App\Modules\Backoffice\Domain\Models;

use App\Modules\Backoffice\Domain\PlatformAdminSessionEndReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-047 §5.1, datos.md §2.7. Dato de negocio: qué sesiones de
 * plataforma hay abiertas, desde dónde y por qué terminó cada una.
 *
 * `session_id` sin FK a propósito (docblock de la migración): lo
 * sustituye el barrido de sesiones huérfanas (§2.7.2,
 * `CloseOrphanedPlatformSessions`).
 *
 * @mixin IdeHelperPlatformAdminSession
 */
class PlatformAdminSession extends Model
{
    protected $connection = 'pgsql_platform';

    protected $fillable = [
        'platform_admin_id',
        'session_id',
        'ip_address',
        'user_agent',
        'started_at',
        'last_seen_at',
        'reauthenticated_at',
        'ended_at',
        'end_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'reauthenticated_at' => 'datetime',
            'ended_at' => 'datetime',
            'end_reason' => PlatformAdminSessionEndReason::class,
        ];
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'platform_admin_id');
    }

    public function isLive(): bool
    {
        return $this->ended_at === null;
    }

    public function close(PlatformAdminSessionEndReason $reason): void
    {
        $this->forceFill([
            'session_id' => null,
            'ended_at' => now(),
            'end_reason' => $reason,
        ])->save();
    }
}
