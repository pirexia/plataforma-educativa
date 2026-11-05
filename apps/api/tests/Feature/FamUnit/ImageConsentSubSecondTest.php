<?php

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
