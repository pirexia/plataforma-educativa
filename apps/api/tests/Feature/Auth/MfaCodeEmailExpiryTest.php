<?php

use App\Modules\Auth\Infrastructure\Jobs\SendMfaChallengeCodeEmail;
use App\Modules\Auth\Infrastructure\Jobs\SendMfaEnrollmentCodeEmail;
use App\Modules\Auth\Infrastructure\Mail\MfaChallengeCodeMail;
use App\Modules\Auth\Infrastructure\Mail\MfaEnrollmentCodeMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * REQ-AUTH-003 / issue #128 (revisión B-1). Con worker real, un código MFA
 * que llega tarde (cola atrasada o reintento) no se entrega: el desafío ya
 * murió y el código en claro viajaría a un buzón sin utilidad.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

dataset('mfa_code_jobs', [
    'desafío de login' => [SendMfaChallengeCodeEmail::class, MfaChallengeCodeMail::class],
    'alta de factor' => [SendMfaEnrollmentCodeEmail::class, MfaEnrollmentCodeMail::class],
]);

function makeMfaCodeJob(string $class, int $ttl = 10): object
{
    return new $class('persona@example.com', 'Ana', 'es', 'Centro Ficticio', '123456', $ttl);
}

test('#128 el correo de código MFA se envía dentro de su vigencia', function (string $job, string $mail): void {
    Mail::fake();

    makeMfaCodeJob($job)->handle();

    Mail::assertSent($mail);
})->with('mfa_code_jobs');

test('#128 el correo de código MFA que llega con el código caducado se descarta sin enviar', function (string $job, string $mail): void {
    Mail::fake();
    $instance = makeMfaCodeJob($job, 10);

    Carbon::setTestNow(now()->addMinutes(11));
    $instance->handle();

    Mail::assertNothingSent();
})->with('mfa_code_jobs');

test('#128 retryUntil corta los reintentos al caducar el código', function (string $job): void {
    $instance = makeMfaCodeJob($job, 10);

    expect($instance->retryUntil()->getTimestamp())->toBe(now()->addMinutes(10)->getTimestamp());
})->with(['SendMfaChallengeCodeEmail' => [SendMfaChallengeCodeEmail::class], 'SendMfaEnrollmentCodeEmail' => [SendMfaEnrollmentCodeEmail::class]]);

test('#128 un trabajo encolado por la versión anterior (sin expiresAt) se sigue enviando', function (string $job, string $mail): void {
    Mail::fake();
    $instance = makeMfaCodeJob($job);
    $instance->expiresAt = null;

    expect($instance->retryUntil())->toBeNull();
    $instance->handle();

    Mail::assertSent($mail);
})->with('mfa_code_jobs');
