<?php

use App\Models\PermissionRole;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Domain\Models\UserImport;
use App\Modules\Core\Infrastructure\Jobs\ValidateUserImport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// REQ-CORE-003, paso 1.9c: importación con catálogo cerrado de tipos de
// documento (CA-CORE-278, #308/#309/#310), `created_at` (S8, CA-CORE-238)
// e idioma de los mensajes (S9, OPEN-CORE-38 = A, #285, CA-CORE-239).

beforeEach(function (): void {
    Mail::fake();
    Storage::fake('local');
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/**
 * @param  list<array{0: string, 1: string, 2?: string, 3?: string}>  $rows  email, nombre, tipo, número
 */
function uicCsv(array $rows): string
{
    $lines = ['email;given_name;family_name_1;family_name_2;document_type;document_number;birth_date;contact_email;contact_phone;locale;roles'];

    foreach ($rows as $row) {
        $lines[] = implode(';', [$row[0], $row[1], 'Sintetica', '', $row[2] ?? '', $row[3] ?? '', '', '', '', '', '']);
    }

    return implode("\n", $lines);
}

function uicUpload(object $tenant, User $admin, string $csv): string
{
    $file = UploadedFile::fake()->createWithContent('personal.csv', $csv);

    return test()->actingAs($admin)
        ->call('POST', coreApiUrl($tenant->slug, '/user-imports'), [], [], ['file' => $file])
        ->assertStatus(202)
        ->json('public_id');
}

/**
 * @return array<string, mixed>
 */
function uicShow(object $tenant, User $admin, string $importId): array
{
    return test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, "/user-imports/{$importId}"))->assertOk()->json();
}

// CA-CORE-278
test('CA-CORE-278 (RN-CORE-90 a 92, OPEN-CORE-50 = A, #308 F1/F3, #310 F4): tipo desconocido, grafía tolerante, par incompleto y duplicados sobre el valor normalizado', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uic-278');
    config(['core.documents.validate_check_digit' => false]);

    // Una persona viva del centro con el documento 11111111H.
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'existente@example.com',
        'person' => ['given_name' => 'Ya', 'family_name_1' => 'Existe', 'document_type' => 'dni', 'document_number' => '11111111H'],
        'send_invitation' => false,
    ])->assertCreated();

    $importId = uicUpload($tenant, $admin, uicCsv([
        ['desconocido@example.com', 'Desconocido', 'carnet', 'ZZ999'],            // línea 2
        ['grafia@example.com', 'Grafia', ' DNI ', '12345678-z'],                  // línea 3: válida, se guarda canónica
        ['uno@example.com', 'Uno', 'dni', '87654321X'],                           // línea 4
        ['dos@example.com', 'Dos', 'DNI', '87654321-x'],                          // línea 5: duplicado_en_fichero
        ['basedatos@example.com', 'BD', 'DNI', '11111111-h'],                     // línea 6: duplicado_en_base_de_datos
        ['sintipo@example.com', 'SinTipo', '', 'AB1234567'],                      // línea 7: documento_incompleto (document_type)
        ['sinnumero@example.com', 'SinNumero', 'pasaporte', ''],                  // línea 8: documento_incompleto (document_number)
        ['pasaporte@example.com', 'Pasaporte', 'Pasaporte', ' ab1234567 '],       // línea 9: válida
        ['ninguno@example.com', 'Ninguno'],                                       // línea 10: válida sin documento
    ]));

    $show = uicShow($tenant, $admin, $importId);
    $errors = collect($show['error_summary']);

    expect($show['status'])->toBe('validado')
        ->and($show['row_count'])->toBe(9)
        ->and($show['error_count'])->toBe(5);

    $by = fn (int $line) => $errors->firstWhere('line', $line);

    expect($by(2)['code'])->toBe('tipo_documento_no_valido')->and($by(2)['column'])->toBe('document_type')
        ->and($by(3))->toBeNull()
        ->and($by(4))->toBeNull()
        ->and($by(5)['code'])->toBe('duplicado_en_fichero')->and($by(5)['column'])->toBe('document_number')
        ->and($by(6)['code'])->toBe('duplicado_en_base_de_datos')
        ->and($by(7)['code'])->toBe('documento_incompleto')->and($by(7)['column'])->toBe('document_type')
        ->and($by(8)['code'])->toBe('documento_incompleto')->and($by(8)['column'])->toBe('document_number')
        ->and($by(9))->toBeNull();

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/user-imports/{$importId}/execute"), [], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(202);

    $stored = app(TenantContext::class)->runFor($tenant->id, fn () => Person::query()
        ->whereIn('given_name', ['Grafia', 'Uno', 'Pasaporte', 'Ninguno'])
        ->orderBy('given_name')
        ->get(['given_name', 'document_type', 'document_number'])
        ->map(fn ($person) => [$person->given_name, $person->document_type, $person->document_number])
        ->all());

    expect($stored)->toBe([
        ['Grafia', 'dni', '12345678Z'],
        ['Ninguno', null, null],
        ['Pasaporte', 'pasaporte', 'AB1234567'],
        ['Uno', 'dni', '87654321X'],
    ]);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(User::query()->whereIn('email', ['dos@example.com', 'desconocido@example.com', 'sintipo@example.com'])->count())->toBe(0);
    });
});

