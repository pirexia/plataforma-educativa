<?php

use App\Support\Mail\MailEnvironmentGuard;

/**
 * Issue #128, revisión de seguridad A-1: con un worker consumiendo
 * `auth-mail`, el mailer `log`/`array` fuera de local/testing volcaría en
 * claro (o perdería) los correos con códigos MFA y enlaces.
 */
test('#128 MailEnvironmentGuard aborta con mailer log o array fuera de local/testing', function (string $mailer, string $env): void {
    config(['mail.default' => $mailer]);
    $this->app['env'] = $env;

    expect(fn () => app(MailEnvironmentGuard::class)->verify())
        ->toThrow(RuntimeException::class, "MAIL_MAILER={$mailer}");
})->with([
    ['log', 'production'],
    ['array', 'production'],
    ['log', 'staging'],
]);

test('#128 MailEnvironmentGuard permite log en local/testing y un transporte real en producción', function (string $mailer, string $env): void {
    config(['mail.default' => $mailer]);
    $this->app['env'] = $env;

    app(MailEnvironmentGuard::class)->verify();

    expect(true)->toBeTrue();
})->with([
    ['log', 'local'],
    ['array', 'testing'],
    ['smtp', 'production'],
]);
