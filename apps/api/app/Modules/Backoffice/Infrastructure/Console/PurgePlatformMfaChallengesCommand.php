<?php

namespace App\Modules\Backoffice\Infrastructure\Console;

use App\Modules\Backoffice\Infrastructure\Jobs\PurgePlatformMfaChallenges;
use Illuminate\Console\Command;

/** operacion.md §6.2. Cada hora, igual que su homóloga de 1.3. */
class PurgePlatformMfaChallengesCommand extends Command
{
    protected $signature = 'bo:purge-mfa-challenges';

    protected $description = 'Purga los desafíos de MFA de plataforma caducados (operacion.md §6.2)';

    public function handle(): int
    {
        PurgePlatformMfaChallenges::dispatch();

        return self::SUCCESS;
    }
}
