<?php

namespace App\Modules\Backoffice\Domain\Models;

use App\Support\Database\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * REQ-BO-007, datos.md §2.5. `cidr` nativo: el operador de contención lo
 * verifica el motor, no PHP analizando una máscara en cada petición.
 *
 * @mixin IdeHelperPlatformIpAllowlistEntry
 */
class PlatformIpAllowlistEntry extends Model
{
    use HasPublicId;
    use SoftDeletes;

    protected $table = 'platform_ip_allowlist';

    protected $connection = 'pgsql_platform';

    protected $fillable = [
        'cidr',
        'description',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
