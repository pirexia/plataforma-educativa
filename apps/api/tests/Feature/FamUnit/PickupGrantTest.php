<?php

use App\Modules\FamUnit\Domain\AuthorizedPickupList;
use App\Modules\FamUnit\Domain\PickupBasis;
use App\Modules\FamUnit\Domain\PickupGrant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-PRL-004 (1.14b-b), ADR-068 §5.5 ampliado, funcional.md §21.8 (`REQ-FAM-UNIT`), RN-PRL-02, RN-PRL-05, RN-PRL-06, INV-007:
// `AuthorizedPickupList::grantFor()`, la consulta que `prl` necesita para guardar la base y la referencia de evidencia de una
// salida y para exigir documento a un tutor sin fotografía, sin pedir la lista completa (lectura auditada y limitada).

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return mixed */
function grantFor(object $tenant, Closure $callback)
{
    return app(TenantContext::class)->runFor($tenant->id, fn () => $callback(app(AuthorizedPickupList::class)));
}

test('RN-PRL-05 ADR-068 §5.5: grantFor devuelve las bases con el id interno de la referencia — vínculo del tutor o autorización del tercero — y si la persona tiene fotografía', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pg-bases');
    $abuelo = Fam::thirdParty($f, 1);
    $personId = fn (string $public) => app(TenantContext::class)->runFor($f['tenant']->id, fn () => (int) DB::table('people')->where('public_id', $public)->value('id'));

    $grant = grantFor($f['tenant'], fn (AuthorizedPickupList $list) => $list->grantFor($personId($abuelo['person']), $f['student']->id));
    $authorizationId = Fam::row($f['tenant'], 'pickup_authorizations', $abuelo['authorization'])->id;

    expect($grant)->toBeInstanceOf(PickupGrant::class)
        ->and($grant->hasPhoto)->toBeTrue()
        ->and($grant->bases)->toHaveCount(1)
        ->and($grant->primaryBasis()->basis)->toBe(PickupBasis::THIRD_PARTY)
        ->and($grant->primaryBasis()->reference)->toBe($abuelo['authorization'])
        ->and($grant->primaryBasis()->referenceId)->toBe($authorizationId);

    // Un tutor sin fotografía figura (para que la puerta exija documento), con el vínculo como referencia.
    $tutorPerson = app(TenantContext::class)->runFor($f['tenant']->id, fn () => (int) DB::table('people')->where('id', $f['other']->person_id)->value('id'));
    $tutor = grantFor($f['tenant'], fn (AuthorizedPickupList $list) => $list->grantFor($tutorPerson, $f['student']->id));

    expect($tutor->hasPhoto)->toBeFalse()
        ->and($tutor->primaryBasis()->basis)->toBe(PickupBasis::TUTOR)
        ->and($tutor->primaryBasis()->referenceId)->toBe(Fam::row($f['tenant'], 'guardian_student_links', $f['lo'])->id);
});

test('RN-PRL-02 CA-PRL-002 ADR-068 §5.5: grantFor es null exactamente cuando isAuthorized es falso — retirada, revocación, otra persona, alumno o persona desconocidos, de otro centro o fam_unit descontratado — para toda persona de los datos de prueba', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pg-null');
    $abuelo = Fam::thirdParty($f, 1);
    $stranger = Fam::registerPerson($f['tenant'], $f['admin'], ['document_type' => 'dni', 'document_number' => Fam::dni(9)])->assertCreated()->json('person');
    $ids = app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('people')->pluck('id', 'public_id')->all());
    $everyone = array_values($ids);
    $consistent = function () use ($f, $everyone): array {
        return grantFor($f['tenant'], function (AuthorizedPickupList $list) use ($f, $everyone): array {
            $result = [];

            foreach ($everyone as $personId) {
                $grant = $list->grantFor($personId, $f['student']->id);
                expect($grant !== null)->toBe($list->isAuthorized($personId, $f['student']->id), "persona {$personId}");
                $result[$personId] = $grant;
            }

            return $result;
        });
    };

    expect(array_filter($consistent()))->toHaveCount(3); // madre, padre y abuelo

    // Retirada del abuelo y revocación del padre: dejan de figurar en la petición siguiente.
    Fam::call($f['tenant'], $f['admin'], 'post', "/pickup-authorizations/{$abuelo['authorization']}/withdrawal")->assertOk();
    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['lo']}/revocation", ['reason' => 'resolucion_judicial', 'notes' => null])->assertOk();
    $after = $consistent();

    expect(array_filter($after))->toHaveCount(1)
        ->and($after[$ids[$stranger]])->toBeNull();

    // Desconocidos y alumno inexistente.
    grantFor($f['tenant'], function (AuthorizedPickupList $list) use ($f): void {
        expect($list->grantFor(999999999, $f['student']->id))->toBeNull()
            ->and($list->grantFor($f['student']->id, 999999999))->toBeNull();
    });

    // Otro centro: nada, ni con ids válidos de este.
    [$tenantB] = Fam::setup('pg-null-b');
    $first = array_values($ids)[0];
    grantFor($tenantB, fn (AuthorizedPickupList $list) => expect($list->grantFor($first, $f['student']->id))->toBeNull());

    // fam_unit descontratado: fallo en cerrado aunque la persona siga figurando.
    Fam::disableModule($f['tenant']);
    grantFor($f['tenant'], fn (AuthorizedPickupList $list) => expect($list->grantFor($ids[$f['principal']->public_id] ?? 0, $f['student']->id))->toBeNull());
});

test('ADR-068 §5.4 ADR-068 §5.5: grantFor no se audita (no devuelve datos personales) y no gasta el límite de lecturas de la lista', function (): void {
    config(['fam_unit.pickup.read_rate_limit.attempts' => 1]);
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pg-audit');
    $abuelo = Fam::thirdParty($f, 1);
    $personId = app(TenantContext::class)->runFor($f['tenant']->id, fn () => (int) DB::table('people')->where('public_id', $abuelo['person'])->value('id'));

    $before = count(Fam::audit($f['tenant'], 'person', 'read'));

    foreach (range(1, 5) as $i) {
        expect(grantFor($f['tenant'], fn (AuthorizedPickupList $list) => $list->grantFor($personId, $f['student']->id)))->not->toBeNull();
    }

    expect(count(Fam::audit($f['tenant'], 'person', 'read')))->toBe($before);

    // El cubo de la lista (1 intento) sigue intacto: la primera lectura entra y la segunda no.
    Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-lists/{$f['student']->public_id}")->assertOk();
    Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-lists/{$f['student']->public_id}")->assertStatus(429);
});
