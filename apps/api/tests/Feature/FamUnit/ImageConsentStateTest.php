<?php

use App\Modules\FamUnit\Domain\ImageConsentLevel;
use App\Modules\FamUnit\Domain\ImageConsentPolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-004 (1.14-b), docs/modulos/REQ-FAM-UNIT/funcional.md §3.8, §5.4, §7.3 y RN-FAM-UNIT-45 a -47, -72, -74,
// CA-FAM-UNIT-054, -055, -059, -060, -062, -086 a -088, -094, -096, -102, ADR-066 §5.2 a §5.4, INV-014, INV-015: el
// estado vigente se calcula en origen desde quienes deciden y sus decisiones, y `ImageConsentPolicy` lo expone.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Lo que dice la interfaz pública y la API para un alumno y un nivel: el estado calculado, si es publicable y los ids que
 * bloquean.
 *
 * @return array{state: ?string, publishable: bool, blocking: list<int>}
 */
function consentOutcome(array $f, string $level = 'web'): array
{
    $policy = app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f, $level): array {
        $policy = app(ImageConsentPolicy::class);
        $enum = ImageConsentLevel::from($level);

        return ['publishable' => $policy->isPublishable($enum, [$f['student']->id]), 'blocking' => $policy->blockingAmong($enum, [$f['student']->id])];
    });

    return ['state' => Fam::consentState($f['tenant'], $f['admin'], $f['student'], $level)] + $policy;
}

test('CA-FAM-UNIT-086 ADR-066 §5.2: un alumno vivo de una unidad sin ninguna decisión está pendiente en los dos niveles y no es publicable; el que bloquea es él', function (): void {
    $f = Fam::consentFamily('is-086', twoGuardians: false);

    foreach (['web', 'redes_sociales'] as $level) {
        expect(consentOutcome($f, $level))->toBe(['state' => 'pendiente', 'publishable' => false, 'blocking' => [$f['student']->id]]);
    }

    $row = Fam::call($f['tenant'], $f['admin'], 'get', "/image-consents?student_person={$f['student']->public_id}&level=web")->assertOk()->json('data.0');
    expect($row['last_decision_at'])->toBeNull();
});

/**
 * Las trece filas de la tabla de verdad de RN-FAM-UNIT-45 (CA-FAM-UNIT-055), una prueba por fila. R1 y R2 son los
 * representantes legales con texto publicado; cada fila prepara su situación con la API y comprueba estado y
 * publicabilidad por la API y por `ImageConsentPolicy`.
 *
 * @var array<string, array{0: string, 1: Closure(array): void, 2: string, 3: bool, 4: bool}> clave => descripción, preparación, estado, publicable, dos tutores
 */
