<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Modules\Core\Domain\PersonCorrection;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\Core\Domain\PersonNotCorrectableException;
use App\Modules\Core\Domain\PersonRegistrar;
use App\Modules\Core\Domain\PersonRegistration;
use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

// ADR-068 §5.2 (D2), CA-068-06, OPEN-068-02, OPEN-068-05, OPEN-068-07, RN-FAM-UNIT-89, RN-CORE-90 a -93, INV-001, INV-003:
// PersonRegistrar (alta o reutilización de una persona sin cuenta, y su corrección) y las ampliaciones de PersonDirectory.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array{0: object, 1: object} dos centros aprovisionados */
function registrarTenants(string $a, string $b): array
{
    [$tenantA] = provisionCoreTenant($a);
    [$tenantB] = provisionCoreTenant($b);

    return [$tenantA, $tenantB];
}

function inTenant(object $tenant, Closure $callback): mixed
{
    return app(TenantContext::class)->runFor($tenant->id, $callback);
}

test('ADR-068 §5.2: registerOrReuse crea una persona sin cuenta con el documento canónico y el idioma del centro, y la audita Core', function (): void {
    [$tenant] = registrarTenants('reg-crea', 'reg-crea-b');

    $outcome = inTenant($tenant, fn () => app(PersonRegistrar::class)->registerOrReuse(
        new PersonRegistration(' Ana ', 'Pérez', 'Ruiz', 'dni', ' 12345678-z ', ' 600 000 000 '),
    ));

    expect($outcome->reused)->toBeFalse()->and($outcome->dataMismatch)->toBeFalse();

    $person = inTenant($tenant, fn () => Person::query()->findOrFail($outcome->personId));

    expect($person->given_name)->toBe('Ana')
        ->and($person->family_name_1)->toBe('Pérez')
        ->and($person->family_name_2)->toBe('Ruiz')
        ->and($person->document_type)->toBe('dni')
        ->and($person->document_number)->toBe('12345678Z')
        ->and($person->contact_phone)->toBe('600 000 000')
        ->and($person->contact_email)->toBeNull()
        ->and($person->birth_date)->toBeNull()
        ->and($person->public_id)->toBe($outcome->publicId)
        ->and(inTenant($tenant, fn () => DB::table('users')->where('person_id', $outcome->personId)->exists()))->toBeFalse();

    // INV-003: el alta de la persona la audita Core por su ciclo de vida.
    $log = inTenant($tenant, fn () => AuditLog::query()->where('auditable_type', 'person')->where('auditable_id', $outcome->personId)->where('event', 'created')->first());
    expect($log)->not->toBeNull();
});

test('ADR-068 §5.2: sin documento siempre crea (no hay clave con la que reutilizar)', function (): void {
    [$tenant] = registrarTenants('reg-sindoc', 'reg-sindoc-b');
    $registrar = app(PersonRegistrar::class);

    [$one, $two] = inTenant($tenant, fn () => [
        $registrar->registerOrReuse(new PersonRegistration('Luis', 'Gómez')),
        $registrar->registerOrReuse(new PersonRegistration('Luis', 'Gómez')),
    ]);

    expect($one->personId)->not->toBe($two->personId)->and($one->reused)->toBeFalse()->and($two->reused)->toBeFalse();
});

test('CA-068-06 CA-FAM-UNIT-126 RN-FAM-UNIT-89: reutilizar una persona existente con otro nombre y teléfono no cambia ninguna columna de people y devuelve reused y dataMismatch', function (): void {
    [$tenant] = registrarTenants('reg-reuse', 'reg-reuse-b');

    $existing = inTenant($tenant, fn () => Person::factory()->create([
        'given_name' => 'María', 'family_name_1' => 'Soler', 'family_name_2' => null,
        'document_type' => 'dni', 'document_number' => '12345678Z', 'contact_phone' => '611111111', 'contact_email' => null,
    ]));
    $before = inTenant($tenant, fn () => (array) DB::table('people')->where('id', $existing->id)->first());
    $auditBefore = inTenant($tenant, fn () => AuditLog::query()->where('auditable_type', 'person')->where('auditable_id', $existing->id)->count());
    $peopleBefore = inTenant($tenant, fn () => DB::table('people')->count());

    $outcome = inTenant($tenant, fn () => app(PersonRegistrar::class)->registerOrReuse(
        new PersonRegistration('Marta', 'Solé', 'Vidal', 'dni', '12345678z', '622222222'),
    ));

    $after = inTenant($tenant, fn () => (array) DB::table('people')->where('id', $existing->id)->first());

    expect($outcome->reused)->toBeTrue()
        ->and($outcome->dataMismatch)->toBeTrue()
        ->and($outcome->personId)->toBe((int) $existing->id)
        ->and($outcome->publicId)->toBe($existing->public_id)
        ->and($after)->toBe($before)
        ->and(inTenant($tenant, fn () => DB::table('people')->count()))->toBe($peopleBefore)
        ->and(inTenant($tenant, fn () => AuditLog::query()->where('auditable_type', 'person')->where('auditable_id', $existing->id)->count()))->toBe($auditBefore);
});

