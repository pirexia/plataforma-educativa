<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\LinkedIdentityDirectory;
use App\Modules\Auth\Domain\Models\UserIdentity;
use Illuminate\Support\Collection;

final class EloquentLinkedIdentityDirectory implements LinkedIdentityDirectory
{
    public function liveForUser(int $userId): Collection
    {
        return UserIdentity::query()->where('user_id', $userId)->get();
    }
}
