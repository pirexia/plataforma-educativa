<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Core\Domain\Models\DataExport;
use App\Modules\Core\Domain\Models\UserInvitation;
use App\Modules\Core\Http\Controllers\DataExportsController;
use App\Modules\Core\Infrastructure\Jobs\GenerateAuditLogExport;
use App\Modules\Core\Infrastructure\Jobs\GenerateUserExport;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\ScopedQuery;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

// REQ-CORE-003, paso 1.9b (S1-S7): exportación de usuarios, autorización por
// `kind` de GET /data-exports/{id}, contrato de `fallida`, filtros múltiples
// y -email.

beforeEach(function (): void {
    Mail::fake();
    Storage::fake('local');
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/**
 * Crea un rol personalizado con los permisos dados (ámbito `todos`) y un
 * usuario que lo tiene. Devuelve el usuario.
 *
 * @param  list<string>  $codes
 */
function ue_userWith(Tenant $tenant, User $admin, string $email, array $codes): User
{
    $roleId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'rol_'.substr(md5($email), 0, 10),
        'name' => 'Rol de '.$email,
        'permissions' => array_map(fn (string $code) => ['code' => $code, 'effect' => 'allow', 'scope' => 'todos'], $codes),
    ])->assertCreated()->json('public_id');

    resetSessionState();

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => $email,
        'person' => ['given_name' => 'Con', 'family_name_1' => 'Permisos'],
        'send_invitation' => false,
        'role_ids' => [$roleId],
    ])->assertCreated();

    resetSessionState();

    return app(TenantContext::class)->runFor($tenant->id, fn () => User::where('email', $email)->firstOrFail());
}

/**
 * @param  array<string, mixed>  $body
 */
function ue_requestExport(Tenant $tenant, User $actor, array $body = []): string
{
    $id = test()->actingAs($actor)
        ->postJson(coreApiUrl($tenant->slug, '/users/exports'), $body)
        ->assertStatus(202)
        ->json('public_id');

    resetSessionState();

    return $id;
}

function ue_csv(Tenant $tenant, string $exportId): string
{
    $key = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => DataExport::where('public_id', $exportId)->firstOrFail()->object_key,
    );

    return Storage::disk('local')->get($key);
}

/**
 * @return list<list<string|null>> incluida la cabecera
 */
function ue_parse(string $csv): array
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

// CA-CORE-223
test('CA-CORE-223: sin usuario.exportar, POST /users/exports responde 403; con él, 202, kind users, trabajo en core-exports y exported en auditoría', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uexp-223');
    $reader = ue_userWith($tenant, $admin, 'lector@example.com', ['usuario.leer']);
    $exporter = ue_userWith($tenant, $admin, 'exportador@example.com', ['usuario.exportar']);

    test()->actingAs($reader)
        ->postJson(coreApiUrl($tenant->slug, '/users/exports'), [])
        ->assertForbidden();

    resetSessionState();
    Queue::fake();

    $response = test()->actingAs($exporter)
        ->postJson(coreApiUrl($tenant->slug, '/users/exports'), [])
        ->assertStatus(202);

    $id = $response->json('public_id');
    expect($response->json('status'))->toBe('pendiente');

    Queue::assertPushedOn('core-exports', GenerateUserExport::class);

    app(TenantContext::class)->runFor($tenant->id, function () use ($id): void {
        $export = DataExport::where('public_id', $id)->firstOrFail();

        expect($export->kind)->toBe('users')
            ->and($export->status)->toBe('pendiente')
            ->and($export->object_key)->toBeNull()
            ->and(AuditLog::where('auditable_public_id', $id)->where('event', 'exported')->exists())->toBeTrue();
    });
});

test('CA-CORE-223 (INV-002): sin sesión, POST /users/exports responde 401', function (): void {
    [$tenant] = provisionCoreTenant('uexp-223-anon');

    test()->postJson(coreApiUrl($tenant->slug, '/users/exports'), [])->assertUnauthorized();
});

