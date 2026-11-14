<?php

namespace App\Modules\Core\Application;

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
 * de la comprobación de unicidad; el documento, por `PersonDocumentRules`
 * (RN-CORE-90 a 93).
 */
final class UpdateUser
{
    public function __construct(
        private readonly TenantSettingsReader $settings,
        private readonly PersonDocumentRules $documentRules,
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

        // RN-CORE-93: si llega el tipo, el número o ambos, se valida el par
        // **resultante** (lo no enviado se toma de lo guardado). Si no llega
        // ninguno, no se toca ni se revalida: un valor guardado anterior al
        // catálogo no bloquea un cambio ajeno al documento.
        $typeProvided = array_key_exists('document_type', $person);
        $numberProvided = array_key_exists('document_number', $person);
        $document = null;

        if ($typeProvided || $numberProvided) {
            $document = $this->documentRules->check(
                $typeProvided ? $person['document_type'] : $user->person->document_type,
                $numberProvided ? $person['document_number'] : $user->person->document_number,
                $errors,
                $user->person_id,
            );
        }

        $errors->throwIfAny();

        return DB::transaction(function () use ($user, $data, $person, $document): User {
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

                if ($document !== null) {
                    // Valor canónico (RN-CORE-92) para los campos enviados.
                    if (array_key_exists('document_type', $personUpdates)) {
                        $personUpdates['document_type'] = $document['type'];
                    }

                    if (array_key_exists('document_number', $personUpdates)) {
                        $personUpdates['document_number'] = $document['number'];
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
