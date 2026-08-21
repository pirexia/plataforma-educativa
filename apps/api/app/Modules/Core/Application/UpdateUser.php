<?php

namespace App\Modules\Core\Application;

use App\Models\Person;
use App\Models\User;
use App\Modules\Core\Domain\Events\UserEmailChanged;
use App\Modules\Core\Domain\Models\UserInvitation;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Support\Api\ValidationErrorBag;
use Illuminate\Support\Facades\DB;

/**
 * api.md §3, `PATCH /users/{public_id}`. RN-CORE-11: cambiar `email`
 * revoca las invitaciones vivas. Mismas comprobaciones de negocio que
 * `CreateUser` para los campos que cambian, excluyendo al propio usuario
 * de la comprobación de unicidad.
 */
final class UpdateUser
{
    public function __construct(
        private readonly TenantSettingsReader $settings,
        private readonly DocumentNumberValidator $documentValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User
    {
        $errors = new ValidationErrorBag;
        $person = $data['person'] ?? [];

        if (array_key_exists('email', $data)) {
            $exists = User::query()->where('email', $data['email'])->whereKeyNot($user->getKey())->exists();

            if ($exists) {
                $errors->add('email', 'core.validation.email_duplicate', 'core.validation.email_duplicate');
            }
        }

        if (array_key_exists('locale', $person)) {
            if (! in_array($person['locale'], $this->settings->activeLocales(), true)) {
                $errors->add('person.locale', 'core.validation.locale_not_active', 'core.validation.locale_not_active', ['locale' => $person['locale']]);
            }
        }

        $documentType = $person['document_type'] ?? $user->person->document_type;
        $documentNumberProvided = array_key_exists('document_number', $person);
        $documentNumber = $documentNumberProvided ? $person['document_number'] : $user->person->document_number;

        if ($documentNumberProvided && $documentNumber !== null) {
            if (! $this->documentValidator->isValid((string) $documentType, (string) $documentNumber)) {
                $errors->add('person.document_number', 'core.validation.document_number_invalid', 'core.validation.document_number_invalid');
            } elseif (Person::query()->where('document_type', $documentType)->where('document_number', $documentNumber)->whereKeyNot($user->person_id)->exists()) {
                $errors->add('person.document_number', 'core.validation.document_duplicate', 'core.validation.document_duplicate');
            }
        }

        $errors->throwIfAny();

        return DB::transaction(function () use ($user, $data, $person): User {
            $emailChanged = array_key_exists('email', $data) && $data['email'] !== $user->email;

            if ($emailChanged) {
                $user->email = $data['email'];
            }

            $user->save();

            if ($person !== []) {
                $personUpdates = [];

                foreach (['given_name', 'family_name_1', 'family_name_2', 'birth_date', 'document_type', 'document_number', 'contact_email', 'contact_phone', 'locale'] as $field) {
                    if (array_key_exists($field, $person)) {
                        $personUpdates[$field] = $person[$field];
                    }
                }

                $user->person->fill($personUpdates)->save();
            }

            if ($emailChanged) {
                UserInvitation::query()
                    ->where('user_id', $user->id)
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at')
                    ->get()
                    ->each(fn (UserInvitation $invitation) => $invitation->update(['revoked_at' => now()]));

                event(new UserEmailChanged($user->tenant_id, $user->public_id));
            }

            return $user->fresh(['person', 'roles']);
        });
    }
}