$truthTable = [
    'a' => ['nadie decide: el alumno no tiene ningún vínculo vivo con representación legal', function (array $f): void {}, 'pendiente', false, false],
    'b' => ['R1 autorizo', function (array $f): void {
        Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    }, 'autorizo', true, false],
    'c' => ['R1 no_autorizo', function (array $f): void {
        Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'no_autorizo')->assertCreated();
    }, 'no_autorizo', false, false],
    'd' => ['R1 autorizo, R2 sin decisión', function (array $f): void {
        Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    }, 'pendiente', false, true],
    'e' => ['R1 autorizo, R2 autorizo', function (array $f): void {
        Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
        Fam::decideAs($f['tenant'], $f['r2'], $f['student'], 'web', 'autorizo')->assertCreated();
    }, 'autorizo', true, true],
    'f' => ['R1 autorizo, R2 no_autorizo', function (array $f): void {
        Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
        Fam::decideAs($f['tenant'], $f['r2'], $f['student'], 'web', 'no_autorizo')->assertCreated();
    }, 'no_autorizo', false, true],
    'g' => ['R1 no_autorizo, R2 sin decisión', function (array $f): void {
        Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'no_autorizo')->assertCreated();
    }, 'no_autorizo', false, true],
    'h' => ['R1 autorizo (activo), R2 revocado sin decisión', function (array $f): void {
        Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
        Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['l2']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();
    }, 'pendiente', false, true],
    'i' => ['R1 autorizo (activo), R2 revocado con autorizo registrado por el centro', function (array $f): void {
        Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
        Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['l2']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();
        Fam::decide($f['tenant'], $f['admin'], ['student_person' => $f['student']->public_id, 'level' => 'web', 'decision' => 'autorizo', 'on_behalf_of' => $f['g2']])->assertCreated();
    }, 'autorizo', true, true],
    'j' => ['R1 autorizo (activo), R2 revocado con no_autorizo registrado por el centro', function (array $f): void {
        Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
        Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['l2']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();
        Fam::decide($f['tenant'], $f['admin'], ['student_person' => $f['student']->public_id, 'level' => 'web', 'decision' => 'no_autorizo', 'on_behalf_of' => $f['g2']])->assertCreated();
    }, 'no_autorizo', false, true],
    'k' => ['nadie decide: R1 autorizó cuando tenía representación legal y después se le retira; su decisión se conserva pero no cuenta', function (array $f): void {
        Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
        Fam::call($f['tenant'], $f['admin'], 'patch', "/guardian-links/{$f['l1']}", ['is_legal_guardian' => false])->assertOk();
    }, 'pendiente', false, false],
    'l' => ['R1 no_autorizo y después autorizo: vale la última por (occurred_at, id)', function (array $f): void {
        Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'no_autorizo')->assertCreated();
        Fam::tick();
        Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    }, 'autorizo', true, false],
    'm' => ['R1 autorizo; el vínculo de R2, con un no_autorizo previo, se da de baja y no cuenta', function (array $f): void {
        Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
        Fam::decideAs($f['tenant'], $f['r2'], $f['student'], 'web', 'no_autorizo')->assertCreated();
        Fam::tick();
        Fam::call($f['tenant'], $f['admin'], 'delete', "/guardian-links/{$f['l2']}")->assertNoContent();
    }, 'autorizo', true, true],
];

foreach ($truthTable as $row => [$description, $prepare, $state, $publishable, $two]) {
    test("CA-FAM-UNIT-055 RN-FAM-UNIT-45 fila {$row}: {$description} → {$state}".($publishable ? ', publicable' : ', no publicable'), function () use ($row, $prepare, $state, $publishable, $two): void {
        $f = $row === 'a'
            ? (function (): array {
                [$tenant, $admin] = Fam::setup('is-055-a');
                Fam::freeze();
                $unit = Fam::unitId($tenant, $admin);
                $student = Fam::person($tenant);
                Fam::addStudent($tenant, $admin, $unit, $student)->assertCreated();
                Fam::publishText($tenant, $admin, 'web');

                return ['tenant' => $tenant, 'admin' => $admin, 'unit' => $unit, 'student' => $student];
            })()
            : Fam::consentFamily("is-055-{$row}", twoGuardians: $two);

        $prepare($f);
        Fam::tick();

        $outcome = consentOutcome($f);

        expect($outcome['state'])->toBe($state)
            ->and($outcome['publishable'])->toBe($publishable)
            ->and($outcome['blocking'])->toBe($publishable ? [] : [$f['student']->id]);
    });
}

test('RN-FAM-UNIT-47 CA-FAM-UNIT-051: los dos niveles son independientes en el estado y en la interfaz', function (): void {
    $f = Fam::consentFamily('is-047', twoGuardians: false);
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'redes_sociales', 'autorizo')->assertCreated();

    expect(consentOutcome($f, 'redes_sociales'))->toBe(['state' => 'autorizo', 'publishable' => true, 'blocking' => []])
        ->and(consentOutcome($f, 'web'))->toBe(['state' => 'pendiente', 'publishable' => false, 'blocking' => [$f['student']->id]]);
});

test('CA-FAM-UNIT-054 REQ-FAM-UNIT-004: revocar el consentimiento es registrar no_autorizo — la respuesta trae el estado y la interfaz bloquea en la consulta siguiente, sin caché', function (): void {
    $f = Fam::consentFamily('is-054', twoGuardians: false);
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'redes_sociales', 'autorizo')->assertCreated();

    expect(consentOutcome($f, 'redes_sociales')['publishable'])->toBeTrue();
    Fam::tick();

    $response = Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'redes_sociales', 'no_autorizo')->assertCreated();

    expect($response->json('state.state'))->toBe('no_autorizo')
        ->and(consentOutcome($f, 'redes_sociales'))->toBe(['state' => 'no_autorizo', 'publishable' => false, 'blocking' => [$f['student']->id]]);

    // Y volver a autorizar la restituye: la última decisión manda.
    Fam::tick();
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'redes_sociales', 'autorizo')->assertCreated();
    expect(consentOutcome($f, 'redes_sociales')['publishable'])->toBeTrue();
});

