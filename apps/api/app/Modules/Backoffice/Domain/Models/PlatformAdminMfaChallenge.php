<?php

namespace App\Modules\Backoffice\Domain\Models;

use App\Support\Database\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §2.3. Segundo paso pendiente de un login de plataforma,
 * artefacto transitorio purgado por `bo:purge-mfa-challenges`.
 *
 * @mixin IdeHelperPlatformAdminMfaChallenge
 */
class PlatformAdminMfaChallenge extends Model
{
    use HasPublicId;

    protected $connection = 'pgsql_platform';

    protected $fillable = [
        'platform_admin_id',
        'challenge_hash',
        'expires_at',
        'consumed_at',
        'attempts',
    ];

    protected $hidden = [
        'challenge_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'platform_admin_id');
    }
}