// CA-CORE-224
test('CA-CORE-224: POST /users/exports con q responde 422 con código propio y no crea ninguna fila', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uexp-224');

    $response = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/users/exports'), ['q' => 'ana'])
        ->assertStatus(422);

    expect($response->json('errors.q.0.code'))->toBe('core.validation.export_search_not_supported');

    resetSessionState();

    // Un valor de filtro inválido también es 422, y tampoco crea nada.
    foreach ([['status' => ['borrado']], ['locale' => ['xx']], ['role' => ['no-ulid']], ['format' => 'pdf'], ['include_deleted' => 'true']] as $body) {
        test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users/exports'), $body)->assertStatus(422);
        resetSessionState();
    }

    expect(app(TenantContext::class)->runFor($tenant->id, fn () => DataExport::query()->count()))->toBe(0);

    // Una exportación válida nunca guarda `q` en `filters`.
    $id = ue_requestExport($tenant, $admin, ['status' => ['activo']]);
    $filters = app(TenantContext::class)->runFor($tenant->id, fn () => DataExport::where('public_id', $id)->firstOrFail()->filters);

    expect($filters)->toBe(['status' => ['activo']])->and($filters)->not->toHaveKey('q');
});

// CA-CORE-225
test('CA-CORE-225: paridad de filtros con GET /users según OpenAPI; include_deleted sin usuario.eliminar responde 403', function (): void {
    $spec = openapiSpec();
    $listParams = collect($spec['paths']['/api/v1/users']['get']['parameters'])
        ->map(fn (array $p) => $p['name'] ?? ($p['$ref'] ?? ''))
        ->reject(fn (string $name) => in_array($name, ['q', 'sort', 'page', 'per_page'], true) || str_contains($name, '/'))
        ->values()->all();

    $bodyProperties = array_keys($spec['paths']['/api/v1/users/exports']['post']['requestBody']['content']['application/json']['schema']['properties']);

    expect($listParams)->not->toBeEmpty();

    foreach ($listParams as $name) {
        expect($bodyProperties)->toContain($name);
    }

    expect($bodyProperties)->not->toContain('q')->not->toContain('sort')->not->toContain('page')->not->toContain('per_page');

    [$tenant, $admin] = provisionCoreTenant('uexp-225');
    $exporter = ue_userWith($tenant, $admin, 'solo-exporta@example.com', ['usuario.exportar']);

    test()->actingAs($exporter)
        ->postJson(coreApiUrl($tenant->slug, '/users/exports'), ['include_deleted' => true])
        ->assertForbidden();

    resetSessionState();

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/users/exports'), ['include_deleted' => true])
        ->assertStatus(202);
});

/**
 * @return array<string, mixed>
 */
function openapiSpec(): array
{
    static $spec = null;

    if ($spec === null) {
        $spec = Yaml::parseFile(base_path('openapi/paths/core.yaml'));

        $spec = ['paths' => $spec['paths'] ?? $spec];
    }

    return $spec;
}

// CA-CORE-226
test('CA-CORE-226 (CA-CORE-207, ADR-055): dos solicitantes con idioma distinto obtienen ficheros idénticos byte a byte, con códigos técnicos y sin __()/trans() en los generadores', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uexp-226');
    $exporterDe = ue_userWith($tenant, $admin, 'exporta-de@example.com', ['usuario.exportar']);

    app(TenantContext::class)->runFor($tenant->id, function () use ($exporterDe): void {
        $exporterDe->person->update(['locale' => 'de']);
    });

    $first = ue_csv($tenant, ue_requestExport($tenant, $admin, ['status' => ['activo', 'pendiente']]));
    $second = ue_csv($tenant, ue_requestExport($tenant, $exporterDe, ['status' => ['activo', 'pendiente']]));

    expect($first)->toBe($second);

    $rows = ue_parse($first);

    expect($rows[0])->toBe(['public_id', 'status', 'deleted_at', 'created_at', 'email', 'given_name', 'family_name_1', 'family_name_2', 'contact_email', 'contact_phone', 'locale', 'roles']);

    foreach (array_slice($rows, 1) as $row) {
        expect($row[1])->toBeIn(['pendiente', 'activo', 'inactivo']);
    }

    // CA-CORE-207 acotado a los generadores de CSV de datos (funcional.md §14.12 punto 1).
    foreach ([GenerateUserExport::class, GenerateAuditLogExport::class] as $class) {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());
        $calls = collect(token_get_all($source))
            ->filter(fn ($t) => is_array($t) && $t[0] === T_STRING && in_array(strtolower($t[1]), ['__', 'trans', 'trans_choice'], true));

        expect($calls)->toBeEmpty("{$class} no debe llamar a __()/trans()");
    }
});