test('ADR-068 §5.2: reutilizar con los mismos datos aportados no marca discrepancia; los datos que no se dan no cuentan', function (): void {
    [$tenant] = registrarTenants('reg-same', 'reg-same-b');

    inTenant($tenant, fn () => Person::factory()->create([
        'given_name' => 'María', 'family_name_1' => 'Soler', 'family_name_2' => 'Pons',
        'document_type' => 'dni', 'document_number' => '12345678Z', 'contact_phone' => '611111111',
    ]));
    $registrar = app(PersonRegistrar::class);

    $same = inTenant($tenant, fn () => $registrar->registerOrReuse(new PersonRegistration('María', 'Soler', null, 'dni', '12345678Z', null)));
    $other = inTenant($tenant, fn () => $registrar->registerOrReuse(new PersonRegistration('María', 'Soler', 'Pons', 'dni', '12345678Z', '611111111')));
    $differs = inTenant($tenant, fn () => $registrar->registerOrReuse(new PersonRegistration('María', 'Soler', 'Pons', 'dni', '12345678Z', '699999999')));

    expect($same->reused)->toBeTrue()->and($same->dataMismatch)->toBeFalse()
        ->and($other->dataMismatch)->toBeFalse()
        ->and($differs->dataMismatch)->toBeTrue();
});

test('ADR-068 §5.2: reutiliza también a la persona de un usuario (la elegibilidad es de fam_unit) y no la de un borrado ni la de otro centro', function (): void {
    [$tenantA, $tenantB] = registrarTenants('reg-user', 'reg-user-b');
    $registrar = app(PersonRegistrar::class);

    $adminPerson = inTenant($tenantA, fn () => Person::query()->whereHas('user')->firstOrFail());
    inTenant($tenantA, fn () => Person::query()->whereKey($adminPerson->id)->update(['document_type' => 'dni', 'document_number' => '87654321X']));

    $reused = inTenant($tenantA, fn () => $registrar->registerOrReuse(new PersonRegistration('Otra', 'Cosa', null, 'dni', '87654321X')));
    expect($reused->reused)->toBeTrue()->and($reused->personId)->toBe((int) $adminPerson->id);

    // Un centro no ve el documento del otro: crea una persona propia con el mismo documento.
    $other = inTenant($tenantB, fn () => $registrar->registerOrReuse(new PersonRegistration('Otra', 'Cosa', null, 'dni', '87654321X')));
    expect($other->reused)->toBeFalse()->and($other->personId)->not->toBe((int) $adminPerson->id);

    // Una persona borrada no se reutiliza: el índice único es parcial (`deleted_at IS NULL`).
    inTenant($tenantB, fn () => Person::query()->whereKey($other->personId)->firstOrFail()->delete());
    $again = inTenant($tenantB, fn () => $registrar->registerOrReuse(new PersonRegistration('Otra', 'Cosa', null, 'dni', '87654321X')));
    expect($again->reused)->toBeFalse()->and($again->personId)->not->toBe($other->personId);
});

