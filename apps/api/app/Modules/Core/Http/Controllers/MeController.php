<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * api.md §3, funcional.md §4.9. Autoservicio del perfil propio: se
 * autoriza por identidad del sujeto (el propio usuario autenticado), no
 * por permiso (permisos.md §5 — con el resolutor provisional, un ámbito
 * `propios` se comportaría como `todos`).
 */
class MeController extends Controller
{
    public function __construct(
        private readonly TenantSettingsReader $settings,
        private readonly PermissionResolver $permissions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(Request $request): array
    {
        $user = $this->currentUser($request);
        $user->loadMissing(['person', 'roles']);

        return [
            'public_id' => $user->public_id,
            'email' => $user->email,
            'status' => $user->status->value,
            'person' => [
                'public_id' => $user->person->public_id,
                'given_name' => $user->person->given_name,
                'family_name_1' => $user->person->family_name_1,
                'family_name_2' => $user->person->family_name_2,
                'contact_email' => $user->person->contact_email,
                'contact_phone' => $user->person->contact_phone,
                'locale' => $user->person->locale,
            ],
            'roles' => $user->roles->map(fn ($role) => [
                'public_id' => $role->public_id,
                'code' => $role->code,
                'name' => $role->name ?? __($role->name_key),
            ])->all(),
            'permissions' => $this->permissions->effectivePermissionCodes($user),
        ];
    }

    /**
     * §4.9: solo `person.locale`, `person.contact_email`,
     * `person.contact_phone`. Cualquier otro campo se ignora en
     * silencio (CA-CORE-018) — por eso no hay `email`/`status`/`roles`
     * en `rules()`: no están, así que `$request->validated()` nunca los
     * contiene, aunque el cliente los envíe.
     *
     * @return array<string, mixed>
     */
    public function update(Request $request): array
    {
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'person' => ['sometimes', 'array'],
            'person.locale' => ['sometimes', 'string', 'in:es-ES,en,de,fr'],
            'person.contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'person.contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $person = $validated['person'] ?? [];

        if (array_key_exists('locale', $person) && ! in_array($person['locale'], $this->settings->activeLocales(), true)) {
            (new ValidationErrorBag)
                ->add('person.locale', 'core.validation.locale_not_active', 'core.validation.locale_not_active', ['locale' => $person['locale']])
                ->throwIfAny();
        }

        $updates = array_intersect_key($person, array_flip(['locale', 'contact_email', 'contact_phone']));

        if ($updates !== []) {
            $user->person->fill($updates)->save();
        }

        return $this->show($request);
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ApiException::unauthenticated();
        }

        return $user;
    }
}
