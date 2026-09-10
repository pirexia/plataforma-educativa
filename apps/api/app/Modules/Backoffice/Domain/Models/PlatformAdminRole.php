<?php

namespace App\Modules\Backoffice\Domain\Models;

use App\Modules\Backoffice\Domain\PlatformRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * datos.md §2.2. Pivote entre administrador y rol interno.
 *
 * @mixin IdeHelperPlatformAdminRole
 */
class PlatformAdminRole extends Model
{
    use SoftDeletes;

    protected $connection = 'pgsql_platform';

    protected $fillable = [
        'platform_admin_id',
        'role',
        'granted_by',
    ];

    protected function casts(): array
    {
        return [
            'role' => PlatformRole::class,
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