test('RN-CORE-90 a -92: tipo fuera del catálogo, par incompleto y número inválido son 422 y no escriben nada', function (): void {
    [$tenant] = registrarTenants('reg-422', 'reg-422-b');
    $registrar = app(PersonRegistrar::class);
    $count = fn () => inTenant($tenant, fn () => DB::table('people')->count());
    $before = $count();

    foreach ([
        ['carnet', 'ABC123', 'document_type'],
        ['dni', null, 'document_number'],
        [null, '12345678Z', 'document_type'],
        ['dni', '12345678A', 'document_number'],
    ] as [$type, $number, $field]) {
        try {
            inTenant($tenant, fn () => $registrar->registerOrReuse(new PersonRegistration('Ana', 'Pérez', null, $type, $number)));
            $thrown = null;
        } catch (ApiException $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull()->and($thrown->status)->toBe(422)->and(array_keys($thrown->errors))->toBe([$field]);
    }

    expect($count())->toBe($before)
        ->and(fn () => inTenant($tenant, fn () => $registrar->registerOrReuse(new PersonRegistration('  ', 'Pérez'))))->toThrow(InvalidArgumentException::class);
});

test('OPEN-068-07 CA-FAM-UNIT-130: correctUnaccounted corrige nombre, apellidos y teléfono de una persona sin usuario, no toca el documento y lo audita Core', function (): void {
    [$tenant] = registrarTenants('reg-fix', 'reg-fix-b');
    $registrar = app(PersonRegistrar::class);

    $created = inTenant($tenant, fn () => $registrar->registerOrReuse(new PersonRegistration('Ana', 'Perez', 'Ruiz', 'dni', '12345678Z', '600000000')));
    $before = inTenant($tenant, fn () => (array) DB::table('people')->where('id', $created->personId)->first());

    inTenant($tenant, fn () => $registrar->correctUnaccounted($created->personId, new PersonCorrection([
        'family_name_1' => ' Pérez ', 'contact_phone' => '611111111', 'family_name_2' => null,
    ])));

    $after = inTenant($tenant, fn () => (array) DB::table('people')->where('id', $created->personId)->first());

    expect($after['family_name_1'])->toBe('Pérez')
        ->and($after['contact_phone'])->toBe('611111111')
        ->and($after['family_name_2'])->toBeNull()
        ->and($after['given_name'])->toBe('Ana')
        ->and($after['document_type'])->toBe($before['document_type'])
        ->and($after['document_number'])->toBe($before['document_number'])
        ->and(inTenant($tenant, fn () => AuditLog::query()->where('auditable_type', 'person')->where('auditable_id', $created->personId)->where('event', 'updated')->count()))->toBe(1);
});

test('OPEN-068-07: correctUnaccounted lanza sobre una persona con usuario, inexistente, borrada o de otro centro, sin modificar nada', function (): void {
    [$tenantA, $tenantB] = registrarTenants('reg-fix2', 'reg-fix2-b');
    $registrar = app(PersonRegistrar::class);
    $correction = new PersonCorrection(['contact_phone' => '699999999']);

    $withUser = inTenant($tenantA, fn () => Person::query()->whereHas('user')->firstOrFail());
    $phoneBefore = $withUser->contact_phone;

    $foreign = inTenant($tenantB, fn () => $registrar->registerOrReuse(new PersonRegistration('Ana', 'Perez')));
    $deleted = inTenant($tenantA, fn () => $registrar->registerOrReuse(new PersonRegistration('Borrada', 'Perez')));
    inTenant($tenantA, fn () => Person::query()->whereKey($deleted->personId)->firstOrFail()->delete());

    foreach ([(int) $withUser->id, $foreign->personId, $deleted->personId, 987654321] as $personId) {
        expect(fn () => inTenant($tenantA, fn () => $registrar->correctUnaccounted($personId, $correction)))->toThrow(PersonNotCorrectableException::class);
    }

    expect(inTenant($tenantA, fn () => Person::query()->findOrFail($withUser->id)->contact_phone))->toBe($phoneBefore)
        ->and(inTenant($tenantB, fn () => Person::query()->findOrFail($foreign->personId)->contact_phone))->toBeNull();
});

test('ADR-068 §5.2: PersonCorrection exige al menos un campo, solo los permitidos (nunca el documento) y no deja vacíos nombre ni primer apellido', function (): void {
    expect(fn () => new PersonCorrection([]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PersonCorrection(['document_number' => '12345678Z']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PersonCorrection(['document_type' => 'dni']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PersonCorrection(['given_name' => ' ']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PersonCorrection(['family_name_1' => null]))->toThrow(InvalidArgumentException::class)
        ->and((new PersonCorrection(['contact_phone' => null]))->changes)->toBe(['contact_phone' => null]);
});

test('OPEN-068-02 H9: searchByNameAmong busca por prefijo de nombre o apellidos, sin mayúsculas, acotado a los ids dados, con comodines escapados y solo vivas', function (): void {
    [$tenant, $other] = registrarTenants('dir-search', 'dir-search-b');
    $directory = app(PersonDirectory::class);

    $make = fn (string $given, string $family1, ?string $family2 = null) => inTenant($tenant, fn () => Person::factory()->create(['given_name' => $given, 'family_name_1' => $family1, 'family_name_2' => $family2]));
    $ana = $make('Ana', 'Pérez', 'Soler');
    $anabel = $make('Anabel', 'Gil');
    $luis = $make('Luis', 'Anaya');
    $paco = $make('Paco', 'Díaz', 'Ana');
    $porcentaje = $make('100%', 'Real');
    $borrada = $make('Anastasia', 'Muerta');
    inTenant($tenant, fn () => $borrada->delete());
    $foreign = inTenant($other, fn () => Person::factory()->create(['given_name' => 'Ana', 'family_name_1' => 'Ajena']));

    $ids = [(int) $ana->id, (int) $anabel->id, (int) $luis->id, (int) $paco->id, (int) $porcentaje->id, (int) $borrada->id, (int) $foreign->id];
    $names = fn (string $term, ?array $among = null, int $limit = 10) => array_map(
        static fn ($ref) => $ref->displayName,
        inTenant($tenant, fn () => $directory->searchByNameAmong($term, $among ?? $ids, $limit)),
    );

    // Prefijo de nombre, primer apellido, segundo apellido; sin distinguir mayúsculas; ordenado por nombre visible; sin borrados ni ajenos.
    expect($names('ana'))->toBe(['Ana Pérez', 'Anabel Gil', 'Luis Anaya', 'Paco Díaz'])
        // Nombre visible: «Nombre Apellido».
        ->and($names('Ana Pé'))->toBe(['Ana Pérez'])
        // Acotada a los ids dados.
        ->and($names('ana', [(int) $anabel->id]))->toBe(['Anabel Gil'])
        ->and($names('ana', []))->toBe([])
        // Límite.
        ->and($names('ana', null, 2))->toBe(['Ana Pérez', 'Anabel Gil'])
        // Comodines escapados: «%» y «_» son literales.
        ->and($names('100%'))->toBe(['100% Real'])
        ->and($names('%'))->toBe([])
        ->and($names('_'))->toBe([])
        ->and($names(''))->toBe([])
        // Sin plegado de acentos (hecho 17 de ADR-068): «Perez» no encuentra «Pérez».
        ->and($names('Perez'))->toBe([]);
});

test('OPEN-068-05: findByDocument encuentra a la persona viva del centro por su documento canónico, y no a la de otro centro ni a la borrada', function (): void {
    [$tenant, $other] = registrarTenants('dir-doc', 'dir-doc-b');
    $directory = app(PersonDirectory::class);

    $person = inTenant($tenant, fn () => Person::factory()->create(['document_type' => 'dni', 'document_number' => '12345678Z']));
    inTenant($other, fn () => Person::factory()->create(['document_type' => 'dni', 'document_number' => '87654321X']));

    $found = inTenant($tenant, fn () => $directory->findByDocument('dni', ' 12345678-z '));
    expect($found?->id)->toBe((int) $person->id)->and($found?->publicId)->toBe($person->public_id);

    expect(inTenant($tenant, fn () => $directory->findByDocument('dni', '87654321X')))->toBeNull()
        ->and(inTenant($tenant, fn () => $directory->findByDocument('carnet', '12345678Z')))->toBeNull()
        ->and(inTenant($tenant, fn () => $directory->findByDocument('dni', '  ')))->toBeNull();

    inTenant($tenant, fn () => $person->delete());
    expect(inTenant($tenant, fn () => $directory->findByDocument('dni', '12345678Z')))->toBeNull();
});

test('OPEN-068-05: contactDetailsByIds devuelve teléfono y documento completos de las vivas del centro, en una consulta, sin las borradas ni ajenas', function (): void {
    [$tenant, $other] = registrarTenants('dir-contact', 'dir-contact-b');
    $directory = app(PersonDirectory::class);

    $with = inTenant($tenant, fn () => Person::factory()->create(['document_type' => 'dni', 'document_number' => '12345678Z', 'contact_phone' => '600000000']));
    $without = inTenant($tenant, fn () => Person::factory()->create(['contact_phone' => null]));
    $deleted = inTenant($tenant, fn () => Person::factory()->create(['contact_phone' => '633333333']));
    inTenant($tenant, fn () => $deleted->delete());
    $foreign = inTenant($other, fn () => Person::factory()->create(['contact_phone' => '644444444']));

    DB::connection()->enableQueryLog();
    $contacts = inTenant($tenant, fn () => $directory->contactDetailsByIds([(int) $with->id, (int) $without->id, (int) $deleted->id, (int) $foreign->id]));
    $selects = collect(DB::connection()->getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'from "people"'))->count();
    DB::connection()->disableQueryLog();

    expect(array_keys($contacts))->toEqualCanonicalizing([(int) $with->id, (int) $without->id])
        ->and($contacts[(int) $with->id]->contactPhone)->toBe('600000000')
        ->and($contacts[(int) $with->id]->documentType)->toBe('dni')
        ->and($contacts[(int) $with->id]->documentNumber)->toBe('12345678Z')
        ->and($contacts[(int) $without->id]->contactPhone)->toBeNull()
        ->and($contacts[(int) $without->id]->documentNumber)->toBeNull()
        ->and($selects)->toBe(1)
        ->and(inTenant($tenant, fn () => $directory->contactDetailsByIds([])))->toBe([]);
});
