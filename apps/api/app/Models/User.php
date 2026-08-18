<?php

namespace App\Models;

use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

/**
 * ADR-034 §1: la credencial, no la identidad — esa es Person. Extiende
 * TenantModel, no Authenticatable: implementar el contrato
 * Illuminate\Contracts\Auth\Authenticatable sobre esta base (guards,
 * remember-token, proveedor de config/auth.php) es tarea de REQ-AUTH
 * (1.2), no de 0.8. Hasta entonces no hay ningún flujo de login real que
 * lo necesite.
 */
#[Fillable(['person_id', 'email', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends TenantModel
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasPublicId;
    use Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
