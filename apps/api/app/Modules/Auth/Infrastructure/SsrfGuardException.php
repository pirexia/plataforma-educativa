<?php

namespace App\Modules\Auth\Infrastructure;

use RuntimeException;

/**
 * Ver `SsrfSafeFetcher`.
 */
final class SsrfGuardException extends RuntimeException
{
    public function __construct(public readonly SsrfGuardFailureReason $reason)
    {
        parent::__construct($reason->name);
    }
}
