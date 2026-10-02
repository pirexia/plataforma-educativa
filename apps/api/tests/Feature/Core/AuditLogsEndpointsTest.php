<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Domain\Models\DataExport;
use App\Modules\Core\Domain\TenantAdministrator;
use App\Modules\Core\Domain\TenantInitialSettings;
use App\Modules\Core\Domain\TenantProvisioner;
use App\Modules\Core\Http\Requests\IndexAuditLogsRequest;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Mail::fake();
    Storage::fake('local');
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

// CA-CORE-050
test('CA-CORE-050: GET /audit-logs devuelve solo registros del propio tenant, orden descendente, con cursor', function (): void {
    [$tenant, $admin] = provisionCoreTenant('audit-050');

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'uno@example.com',
        'person' => ['given_name' => 'Uno', 'family_name_1' => 'Test'],
        'send_invitation' => false,
    ])->assertCreated();

    $response = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/audit-logs?limit=5'))
        ->assertOk();

    expect($response->json('data'))->not->toBeEmpty();
    $occurredAts = collect($response->json('data'))->pluck('occurred_at');
    expect($occurredAts->values()->all())->toBe($occurredAts->sortDesc()->values()->all());
});

// CA-CORE-051
test('CA-CORE-051: sin auditoria.leer, GET /audit-logs devuelve 403', function (): void {
    [$tenant] = provisionCoreTenant('audit-051');
    $secretaria = app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create(['contact_email' => 's@example.com']);
        $user = User::factory()->for($person)->create(['email' => 's@example.com']);
        $role = Role::where('code', 'secretaria')->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    });

    test()->actingAs($secretaria)
        ->getJson(coreApiUrl($tenant->slug, '/audit-logs'))
        ->assertStatus(403);
});

// CA-CORE-052
test('CA-CORE-052: un registro con changes redactado conserva el objeto redacted sin exponer ningún valor', function (): void {
    [$tenant, $admin] = provisionCoreTenant('audit-052');

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'con-documento@example.com',
        'person' => [
            'given_name' => 'Con', 'family_name_1' => 'Documento',
            'document_type' => 'dni', 'document_number' => '00000000T',
        ],
        'send_invitation' => false,
    ])->assertCreated();

    $response = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/audit-logs?event=created&auditable_type=person&limit=10'))
        ->assertOk();

    $personLog = collect($response->json('data'))->firstWhere('auditable_type', 'person');
    expect($personLog)->not->toBeNull()
        // Alta (created): no hay "antes", from_empty es true por diseño
        // (mismo criterio que AuditObserverTest de 0.9).
        ->and($personLog['changes']['document_number'])->toEqual(['redacted' => 'identifier', 'from_empty' => true, 'to_empty' => false]);

    $encoded = json_encode($personLog['changes']);
    expect($encoded)->not->toContain('00000000T');
});

// CA-CORE-053, CA-CORE-054
test('CA-CORE-053/054: solicitar una exportación la audita como exported, se genera en cola y se descarga por URL firmada', function (): void {
    [$tenant, $admin] = provisionCoreTenant('audit-053');

    $response = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/audit-logs/exports'), ['format' => 'csv'])
        ->assertStatus(202);

    $exportId = $response->json('public_id');
    expect($exportId)->not->toBeNull();

    app(TenantContext::class)->runFor($tenant->id, function () use ($exportId): void {
        $log = AuditLog::where('auditable_public_id', $exportId)->where('event', 'exported')->first();
        expect($log)->not->toBeNull();
    });

    $download = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/data-exports/{$exportId}"))
        ->assertOk();

    expect($download->json('status'))->toBe('completada')
        ->and($download->json('download_url'))->not->toBeNull();
});

// issue #268: inyección de fórmulas CSV en la exportación de auditoría.
test('CA-CORE-055: la exportación de auditoría neutraliza valores que empiezan por un carácter de fórmula', function (): void {
    test()->artisan('platform:sync-registry')->run();

    $tenant = Tenant::factory()->create();
    Cache::forget("tenant-resolution:{$tenant->slug}");

    app(TenantProvisioner::class)->provision(
        $tenant,
        TenantInitialSettings::defaults(),
        new TenantAdministrator('admin@example.com', "=cmd|'/c calc'!A1", 'Perez'),
    );

    $admin = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => User::query()->where('email', 'admin@example.com')->firstOrFail(),
    );

    // Genera un evento de auditoría con este administrador como actor
    // (el nombre malicioso viaja en `actor.person`, RN-DT/GenerateAuditLogExport).
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'otro@example.com',
        'person' => ['given_name' => 'Otro', 'family_name_1' => 'Usuario'],
        'send_invitation' => false,
    ])->assertCreated();

    $exportId = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/audit-logs/exports'), ['format' => 'csv'])
        ->assertStatus(202)
        ->json('public_id');

    $objectKey = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => DataExport::where('public_id', $exportId)->firstOrFail()->object_key,
    );

    $rows = parseAuditCsv(Storage::disk('local')->get($objectKey));

    // La celda del actor es exactamente el nombre malicioso con apóstrofo
    // delante (aserción positiva y exacta, no una ausencia que fputcsv
    // ya garantizaría por el entrecomillado).
    $actorCells = array_column(array_slice($rows, 1), 1);

    expect($actorCells)->toContain("'=cmd|'/c calc'!A1 Perez");

    foreach ($rows as $row) {
        foreach ($row as $cell) {
            expect($cell)->not->toMatch('/^[=+\-@]/');
        }
    }
});