test('CA-CORE-278 (§14.6.4.6): un lote validado antes del catálogo se revalida al ejecutar con las reglas nuevas', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uic-278b');
    config(['core.documents.validate_check_digit' => false]);

    $importId = uicUpload($tenant, $admin, uicCsv([
        ['valida@example.com', 'Valida', 'dni', '12345678Z'],
        ['otra@example.com', 'Otra', 'dni', '12345678-z'],
    ]));

    // Entre la validación y la ejecución, otra vía da de alta ese documento.
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'ganadora@example.com',
        'person' => ['given_name' => 'Ganadora', 'family_name_1' => 'Primera', 'document_type' => 'dni', 'document_number' => '12345678Z'],
        'send_invitation' => false,
    ])->assertCreated();

    test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/user-imports/{$importId}/execute"), [], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(202);

    expect(uicShow($tenant, $admin, $importId)['created_count'])->toBe(0);
});

// CA-CORE-238
test('CA-CORE-238 (S8): GET /user-imports y GET /user-imports/{id} incluyen created_at en ISO 8601 UTC', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uic-238');

    $importId = uicUpload($tenant, $admin, uicCsv([['uno@example.com', 'Uno']]));

    $show = uicShow($tenant, $admin, $importId);
    $list = test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/user-imports'))->assertOk()->json('data.0');

    foreach ([$show, $list] as $resource) {
        expect($resource['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/');
        expect(Carbon\Carbon::parse($resource['created_at'])->utc()->isValid())->toBeTrue();
    }
});

/**
 * Vuelve a validar el lote en un proceso cuyo idioma es otro (`de`), como
 * un worker de cola: el resultado no puede depender del idioma del proceso.
 */
function uicRevalidateAs(object $tenant, string $importId, string $processLocale): void
{
    app(TenantContext::class)->runFor($tenant->id, function () use ($tenant, $importId, $processLocale): void {
        $import = UserImport::query()->where('public_id', $importId)->firstOrFail();
        $import->update(['status' => 'subido']);

        App::setLocale($processLocale);
        app()->call([new ValidateUserImport($import->id, $tenant->public_id), 'handle']);
    });
}

