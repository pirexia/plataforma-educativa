<?php

use App\Modules\Auth\Infrastructure\Jobs\SendAccountLockedEmail;
use App\Modules\Auth\Infrastructure\Jobs\SendPasswordResetEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Issue #73: si el correo agota sus 5 reintentos, Laravel escribe el job
// completo en failed_jobs. Sin ShouldBeEncrypted, el token de un solo uso
// (rawToken/rawUnlockToken) quedaría ahí en texto plano indefinidamente —
// equivalente a filtrar una credencial. No hace falta forzar el fallo
// para probarlo: ShouldBeEncrypted cifra el payload al *encolar*, antes de
// cualquier intento de ejecución, así que basta con inspeccionar la fila
// de `jobs` (DatabaseTransactions revierte la inserción al terminar el
// test, mismo mecanismo que tests/Feature/Tenancy/QueueTenancyTest.php).

test('SendPasswordResetEmail cifra el payload — el token en claro no aparece en la tabla jobs', function (): void {
    $rawToken = 'token-de-prueba-'.str_repeat('x', 40);

    SendPasswordResetEmail::dispatch(
        rawToken: $rawToken,
        recipientEmail: 'admin@example.com',
        recipientGivenName: 'Ana',
        recipientLocale: 'es',
        tenantName: 'Centro de prueba',
        tenantSlug: 'demo-jobs',
        expiresInMinutes: 60,
    )->onConnection('database');

    $row = DB::table('jobs')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->payload)->not->toContain($rawToken);
});

test('SendAccountLockedEmail cifra el payload — el token en claro no aparece en la tabla jobs', function (): void {
    $rawUnlockToken = 'token-desbloqueo-'.str_repeat('y', 40);

    SendAccountLockedEmail::dispatch(
        rawUnlockToken: $rawUnlockToken,
        recipientEmail: 'admin@example.com',
        recipientGivenName: 'Ana',
        recipientLocale: 'es',
        tenantName: 'Centro de prueba',
        tenantSlug: 'demo-jobs',
        failedCount: 5,
        lockedAt: Carbon::now(),
        autoUnlocksAt: Carbon::now()->addMinutes(15),
    )->onConnection('database');

    $row = DB::table('jobs')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->payload)->not->toContain($rawUnlockToken);
});
