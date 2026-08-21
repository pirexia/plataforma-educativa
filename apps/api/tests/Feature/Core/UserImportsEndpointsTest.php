<?php

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Domain\Models\UserImport;
use App\Modules\Core\Infrastructure\Jobs\PurgeImportArtifacts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
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

const CORE_IMPORT_HEADER = 'email;given_name;family_name_1;family_name_2;document_type;document_number;birth_date;contact_email;contact_phone;locale;roles';

function coreImportRow(string $email, string $givenName): string
{
    return implode(';', [$email, $givenName, 'Test', '', '', '', '', '', '', '', '']);
}

function coreImportCsv(): string
{
    return implode("\n", [
        CORE_IMPORT_HEADER,
        coreImportRow('uno@example.com', 'Uno'),
        coreImportRow('dos@example.com', 'Dos'),
        coreImportRow('tres@example.com', 'Tres'),
        implode(';', ['', 'Cuatro', 'Test', '', '', '', '', '', '', '', '']),
        coreImportRow('uno@example.com', 'Cinco'),
    ]);
}

// CA-CORE-030
test('CA-CORE-030: un CSV con 3 filas válidas y 2 inválidas se valida sin crear usuarios', function (): void {
    [$tenant, $admin] = provisionCoreTenant('import-030');

    $file = UploadedFile::fake()->createWithContent('personal.csv', coreImportCsv());

    $response = test()->actingAs($admin)
        ->call('POST', coreApiUrl($tenant->slug, '/user-imports'), [], [], ['file' => $file])
        ->assertStatus(202);

    $importId = $response->json('public_id');
    expect($importId)->not->toBeNull();

    $show = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/user-imports/{$importId}"))
        ->assertOk();

    expect($show->json('status'))->toBe('validado')
        ->and($show->json('row_count'))->toBe(5)
        ->and($show->json('error_count'))->toBe(2)
        ->and($show->json('report_url'))->not->toBeNull();

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(User::where('email', 'uno@example.com')->exists())->toBeFalse();
    });
});

// CA-CORE-031/032
test('CA-CORE-031/032: ejecutar un lote validado crea exactamente las filas válidas y es idempotente', function (): void {
    [$tenant, $admin] = provisionCoreTenant('import-031');

    $file = UploadedFile::fake()->createWithContent('personal.csv', coreImportCsv());

    $store = test()->actingAs($admin)
        ->call('POST', coreApiUrl($tenant->slug, '/user-imports'), [], [], ['file' => $file])
        ->assertStatus(202);

    $importId = $store->json('public_id');
    $key = (string) Str::ulid();

    $execute = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/user-imports/{$importId}/execute"), [], ['Idempotency-Key' => $key])
        ->assertStatus(202);

    expect($execute->headers->get('Idempotency-Replayed'))->toBeNull();

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(User::where('email', 'uno@example.com')->exists())->toBeTrue()
            ->and(User::where('email', 'dos@example.com')->exists())->toBeTrue()
            ->and(User::where('email', 'tres@example.com')->exists())->toBeTrue()
            ->and(User::query()->count())->toBe(4); // 3 importados + el admin del aprovisionamiento
    });

    // CA-CORE-032: repetir con la misma clave no crea usuarios nuevos.
    $replay = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/user-imports/{$importId}/execute"), [], ['Idempotency-Key' => $key])
        ->assertStatus(202);

    expect($replay->headers->get('Idempotency-Replayed'))->toBe('true');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(User::query()->count())->toBe(4);
    });
});

test('POST .../execute sin Idempotency-Key devuelve 400', function (): void {
    [$tenant, $admin] = provisionCoreTenant('import-400');

    $file = UploadedFile::fake()->createWithContent('personal.csv', coreImportCsv());
    $store = test()->actingAs($admin)
        ->call('POST', coreApiUrl($tenant->slug, '/user-imports'), [], [], ['file' => $file])
        ->assertStatus(202);

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/user-imports/{$store->json('public_id')}/execute"))
        ->assertStatus(400);
});

// CA-CORE-033
test('CA-CORE-033: un CSV con cabecera desconocida queda fallido y la ejecución se rechaza con 409', function (): void {
    [$tenant, $admin] = provisionCoreTenant('import-033');

    $content = "nombre,apellido\nAna,Perez";
    $file = UploadedFile::fake()->createWithContent('personal.csv', $content);

    $store = test()->actingAs($admin)
        ->call('POST', coreApiUrl($tenant->slug, '/user-imports'), [], [], ['file' => $file])
        ->assertStatus(202);

    $importId = $store->json('public_id');

    $show = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/user-imports/{$importId}"))
        ->assertOk();

    expect($show->json('status'))->toBe('fallido');

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/user-imports/{$importId}/execute"), [], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(409);
});

