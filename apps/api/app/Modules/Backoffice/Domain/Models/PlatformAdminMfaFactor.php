<?php

namespace App\Modules\Backoffice\Domain\Models;

use App\Support\Database\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * datos.md §2.3. Solo TOTP (datos.md §2.4): el correo no es segundo
 * factor en el backoffice.
 *
 * @mixin IdeHelperPlatformAdminMfaFactor
 */
class PlatformAdminMfaFactor extends Model
{
    use HasPublicId;
    use SoftDeletes;

    protected $connection = 'pgsql_platform';

    protected $fillable = [
        'platform_admin_id',
        'type',
        'secret_encrypted',
        'last_used_step',
        'confirmed_at',
        'last_used_at',
    ];

    protected $hidden = [
        'secret_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'secret_encrypted' => 'encrypted',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
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
