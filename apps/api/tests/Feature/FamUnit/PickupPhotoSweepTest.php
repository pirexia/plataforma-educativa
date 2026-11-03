<?php

use App\Models\Person;
use App\Modules\FamUnit\Application\PickupListService;
use App\Modules\FamUnit\Infrastructure\Jobs\PurgeOrphanPickupPhotos;
use App\Support\Files\PrivateObjectStore;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-005 (1.14b-a), docs/modulos/REQ-FAM-UNIT/datos.md §8.8, operacion.md §12.3, OPEN-FAM-UNIT-37, ADR-068 §5.3, RN-FAM-UNIT-83:
// barrido de fotografías de recogida huérfanas, con plazo de gracia (30 días por defecto).

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Adelanta el reloj `$days` días desde el momento real en que se escribieron los objetos.
 *
 * El barrido compara `lastModified()` (fecha real del fichero en el disco falso) con `now()`; anclar el reloj a una
 * fecha fija del pasado hacía fallar el test en cuanto el reloj real superaba esa fecha (CI del 2026-11-03, #515).
 */
function sweepAfter(int $days, object $tenant): void
{
    test()->travelTo(CarbonImmutable::createFromTimestamp(time())->addDays($days));
    app(TenantContext::class)->runFor($tenant->id, function () use ($tenant): void {
        PurgeOrphanPickupPhotos::dispatch($tenant->id);
    });
}

test('datos.md §8.8 operacion.md §12.3: el barrido borra el objeto sin fila viva pasado el plazo de gracia, conserva el referenciado y el joven, y no toca el prefijo de otro centro', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('sw-objetos');
    $other = Fam::pickupFamily('sw-objetos-b');
    $third = Fam::thirdParty($f, 1);
    $otherThird = Fam::thirdParty($other, 1);

    $liveKey = app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_photos')->value('object_key'));
    $otherKey = app(TenantContext::class)->runFor($other['tenant']->id, fn () => DB::table('pickup_photos')->value('object_key'));

    // Una sustitución deja huérfano el objeto anterior; una escritura cuya alta falló deja otro sin fila.
    Fam::putPhoto($f['tenant'], $f['admin'], $third['person'])->assertOk();
    $keys = app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_photos')->orderBy('id')->pluck('object_key')->all());
    [$replaced, $current] = $keys;
    $lost = "tenants/{$f['tenant']->public_id}/fam-unit/pickup-photos/01JZZZZZZZZZZZZZZZZZZZZZZZ.jpg";
    Storage::disk('local')->put($lost, 'huerfano');
    $branding = "tenants/{$f['tenant']->public_id}/branding/logo/01JZZZZZZZZZZZZZZZZZZZZZZY.png";
    Storage::disk('local')->put($branding, 'logo');

    // A los 10 días, dentro del plazo de gracia: no se borra nada.
    sweepAfter(10, $f['tenant']);
    foreach ([$replaced, $current, $lost, $liveKey, $otherKey, $branding] as $key) {
        expect(Storage::disk('local')->exists($key))->toBeTrue($key);
    }

    // A los 31 días: caen el sustituido y el perdido; la viva, la del otro centro y el logotipo (otra área), no.
    sweepAfter(31, $f['tenant']);
    expect(Storage::disk('local')->exists($replaced))->toBeFalse()
        ->and(Storage::disk('local')->exists($lost))->toBeFalse()
        ->and(Storage::disk('local')->exists($current))->toBeTrue()
        ->and(Storage::disk('local')->exists($otherKey))->toBeTrue()
        ->and(Storage::disk('local')->exists($branding))->toBeTrue()
        ->and(app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_photos')->whereNull('deleted_at')->count()))->toBe(1)
        ->and($otherThird['person'])->toBeString();
});

test('OPEN-FAM-UNIT-37: la fotografía de un tercero sin autorización vigente ni futura pasado el plazo se da de baja y se purga; con autorización vigente, retirada o caducada hace poco, o siendo tutor, se conserva', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('sw-terceros');
    $vigente = Fam::thirdParty($f, 2);
    $retiradaAyer = Fam::thirdParty($f, 3);
    $retiradaAntiguaFutura = Fam::thirdParty($f, 4, overrides: ['valid_until' => '2026-11-20']);
    $caducada = Fam::thirdParty($f, 5, overrides: ['valid_until' => '2026-11-10']);
    $retiradaYa = Fam::thirdParty($f, 6);
    // Una persona cuya única autorización se dio de baja (sin autorización viva de ninguna clase).
    $sinNada = Fam::authorizedPerson($f, ['given_name' => 'Sin']);
    Fam::putPhoto($f['tenant'], $f['admin'], $sinNada)->assertOk();
    app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_authorizations')->where('person_id', DB::table('people')->where('public_id', $sinNada)->value('id'))->update(['deleted_at' => now()]));
    $tutor = app(TenantContext::class)->runFor($f['tenant']->id, fn () => Person::query()->findOrFail($f['other']->person_id));
    Fam::putPhoto($f['tenant'], $f['admin'], $tutor->public_id)->assertOk();

    Fam::call($f['tenant'], $f['admin'], 'post', "/pickup-authorizations/{$retiradaYa['authorization']}/withdrawal")->assertOk();
    Fam::call($f['tenant'], $f['admin'], 'post', "/pickup-authorizations/{$retiradaAyer['authorization']}/withdrawal")->assertOk();

    $keyOf = fn (string $person) => app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_photos')->join('people', 'people.id', '=', 'pickup_photos.person_id')->where('people.public_id', $person)->whereNull('pickup_photos.deleted_at')->value('object_key'));
    $live = fn (string $person) => app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_photos')->join('people', 'people.id', '=', 'pickup_photos.person_id')->where('people.public_id', $person)->whereNull('pickup_photos.deleted_at')->exists());

    $keys = ['vigente' => $keyOf($vigente['person']), 'caducada' => $keyOf($caducada['person']), 'retiradaYa' => $keyOf($retiradaYa['person']), 'sinNada' => $keyOf($sinNada), 'tutor' => $keyOf($tutor->public_id)];

    // Dentro del plazo de gracia, nada cambia.
    sweepAfter(20, $f['tenant']);
    expect($live($vigente['person']))->toBeTrue()->and($live($sinNada))->toBeTrue()->and($live($caducada['person']))->toBeTrue();

    // A los 45 días: el tercero sin nada, el de la autorización retirada hace más de 30 días y el de la caducada el 10 de noviembre
    // (hace más de 30 días) pierden la foto; la vigente sin caducidad y el tutor, no. Todas las autorizaciones siguen en su tabla.
    sweepAfter(45, $f['tenant']);
    expect($live($vigente['person']))->toBeTrue()
        ->and($live($tutor->public_id))->toBeTrue()
        ->and($live($sinNada))->toBeFalse()
        ->and($live($retiradaYa['person']))->toBeFalse()
        ->and($live($caducada['person']))->toBeFalse()
        ->and(Storage::disk('local')->exists($keys['vigente']))->toBeTrue()
        ->and(Storage::disk('local')->exists($keys['tutor']))->toBeTrue()
        ->and(Storage::disk('local')->exists($keys['sinNada']))->toBeFalse()
        ->and(Storage::disk('local')->exists($keys['retiradaYa']))->toBeFalse()
        ->and(Storage::disk('local')->exists($keys['caducada']))->toBeFalse()
        ->and(app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_authorizations')->count()))->toBe(6)
        // La baja de la foto queda auditada (INV-003), por el sistema y no por una persona.
        ->and(count(Fam::audit($f['tenant'], 'pickup_photo', 'deleted')))->toBeGreaterThanOrEqual(3)
        ->and(Fam::audit($f['tenant'], 'pickup_photo', 'deleted')[0]->actor_type)->toBe('system')
        ->and($retiradaAntiguaFutura['person'])->toBeString();

    // A los 100 días la autorización sin caducidad sigue vigente: su foto sigue, y la persona sigue figurando una vez repuestas.
    sweepAfter(100, $f['tenant']);
    expect($live($vigente['person']))->toBeTrue();
});

test('operacion.md §12.3: fam_unit:purge-orphan-photos despacha el barrido de cada centro activo', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $a = Fam::pickupFamily('sw-cmd-a');
    $b = Fam::pickupFamily('sw-cmd-b');
    $keyA = "tenants/{$a['tenant']->public_id}/fam-unit/pickup-photos/01JZZZZZZZZZZZZZZZZZZZZZZZ.jpg";
    $keyB = "tenants/{$b['tenant']->public_id}/fam-unit/pickup-photos/01JZZZZZZZZZZZZZZZZZZZZZZZ.jpg";
    Storage::disk('local')->put($keyA, 'a');
    Storage::disk('local')->put($keyB, 'b');

    test()->travelTo(CarbonImmutable::parse('2026-12-20 09:00:00'));
    test()->artisan('fam_unit:purge-orphan-photos')->assertSuccessful();

    expect(Storage::disk('local')->exists($keyA))->toBeFalse()->and(Storage::disk('local')->exists($keyB))->toBeFalse();
});

test('INV-001: el trabajo de barrido se niega a correr si el contexto de centro no es el que debía barrer', function (): void {
    [$tenant] = provisionCoreTenant('sw-ctx');
    [$other] = provisionCoreTenant('sw-ctx-b');

    expect(fn () => app(TenantContext::class)->runFor($other->id, fn () => (new PurgeOrphanPickupPhotos($tenant->id))->handle(app(PickupListService::class), app(PrivateObjectStore::class), app(TenantContext::class))))
        ->toThrow(LogicException::class);
});
