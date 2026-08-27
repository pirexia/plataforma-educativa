<?php

namespace App\Modules\Auth\Application;

/**
 * Resultado de `AuthenticatedSessionEstablisher::establish()`.
 */
final class AuthenticatedSessionResult
{
    /**
     * @param  array<string, mixed>  $profile
     */
    public function __construct(
        public readonly array $profile,
        public readonly ?string $newDeviceCookieValue,
    ) {}
}