// RN-CORE-47/48 (#270): la exportación de auditoría escribe con CsvWriter,
// así que gana BOM, CRLF y el instante en ISO 8601 con desfase (ADR-054 §10.3).
test('RN-CORE-47/48: la exportación de auditoría usa el dialecto común (BOM, CRLF, cabecera y ISO 8601 con desfase)', function (): void {
    [$tenant, $admin] = provisionCoreTenant('audit-csv-dialect');

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'dialecto@example.com',
        'person' => ['given_name' => 'Dia', 'family_name_1' => 'Lecto'],
        'send_invitation' => false,
    ])->assertCreated();

    $csv = exportAuditCsv($tenant, $admin, []);

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and(str_contains($csv, "\r\n"))->toBeTrue();

    $rows = parseAuditCsv($csv);

    expect($rows[0])->toBe(['occurred_at', 'actor', 'actor_type', 'auditable_type', 'auditable_public_id', 'event', 'request_id'])
        ->and(count($rows))->toBeGreaterThan(1)
        ->and($rows[1][0])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
});

/**
 * Solicita una exportación como `$actor`, la genera (cola síncrona en
 * tests) y devuelve el contenido del fichero.
 *
 * @param  array<string, mixed>  $body
 */
function exportAuditCsv(Tenant $tenant, User $actor, array $body): string
{
    $exportId = test()->actingAs($actor)
        ->postJson(coreApiUrl($tenant->slug, '/audit-logs/exports'), ['format' => 'csv', ...$body])
        ->assertStatus(202)
        ->json('public_id');

    $objectKey = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => DataExport::where('public_id', $exportId)->firstOrFail()->object_key,
    );

    return Storage::disk('local')->get($objectKey);
}

/**
 * @return list<list<string|null>> incluida la cabecera
 */
function parseAuditCsv(string $csv): array
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
    rewind($stream);

    $rows = [];

    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($stream);

    return $rows;
}

// issue #266, ADR-038 §5.2: rango con sufijo _from/_to.
test('ADR-038 §5.2 (#266): GET /audit-logs filtra por occurred_at_from/occurred_at_to y ya no por from/to', function (): void {
    [$tenant, $admin] = provisionCoreTenant('audit-rango');

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'rango@example.com',
        'person' => ['given_name' => 'Ran', 'family_name_1' => 'Go'],
        'send_invitation' => false,
    ])->assertCreated();

    $count = fn (string $query): int => count(
        test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, "/audit-logs?limit=200{$query}"))->assertOk()->json('data'),
    );

    $all = $count('');
    expect($all)->toBeGreaterThan(0)
        ->and($count('&occurred_at_from=2000-01-01'))->toBe($all)
        ->and($count('&occurred_at_from=2999-01-01'))->toBe(0)
        ->and($count('&occurred_at_to=2000-01-01'))->toBe(0)
        ->and($count('&occurred_at_to=2999-01-01'))->toBe($all)
        // Los nombres antiguos no están en la lista blanca: se ignoran (ADR-038 §5.2).
        ->and($count('&from=2999-01-01'))->toBe($all)
        ->and($count('&to=2000-01-01'))->toBe($all);

    test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/audit-logs?occurred_at_from=no-es-fecha'))
        ->assertStatus(422);
});

