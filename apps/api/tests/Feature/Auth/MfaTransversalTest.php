<?php

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// funcional.md §C.10, §C.4.13. Auditoría e i18n transversales
// (REQ-AUTH-003, 1.3).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

// CA-AUTH-142, ADR-035, RN-AUTH-74
test('CA-AUTH-142: el ciclo de vida de MFA solo audita eventos de ADR-039, sin secretos ni hashes en claro', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-142');

    // Alta + confirmación (created, updated sobre MfaFactor).
    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    $enroll = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'totp'])
        ->assertStatus(201);

    $secret = $enroll->json('secret');

    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-factors'), [
            'enrollment' => $enroll->json('public_id'),
            'code' => currentTotpCode($secret),
        ])
        ->assertStatus(201);

    // Regenerar códigos (created/deleted sobre MfaRecoveryCode).
    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-recovery-codes'), ['current_password' => $password])
        ->assertStatus(201);

    $allowedEvents = ['created', 'updated', 'deleted', 'restored', 'read', 'exported', 'login', 'logout', 'password_reset_requested'];

    app(TenantContext::class)->runFor($tenant->id, function () use ($allowedEvents, $secret): void {
        $rows = AuditLog::query()->get();

        expect($rows)->not->toBeEmpty();

        foreach ($rows as $row) {
            expect(in_array($row->event, $allowedEvents, true))
                ->toBeTrue("evento fuera del vocabulario de ADR-039: {$row->event}");

            $raw = json_encode($row->changes);
            expect($raw)->not->toContain($secret);
        }

        // Al menos una fila trae el secreto redactado explícitamente
        // (auditSecretAttributes de MfaFactor, ADR-035).
        $factorRows = AuditLog::query()->where('auditable_type', 'mfa_factor')->get();
        expect($factorRows)->not->toBeEmpty();

        foreach ($factorRows as $row) {
            if ($row->changes !== null && array_key_exists('secret_encrypted', $row->changes)) {
                // AuditChangeBuilder::buildEntry(), paso 1: un atributo
                // secreto nunca lleva from/to, solo la marca de redacción.
                expect($row->changes['secret_encrypted'])->toBe(['redacted' => 'secret']);
            }
        }
    });
});

// CA-AUTH-144, INV-009. Los tres avisos nuevos de §C.4.13 (activación,
// baja/restablecimiento, uso de código de respaldo) en los 4 idiomas,
// sin el código en el asunto (comprobado por construcción: el asunto no
// interpola ningún parámetro 'code').
test('CA-AUTH-144: los tres avisos de MFA existen traducidos en los cuatro idiomas', function (): void {
    $expectations = [
        'auth.mail.mfa_factor_activated.subject' => [
            'es' => 'verificación en dos pasos', 'en' => 'Two-step verification',
            'de' => 'zweistufige Verifizierung', 'fr' => 'vérification en deux étapes',
        ],
        'auth.mail.mfa_factor_removed.subject' => [
            'es' => 'verificación en dos pasos', 'en' => 'Two-step verification',
            'de' => 'zweistufige Verifizierung', 'fr' => 'vérification en deux étapes',
        ],
        'auth.mail.recovery_code_used.subject' => [
            'es' => 'código de respaldo', 'en' => 'backup code',
            'de' => 'Wiederherstellungscode', 'fr' => 'code de secours',
        ],
    ];

    foreach ($expectations as $key => $perLocale) {
        foreach ($perLocale as $locale => $expectedFragment) {
            app()->setLocale($locale);
            $subject = __($key, ['tenant' => 'Centro ficticio']);
            expect($subject)->toContain($expectedFragment);
        }
    }

    app()->setLocale('es');
});
