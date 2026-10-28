<?php

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-003 (1.14-c), CA-FAM-UNIT-043, ADR-067 §5.12, INV-001, INV-015, skill aislamiento-tenant: dos centros con
// condiciones publicadas, tutores y aceptaciones equivalentes. Desde el centro A, ningún endpoint nuevo del módulo ni el
// muro devuelve, modifica ni cuenta datos del B, y un tutor pendiente de A no se ve afectado por las condiciones de B.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-FAM-UNIT-043 INV-001: el muro, GET /me/terms, POST /me/terms-acceptances y GET /terms-acceptances no mezclan datos entre centros', function (): void {
    $a = Fam::termsFamily('tiso-a');
    $b = Fam::termsFamily('tiso-b');

    // Solo el centro B publica condiciones: el tutor de A no queda detenido por ellas.
    $bVersion = Fam::publishTerms($b['tenant'], $b['admin']);
    Fam::call($a['tenant'], $a['tutor'], 'get', '/family-units')->assertOk();
    expect(Fam::call($a['tenant'], $a['tutor'], 'get', '/me/terms')->json())->toBe(['required' => false, 'version' => null])
        ->and(Fam::call($b['tenant'], $b['tutor'], 'get', '/me/terms')->json('required'))->toBeTrue();

    // A publica las suyas y aceptan los dos tutores en sus centros.
    $aVersion = Fam::publishTerms($a['tenant'], $a['admin']);
    Fam::call($a['tenant'], $a['tutor'], 'post', '/me/terms-acceptances', ['terms_version' => $aVersion])->assertCreated();
    Fam::call($b['tenant'], $b['tutor'], 'post', '/me/terms-acceptances', ['terms_version' => $bVersion])->assertCreated();

    $bBefore = app(TenantContext::class)->runFor($b['tenant']->id, fn () => DB::table('terms_acceptances')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
    $bAudit = count(Fam::audit($b['tenant'], 'terms_acceptance'));

    // Desde A, aceptar la versión de B: 422 (no existe para A, no se revela), sin tocar B.
    $r = Fam::call($a['tenant'], $a['tutor'], 'post', '/me/terms-acceptances', ['terms_version' => $bVersion])->assertStatus(422);
    expect(Fam::errorCode($r, 'terms_version'))->toBe('fam_unit.validation.terms_version_not_found');

    // El listado administrativo de A solo cuenta lo de A; los filtros por ids de B no devuelven nada.
    $list = Fam::call($a['tenant'], $a['admin'], 'get', '/terms-acceptances')->assertOk();
    expect($list->json('data'))->toHaveCount(1)->and($list->json('data.0.terms_version'))->toBe($aVersion)
        ->and(Fam::call($a['tenant'], $a['admin'], 'get', "/terms-acceptances?terms_version={$bVersion}")->assertOk()->json('data'))->toBe([])
        ->and(Fam::call($a['tenant'], $a['admin'], 'get', "/terms-acceptances?user={$b['tutor']->public_id}")->assertOk()->json('data'))->toBe([]);

    // Un cursor del centro A no sirve en el B.
    Fam::call($a['tenant'], $a['admin'], 'get', "/terms-versions/{$bVersion}")->assertNotFound();

    $bAfter = app(TenantContext::class)->runFor($b['tenant']->id, fn () => DB::table('terms_acceptances')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
    expect($bAfter)->toBe($bBefore)->and(count(Fam::audit($b['tenant'], 'terms_acceptance')))->toBe($bAudit);

    // El usuario de B no puede usar el centro A (sesión de otro tenant), y el muro es por centro.
    expect(app(TenantContext::class)->runFor($a['tenant']->id, fn () => DB::table('terms_acceptances')->count()))->toBe(1)
        ->and(app(TenantContext::class)->runFor($b['tenant']->id, fn () => DB::table('terms_acceptances')->count()))->toBe(1);
});