test('CA-FAM-UNIT-087 ADR-066 §5.2: dar de alta un segundo representante legal deja el estado pendiente en ese instante, sin ninguna decisión nueva — también al marcar is_legal_guardian en un vínculo existente', function (): void {
    $f = Fam::consentFamily('is-087', twoGuardians: false);
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    expect(consentOutcome($f)['state'])->toBe('autorizo');

    $before = count(app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('image_consent_decisions')->get()->all()));
    Fam::tick();

    // Alta del vínculo de R2 con representación legal.
    $r2 = Fam::consentGuardian($f['tenant'], $f['admin'], 'r2', $f['unit']);
    $g2 = Fam::guardian($f['tenant'], $f['admin'], $r2, $f['unit'])->assertCreated()->json('public_id');
    $l2 = Fam::link($f['tenant'], $f['admin'], $g2, $f['student'], ['is_legal_guardian' => true, 'relationship' => 'padre'])->assertCreated()->json('public_id');

    expect(consentOutcome($f))->toBe(['state' => 'pendiente', 'publishable' => false, 'blocking' => [$f['student']->id]])
        ->and(count(app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('image_consent_decisions')->get()->all())))->toBe($before);

    // CA-FAM-UNIT-088: la baja de ese vínculo lo devuelve a autorizo, de nuevo sin decisión alguna.
    Fam::tick();
    Fam::call($f['tenant'], $f['admin'], 'delete', "/guardian-links/{$l2}")->assertNoContent();
    expect(consentOutcome($f)['state'])->toBe('autorizo');

    // Si R2 no era representante legal y se marca (false → true), el estado vuelve a pendiente; desmarcar, a autorizo.
    $l3 = Fam::link($f['tenant'], $f['admin'], $g2, $f['student'], ['is_legal_guardian' => false, 'relationship' => 'abuelo'])->assertCreated()->json('public_id');
    expect(consentOutcome($f)['state'])->toBe('autorizo');

    Fam::tick();
    Fam::call($f['tenant'], $f['admin'], 'patch', "/guardian-links/{$l3}", ['is_legal_guardian' => true])->assertOk();
    expect(consentOutcome($f)['state'])->toBe('pendiente');

    Fam::tick();
    Fam::call($f['tenant'], $f['admin'], 'patch', "/guardian-links/{$l3}", ['is_legal_guardian' => false])->assertOk();
    expect(consentOutcome($f)['state'])->toBe('autorizo');
});

test('CA-FAM-UNIT-088 RN-FAM-UNIT-72: volver a crear el vínculo de R2 con representación legal deja el estado pendiente, y una decisión anterior de R2 a la nueva verificación tampoco cuenta', function (): void {
    $f = Fam::consentFamily('is-088');
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    Fam::decideAs($f['tenant'], $f['r2'], $f['student'], 'web', 'autorizo')->assertCreated();
    expect(consentOutcome($f)['state'])->toBe('autorizo');

    Fam::tick();
    Fam::call($f['tenant'], $f['admin'], 'delete', "/guardian-links/{$f['l2']}")->assertNoContent();
    expect(consentOutcome($f)['state'])->toBe('autorizo');

    Fam::tick();
    Fam::link($f['tenant'], $f['admin'], $f['g2'], $f['student'], ['is_legal_guardian' => true, 'relationship' => 'padre'])->assertCreated();

    // La autorización anterior de R2 es anterior a la nueva verificación: no cuenta.
    expect(consentOutcome($f)['state'])->toBe('pendiente');

    Fam::tick();
    Fam::decideAs($f['tenant'], $f['r2'], $f['student'], 'web', 'autorizo')->assertCreated();
    expect(consentOutcome($f)['state'])->toBe('autorizo');
});

