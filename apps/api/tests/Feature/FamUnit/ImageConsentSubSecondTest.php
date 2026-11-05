<?php

use App\Modules\FamUnit\Domain\Models\GuardianStudentLink;
use App\Modules\FamUnit\Domain\Models\ImageConsentDecision;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-004, ADR-066 §5.2 y OPEN-066-04 (solo cuentan las decisiones posteriores a `legal_guardian_verified_at`),
// RN-FAM-UNIT-74, issue #483: `occurred_at` y `legal_guardian_verified_at` tienen precisión de microsegundos
// (`timestamptz(6)`). Con precisión de segundos, una decisión del mismo segundo que la verificación se descartaba en silencio.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('REQ-FAM-UNIT-004 OPEN-066-04 #483: una decisión registrada en el mismo segundo que la verificación del tutor, pero después de ella, cuenta', function (): void {
    $f = Fam::consentFamily('is-483', twoGuardians: false);

    // El reloj vuelve al segundo de la verificación (09:00:00.000), 400 ms más tarde.
    test()->travelTo(CarbonImmutable::parse('2026-10-27 09:00:00.400000'));

    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();

    expect(Fam::consentState($f['tenant'], $f['admin'], $f['student'], 'web'))->toBe('autorizo');
});

test('REQ-FAM-UNIT-004 OPEN-066-04 #483: una decisión del mismo instante exacto que la verificación no cuenta (semántica estricta intacta)', function (): void {
    $f = Fam::consentFamily('is-483b', twoGuardians: false);

    test()->travelTo(CarbonImmutable::parse('2026-10-27 09:00:00.000000'));

    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();

    expect(Fam::consentState($f['tenant'], $f['admin'], $f['student'], 'web'))->toBe('pendiente');
});

test('REQ-FAM-UNIT-004 #483 datos.md: occurred_at y legal_guardian_verified_at son timestamptz(6)', function (): void {
    foreach ([['image_consent_decisions', 'occurred_at'], ['guardian_student_links', 'legal_guardian_verified_at']] as [$table, $column]) {
        $type = DB::connection('pgsql_owner')->selectOne('SELECT format_type(atttypid, atttypmod) AS t FROM pg_attribute WHERE attrelid = ?::regclass AND attname = ?', [$table, $column]);

        expect($type->t)->toBe('timestamp(6) with time zone', "{$table}.{$column}");
    }
});

test('REQ-FAM-UNIT-004 #483 api.md §3: el cursor del histórico conserva los microsegundos y no salta decisiones del mismo segundo', function (): void {
    $f = Fam::consentFamily('is-483c', twoGuardians: false);

    foreach ([['.100000', 'web', 'autorizo'], ['.500000', 'web', 'no_autorizo'], ['.900000', 'redes_sociales', 'autorizo']] as [$fraction, $level, $decision]) {
        test()->travelTo(CarbonImmutable::parse('2026-10-27 09:00:02'.$fraction));
        Fam::decideAs($f['tenant'], $f['r1'], $f['student'], $level, $decision)->assertCreated();
    }

    $path = "/image-consent-decisions?student_person={$f['student']->public_id}";
    $all = Fam::call($f['tenant'], $f['admin'], 'get', $path)->assertOk()->json('data');
    expect($all)->toHaveCount(3);

    $seen = [];
    $cursor = null;

    do {
        $page = Fam::call($f['tenant'], $f['admin'], 'get', $path.'&limit=1'.($cursor === null ? '' : '&cursor='.urlencode($cursor)))->assertOk();
        $seen[] = $page->json('data.0.public_id');
        $cursor = $page->json('meta.next_cursor');
    } while ($page->json('meta.has_more') && count($seen) < 5);

    expect($seen)->toBe(array_column($all, 'public_id'));
});

test('REQ-FAM-UNIT-004 OPEN-066-04 #483: una decisión anterior a la verificación dentro del mismo segundo no cuenta, ni siquiera para la deduplicación de RN-FAM-UNIT-77', function (): void {
    $f = Fam::consentFamily('is-483d', twoGuardians: false);

    // Reverificación a .700; una decisión a .300 (anterior) no cuenta; la misma decisión a .800 sí.
    test()->travelTo(CarbonImmutable::parse('2026-10-27 10:00:00.200000'));
    Fam::call($f['tenant'], $f['admin'], 'patch', "/guardian-links/{$f['l1']}", ['is_legal_guardian' => false])->assertOk();
    test()->travelTo(CarbonImmutable::parse('2026-10-27 10:00:00.700000'));
    Fam::call($f['tenant'], $f['admin'], 'patch', "/guardian-links/{$f['l1']}", ['is_legal_guardian' => true])->assertOk();

    test()->travelTo(CarbonImmutable::parse('2026-10-27 10:00:00.300000'));
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    expect(Fam::consentState($f['tenant'], $f['admin'], $f['student'], 'web'))->toBe('pendiente');

    test()->travelTo(CarbonImmutable::parse('2026-10-27 10:00:00.800000'));
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    expect(Fam::consentState($f['tenant'], $f['admin'], $f['student'], 'web'))->toBe('autorizo');
});

test('REQ-FAM-UNIT-004 #483: el cast MicrosecondDateTime lee sin ensuciar el modelo y la API devuelve la fracción', function (): void {
    $f = Fam::consentFamily('is-483e', twoGuardians: false);
    test()->travelTo(CarbonImmutable::parse('2026-10-27 09:00:02.456789'));
    $decision = Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();

    expect($decision->json('occurred_at') ?? $decision->json('data.occurred_at') ?? json_encode($decision->json()))->toContain('.456789');

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f): void {
        $d = ImageConsentDecision::query()->where('student_person_id', $f['student']->id)->firstOrFail();
        $l = GuardianStudentLink::query()->where('student_person_id', $f['student']->id)->firstOrFail();

        expect($d->isDirty())->toBeFalse()->and($d->occurred_at->format('u'))->toBe('456789')
            ->and($l->isDirty())->toBeFalse()->and($l->legal_guardian_verified_at)->not->toBeNull();
        // Reasignar el mismo valor no lo ensucia (el reenvío de is_legal_guardian = true no debe tocar la verificación).
        $l->legal_guardian_verified_at = $l->legal_guardian_verified_at;
        expect($l->isDirty('legal_guardian_verified_at'))->toBeFalse();
    });
});
