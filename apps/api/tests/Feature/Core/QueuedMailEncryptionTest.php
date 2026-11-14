<?php

use App\Modules\Core\Infrastructure\Jobs\SendInvitationEmail;
use Illuminate\Support\Facades\DB;

// Issue #75, mismo mecanismo que tests/Feature/Auth/QueuedMailEncryptionTest.php
// (issue #73): si el correo agota sus 5 reintentos, Laravel escribe el job
// completo en failed_jobs. Sin ShouldBeEncrypted, el token de activación
// quedaría ahí en texto plano indefinidamente.

test('SendInvitationEmail cifra el payload — el token en claro no aparece en la tabla jobs', function (): void {
    $rawToken = 'token-invitacion-'.str_repeat('z', 40);

    SendInvitationEmail::dispatch(
        rawToken: $rawToken,
        recipientEmail: 'admin@example.com',
        recipientGivenName: 'Ana',
        recipientLocale: 'es',
        tenantName: 'Centro de prueba',
        tenantSlug: 'demo-jobs',
        expiresInDays: 7,
    )->onConnection('database');

    $row = DB::table('jobs')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->payload)->not->toContain($rawToken);
});