test('CA-FAM-UNIT-096 RN-FAM-UNIT-72 OPEN-066-04: desmarcar y volver a marcar is_legal_guardian reverifica — el estado pasa a pendiente hasta que R1 decida de nuevo — y su decisión anterior sigue en el histórico', function (): void {
    $f = Fam::consentFamily('is-096', twoGuardians: false);
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    expect(consentOutcome($f)['state'])->toBe('autorizo');

    Fam::tick();
    Fam::call($f['tenant'], $f['admin'], 'patch', "/guardian-links/{$f['l1']}", ['is_legal_guardian' => false])->assertOk();
    Fam::tick();
    Fam::call($f['tenant'], $f['admin'], 'patch', "/guardian-links/{$f['l1']}", ['is_legal_guardian' => true])->assertOk();
    Fam::tick();

    expect(consentOutcome($f)['state'])->toBe('pendiente');
    $history = Fam::call($f['tenant'], $f['admin'], 'get', "/image-consent-decisions?student_person={$f['student']->public_id}")->assertOk()->json('data');
    expect($history)->toHaveCount(1)->and($history[0]['decision'])->toBe('autorizo');

    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    expect(consentOutcome($f)['state'])->toBe('autorizo');
});

test('CA-FAM-UNIT-102 RN-FAM-UNIT-74 RN-FAM-UNIT-72: reenviar is_legal_guardian = true en una edición rutinaria no deja sin efecto el autorizo; desmarcar y volver a marcar sí', function (): void {
    $f = Fam::consentFamily('is-102', twoGuardians: false);
    $verifiedAt = Fam::row($f['tenant'], 'guardian_student_links', $f['l1'])->legal_guardian_verified_at;
    $editor = Fam::userWith($f['tenant'], $f['admin'], 'editor', ['vinculo_tutor.actualizar' => 'todos', 'vinculo_tutor.leer' => 'todos']);

    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    expect(consentOutcome($f)['state'])->toBe('autorizo');

    // Edición rutinaria (otro usuario, otro instante) que reenvía is_legal_guardian = true y cambia otro campo.
    Fam::tick(60);
    Fam::call($f['tenant'], $editor, 'patch', "/guardian-links/{$f['l1']}", ['is_legal_guardian' => true, 'access_level' => 'lectura_escritura'])->assertOk();

    $row = Fam::row($f['tenant'], 'guardian_student_links', $f['l1']);
    expect($row->legal_guardian_verified_at)->toBe($verifiedAt)
        ->and((int) $row->legal_guardian_verified_by)->toBe($f['admin']->id)
        ->and(consentOutcome($f)['state'])->toBe('autorizo');

    $audit = Fam::audit($f['tenant'], 'guardian_student_link', 'updated');
    expect(array_keys(end($audit)->changes))->toContain('access_level')
        ->not->toContain('is_legal_guardian', 'legal_guardian_verified_at', 'legal_guardian_verified_by');

    // Una reverificación explícita (false y después true) sí la invalida.
    Fam::tick();
    Fam::call($f['tenant'], $editor, 'patch', "/guardian-links/{$f['l1']}", ['is_legal_guardian' => false])->assertOk();
    Fam::tick();
    Fam::call($f['tenant'], $editor, 'patch', "/guardian-links/{$f['l1']}", ['is_legal_guardian' => true])->assertOk();

    $again = Fam::row($f['tenant'], 'guardian_student_links', $f['l1']);
    expect($again->legal_guardian_verified_at)->not->toBe($verifiedAt)
        ->and((int) $again->legal_guardian_verified_by)->toBe($editor->id)
        ->and(consentOutcome($f)['state'])->toBe('pendiente');
});

