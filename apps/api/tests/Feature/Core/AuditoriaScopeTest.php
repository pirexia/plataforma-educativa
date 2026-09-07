<?php

use App\Models\User;
use App\Modules\Core\Domain\Models\DataExport;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * REQ-PERM/funcional.md §6, ADR-044 §8: el resolutor real de 1.5 —
 * `propios` sobre `auditoria` — probado de punta a punta: listado,
 * detalle implícito y exportación. Sin este caso, el contrato
 * `ScopeResolver` no está verificado (INV-015).
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * @return array{0: Tenant, 1: User, 2: User}
 *                                            admin, restrictedUser (auditoria.leer propios)
 */
function provisionPropiosAuditTenant(string $slug): array
{
    [$tenant, $admin] = provisionCoreTenant($slug);

    $roleId = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'auditor_propio', 'name' => 'Auditor de lo propio',
            'permissions' => [
                ['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios'],
                ['code' => 'auditoria.exportar', 'effect' => 'allow', 'scope' => 'propios'],
            ],
        ])->assertCreated()->json('public_id');

    resetSessionState();

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'auditor@example.com',
        'person' => ['given_name' => 'Auditor', 'family_name_1' => 'Propio'],
        'send_invitation' => false,
        'role_ids' => [$roleId],
    ])->assertCreated();

    $restrictedUser = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => User::where('email', 'auditor@example.com')->firstOrFail(),
    );

    return [$tenant, $admin, $restrictedUser];
}

// CA-PERM-013
test('CA-PERM-013: sin auditoria.leer, GET /audit-logs responde 403 aunque exista propios en otro lado', function (): void {
    [$tenant, $admin] = provisionCoreTenant('auditscope-013');

    $roleId = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), ['code' => 'sin_auditoria_013', 'name' => 'Sin auditoría'])
        ->assertCreated()->json('public_id');

    resetSessionState();

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'sin-auditoria@example.com',
        'person' => ['given_name' => 'Sin', 'family_name_1' => 'Auditoria'],
        'send_invitation' => false,
        'role_ids' => [$roleId],
    ])->assertCreated();

    $user = app(TenantContext::class)->runFor($tenant->id, fn () => User::where('email', 'sin-auditoria@example.com')->firstOrFail());

    resetSessionState();

    test()->actingAs($user)
        ->getJson(coreApiUrl($tenant->slug, '/audit-logs'))
        ->assertStatus(403);
});

// CA-PERM-010, CA-PERM-014
test('CA-PERM-010/014: propios en GET /audit-logs solo muestra las entradas del propio actor; todos las muestra todas', function (): void {
    [$tenant, $admin, $restrictedUser] = provisionPropiosAuditTenant('auditscope-010');

    // Generar rastro: una acción del admin (crea el rol auditor_propio,
    // ya generado arriba) y una acción del propio restrictedUser (crear
    // OTRO rol, autorizado porque auditor_propio no tiene rol.crear —
    // usamos en su lugar una simple lectura para no complicar permisos:
    // el propio login/consulta ya genera auditoría de 'user' vía UsersController
    // si consulta /users. Para tener una entrada segura, usamos PATCH /me).
    resetSessionState();

    test()->actingAs($restrictedUser)
        ->patchJson(coreApiUrl($tenant->slug, '/me'), ['person' => ['contact_phone' => '600000000']])
        ->assertOk();

    resetSessionState();

    $ownEntries = test()->actingAs($restrictedUser)
        ->getJson(coreApiUrl($tenant->slug, '/audit-logs'))
        ->assertOk()
        ->json('data');

    $restrictedUserId = app(TenantContext::class)->runFor($tenant->id, fn () => $restrictedUser->fresh()->id);

    foreach ($ownEntries as $entry) {
        expect($entry['actor']['public_id'] ?? null)->toBe($restrictedUser->public_id);
    }

    resetSessionState();

    // CA-PERM-014: admin con ámbito todos ve todas las entradas del tenant
    // (incluidas las del propio restrictedUser), y ninguna de otro tenant.
    $allEntries = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/audit-logs'))
        ->assertOk()
        ->json('data');

    expect(count($allEntries))->toBeGreaterThanOrEqual(count($ownEntries));
});

// CA-PERM-011
test('CA-PERM-011: consultar el historial de una entidad ajena por auditable_id responde 404, no una lista vacía', function (): void {
    [$tenant, $admin, $restrictedUser] = provisionPropiosAuditTenant('auditscope-011');

    // El admin genera una entrada auditable identificable: PATCH /me suyo.
    resetSessionState();

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, '/me'), ['person' => ['contact_phone' => '611111111']])
        ->assertOk();

    $adminAuditableId = $admin->public_id;

    resetSessionState();

    // El usuario restringido (propios) consulta el historial del ADMIN
    // (no es su propia entrada) ⇒ 404, nunca 403 ni lista vacía con 200
    // (RN-PERM-14).
    test()->actingAs($restrictedUser)
        ->getJson(coreApiUrl($tenant->slug, "/audit-logs?auditable_id={$adminAuditableId}"))
        ->assertStatus(404);
});

// CA-PERM-012
test('CA-PERM-012: una exportación con ámbito propios genera un artefacto con solo las filas del solicitante', function (): void {
    Storage::fake(config('filesystems.default'));

    [$tenant, $admin, $restrictedUser] = provisionPropiosAuditTenant('auditscope-012');

    resetSessionState();

    test()->actingAs($restrictedUser)
        ->patchJson(coreApiUrl($tenant->slug, '/me'), ['person' => ['contact_phone' => '622222222']])
        ->assertOk();

    resetSessionState();

    $exportPublicId = test()->actingAs($restrictedUser)
        ->postJson(coreApiUrl($tenant->slug, '/audit-logs/exports'), ['format' => 'csv'])
        ->assertStatus(202)
        ->json('public_id');

    app(TenantContext::class)->runFor($tenant->id, function () use ($exportPublicId): void {
        $export = DataExport::where('public_id', $exportPublicId)->firstOrFail();

        expect($export->status)->toBe('completada');

        $csv = Storage::disk(config('filesystems.default'))->get($export->object_key);
        $lines = array_filter(explode("\n", trim($csv)));
        array_shift($lines); // cabecera

        expect($lines)->not->toBeEmpty();

        foreach ($lines as $line) {
            $columns = str_getcsv($line);
            $actorLabel = $columns[1] ?? '';
            expect($actorLabel)->not->toBe('Ana Perez'); // el admin, no el solicitante
        }
    });
});