// CA-CORE-034
test('CA-CORE-034: un correo repetido dos veces en el fichero se reporta como duplicado en fichero', function (): void {
    [$tenant, $admin] = provisionCoreTenant('import-034');

    $file = UploadedFile::fake()->createWithContent('personal.csv', coreImportCsv());
    $store = test()->actingAs($admin)
        ->call('POST', coreApiUrl($tenant->slug, '/user-imports'), [], [], ['file' => $file])
        ->assertStatus(202);

    $show = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/user-imports/{$store->json('public_id')}"))
        ->assertOk();

    $duplicateError = collect($show->json('error_summary'))->firstWhere('code', 'duplicado_en_fichero');
    expect($duplicateError)->not->toBeNull()
        ->and($duplicateError['column'])->toBe('email');
});

// CA-CORE-035
test('CA-CORE-035: la purga de artefactos de importación borra el CSV fuente y el informe tras el periodo de retención', function (): void {
    [$tenant, $admin] = provisionCoreTenant('import-035');

    $file = UploadedFile::fake()->createWithContent('personal.csv', coreImportCsv());
    $store = test()->actingAs($admin)
        ->call('POST', coreApiUrl($tenant->slug, '/user-imports'), [], [], ['file' => $file])
        ->assertStatus(202);

    $importId = $store->json('public_id');

    app(TenantContext::class)->runFor($tenant->id, function () use ($importId): void {
        $import = UserImport::where('public_id', $importId)->firstOrFail();
        expect($import->source_object_key)->not->toBeNull();

        Storage::disk('local')->assertExists($import->source_object_key);

        $import->forceFill(['created_at' => now()->subDays(31)])->saveQuietly();
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($tenant): void {
        PurgeImportArtifacts::dispatch($tenant->id);
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($importId): void {
        $import = UserImport::where('public_id', $importId)->firstOrFail();
        expect($import->source_object_key)->toBeNull()
            ->and($import->report_object_key)->toBeNull();
    });
});

test('un lote no ejecutado se puede descartar; uno ejecutado no', function (): void {
    [$tenant, $admin] = provisionCoreTenant('import-destroy');

    $file = UploadedFile::fake()->createWithContent('personal.csv', coreImportCsv());
    $store = test()->actingAs($admin)
        ->call('POST', coreApiUrl($tenant->slug, '/user-imports'), [], [], ['file' => $file])
        ->assertStatus(202);

    $importId = $store->json('public_id');

    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, "/user-imports/{$importId}"))
        ->assertNoContent();

    $file2 = UploadedFile::fake()->createWithContent('personal.csv', coreImportCsv());
    $store2 = test()->actingAs($admin)
        ->call('POST', coreApiUrl($tenant->slug, '/user-imports'), [], [], ['file' => $file2])
        ->assertStatus(202);

    $importId2 = $store2->json('public_id');

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/user-imports/{$importId2}/execute"), [], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(202);

    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, "/user-imports/{$importId2}"))
        ->assertStatus(409);
});

// Issue #53 (hallazgo de security-reviewer, CA-CORE-073/INV-001): un
// administrador del tenant A, autenticado en su propio host, que pide
// por public_id un lote de importación del tenant B recibe 404 (nunca
// 403: no se confirma la existencia del recurso ajeno) en show, execute
// y destroy — mismo patrón que el CA-CORE-073 ya existente para /users.
test('un lote de importación de otro tenant devuelve 404 en show, execute y destroy', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('import-tenant-a');
    [$tenantB, $adminB] = provisionCoreTenant('import-tenant-b');

    $file = UploadedFile::fake()->createWithContent('personal.csv', coreImportCsv());
    $store = test()->actingAs($adminB)
        ->call('POST', coreApiUrl($tenantB->slug, '/user-imports'), [], [], ['file' => $file])
        ->assertStatus(202);

    $foreignImportId = $store->json('public_id');

    test()->actingAs($adminA)
        ->getJson(coreApiUrl($tenantA->slug, "/user-imports/{$foreignImportId}"))
        ->assertStatus(404);

    test()->actingAs($adminA)
        ->postJson(
            coreApiUrl($tenantA->slug, "/user-imports/{$foreignImportId}/execute"),
            [],
            ['Idempotency-Key' => (string) Str::ulid()],
        )
        ->assertStatus(404);

    test()->actingAs($adminA)
        ->deleteJson(coreApiUrl($tenantA->slug, "/user-imports/{$foreignImportId}"))
        ->assertStatus(404);
});

test('sin usuario.importar, POST /user-imports devuelve 403', function (): void {
    [$tenant] = provisionCoreTenant('import-403');
    $docente = app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create(['contact_email' => 'd@example.com']);
        $user = User::factory()->for($person)->create(['email' => 'd@example.com']);
        $role = Role::where('code', 'docente')->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    });

    $file = UploadedFile::fake()->createWithContent('personal.csv', coreImportCsv());

    test()->actingAs($docente)
        ->call('POST', coreApiUrl($tenant->slug, '/user-imports'), [], [], ['file' => $file])
        ->assertStatus(403);
});