test('ADR-038 §5.2 (#266): POST /audit-logs/exports acota por occurred_at_from/occurred_at_to', function (): void {
    [$tenant, $admin] = provisionCoreTenant('audit-rango-export');

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'rango2@example.com',
        'person' => ['given_name' => 'Ran', 'family_name_1' => 'Go'],
        'send_invitation' => false,
    ])->assertCreated();

    $all = count(parseAuditCsv(exportAuditCsv($tenant, $admin, []))) - 1;

    expect($all)->toBeGreaterThan(0)
        ->and(count(parseAuditCsv(exportAuditCsv($tenant, $admin, ['occurred_at_from' => '2999-01-01']))) - 1)->toBe(0)
        ->and(count(parseAuditCsv(exportAuditCsv($tenant, $admin, ['occurred_at_to' => '2000-01-01']))) - 1)->toBe(0)
        ->and(count(parseAuditCsv(exportAuditCsv($tenant, $admin, ['occurred_at_from' => '2000-01-01', 'occurred_at_to' => '2999-01-01']))) - 1)->toBeGreaterThanOrEqual($all);
});

// issue #267, ADR-054 §8.2: paridad de filtros listado/exportación.
test('ADR-054 §8.2 (#267): la exportación valida los filtros escalares del listado con las mismas reglas', function (): void {
    expect(array_keys(IndexAuditLogsRequest::filterRules()))
        ->toBe(['occurred_at_from', 'occurred_at_to', 'actor_id', 'actor_type', 'auditable_id', 'module']);

    [$tenant, $admin] = provisionCoreTenant('audit-paridad');

    // Mismas reglas que GET: ULID inválido, actor_type fuera de vocabulario y fecha inválida ⇒ 422.
    foreach ([
        ['actor_id' => 'no-es-ulid'],
        ['auditable_id' => 'no-es-ulid'],
        ['actor_type' => 'inventado'],
        ['occurred_at_from' => 'no-es-fecha'],
    ] as $invalid) {
        test()->actingAs($admin)
            ->postJson(coreApiUrl($tenant->slug, '/audit-logs/exports'), ['format' => 'csv', ...$invalid])
            ->assertStatus(422);
    }
});

test('ADR-054 §8.2 (#267): el CSV contiene solo las filas del filtro, por cada uno de los cuatro filtros', function (): void {
    [$tenant, $admin] = provisionCoreTenant('audit-filtros');

    // Un segundo actor con auditoria.leer/exportar sobre todos.
    $roleId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'auditor_todos', 'name' => 'Auditor de todo',
        'permissions' => [
            ['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'todos'],
            ['code' => 'auditoria.exportar', 'effect' => 'allow', 'scope' => 'todos'],
            ['code' => 'usuario.crear', 'effect' => 'allow', 'scope' => 'todos'],
        ],
    ])->assertCreated()->json('public_id');

    resetSessionState();

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'auditor2@example.com',
        'person' => ['given_name' => 'Segundo', 'family_name_1' => 'Actor'],
        'send_invitation' => false,
        'role_ids' => [$roleId],
    ])->assertCreated();

    $second = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => User::where('email', 'auditor2@example.com')->firstOrFail(),
    );

    resetSessionState();

    $created = test()->actingAs($second)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'creado-por-segundo@example.com',
        'person' => ['given_name' => 'Creado', 'family_name_1' => 'Segundo'],
        'send_invitation' => false,
    ])->assertCreated();

    resetSessionState();

    $unfiltered = array_slice(parseAuditCsv(exportAuditCsv($tenant, $admin, [])), 1);
    $actorLabels = array_unique(array_column($unfiltered, 1));

    // Sin filtro el CSV mezcla actores, tipos de actor y entidades: los
    // casos de abajo no son vacuos.
    expect($actorLabels)->toContain('Ana Perez')->toContain('Segundo Actor')
        ->and(count(array_unique(array_column($unfiltered, 2))))->toBeGreaterThan(1)
        ->and(count(array_unique(array_column($unfiltered, 4))))->toBeGreaterThan(1);

    // actor_id
    $rows = array_slice(parseAuditCsv(exportAuditCsv($tenant, $admin, ['actor_id' => $second->public_id])), 1);
    expect($rows)->not->toBeEmpty()
        ->and(array_values(array_unique(array_column($rows, 1))))->toBe(['Segundo Actor'])
        ->and(count($rows))->toBeLessThan(count($unfiltered));

    // actor_type
    $rows = array_slice(parseAuditCsv(exportAuditCsv($tenant, $admin, ['actor_type' => 'user'])), 1);
    expect($rows)->not->toBeEmpty()
        ->and(array_values(array_unique(array_column($rows, 2))))->toBe(['user'])
        ->and(count($rows))->toBeLessThan(count($unfiltered));

    // auditable_id
    $auditableId = $created->json('public_id');
    $rows = array_slice(parseAuditCsv(exportAuditCsv($tenant, $admin, ['auditable_id' => $auditableId])), 1);
    expect($rows)->not->toBeEmpty()
        ->and(array_values(array_unique(array_column($rows, 4))))->toBe([$auditableId])
        ->and(count($rows))->toBeLessThan(count($unfiltered));

    // module: uno sin ninguna entidad ⇒ solo cabecera; `core` ⇒ solo entidades de ese módulo.
    expect(parseAuditCsv(exportAuditCsv($tenant, $admin, ['module' => 'no-existe'])))->toHaveCount(1);

    $rows = array_slice(parseAuditCsv(exportAuditCsv($tenant, $admin, ['module' => 'core'])), 1);
    expect($rows)->not->toBeEmpty()
        ->and(array_diff(array_unique(array_column($rows, 3)), [
            'person', 'user', 'role', 'academic_year', 'module_subscription',
            'tenant_setting', 'user_invitation', 'user_import', 'data_export',
        ]))->toBe([]);
});

