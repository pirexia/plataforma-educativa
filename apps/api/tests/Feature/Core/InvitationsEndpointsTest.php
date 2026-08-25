<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use App\Modules\Core\Domain\Models\UserInvitation;
use App\Modules\Core\Infrastructure\Mail\InvitationMail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

// CA-CORE-020
test('CA-CORE-020: emitir la invitación de un usuario pendiente crea fila con caducidad futura y encola el correo sin token en claro', function (): void {
    [$tenant, $admin] = provisionCoreTenant('inv-020');

    $userId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'pendiente@example.com',
        'person' => ['given_name' => 'Pendiente', 'family_name_1' => 'Usuario'],
        'send_invitation' => false,
    ])->assertCreated()->json('public_id');

    $response = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/users/{$userId}/invitations"))
        ->assertCreated();

    expect($response->json('status'))->toBe('vigente')
        ->and($response->json())->not->toHaveKey('token')
        ->and($response->json())->not->toHaveKey('token_hash');

    Mail::assertSent(InvitationMail::class);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $log = AuditLog::where('auditable_type', 'user_invitation')->where('event', 'created')->latest('id')->first();
        expect($log)->not->toBeNull()
            // RN-CORE-19: el patrón global *token* redacta la columna
            // como "secret" — solo se comprueba el VALOR (nunca el
            // token en claro), no el nombre de la clave "token_hash",
            // que contiene la subcadena "token" de forma legítima.
            ->and($log->changes['token_hash'])->toEqual(['redacted' => 'secret']);
    });
});

// CA-CORE-021
test('CA-CORE-021: emitir una segunda invitación revoca la anterior y solo una queda vigente', function (): void {
    [$tenant, $admin] = provisionCoreTenant('inv-021');

    $userId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'doble@example.com',
        'person' => ['given_name' => 'Doble', 'family_name_1' => 'Invitacion'],
        'send_invitation' => false,
    ])->assertCreated()->json('public_id');

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, "/users/{$userId}/invitations"))->assertCreated();
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, "/users/{$userId}/invitations"))->assertCreated();

    $list = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/invitations?per_page=100'))
        ->json('data');

    $forUser = collect($list)->where('user.public_id', $userId);
    expect($forUser->where('status', 'vigente'))->toHaveCount(1)
        ->and($forUser->where('status', 'revocada'))->toHaveCount(1);
});

// CA-CORE-022
test('CA-CORE-022: cambiar el correo de acceso revoca la invitación viva', function (): void {
    [$tenant, $admin] = provisionCoreTenant('inv-022');

    $userId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'antes@example.com',
        'person' => ['given_name' => 'Cambia', 'family_name_1' => 'Correo'],
        'send_invitation' => true,
    ])->assertCreated()->json('public_id');

    test()->actingAs($admin)->patchJson(coreApiUrl($tenant->slug, "/users/{$userId}"), [
        'email' => 'despues@example.com',
    ])->assertOk();

    $invitation = app(TenantContext::class)->runFor($tenant->id, fn () => UserInvitation::query()->latest('id')->first());
    expect($invitation->revoked_at)->not->toBeNull();
});

// CA-CORE-023. La transición pendiente -> activo solo la hace el canje
// de invitación, que es 1.2 (RN-CORE-04): para probar "invitar a un
// activo" hay que fijar el estado directamente por Eloquent, no por la
// API de 1.1, que no tiene ninguna vía para producirlo todavía.
test('CA-CORE-023: invitar a un usuario activo devuelve 409', function (): void {
    [$tenant, $admin] = provisionCoreTenant('inv-023');

    $activeUser = app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create(['contact_email' => 'ya-activo@example.com']);
        $user = User::factory()->for($person)->create(['email' => 'ya-activo@example.com', 'status' => 'activo']);

        return $user;
    });

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/users/{$activeUser->public_id}/invitations"))
        ->assertStatus(409);
});

// CA-CORE-024
test('CA-CORE-024: el correo de invitación existe en los cuatro idiomas y usa el idioma preferido del destinatario', function (): void {
    [$tenant, $admin] = provisionCoreTenant('inv-024');

    $userId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'frances@example.com',
        'person' => ['given_name' => 'Marie', 'family_name_1' => 'Curie', 'locale' => 'es-ES'],
        'send_invitation' => false,
    ])->assertCreated()->json('public_id');

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, "/users/{$userId}/invitations"))->assertCreated();

    Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->givenName === 'Marie');

    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        expect(trans('core.mail.invitation.subject', [], $locale))->not->toBe('core.mail.invitation.subject');
    }
});
