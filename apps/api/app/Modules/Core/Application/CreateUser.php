<?php

namespace App\Modules\Core\Application;

use App\Models\Person;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Domain\Events\UserCreated;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use App\Support\Authorization\PermissionResolver;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * funcional.md §4.3, api.md §3 `POST /users`. Validación de negocio
 * (INV-010) que no expresa una regla de Laravel sobre un campo aislado:
 * unicidad entre vivos (RN-CORE-02/03), formato/dígito de documento,
 * pertenencia del idioma a los activos (RN-CORE-13), y RPERM-013
 * (RN-CORE-08) sobre los roles solicitados.
 */
final class CreateUser
{
    public function __construct(
        private readonly TenantSettingsReader $settings,
        private readonly DocumentNumberValidator $documentValidator,
        private readonly PermissionResolver $permissions,
    ) {}

    /**
     * @param  array<string, mixed>  $data  ya validado en forma por StoreUserRequest
     * @return array{user: User, invitation: ?\App\Modules\Core\Domain\Models\UserInvitation}
     */
    public function create(array $data, User $actor): array
    {
        $errors = new ValidationErrorBag;

        $person = $data['person'] ?? [];
        $locale = $person['locale'] ?? $this->settings->defaultLocale();

        if (! in_array($locale, $this->settings->activeLocales(), true)) {
            $errors->add('person.locale', 'core.validation.locale_not_active', 'core.validation.locale_not_active', ['locale' => $locale]);
        }

        if (User::query()->where('email', $data['email'])->exists()) {
            $errors->add('email', 'core.validation.email_duplicate', 'core.validation.email_duplicate');
        }

        $documentType = $person['document_type'] ?? null;
        $documentNumber = $person['document_number'] ?? null;

        if ($documentNumber !== null) {
            if (! $this->documentValidator->isValid((string) $documentType, (string) $documentNumber)) {
                $errors->add('person.document_number', 'core.validation.document_number_invalid', 'core.validation.document_number_invalid');
            } elseif (Person::query()->where('document_type', $documentType)->where('document_number', $documentNumber)->exists()) {
                $errors->add('person.document_number', 'core.validation.document_duplicate', 'core.validation.document_duplicate');
            }
        }

        $roles = $this->resolveRoles($data['role_ids'] ?? [], $errors);

        $errors->throwIfAny();

        if ($roles->isNotEmpty()) {
            $this->assertActorCanGrant($actor, $roles);
        }

        return DB::transaction(function () use ($data, $person, $locale, $roles): array {
            $personModel = Person::create([
                'given_name' => $person['given_name'],
                'family_name_1' => $person['family_name_1'],
                'family_name_2' => $person['family_name_2'] ?? null,
                'birth_date' => $person['birth_date'] ?? null,
                'document_type' => $person['document_type'] ?? null,
                'document_number' => $person['document_number'] ?? null,
                'contact_email' => $person['contact_email'] ?? null,
                'contact_phone' => $person['contact_phone'] ?? null,
                'locale' => $locale,
            ]);

            $user = User::create([
                'person_id' => $personModel->id,
                'email' => $data['email'],
                'password' => Str::password(48),
                'status' => 'pendiente',
            ]);

            if ($roles->isNotEmpty()) {
                $user->roles()->attach($roles->pluck('id'));
            }

            event(new UserCreated($user->tenant_id, $user->public_id));

            $invitation = null;

            if ($data['send_invitation'] ?? true) {
                $tenant = Tenant::query()->find(app(TenantContext::class)->tenantId());

                $invitation = app(IssueUserInvitation::class)->issue(
                    $user->fresh(),
                    $tenant->slug ?? '',
                    $tenant->name ?? '',
                );
            }

            return ['user' => $user->fresh(['person', 'roles']), 'invitation' => $invitation];
        });
    }

    /**
     * @param  list<string>  $rolePublicIds
     * @return Collection<int, Role>
     */
    private function resolveRoles(array $rolePublicIds, ValidationErrorBag $errors): Collection
    {
        if ($rolePublicIds === []) {
            return collect();
        }

        $roles = Role::query()->whereIn('public_id', $rolePublicIds)->get();

        if ($roles->count() !== count(array_unique($rolePublicIds))) {
            $errors->add('role_ids', 'core.validation.role_not_found', 'core.validation.role_not_found');
        }

        return $roles;
    }

    /**
     * RN-CORE-08/RPERM-013: nadie concede un permiso que no posee.
     *
     * @param  Collection<int, Role>  $roles
     */
    private function assertActorCanGrant(User $actor, Collection $roles): void
    {
        $actorPermissions = $this->permissions->effectivePermissionCodes($actor);

        $grantedCodes = PermissionRole::query()
            ->whereIn('role_id', $roles->pluck('id'))
            ->where('effect', 'allow')
            ->pluck('permission_code')
            ->unique();

        if ($grantedCodes->diff($actorPermissions)->isNotEmpty()) {
            throw ApiException::forbidden();
        }
    }
}
