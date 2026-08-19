<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\User;
use App\Modules\Core\Domain\UserDirectory;
use App\Modules\Core\Domain\UserDirectoryEntry;

final class EloquentUserDirectory implements UserDirectory
{
    public function findByPublicId(string $publicId): ?UserDirectoryEntry
    {
        $user = User::query()->with('person')->where('public_id', $publicId)->first();

        if ($user === null) {
            return null;
        }

        $person = $user->person;
        $displayName = trim(($person?->given_name ?? '').' '.($person?->family_name_1 ?? ''));

        return new UserDirectoryEntry(
            publicId: $user->public_id,
            email: $user->email,
            displayName: $displayName !== '' ? $displayName : $user->email,
            preferredLocale: $person?->locale ?? 'es-ES',
        );
    }
}