test('RN-FAM-UNIT-72: solo cuentan las decisiones posteriores a legal_guardian_verified_at — una decisión en el mismo segundo, o con la verificación nula, no cuenta (fallo en cerrado)', function (): void {
    [$tenant, $admin] = Fam::setup('is-072');
    Fam::freeze();
    $unit = Fam::unitId($tenant, $admin);
    $student = Fam::person($tenant);
    Fam::addStudent($tenant, $admin, $unit, $student)->assertCreated();
    $r1 = Fam::consentGuardian($tenant, $admin, 'r1', $unit);
    $g1 = Fam::guardian($tenant, $admin, $r1, $unit)->assertCreated()->json('public_id');
    $link = Fam::link($tenant, $admin, $g1, $student, ['is_legal_guardian' => true])->assertCreated()->json('public_id');
    Fam::publishText($tenant, $admin, 'web');
    $f = compact('tenant', 'admin', 'student');

    // Mismo segundo que la verificación (el reloj no ha avanzado): la decisión no es posterior y no cuenta.
    Fam::decideAs($tenant, $r1, $student, 'web', 'autorizo')->assertCreated();
    expect(consentOutcome($f)['state'])->toBe('pendiente');

    Fam::tick();
    Fam::decideAs($tenant, $r1, $student, 'web', 'autorizo')->assertCreated();
    expect(consentOutcome($f)['state'])->toBe('autorizo');

    // El CHECK de datos.md §1.4 admite is_legal_guardian con la verificación nula: ninguna decisión suya cuenta.
    app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('guardian_student_links')->where('public_id', $link)->update(['legal_guardian_verified_at' => null]));
    expect(consentOutcome($f))->toBe(['state' => 'pendiente', 'publishable' => false, 'blocking' => [$student->id]]);
});

test('RN-FAM-UNIT-45: el orden de las decisiones de un tutor en el mismo segundo lo desempata el id — la última registrada vale', function (): void {
    $f = Fam::consentFamily('is-045', twoGuardians: false);

    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'no_autorizo')->assertCreated();
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();

    $stamps = app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('image_consent_decisions')->orderBy('id')->pluck('occurred_at')->all());
    expect($stamps[0])->toBe($stamps[1])->and(consentOutcome($f)['state'])->toBe('autorizo');
});

test('CA-FAM-UNIT-062 INV-014 §7.3: un publicador de prueba que consulta ImageConsentPolicy antes de publicar recibe «no publicable» con el alumno que bloquea; una foto de grupo solo es publicable si lo son todos', function (): void {
    $f = Fam::consentFamily('is-062', twoGuardians: false);
    $second = Fam::person($f['tenant'], 'Segundo', 'Alumno');
    Fam::addStudent($f['tenant'], $f['admin'], $f['unit'], $second)->assertCreated();
    Fam::link($f['tenant'], $f['admin'], $f['g1'], $second, ['is_legal_guardian' => true])->assertCreated();
    Fam::tick();

    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    Fam::decideAs($f['tenant'], $f['r1'], $second, 'web', 'no_autorizo')->assertCreated();

    // El «publicador»: consulta antes de publicar y devuelve los bloqueados; nunca guarda el estado.
    $publish = static function (array $students) use ($f): array {
        return app(TenantContext::class)->runFor($f['tenant']->id, function () use ($students): array {
            $policy = app(ImageConsentPolicy::class);

            return $policy->isPublishable(ImageConsentLevel::Web, $students)
                ? ['published' => true, 'blocking' => []]
                : ['published' => false, 'blocking' => $policy->blockingAmong(ImageConsentLevel::Web, $students)];
        });
    };

    expect($publish([$f['student']->id]))->toBe(['published' => true, 'blocking' => []])
        ->and($publish([$second->id]))->toBe(['published' => false, 'blocking' => [$second->id]])
        ->and($publish([$second->id, $f['student']->id]))->toBe(['published' => false, 'blocking' => [$second->id]])
        ->and($publish([$f['student']->id, $f['student']->id]))->toBe(['published' => true, 'blocking' => []]);
});

test('CA-FAM-UNIT-094 RN-FAM-UNIT-46: una lista de alumnos vacía lanza una excepción en isPublishable y en blockingAmong y nunca es «publicable»', function (): void {
    $f = Fam::consentFamily('is-094', twoGuardians: false);

    app(TenantContext::class)->runFor($f['tenant']->id, function (): void {
        $policy = app(ImageConsentPolicy::class);

        expect(fn () => $policy->isPublishable(ImageConsentLevel::Web, []))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $policy->blockingAmong(ImageConsentLevel::SocialNetworks, []))->toThrow(InvalidArgumentException::class);
    });
});