// CA-CORE-239
test('CA-CORE-239 (S9, OPEN-CORE-38 = A, #285): los mensajes salen en el idioma de quien subió el lote, también en report.csv, y el idioma del proceso se restaura', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uic-239');

    test()->actingAs($admin)->patchJson(coreApiUrl($tenant->slug, '/tenant/settings'), [
        'regional' => ['active_locales' => ['es-ES', 'fr']],
    ])->assertOk();

    $importId = uicUpload($tenant, $admin, uicCsv([
        ['duplicado@example.com', 'Uno'],
        ['duplicado@example.com', 'Dos'],
    ]));

    app(TenantContext::class)->runFor($tenant->id, fn () => $admin->person->update(['locale' => 'fr']));

    uicRevalidateAs($tenant, $importId, 'de');

    expect(App::getLocale())->toBe('de');

    $show = uicShow($tenant, $admin, $importId);
    $message = collect($show['error_summary'])->firstWhere('code', 'duplicado_en_fichero')['message'];

    expect($message)->toContain('apparaît plus d\'une fois');

    $reportKey = app(TenantContext::class)->runFor($tenant->id, fn () => UserImport::where('public_id', $importId)->firstOrFail()->report_object_key);

    expect(Storage::disk('local')->get($reportKey))->toContain('apparaît plus d\'une fois');
});

test('CA-CORE-239 (S9, RN-CORE-34): con un idioma que ya no está activo en el centro se usa el idioma por defecto del centro', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uic-239b');

    $importId = uicUpload($tenant, $admin, uicCsv([
        ['duplicado@example.com', 'Uno'],
        ['duplicado@example.com', 'Dos'],
    ]));

    // `fr` no está activo (por defecto solo `es-ES`): decide `default_locale`.
    app(TenantContext::class)->runFor($tenant->id, fn () => $admin->person->update(['locale' => 'fr']));

    uicRevalidateAs($tenant, $importId, 'de');

    $message = collect(uicShow($tenant, $admin, $importId)['error_summary'])->firstWhere('code', 'duplicado_en_fichero')['message'];

    expect($message)->toContain('aparece más de una vez en el fichero');
});

test('CA-CORE-239 (S9, #285): el mensaje de cabecera desconocida también sale en el idioma de quien subió el lote', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uic-239c');

    test()->actingAs($admin)->patchJson(coreApiUrl($tenant->slug, '/tenant/settings'), [
        'regional' => ['active_locales' => ['es-ES', 'en']],
    ])->assertOk();

    $file = UploadedFile::fake()->createWithContent('personal.csv', "nombre,apellido\nAna,Perez");
    $importId = test()->actingAs($admin)
        ->call('POST', coreApiUrl($tenant->slug, '/user-imports'), [], [], ['file' => $file])
        ->assertStatus(202)
        ->json('public_id');

    app(TenantContext::class)->runFor($tenant->id, fn () => $admin->person->update(['locale' => 'en']));

    uicRevalidateAs($tenant, $importId, 'de');

    $show = uicShow($tenant, $admin, $importId);

    expect($show['status'])->toBe('fallido')
        ->and($show['error_summary'][0]['message'])->toBe('The file header does not match the expected format.');
});

// S8 (ampliación aditiva), RN-CORE-73, CA-CORE-235
test('CA-CORE-235 (S8): GET /user-imports y GET /user-imports/{id} devuelven send_invitations tal como se eligió al subir', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uic-235');

    foreach ([true, false] as $choice) {
        $file = UploadedFile::fake()->createWithContent('personal.csv', uicCsv([['uno@example.com', 'Uno']]));
        $id = test()->actingAs($admin)
            ->call('POST', coreApiUrl($tenant->slug, '/user-imports'), ['send_invitations' => $choice ? '1' : '0'], [], ['file' => $file])
            ->assertStatus(202)
            ->json('public_id');

        expect(uicShow($tenant, $admin, $id)['send_invitations'])->toBe($choice);

        $row = collect(test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/user-imports'))->json('data'))->firstWhere('public_id', $id);
        expect($row['send_invitations'])->toBe($choice);
    }
});