// CA-CORE-227
test('CA-CORE-227: esquema exacto del CSV de usuarios, sin documento ni fecha de nacimiento, orden, roles con |, neutralización, BOM y CRLF', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uexp-227');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $mk = function (array $person, string $email, array $roleCodes = [], ?UserStatus $status = null): void {
            $p = Person::factory()->create($person + [
                'document_type' => 'DNI',
                'document_number' => 'ZZDOC-'.Str::upper(Str::random(8)),
                'birth_date' => '2001-02-03',
            ]);
            $u = User::factory()->for($p)->create(['email' => $email] + ($status !== null ? ['status' => $status] : []));
            $u->roles()->attach(Role::whereIn('code', $roleCodes)->pluck('id')->all());
        };

        $mk(['given_name' => '=SUM(1+1)', 'family_name_1' => 'Aaa', 'family_name_2' => null], 'formula@example.com');
        $mk(['given_name' => 'Telefono', 'family_name_1' => 'Aaa', 'contact_phone' => ' +34600000001'], 'telefono@example.com');
        $mk(['given_name' => 'Dos', 'family_name_1' => 'Zzz'], 'dos-roles@example.com', ['secretaria', 'docente']);
    });

    $docs = app(TenantContext::class)->runFor($tenant->id, fn () => Person::query()->pluck('document_number')->filter()->all());
    $births = ['2001-02-03', '03/02/2001', '3/2/2001'];

    $csv = ue_csv($tenant, ue_requestExport($tenant, $admin));

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and(str_contains($csv, "\r\n"))->toBeTrue();

    $rows = ue_parse($csv);

    expect(implode(',', $rows[0]))->toBe('public_id,status,deleted_at,created_at,email,given_name,family_name_1,family_name_2,contact_email,contact_phone,locale,roles')
        ->and($rows[0])->not->toContain('document_type')->not->toContain('document_number')->not->toContain('birth_date');

    // Ningún valor de documento ni de fecha de nacimiento aparece en ninguna celda.
    expect($docs)->not->toBeEmpty();

    foreach ($rows as $row) {
        foreach ($row as $cell) {
            foreach ([...$docs, ...$births] as $secret) {
                expect((string) $cell)->not->toContain($secret);
            }
        }
    }

    $byEmail = [];

    foreach (array_slice($rows, 1) as $row) {
        $byEmail[$row[4]] = $row;
    }

    // Orden: family_name_1, given_name, public_id (Aaa antes que Zzz; Perez, del admin, entre ambos).
    $order = array_column(array_slice($rows, 1), 6);
    $sorted = $order;
    usort($sorted, fn ($a, $b) => strcoll($a, $b));

    expect($order)->toBe($sorted);

    expect($byEmail['formula@example.com'][5])->toBe("'=SUM(1+1)")
        ->and($byEmail['telefono@example.com'][9])->toBe("' +34600000001")
        ->and($byEmail['dos-roles@example.com'][11])->toBe('docente|secretaria')
        ->and($byEmail['formula@example.com'][7])->toBe('')
        ->and($byEmail['formula@example.com'][2])->toBe('')
        ->and($byEmail['formula@example.com'][3])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/')
        ->and($byEmail['formula@example.com'][11])->toBe('');
});

test('RN-CORE-85: include_deleted incluye a los dados de baja con deleted_at; sin él no salen; el filtro de rol acota', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uexp-85');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $p = Person::factory()->create(['given_name' => 'Baja', 'family_name_1' => 'Logica']);
        $u = User::factory()->for($p)->create(['email' => 'baja@example.com']);
        $u->delete();

        $p2 = Person::factory()->create(['given_name' => 'Doc', 'family_name_1' => 'Ente']);
        $u2 = User::factory()->for($p2)->create(['email' => 'docente@example.com']);
        $u2->roles()->attach(Role::where('code', 'docente')->firstOrFail()->id);
    });

    $without = ue_parse(ue_csv($tenant, ue_requestExport($tenant, $admin)));
    $with = ue_parse(ue_csv($tenant, ue_requestExport($tenant, $admin, ['include_deleted' => true])));

    expect(array_column($without, 4))->not->toContain('baja@example.com');

    $deleted = collect($with)->firstWhere(4, 'baja@example.com');
    expect($deleted)->not->toBeNull()->and($deleted[2])->not->toBe('');

    $docenteId = app(TenantContext::class)->runFor($tenant->id, fn () => Role::where('code', 'docente')->firstOrFail()->public_id);
    $byRole = ue_parse(ue_csv($tenant, ue_requestExport($tenant, $admin, ['role' => [$docenteId]])));

    expect(array_column(array_slice($byRole, 1), 4))->toBe(['docente@example.com']);

    $byLocale = ue_parse(ue_csv($tenant, ue_requestExport($tenant, $admin, ['locale' => ['fr']])));
    expect($byLocale)->toHaveCount(1);
});

