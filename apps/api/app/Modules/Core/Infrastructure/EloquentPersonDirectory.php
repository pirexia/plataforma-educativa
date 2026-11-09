<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\Person;
use App\Models\User;
use App\Modules\Core\Domain\DocumentType;
use App\Modules\Core\Domain\PersonBirth;
use App\Modules\Core\Domain\PersonContact;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\Core\Domain\PersonRef;
use App\Modules\Core\Domain\UserPersonRef;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * `OPEN-FAM-UNIT-03` (1.14-a) y `ADR-068 §5.2` (1.14b-a: búsqueda por nombre y por documento y datos de contacto). Una consulta por llamada, sin memoización. `Person::query()` y `User::query()`
 * aplican `TenantScope` y excluyen los borrados (`SoftDeletes`); RLS lo refuerza en la base de datos (`INV-001`).
 */
final class EloquentPersonDirectory implements PersonDirectory
{
    public function findByPublicId(string $publicId): ?PersonRef
    {
        $person = Person::query()->where('public_id', $publicId)->first();

        return $person === null ? null : self::ref($person);
    }

    public function refsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $refs = [];

        foreach (Person::query()->withTrashed()->whereIn('id', array_values(array_unique($ids)))->get() as $person) {
            $refs[(int) $person->id] = self::ref($person);
        }

        return $refs;
    }

    public function personIdForUser(int $userId): ?int
    {
        $personId = User::query()->whereKey($userId)->value('person_id');

        return $personId === null ? null : (int) $personId;
    }

    public function findUserByPublicId(string $userPublicId): ?UserPersonRef
    {
        $user = User::query()->with('person')->where('public_id', $userPublicId)->first();

        return $user === null || $user->person === null ? null : self::userRef($user);
    }

    public function usersByPersonIds(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        $refs = [];

        foreach (User::query()->withTrashed()->with(['person' => fn ($q) => $q->withTrashed()])->whereIn('person_id', array_values(array_unique($personIds)))->get() as $user) {
            if ($user->person === null) {
                continue;
            }

            // Si hay un usuario vivo y otro borrado de la misma persona, gana el vivo.
            $personId = (int) $user->person_id;

            if (isset($refs[$personId]) && ! $refs[$personId]->removed) {
                continue;
            }

            $refs[$personId] = self::userRef($user);
        }

        return $refs;
    }

    public function searchByNameAmong(string $term, array $personIds, int $limit): array
    {
        $term = trim($term);

        if ($term === '' || $personIds === [] || $limit < 1) {
            return [];
        }

        // `ilike` con los comodines del texto escapados: «50%» busca un «%» literal (consulta parametrizada).
        $pattern = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $display = "(given_name || ' ' || family_name_1)";

        return Person::query()
            ->whereIn('id', array_values(array_unique($personIds)))
            ->where(function ($query) use ($pattern, $display): void {
                $query->whereRaw("given_name ILIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("family_name_1 ILIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("family_name_2 ILIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("{$display} ILIKE ? ESCAPE '\\'", [$pattern]);
            })
            ->orderByRaw('lower(given_name), lower(family_name_1), id')
            ->limit($limit)
            ->get()
            ->map(static fn (Person $person): PersonRef => self::ref($person))
            ->values()
            ->all();
    }

    public function findByDocument(string $documentType, string $documentNumber): ?PersonRef
    {
        $type = DocumentType::fromCode($documentType);

        if ($type === null || trim($documentNumber) === '') {
            return null;
        }

        $person = Person::query()
            ->where('document_type', $type->value)
            ->where('document_number', $type->normalizeNumber($documentNumber))
            ->first();

        return $person === null ? null : self::ref($person);
    }

    public function contactDetailsByIds(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        $contacts = [];

        foreach (Person::query()->whereIn('id', array_values(array_unique($personIds)))->get(['id', 'contact_phone', 'document_type', 'document_number', 'given_name', 'family_name_1', 'family_name_2', 'locale']) as $person) {
            $contacts[(int) $person->id] = new PersonContact(
                $person->contact_phone === null ? null : (string) $person->contact_phone,
                $person->document_type === null ? null : (string) $person->document_type,
                $person->document_number === null ? null : (string) $person->document_number,
                (string) $person->given_name,
                (string) $person->family_name_1,
                $person->family_name_2 === null ? null : (string) $person->family_name_2,
                $person->locale === null ? null : (string) $person->locale,
            );
        }

        return $contacts;
    }

    public function birthDatesByIds(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        $births = [];

        foreach (Person::query()->whereIn('id', array_values(array_unique($personIds)))->get(['id', 'birth_date']) as $person) {
            $births[(int) $person->id] = new PersonBirth($person->birth_date?->format('Y-m-d'));
        }

        return $births;
    }

    public function lockLiveByPublicIds(array $publicIds): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('lockLiveByPublicIds() exige una transacción abierta: un bloqueo sin transacción no protege nada.');
        }

        $publicIds = array_values(array_unique($publicIds));

        if ($publicIds === []) {
            return [];
        }

        // `FOR SHARE` sobre las vivas, en orden de id (orden fijo de bloqueo, `RN-PRL-12`). Si la fila la modifica otra
        // transacción, la consulta espera y, en `READ COMMITTED`, relee la fila confirmada: una borrada ya no aparece.
        $refs = [];

        foreach (Person::query()->whereIn('public_id', $publicIds)->orderBy('id')->sharedLock()->get() as $person) {
            $refs[(string) $person->public_id] = self::ref($person);
        }

        return $refs;
    }

    private static function ref(Person $person): PersonRef
    {
        $removed = $person->trashed();

        return new PersonRef((int) $person->id, (string) $person->public_id, $removed ? '' : self::name($person), $removed);
    }

    private static function userRef(User $user): UserPersonRef
    {
        /** @var Person $person */
        $person = $user->person;
        $removed = $user->trashed();

        return new UserPersonRef(
            (int) $user->id,
            (string) $user->public_id,
            (int) $person->id,
            (string) $person->public_id,
            $user->status->value,
            $removed ? '' : self::name($person),
            $removed,
        );
    }

    /** Nombre y primer apellido, o cadena vacía: nunca el correo (mismo criterio que `UserDirectory`). */
    private static function name(Person $person): string
    {
        return trim(($person->given_name ?? '').' '.($person->family_name_1 ?? ''));
    }
}