test('CA-FAM-UNIT-059 RN-FAM-UNIT-46: un alumno inexistente o de otro centro es «no publicable» y aparece entre los que bloquean, aunque otro de la lista sea publicable', function (): void {
    $f = Fam::consentFamily('is-059', twoGuardians: false);
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();

    // Un alumno con todo autorizado en OTRO centro: su id no existe para este.
    $other = Fam::consentFamily('is-059b', twoGuardians: false);
    Fam::decideAs($other['tenant'], $other['r1'], $other['student'], 'web', 'autorizo')->assertCreated();
    expect(consentOutcome($other)['publishable'])->toBeTrue();

    $missing = max($f['student']->id, $other['student']->id) + 100000;

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f, $other, $missing): void {
        $policy = app(ImageConsentPolicy::class);
        $ids = [$f['student']->id, $other['student']->id, $missing];
        sort($ids);

        expect($policy->isPublishable(ImageConsentLevel::Web, $ids))->toBeFalse()
            ->and($policy->blockingAmong(ImageConsentLevel::Web, $ids))->toBe(array_values(array_diff($ids, [$f['student']->id])))
            ->and($policy->isPublishable(ImageConsentLevel::Web, [$other['student']->id]))->toBeFalse()
            ->and($policy->isPublishable(ImageConsentLevel::Web, [$missing]))->toBeFalse();
    });
});

test('CA-FAM-UNIT-060 funcional.md §9: con el módulo descontratado, ImageConsentPolicy responde «no publicable» para todos, también para quien tiene todo autorizado', function (): void {
    $f = Fam::consentFamily('is-060', twoGuardians: false);
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    expect(consentOutcome($f)['publishable'])->toBeTrue();

    Fam::disableModule($f['tenant']);

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f): void {
        $policy = app(ImageConsentPolicy::class);

        expect($policy->isPublishable(ImageConsentLevel::Web, [$f['student']->id]))->toBeFalse()
            ->and($policy->blockingAmong(ImageConsentLevel::Web, [$f['student']->id, $f['student']->id + 1]))->toBe([$f['student']->id, $f['student']->id + 1]);
    });
});

test('§7.3 punto 4 RN-FAM-UNIT-34: ImageConsentPolicy lee en una sola sentencia sobre vínculos y decisiones sea cual sea el número de alumnos, y no memoiza entre llamadas', function (): void {
    $f = Fam::consentFamily('is-q', twoGuardians: false);
    $ids = [$f['student']->id];

    for ($i = 0; $i < 12; $i++) {
        $extra = Fam::person($f['tenant'], "Alumno{$i}", 'Lote');
        Fam::addStudent($f['tenant'], $f['admin'], $f['unit'], $extra)->assertCreated();
        $ids[] = $extra->id;
    }

    $queries = [];

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($ids, &$queries): void {
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'image_consent_decisions') || str_contains($query->sql, 'guardian_student_links')) {
                $queries[] = $query->sql;
            }
        });

        $policy = app(ImageConsentPolicy::class);
        $policy->blockingAmong(ImageConsentLevel::Web, $ids);
        expect($queries)->toHaveCount(1);

        // Segunda llamada: otra consulta (sin caché entre llamadas).
        $policy->isPublishable(ImageConsentLevel::Web, array_slice($ids, 0, 2));
        expect($queries)->toHaveCount(2);
    });
});

test('RN-FAM-UNIT-41 ADR-066 §5.4: un tutor sin representación legal (abuelo, apoderado) no decide ni su decisión cambia el estado', function (): void {
    $f = Fam::consentFamily('is-041', twoGuardians: false);
    $abuelo = Fam::consentGuardian($f['tenant'], $f['admin'], 'abuelo', $f['unit']);
    $g = Fam::guardian($f['tenant'], $f['admin'], $abuelo, $f['unit'])->assertCreated()->json('public_id');
    Fam::link($f['tenant'], $f['admin'], $g, $f['student'], ['relationship' => 'abuelo'])->assertCreated();
    Fam::tick();

    // El abuelo no puede registrar (CA-052) y no es un decisor: con R1 autorizando, el estado es autorizo.
    Fam::decideAs($f['tenant'], $abuelo, $f['student'], 'web', 'no_autorizo')->assertStatus(422);
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    expect(consentOutcome($f)['state'])->toBe('autorizo');
});