test('ADR-054 §8.2 (#267), INV-001/INV-002: los filtros de la exportación siguen acotados por tenant y por ámbito', function (): void {
    [$tenantA, $adminA, $restricted] = provisionPropiosAuditTenantForExport('audit-filtros-a');
    [$tenantB, $adminB] = provisionCoreTenant('audit-filtros-b');

    resetSessionState();

    // Ámbito `propios`: aunque el filtro pida al administrador, el usuario
    // restringido solo recibe lo suyo (y con ese filtro, nada).
    $asked = array_slice(parseAuditCsv(exportAuditCsv($tenantA, $restricted, ['actor_id' => $adminA->public_id])), 1);
    expect($asked)->toBe([]);

    resetSessionState();

    $own = array_slice(parseAuditCsv(exportAuditCsv($tenantA, $restricted, [])), 1);
    expect($own)->not->toBeEmpty()
        ->and(array_values(array_unique(array_column($own, 1))))->toBe(['Auditor Propio']);

    resetSessionState();

    // Otro tenant: el ULID de un actor del tenant B no existe en el A ⇒ ninguna fila.
    expect(array_slice(parseAuditCsv(exportAuditCsv($tenantA, $adminA, ['actor_id' => $adminB->public_id])), 1))->toBe([]);

    resetSessionState();

    $all = array_slice(parseAuditCsv(exportAuditCsv($tenantA, $adminA, [])), 1);
    expect($all)->not->toBeEmpty();

    $tenantBIds = app(TenantContext::class)->runFor(
        $tenantB->id,
        fn () => AuditLog::query()->pluck('auditable_public_id')->filter()->unique()->values()->all(),
    );
    expect($tenantBIds)->not->toBeEmpty()
        ->and(array_intersect($tenantBIds, array_column($all, 4)))->toBe([]);
});

/**
 * @return array{0: Tenant, 1: User, 2: User} admin y un usuario con `auditoria.leer/exportar` `propios`
 */
function provisionPropiosAuditTenantForExport(string $slug): array
{
    [$tenant, $admin] = provisionCoreTenant($slug);

    $roleId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
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

    $restricted = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => User::where('email', 'auditor@example.com')->firstOrFail(),
    );

    // Para que el restringido tenga filas propias.
    resetSessionState();
    test()->actingAs($restricted)->patchJson(coreApiUrl($tenant->slug, '/me'), ['person' => ['contact_phone' => '622222222']])->assertOk();

    return [$tenant, $admin, $restricted];
}

// issue #267: el tope de filas cuenta lo que se exporta (mismos filtros que el trabajo).
test('RNF-LIM-004 (#267): el tope de filas de la exportación cuenta con occurred_at_from/to y con cada filtro', function (): void {
    [$tenant, $admin] = provisionCoreTenant('audit-tope');

    config(['core.export_max_rows' => 5]);

    $post = fn (array $body) => test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/audit-logs/exports'), ['format' => 'csv', ...$body]);

    // Sin filtro, el aprovisionamiento ya supera el tope.
    $post([])->assertStatus(422)->assertJsonPath('errors.filters.0.code', 'core.validation.export_range_too_large');

    // Rango estrecho: pasa. Un rango que abarca todo vuelve a superar el tope.
    $post(['occurred_at_from' => '2999-01-01'])->assertStatus(202);
    $post(['occurred_at_to' => '2000-01-01'])->assertStatus(202);
    $post(['occurred_at_from' => '2000-01-01', 'occurred_at_to' => '2999-01-01'])->assertStatus(422);

    // Cada filtro nuevo, solo, deja cero filas y por tanto pasa.
    $post(['actor_id' => (string) Str::ulid()])->assertStatus(202);
    $post(['actor_type' => 'anonymous'])->assertStatus(202);
    $post(['auditable_id' => (string) Str::ulid()])->assertStatus(202);
    $post(['module' => 'no-existe'])->assertStatus(202);
    $post(['auditable_type' => ['no_existe']])->assertStatus(202);
});
