<?php

use App\Modules\Core\Domain\Models\DataExport;
use App\Modules\FamUnit\Domain\ImageConsentLevel;
use App\Modules\FamUnit\Domain\ImageConsentPolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-004 (1.14-b), CA-FAM-UNIT-043, INV-001, INV-015, skill aislamiento-tenant: dos centros con unidades, tutores,
// vínculos, textos y decisiones equivalentes; ejercitados todos los endpoints de consentimiento de imagen y textos legales
// y la interfaz ImageConsentPolicy desde el centro A, ningún dato del centro B se devuelve, modifica ni cuenta.

beforeEach(function (): void {
    Storage::fake('local');
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-FAM-UNIT-043 INV-001: desde el centro A, ningún endpoint de consentimiento de imagen ni de textos legales devuelve, modifica ni cuenta datos del centro B', function (): void {
    $a = Fam::consentFamily('iso-a');
    Fam::decideAs($a['tenant'], $a['r1'], $a['student'], 'web', 'autorizo')->assertCreated();
    Fam::decideAs($a['tenant'], $a['r2'], $a['student'], 'web', 'autorizo')->assertCreated();
    $b = Fam::consentFamily('iso-b');
    Fam::decideAs($b['tenant'], $b['r1'], $b['student'], 'web', 'no_autorizo')->assertCreated();
    Fam::decide($b['tenant'], $b['admin'], ['student_person' => $b['student']->public_id, 'level' => 'redes_sociales', 'decision' => 'autorizo', 'on_behalf_of' => $b['g2']])->assertCreated();
    Fam::tick();

    $bDecisions = app(TenantContext::class)->runFor($b['tenant']->id, fn () => DB::table('image_consent_decisions')->pluck('public_id')->all());
    $bVersions = app(TenantContext::class)->runFor($b['tenant']->id, fn () => DB::table('terms_versions')->pluck('public_id')->all());
    $bBefore = [
        'decisions' => app(TenantContext::class)->runFor($b['tenant']->id, fn () => DB::table('image_consent_decisions')->count()),
        'versions' => app(TenantContext::class)->runFor($b['tenant']->id, fn () => DB::table('terms_versions')->orderBy('id')->get()->map(fn ($v) => (array) $v)->all()),
        'audit' => count(Fam::audit($b['tenant'], 'image_consent_decision')) + count(Fam::audit($b['tenant'], 'terms_version')),
    ];

    $outputs = [];
    $get = function (string $path) use ($a, &$outputs): void {
        $outputs[] = json_encode(Fam::call($a['tenant'], $a['admin'], 'get', $path)->assertOk()->json());
    };

    // Listados, histórico y textos desde A.
    $get('/image-consents');
    $get('/image-consent-texts');
    $get('/terms-versions');
    $get("/image-consent-decisions?student_person={$a['student']->public_id}");

    // Cualquier id del centro B es inexistente para A.
    Fam::call($a['tenant'], $a['admin'], 'get', "/image-consent-decisions?student_person={$b['student']->public_id}")->assertNotFound();
    Fam::call($a['tenant'], $a['r1'], 'get', "/image-consent-decisions?student_person={$b['student']->public_id}")->assertNotFound();

    foreach ($bVersions as $version) {
        Fam::call($a['tenant'], $a['admin'], 'get', "/terms-versions/{$version}")->assertNotFound();
        Fam::call($a['tenant'], $a['admin'], 'patch', "/terms-versions/{$version}", ['body_translations' => ['es-ES' => 'Hackeado']])->assertNotFound();
        Fam::call($a['tenant'], $a['admin'], 'delete', "/terms-versions/{$version}")->assertNotFound();
        Fam::call($a['tenant'], $a['admin'], 'post', "/terms-versions/{$version}/publish")->assertNotFound();
    }

    // Escrituras con referencias del centro B: alumno, tutor y versión inexistentes en A.
    $r = Fam::decide($a['tenant'], $a['admin'], ['student_person' => $b['student']->public_id, 'level' => 'web', 'decision' => 'no_autorizo', 'on_behalf_of' => $a['g1']])->assertStatus(422);
    expect(Fam::errorCode($r, 'student_person'))->toBe('fam_unit.validation.student_not_found');
    $r = Fam::decide($a['tenant'], $a['admin'], ['student_person' => $a['student']->public_id, 'level' => 'web', 'decision' => 'no_autorizo', 'on_behalf_of' => $b['g1']])->assertStatus(422);
    expect(Fam::errorCode($r, 'on_behalf_of'))->toBe('fam_unit.image_consent.guardian_not_linked');
    $r = Fam::decide($a['tenant'], $a['admin'], ['student_person' => $a['student']->public_id, 'level' => 'web', 'decision' => 'autorizo', 'on_behalf_of' => $a['g1'], 'channel' => 'presencial', 'consent_text_version' => $bVersions[0]])->assertStatus(422);
    expect(Fam::errorCode($r, 'consent_text_version'))->toBe('fam_unit.image_consent.text_version_invalid');
    Fam::decide($a['tenant'], $a['r1'], ['student_person' => $b['student']->public_id, 'level' => 'web', 'decision' => 'no_autorizo'])->assertNotFound();

    // Filtros por referencias del centro B: no coinciden con nada.
    foreach (["student_person={$b['student']->public_id}", "family_unit={$b['unit']}"] as $filter) {
        expect(Fam::call($a['tenant'], $a['admin'], 'get', "/image-consents?{$filter}")->assertOk()->json('data'))->toBe([]);
    }

    // Informe: solo los alumnos de A, aunque se pidan los de B.
    $export = Fam::call($a['tenant'], $a['admin'], 'post', '/image-consents/exports', ['student_person' => [$b['student']->public_id]])->assertStatus(202)->json('public_id');
    $key = app(TenantContext::class)->runFor($a['tenant']->id, fn () => DataExport::where('public_id', $export)->firstOrFail()->object_key);
    expect(preg_split('/\r\n/', trim(Storage::disk('local')->get($key), "\r\n")))->toHaveCount(1);
    $all = Fam::call($a['tenant'], $a['admin'], 'post', '/image-consents/exports')->assertStatus(202)->json('public_id');
    $key = app(TenantContext::class)->runFor($a['tenant']->id, fn () => DataExport::where('public_id', $all)->firstOrFail()->object_key);
    $outputs[] = Storage::disk('local')->get($key);

    // La exportación de B no es descargable desde A.
    $bExport = Fam::call($b['tenant'], $b['admin'], 'post', '/image-consents/exports')->assertStatus(202)->json('public_id');
    Fam::call($a['tenant'], $a['admin'], 'get', "/data-exports/{$bExport}")->assertNotFound();

    // Ningún dato de B en lo devuelto por A.
    $needles = [$b['student']->public_id, $b['unit'], $b['g1'], $b['g2'], $b['r1']->public_id, $b['admin']->public_id, ...$bDecisions, ...$bVersions];

    foreach ($outputs as $output) {
        foreach ($needles as $needle) {
            expect($output)->not->toContain((string) $needle);
        }
    }

    // La interfaz pública: los alumnos del centro B bloquean desde A (no existen) y los de A no se ven desde B.
    app(TenantContext::class)->runFor($a['tenant']->id, function () use ($a, $b): void {
        $policy = app(ImageConsentPolicy::class);

        expect($policy->isPublishable(ImageConsentLevel::Web, [$a['student']->id]))->toBeTrue()
            ->and($policy->blockingAmong(ImageConsentLevel::Web, [$b['student']->id]))->toBe([$b['student']->id]);
    });
    app(TenantContext::class)->runFor($b['tenant']->id, function () use ($a, $b): void {
        $policy = app(ImageConsentPolicy::class);

        expect($policy->blockingAmong(ImageConsentLevel::Web, [$a['student']->id, $b['student']->id]))->toBe([min($a['student']->id, $b['student']->id), max($a['student']->id, $b['student']->id)]);
    });

    // Nada de B cambió: ni decisiones, ni versiones, ni auditoría.
    expect(app(TenantContext::class)->runFor($b['tenant']->id, fn () => DB::table('image_consent_decisions')->count()))->toBe($bBefore['decisions'])
        ->and(app(TenantContext::class)->runFor($b['tenant']->id, fn () => DB::table('terms_versions')->orderBy('id')->get()->map(fn ($v) => (array) $v)->all()))->toBe($bBefore['versions'])
        ->and(count(Fam::audit($b['tenant'], 'image_consent_decision')) + count(Fam::audit($b['tenant'], 'terms_version')))->toBe($bBefore['audit']);

    // Los recuentos de A solo cuentan lo de A.
    expect(Fam::call($a['tenant'], $a['admin'], 'get', '/image-consents')->json('meta.total'))->toBe(2)
        ->and(Fam::call($a['tenant'], $a['admin'], 'get', '/terms-versions')->json('meta.total'))->toBe(2);
});

test('INV-001 ADR-033: la política RLS del motor aísla también las dos tablas nuevas — con el contexto del centro A, el rol de la aplicación no ve filas del B aunque consulte sin filtro', function (): void {
    $a = Fam::consentFamily('rls-a', twoGuardians: false);
    Fam::decideAs($a['tenant'], $a['r1'], $a['student'], 'web', 'autorizo')->assertCreated();
    $b = Fam::consentFamily('rls-b', twoGuardians: false);
    Fam::decideAs($b['tenant'], $b['r1'], $b['student'], 'web', 'autorizo')->assertCreated();

    foreach (['image_consent_decisions', 'terms_versions'] as $table) {
        $tenantIds = app(TenantContext::class)->runFor($a['tenant']->id, fn () => DB::table($table)->pluck('tenant_id')->unique()->values()->all());

        expect($tenantIds)->toBe([$a['tenant']->id], $table);
    }

    // Sin contexto de centro, la consulta no devuelve filas (política RLS, ADR-033).
    $rows = app(TenantContext::class)->runFor($b['tenant']->id, fn () => DB::table('image_consent_decisions')->get()->all());
    expect($rows)->toHaveCount(1);
});