test('RNF-LIM-004 (RN-CORE-85): el tope de filas de la exportación de usuarios responde 422', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uexp-tope');
    config(['core.export_max_rows' => 0]);

    $response = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/users/exports'), [])
        ->assertStatus(422);

    expect($response->json('errors.filters.0.code'))->toBe('core.validation.export_range_too_large');
});

// CA-CORE-228
test('CA-CORE-228 (INV-001): un usuario con usuario.exportar de otro centro recibe 404 y el fichero no incluye filas del otro', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('uexp-228-a');
    $exportId = ue_requestExport($tenantA, $adminA);

    [$tenantB, $adminB] = provisionCoreTenant('uexp-228-b');

    test()->actingAs($adminB)
        ->getJson(coreApiUrl($tenantB->slug, "/data-exports/{$exportId}"))
        ->assertNotFound();

    resetSessionState();

    $emailsA = array_column(array_slice(ue_parse(ue_csv($tenantA, $exportId)), 1), 4);
    $idB = ue_requestExport($tenantB, $adminB);
    $emailsB = array_column(array_slice(ue_parse(ue_csv($tenantB, $idB)), 1), 4);

    // Misma dirección de administrador en los dos: se distinguen por public_id.
    $idsA = array_column(array_slice(ue_parse(ue_csv($tenantA, $exportId)), 1), 0);
    $idsB = array_column(array_slice(ue_parse(ue_csv($tenantB, $idB)), 1), 0);

    expect($emailsA)->toHaveCount(1)->and($emailsB)->toHaveCount(1)
        ->and(array_intersect($idsA, $idsB))->toBe([]);
});

// CA-CORE-229
test('CA-CORE-229: GET /data-exports/{id} se autoriza por kind y solo para el solicitante', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uexp-229');
    $onlyUsers = ue_userWith($tenant, $admin, 'solo-usuarios@example.com', ['usuario.exportar']);
    $otherUsers = ue_userWith($tenant, $admin, 'otro-usuarios@example.com', ['usuario.exportar']);

    $id = ue_requestExport($tenant, $onlyUsers);

    // El solicitante, sin auditoria.exportar, puede consultar su exportación de usuarios.
    test()->actingAs($onlyUsers)
        ->getJson(coreApiUrl($tenant->slug, "/data-exports/{$id}"))
        ->assertOk()->assertJsonPath('kind', 'users')->assertJsonPath('status', 'completada');

    resetSessionState();

    // Otro usuario con el permiso, pero no el solicitante: 403.
    test()->actingAs($otherUsers)
        ->getJson(coreApiUrl($tenant->slug, "/data-exports/{$id}"))
        ->assertForbidden();

    resetSessionState();

    // Un solicitante de auditoría que ha perdido auditoria.exportar: 403.
    $auditor = ue_userWith($tenant, $admin, 'auditor-pierde@example.com', ['auditoria.exportar']);
    $auditId = test()->actingAs($auditor)
        ->postJson(coreApiUrl($tenant->slug, '/audit-logs/exports'), ['format' => 'csv'])
        ->assertStatus(202)->json('public_id');

    resetSessionState();

    test()->actingAs($auditor)->getJson(coreApiUrl($tenant->slug, "/data-exports/{$auditId}"))->assertOk();

    resetSessionState();

    app(TenantContext::class)->runFor($tenant->id, function () use ($auditor): void {
        $auditor->roles()->detach();
    });

    test()->actingAs($auditor)->getJson(coreApiUrl($tenant->slug, "/data-exports/{$auditId}"))->assertForbidden();

    resetSessionState();

    // Y uno con auditoria.exportar pero no usuario.exportar no ve la de usuarios aunque la solicitara
    // un día en que sí lo tenía.
    app(TenantContext::class)->runFor($tenant->id, function () use ($onlyUsers): void {
        $onlyUsers->roles()->detach();
    });

    test()->actingAs($onlyUsers)->getJson(coreApiUrl($tenant->slug, "/data-exports/{$id}"))->assertForbidden();
});