// CA-CORE-286
test('CA-CORE-286 (RNF-LIM-004, #313): un fichero con más filas que core.import_max_rows deja el lote fallido con limite_filas_superado y sin validar ninguna fila', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uic-286');
    config(['core.import_max_rows' => 2]);

    $importId = uicUpload($tenant, $admin, uicCsv([
        ['a@example.com', 'A'],
        ['b@example.com', 'B'],
        ['c@example.com', 'C'],
    ]));

    $import = uicShow($tenant, $admin, $importId);

    expect($import['status'])->toBe('fallido')
        ->and($import['row_count'])->toBe(0)
        ->and($import['error_summary'][0]['code'])->toBe('limite_filas_superado')
        ->and($import['error_summary'][0]['message'])->toContain('2');

    // En el tope exacto sigue siendo válido.
    $okId = uicUpload($tenant, $admin, uicCsv([
        ['d@example.com', 'D'],
        ['e@example.com', 'E'],
    ]));

    expect(uicShow($tenant, $admin, $okId)['status'])->toBe('validado');
});

function uicUserWithRole(object $tenant, string $email, string $roleCode): User
{
    return app(TenantContext::class)->runFor($tenant->id, function () use ($email, $roleCode): User {
        $user = User::factory()->for(Person::factory()->create(['contact_email' => $email]))->create(['email' => $email]);
        $user->roles()->attach(Role::where('code', $roleCode)->firstOrFail()->id);

        return $user;
    });
}

// CA-CORE-287
test('CA-CORE-287 (RPERM-013, #314): una fila con un rol que el actor no puede conceder se reporta en la fase 1 como rol_no_concedible y no se crea', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uic-287');
    $secretaria = uicUserWithRole($tenant, 'secretaria-287@example.com', 'secretaria');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $role = Role::where('code', 'secretaria')->firstOrFail();
        foreach (['usuario.importar', 'usuario.crear', 'asignacion_rol.crear'] as $code) {
            PermissionRole::create(['role_id' => $role->id, 'permission_code' => $code, 'effect' => 'allow', 'scope' => 'todos']);
        }
    });

    $lines = [
        'email;given_name;family_name_1;family_name_2;document_type;document_number;birth_date;contact_email;contact_phone;locale;roles',
        'concedible@example.com;Ok;Sintetica;;;;;;;;secretaria',
        'prohibido@example.com;No;Sintetica;;;;;;;;administrador_centro',
    ];
    $importId = uicUpload($tenant, $secretaria, implode("\n", $lines));

    $import = uicShow($tenant, $secretaria, $importId);

    expect($import['status'])->toBe('validado')
        ->and($import['error_count'])->toBe(1)
        ->and($import['error_summary'][0]['line'])->toBe(3)
        ->and($import['error_summary'][0]['code'])->toBe('rol_no_concedible');

    test()->actingAs($secretaria)
        ->postJson(coreApiUrl($tenant->slug, "/user-imports/{$importId}/execute"), [], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(202);

    expect(uicShow($tenant, $secretaria, $importId)['created_count'])->toBe(1)
        ->and(app(TenantContext::class)->runFor($tenant->id, fn () => User::query()->where('email', 'prohibido@example.com')->exists()))->toBeFalse();
});

// CA-CORE-299 (issue #339, INV-002): sin quien subió el lote, la validación falla en cerrado
test('CA-CORE-299: si quien subió el lote ya no resuelve al validarse, el lote queda fallido y no se valida con comprobaciones de menos', function (): void {
    [$tenant, $admin] = provisionCoreTenant('uic-299');

    $importId = uicUpload($tenant, $admin, uicCsv([['uno@example.com', 'Uno']]));

    app(TenantContext::class)->runFor($tenant->id, function () use ($tenant, $importId, $admin): void {
        $import = UserImport::query()->where('public_id', $importId)->firstOrFail();
        $import->update(['status' => 'subido', 'error_count' => 0, 'row_count' => null]);

        User::query()->whereKey($admin->id)->firstOrFail()->delete();

        app()->call([new ValidateUserImport($import->id, $tenant->public_id), 'handle']);

        $import = $import->fresh();

        expect($import->status)->toBe('fallido')
            ->and($import->validated_at)->not->toBeNull()
            ->and($import->row_count)->toBeNull();
    });
});
