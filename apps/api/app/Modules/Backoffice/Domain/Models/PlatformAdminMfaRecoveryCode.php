<?php

namespace App\Modules\Backoffice\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §2.3. Solo el hash, nunca el código.
 *
 * @mixin IdeHelperPlatformAdminMfaRecoveryCode
 */
class PlatformAdminMfaRecoveryCode extends Model
{
    protected $connection = 'pgsql_platform';

    protected $fillable = [
        'platform_admin_id',
        'code_hash',
        'used_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
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