test('RN-CORE-86 (RPERM-011): un kind sin correspondencia se deniega y sin sesión responde 401', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uexp-86');
    $id = ue_requestExport($tenant, $admin);

    test()->getJson(coreApiUrl($tenant->slug, "/data-exports/{$id}"))->assertUnauthorized();

    resetSessionState();

    // Un kind que la correspondencia cerrada no conoce. El CHECK de la tabla no lo admite, así que
    // se simula al leer la fila (un paso futuro que amplíe el CHECK sin ampliar la correspondencia).
    DataExport::retrieved(function (DataExport $export): void {
        $export->kind = 'otro';
    });

    try {
        test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, "/data-exports/{$id}"))->assertForbidden();
    } finally {
        app('events')->forget('eloquent.retrieved: '.DataExport::class);
    }

    // La correspondencia cubre exactamente los kind que el CHECK admite: ninguno queda sin permiso.
    $definition = DB::connection('pgsql_owner')->selectOne(
        "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'data_exports_kind_check'",
    )->def;
    preg_match_all("/'([a-z_]+)'::text/", $definition, $matches);
    $mapped = array_keys((new ReflectionClassConstant(DataExportsController::class, 'PERMISSION_BY_KIND'))->getValue());

    expect($matches[1])->not->toBeEmpty()
        ->and(collect($matches[1])->sort()->values()->all())->toBe(collect($mapped)->sort()->values()->all());
});

// CA-CORE-230
test('CA-CORE-230: una exportación fallida responde 200 con status fallida y error_code; una pendiente sigue siendo 409', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uexp-230');
    Queue::fake();

    $id = ue_requestExport($tenant, $admin);

    test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/data-exports/{$id}"))
        ->assertStatus(409);

    resetSessionState();

    app(TenantContext::class)->runFor($tenant->id, function () use ($id): void {
        DataExport::where('public_id', $id)->firstOrFail()->update(['status' => 'fallida', 'error_code' => 'core.export.generation_failed']);
    });

    $response = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/data-exports/{$id}"))
        ->assertOk();

    expect($response->json('status'))->toBe('fallida')
        ->and($response->json('error_code'))->toBe('core.export.generation_failed')
        ->and($response->json('download_url'))->toBeNull()
        ->and($response->json('row_count'))->toBeNull();
});

// CA-CORE-214 (parte Pest, S5)
test('CA-CORE-214: GET /users?sort=-email responde 200 ordenado de forma descendente', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uexp-214');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        foreach (['b@example.com', 'c@example.com', 'a@example.com'] as $email) {
            User::factory()->for(Person::factory()->create())->create(['email' => $email]);
        }
    });

    $asc = test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/users?sort=email'))->assertOk()->json('data.*.email');

    resetSessionState();

    $desc = test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/users?sort=-email'))->assertOk()->json('data.*.email');

    // El orden lo fija la colación de la base de datos, no `sort()` de PHP:
    // basta con que `-email` sea exactamente el inverso de `email`.
    expect($asc)->toHaveCount(4)
        ->and($desc)->toBe(array_reverse($asc));
});

// CA-CORE-216 (parte Pest, S6, CA-CORE-014)
test('CA-CORE-216: GET /users/{id}?include_deleted=true devuelve al eliminado con usuario.eliminar, 403 sin él y 404 sin el parámetro', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uexp-216');
    $reader = ue_userWith($tenant, $admin, 'lector-216@example.com', ['usuario.leer']);

    $deleted = app(TenantContext::class)->runFor($tenant->id, function () {
        $user = User::factory()->for(Person::factory()->create())->create(['email' => 'eliminado@example.com']);
        $user->delete();

        return $user;
    });

    $url = coreApiUrl($tenant->slug, "/users/{$deleted->public_id}");

    test()->actingAs($admin)->getJson($url.'?include_deleted=true')->assertOk()
        ->assertJsonPath('public_id', $deleted->public_id)
        ->assertJsonPath('deleted_at', fn ($value) => $value !== null);

    resetSessionState();
    test()->actingAs($admin)->getJson($url)->assertNotFound();

    resetSessionState();
    test()->actingAs($reader)->getJson($url.'?include_deleted=true')->assertForbidden();

    resetSessionState();
    test()->actingAs($reader)->getJson($url)->assertNotFound();

    resetSessionState();
    test()->actingAs($admin)->getJson($url.'?include_deleted=quizas')->assertStatus(422);
});

// CA-CORE-231 y S7 de usuarios
test('CA-CORE-231: GET /invitations?status=a,b devuelve la unión y un valor inválido responde 422', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uexp-231');

    foreach (['uno', 'dos', 'tres'] as $name) {
        test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
            'email' => "{$name}@example.com",
            'person' => ['given_name' => $name, 'family_name_1' => 'Inv'],
            'send_invitation' => true,
        ])->assertCreated();
        resetSessionState();
    }

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $invitations = UserInvitation::query()->orderBy('id')->get();
        $invitations[0]->update(['expires_at' => now()->subDay()]); // caducada
        $invitations[1]->update(['revoked_at' => now()]);           // revocada
        // la tercera sigue vigente
    });

    $get = fn (string $status) => test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/invitations?status={$status}"));

    $status = fn (string $q) => collect($get($q)->assertOk()->json('data'))->pluck('status')->sort()->values()->all();

    // Además de las tres de arriba existe la del administrador del centro (vigente).
    expect($status('vigente,caducada'))->toBe(['caducada', 'vigente', 'vigente']);
    resetSessionState();
    expect($status('revocada'))->toBe(['revocada']);
    resetSessionState();
    expect($status('vigente,caducada,revocada'))->toHaveCount(4);
    resetSessionState();
    expect($status('revocada,revocada'))->toBe(['revocada']);

    resetSessionState();
    $get('vigente,otro')->assertStatus(422);
});

test('CA-CORE-231 (S7): GET /users?locale=a,b devuelve la unión y un idioma inválido responde 422', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uexp-231-loc');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        User::factory()->for(Person::factory()->create(['locale' => 'de']))->create(['email' => 'de@example.com']);
        User::factory()->for(Person::factory()->create(['locale' => 'fr']))->create(['email' => 'fr@example.com']);
        User::factory()->for(Person::factory()->create(['locale' => 'en']))->create(['email' => 'en@example.com']);
    });

    $emails = test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/users?locale=de,fr'))
        ->assertOk()->json('data.*.email');

    expect(collect($emails)->sort()->values()->all())->toBe(['de@example.com', 'fr@example.com']);

    resetSessionState();
    test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/users?locale=de,xx'))->assertStatus(422);

    resetSessionState();
    // Un solo valor sigue funcionando como antes.
    expect(test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/users?locale=en'))->assertOk()->json('data.*.email'))->toBe(['en@example.com']);
});

// CA-CORE-296, CA-CORE-297 (issue #280, INV-002): sin solicitante, la exportación falla y no exporta todo el tenant
test('CA-CORE-296 y CA-CORE-297: si el solicitante ya no existe al ejecutarse el trabajo, la exportación queda fallida y sin fichero', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uexp-296');

    foreach ([['/users/exports', [], GenerateUserExport::class], ['/audit-logs/exports', ['format' => 'csv'], GenerateAuditLogExport::class]] as [$path, $body, $jobClass]) {
        Queue::fake();

        $id = test()->actingAs($admin)
            ->postJson(coreApiUrl($tenant->slug, $path), $body)
            ->assertStatus(202)
            ->json('public_id');

        resetSessionState();

        app(TenantContext::class)->runFor($tenant->id, function () use ($id, $tenant, $jobClass): void {
            $export = DataExport::where('public_id', $id)->firstOrFail();
            $requesterId = $export->requested_by;

            // El solicitante deja de resolverse (borrado lógico) entre la solicitud y la ejecución.
            User::query()->whereKey($requesterId)->firstOrFail()->delete();
            expect($export->fresh()->requester)->toBeNull();

            (new $jobClass($export->id, $tenant->public_id))->handle(
                app(PermissionResolver::class),
                app(ScopedQuery::class),
            );

            $export = $export->fresh();

            expect($export->status)->toBe('fallida')
                ->and($export->error_code)->toBe('core.export.generation_failed')
                ->and($export->object_key)->toBeNull();
        });

        // Restaura al solicitante para la siguiente vuelta.
        app(TenantContext::class)->runFor($tenant->id, fn () => User::withTrashed()->whereKey($admin->id)->restore());
    }
});
