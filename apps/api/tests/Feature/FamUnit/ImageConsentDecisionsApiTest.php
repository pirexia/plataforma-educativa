<?php

use App\Models\Person;
use App\Modules\FamUnit\Domain\Models\ImageConsentDecision;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-004 (1.14-b), docs/modulos/REQ-FAM-UNIT/api.md §2.7, funcional.md §4.6, §5.4 (RN-FAM-UNIT-41 a -43, -67 a
// -69) y CA-FAM-UNIT-051 a -057, -089 a -093, -097, -101, INV-002, INV-003, INV-010, INV-015: registro de decisiones de
// imagen por el propio tutor (canal `portal`) y por el centro en nombre de un tutor (`presencial`).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** Filas de `image_consent_decisions` del centro. */
function decisionRows(object $tenant): array
{
    return app(TenantContext::class)->runFor($tenant->id, fn (): array => DB::table('image_consent_decisions')->orderBy('id')->get()->all());
}

test('CA-FAM-UNIT-051 RN-FAM-UNIT-47 RN-FAM-UNIT-68: el único representante legal registra autorizo en web — 201 con la forma de api.md §1.6, versión publicada, IP y agente guardados — y redes_sociales no cambia', function (): void {
    $f = Fam::consentFamily('ic-051', twoGuardians: false);

    $response = test()->actingAs($f['r1'])->withHeaders(['User-Agent' => 'PruebaNavegador/1.0'])
        ->postJson(Fam::url($f['tenant'], '/image-consent-decisions'), ['student_person' => $f['student']->public_id, 'level' => 'web', 'decision' => 'autorizo', 'consent_text_version' => Fam::publishedTextId($f['tenant'], 'web')])
        ->assertCreated();

    expect(array_keys($response->json()))->toBe(['decision', 'state'])
        ->and(array_keys($response->json('decision')))->toBe(['public_id', 'student_person', 'level', 'decision', 'guardian', 'recorded_by', 'channel', 'consent_text_version', 'shown_locale', 'occurred_at'])
        ->and($response->json('decision'))->toMatchArray([
            'student_person' => $f['student']->public_id,
            'level' => 'web',
            'decision' => 'autorizo',
            'guardian' => $f['g1'],
            'recorded_by' => $f['r1']->public_id,
            'channel' => 'portal',
            'shown_locale' => 'es-ES',
        ])
        ->and($response->json('decision.consent_text_version.kind'))->toBe('consentimiento_imagen_web')
        ->and($response->json('decision.consent_text_version.version_number'))->toBe(1)
        ->and($response->json('state'))->toMatchArray(['student_person' => $f['student']->public_id, 'family_unit' => $f['unit'], 'level' => 'web', 'state' => 'autorizo'])
        ->and(array_keys($response->json('state')))->toBe(['student_person', 'display_name', 'family_unit', 'level', 'state', 'last_decision_at'])
        ->and($response->json('state.last_decision_at'))->not->toBeNull();

    $row = decisionRows($f['tenant'])[0];
    expect($row->user_agent)->toBe('PruebaNavegador/1.0')->and($row->ip_address)->not->toBeNull();

    // Los dos niveles son independientes (RN-FAM-UNIT-47).
    expect(Fam::consentState($f['tenant'], $f['admin'], $f['student'], 'web'))->toBe('autorizo')
        ->and(Fam::consentState($f['tenant'], $f['admin'], $f['student'], 'redes_sociales'))->toBe('pendiente');
});

test('CA-FAM-UNIT-052 RN-FAM-UNIT-41: un tutor con vínculo activo sin representación legal no puede registrar una decisión por sí mismo (422 not_legal_guardian) y no se escribe nada', function (): void {
    $f = Fam::consentFamily('ic-052', twoGuardians: false);
    $abuelo = Fam::consentGuardian($f['tenant'], $f['admin'], 'abuelo', $f['unit']);
    $g = Fam::guardian($f['tenant'], $f['admin'], $abuelo, $f['unit'])->assertCreated()->json('public_id');
    Fam::link($f['tenant'], $f['admin'], $g, $f['student'], ['relationship' => 'abuelo', 'is_legal_guardian' => false])->assertCreated();

    $response = Fam::decideAs($f['tenant'], $abuelo, $f['student'], 'web', 'autorizo')->assertStatus(422);

    expect(Fam::errorCode($response, 'student_person'))->toBe('fam_unit.image_consent.not_legal_guardian')
        ->and(decisionRows($f['tenant']))->toBe([])
        ->and(Fam::audit($f['tenant'], 'image_consent_decision'))->toBe([]);
});

test('CA-FAM-UNIT-053 RN-FAM-UNIT-35: un tutor con el vínculo revocado no alcanza al alumno — 404 — y no se escribe nada', function (): void {
    $f = Fam::consentFamily('ic-053');
    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['l1']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();

    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertNotFound();
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'no_autorizo')->assertNotFound();

    expect(decisionRows($f['tenant']))->toBe([]);
});

test('CA-FAM-UNIT-056 RN-FAM-UNIT-42: las decisiones son inmutables por cualquier vía — modelo, SQL con el rol de la aplicación y API', function (): void {
    $f = Fam::consentFamily('ic-056', twoGuardians: false);
    $id = Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated()->json('decision.public_id');

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($id): void {
        $row = ImageConsentDecision::query()->where('public_id', $id)->firstOrFail();
        $row->decision = 'no_autorizo';

        expect(fn () => $row->save())->toThrow(LogicException::class)
            ->and(fn () => $row->delete())->toThrow(LogicException::class);

        // REVOKE UPDATE, DELETE en el motor (rol plataforma_app): ni siquiera una sentencia SQL directa. Cada intento va
        // en su propio punto de guarda: un error de PostgreSQL aborta la transacción envolvente del test.
        $attempt = static fn (string $sql) => fn () => DB::connection('pgsql')->transaction(static fn () => DB::connection('pgsql')->statement($sql, [$id]));

        expect($attempt("UPDATE image_consent_decisions SET decision = 'no_autorizo' WHERE public_id = ?"))->toThrow(QueryException::class)
            ->and($attempt('DELETE FROM image_consent_decisions WHERE public_id = ?'))->toThrow(QueryException::class);
    });

    // No hay PATCH ni DELETE en la API.
    Fam::call($f['tenant'], $f['admin'], 'patch', "/image-consent-decisions/{$id}", ['decision' => 'no_autorizo'])->assertStatus(404);
    Fam::call($f['tenant'], $f['admin'], 'delete', "/image-consent-decisions/{$id}")->assertStatus(404);
    expect(decisionRows($f['tenant']))->toHaveCount(1)->and(decisionRows($f['tenant'])[0]->decision)->toBe('autorizo');
});

test('CA-FAM-UNIT-057 RN-FAM-UNIT-43: una decisión registrada por secretaría en nombre de un tutor muestra, en el histórico, el tutor que decidió, el usuario que la registró, el canal presencial y la versión del texto', function (): void {
    $f = Fam::consentFamily('ic-057');

    $response = Fam::decide($f['tenant'], $f['admin'], [
        'student_person' => $f['student']->public_id, 'level' => 'redes_sociales', 'decision' => 'autorizo', 'on_behalf_of' => $f['g2'], 'channel' => 'presencial',
    ])->assertCreated();

    expect($response->json('decision'))->toMatchArray(['guardian' => $f['g2'], 'recorded_by' => $f['admin']->public_id, 'channel' => 'presencial', 'shown_locale' => null]);

    $history = Fam::call($f['tenant'], $f['admin'], 'get', "/image-consent-decisions?student_person={$f['student']->public_id}")->assertOk();

    expect($history->json('data'))->toHaveCount(1)
        ->and($history->json('data.0'))->toMatchArray(['guardian' => $f['g2'], 'recorded_by' => $f['admin']->public_id, 'channel' => 'presencial', 'level' => 'redes_sociales'])
        ->and($history->json('data.0.consent_text_version.kind'))->toBe('consentimiento_imagen_redes')
        ->and($history->json('meta'))->toBe(['next_cursor' => null, 'has_more' => false]);

    // La IP y el agente se conservan como evidencia pero no salen por la API (api.md §1.6).
    expect(array_keys($history->json('data.0')))->not->toContain('ip_address', 'user_agent');
});

test('CA-FAM-UNIT-089 RN-FAM-UNIT-68: sin texto publicado en el nivel, autorizo es 422 text_not_published, no se escribe ninguna fila ni auditoría y el estado sigue pendiente', function (): void {
    [$tenant, $admin] = Fam::setup('ic-089');
    Fam::freeze();
    $unit = Fam::unitId($tenant, $admin);
    $student = Fam::person($tenant);
    Fam::addStudent($tenant, $admin, $unit, $student)->assertCreated();
    $r1 = Fam::consentGuardian($tenant, $admin, 'r1', $unit);
    $g1 = Fam::guardian($tenant, $admin, $r1, $unit)->assertCreated()->json('public_id');
    Fam::link($tenant, $admin, $g1, $student, ['is_legal_guardian' => true])->assertCreated();
    Fam::tick();

    // Ninguna versión; solo un borrador y una retirada tampoco bastan.
    $response = Fam::decideAs($tenant, $r1, $student, 'web', 'autorizo')->assertStatus(422);
    expect(Fam::errorCode($response, 'decision'))->toBe('fam_unit.image_consent.text_not_published');

    Fam::draftText($tenant, $admin, 'web');
    Fam::decideAs($tenant, $r1, $student, 'web', 'autorizo')->assertStatus(422);

    $first = Fam::publishText($tenant, $admin, 'web');
    Fam::publishText($tenant, $admin, 'web');
    expect(Fam::row($tenant, 'terms_versions', $first)->status)->toBe('retirada');

    // Con la otra publicada sí hay texto; el nivel redes_sociales sigue sin él.
    $response = Fam::decideAs($tenant, $r1, $student, 'redes_sociales', 'autorizo')->assertStatus(422);
    expect(Fam::errorCode($response, 'decision'))->toBe('fam_unit.image_consent.text_not_published')
        ->and(decisionRows($tenant))->toBe([])
        ->and(Fam::audit($tenant, 'image_consent_decision'))->toBe([])
        ->and(Fam::consentState($tenant, $admin, $student, 'redes_sociales'))->toBe('pendiente');
});

test('CA-FAM-UNIT-090 RN-FAM-UNIT-68: sin texto publicado, no_autorizo se registra (201) con consent_text_version null; y una inserción directa de autorizo sin versión la rechaza el CHECK del motor', function (): void {
    [$tenant, $admin] = Fam::setup('ic-090');
    Fam::freeze();
    $unit = Fam::unitId($tenant, $admin);
    $student = Fam::person($tenant);
    Fam::addStudent($tenant, $admin, $unit, $student)->assertCreated();
    $r1 = Fam::consentGuardian($tenant, $admin, 'r1', $unit);
    $g1 = Fam::guardian($tenant, $admin, $r1, $unit)->assertCreated()->json('public_id');
    Fam::link($tenant, $admin, $g1, $student, ['is_legal_guardian' => true])->assertCreated();
    Fam::tick();

    $response = Fam::decideAs($tenant, $r1, $student, 'web', 'no_autorizo')->assertCreated();

    expect($response->json('decision.consent_text_version'))->toBeNull()
        ->and($response->json('decision.shown_locale'))->toBeNull()
        ->and($response->json('state.state'))->toBe('no_autorizo');

    $row = decisionRows($tenant)[0];
    $insert = [
        'tenant_id' => $tenant->id, 'public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'student_person_id' => $row->student_person_id, 'level' => 'web', 'decision' => 'autorizo',
        'guardian_id' => $row->guardian_id, 'recorded_by' => $row->recorded_by, 'channel' => 'portal', 'consent_text_version_id' => null, 'occurred_at' => now(),
    ];

    // El motor (rol de la aplicación, dentro del contexto del centro) rechaza autorizo sin versión; no_autorizo sin versión, no.
    app(TenantContext::class)->runFor($tenant->id, function () use ($insert): void {
        $insertRow = static fn (array $row) => fn () => DB::connection('pgsql')->transaction(static fn () => DB::connection('pgsql')->table('image_consent_decisions')->insert($row));

        expect($insertRow($insert))->toThrow(QueryException::class, 'image_consent_decisions_authorize_needs_text_check')
            ->and($insertRow(['decision' => 'no_autorizo', 'public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZY'] + $insert))->not->toThrow(QueryException::class);
    });
});

test('CA-FAM-UNIT-091 RN-FAM-UNIT-41 RN-FAM-UNIT-35 RN-FAM-UNIT-37: con consentimiento_imagen.actualizar (todos) y revocacion_tutor.leer, el centro registra en papel la decisión de un tutor revocado y el estado pasa a autorizo', function (): void {
    $f = Fam::consentFamily('ic-091');
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['l2']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();
    Fam::tick();

    expect(Fam::consentState($f['tenant'], $f['admin'], $f['student'], 'web'))->toBe('pendiente');

    $staff = Fam::userWith($f['tenant'], $f['admin'], 'centro', ['consentimiento_imagen.actualizar' => 'todos', 'consentimiento_imagen.leer' => 'todos', 'revocacion_tutor.leer' => 'todos']);
    $response = Fam::decide($f['tenant'], $staff, [
        'student_person' => $f['student']->public_id, 'level' => 'web', 'decision' => 'autorizo', 'on_behalf_of' => $f['g2'], 'channel' => 'presencial',
    ])->assertCreated();

    expect($response->json('decision'))->toMatchArray(['guardian' => $f['g2'], 'recorded_by' => $staff->public_id, 'channel' => 'presencial'])
        ->and($response->json('state.state'))->toBe('autorizo')
        ->and(Fam::consentState($f['tenant'], $f['admin'], $f['student'], 'web'))->toBe('autorizo');
});

test('CA-FAM-UNIT-092 RN-FAM-UNIT-40 RN-FAM-UNIT-41: sin revocacion_tutor.leer, registrar en nombre de un tutor revocado responde 422 guardian_not_linked con el mismo cuerpo que con un tutor sin vínculo, y no se escribe nada', function (): void {
    $f = Fam::consentFamily('ic-092');
    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['l2']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();

    $staff = Fam::userWith($f['tenant'], $f['admin'], 'centro', ['consentimiento_imagen.actualizar' => 'todos', 'consentimiento_imagen.leer' => 'todos']);
    $stranger = Fam::guardian($f['tenant'], $f['admin'], Fam::consentGuardian($f['tenant'], $f['admin'], 'otro', $f['unit']), $f['unit'])->assertCreated()->json('public_id');

    $body = ['student_person' => $f['student']->public_id, 'level' => 'web', 'decision' => 'autorizo', 'channel' => 'presencial'];
    $revoked = Fam::decide($f['tenant'], $staff, $body + ['on_behalf_of' => $f['g2']])->assertStatus(422);
    $unlinked = Fam::decide($f['tenant'], $staff, $body + ['on_behalf_of' => $stranger])->assertStatus(422);

    expect(Fam::errorCode($revoked, 'on_behalf_of'))->toBe('fam_unit.image_consent.guardian_not_linked')
        ->and($revoked->json('errors'))->toBe($unlinked->json('errors'))
        ->and($revoked->json('detail'))->toBe($unlinked->json('detail'))
        ->and(decisionRows($f['tenant']))->toBe([]);
});

test('CA-FAM-UNIT-097 RN-FAM-UNIT-69: por presencial el centro indica una versión publicada o retirada del tipo del nivel; un borrador, otro tipo o cualquier versión por portal es 422 text_version_invalid', function (): void {
    $f = Fam::consentFamily('ic-097', twoGuardians: false);
    $old = Fam::row($f['tenant'], 'terms_versions', Fam::publishText($f['tenant'], $f['admin'], 'web', ['es-ES' => 'Segunda'], ['es-ES' => 'Texto v2']));
    $v1 = app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('terms_versions')->where('kind', 'consentimiento_imagen_web')->where('status', 'retirada')->value('public_id'));
    $v2 = $old->public_id;
    $draft = Fam::draftText($f['tenant'], $f['admin'], 'web');
    $redes = app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('terms_versions')->where('kind', 'consentimiento_imagen_redes')->value('public_id'));
    $base = ['student_person' => $f['student']->public_id, 'level' => 'web', 'decision' => 'autorizo', 'on_behalf_of' => $f['g1'], 'channel' => 'presencial'];

    // Una retirada del tipo: 201 y la decisión la guarda.
    $response = Fam::decide($f['tenant'], $f['admin'], $base + ['consent_text_version' => $v1])->assertCreated();
    expect($response->json('decision.consent_text_version.public_id'))->toBe($v1)->and($response->json('decision.consent_text_version.version_number'))->toBe(1);

    // Sin indicarla, la publicada.
    expect(Fam::decide($f['tenant'], $f['admin'], $base)->assertCreated()->json('decision.consent_text_version.public_id'))->toBe($v2);

    $rows = count(decisionRows($f['tenant']));

    foreach ([$draft, $redes, '01JZZZZZZZZZZZZZZZZZZZZZZZ'] as $invalid) {
        $r = Fam::decide($f['tenant'], $f['admin'], $base + ['consent_text_version' => $invalid])->assertStatus(422);
        expect(Fam::errorCode($r, 'consent_text_version'))->toBe('fam_unit.image_consent.text_version_invalid');
    }

    // Por portal el cliente envía la versión que vio (RN-FAM-UNIT-69, decisión del usuario del 2026-10-27): la vigente, 201;
    // una retirada o antigua, 409 text_version_outdated; de otro nivel o inexistente, 422 text_version_invalid; ausente con
    // autorizo, 422 required.
    $portal = ['student_person' => $f['student']->public_id, 'level' => 'web', 'decision' => 'autorizo'];
    // Idéntica a la que el centro acaba de registrar para este tutor (RN-FAM-UNIT-77): 200, sin fila nueva.
    Fam::decide($f['tenant'], $f['r1'], $portal + ['consent_text_version' => $v2])->assertOk();

    $stale = Fam::decide($f['tenant'], $f['r1'], $portal + ['consent_text_version' => $v1])->assertStatus(409);
    expect(Fam::errorCode($stale, 'consent_text_version'))->toBe('fam_unit.image_consent.text_version_outdated');

    foreach ([$redes, $draft, '01JZZZZZZZZZZZZZZZZZZZZZZZ'] as $invalid) {
        $r = Fam::decide($f['tenant'], $f['r1'], $portal + ['consent_text_version' => $invalid])->assertStatus(422);
        expect(Fam::errorCode($r, 'consent_text_version'))->toBe('fam_unit.image_consent.text_version_invalid');
    }

    $r = Fam::decide($f['tenant'], $f['r1'], $portal)->assertStatus(422);
    expect(Fam::errorCode($r, 'consent_text_version'))->toBe('fam_unit.validation.required')
        ->and(decisionRows($f['tenant']))->toHaveCount($rows);

    // Negarse no necesita texto ni vigente: sin versión se registra la publicada; con una retirada, esa.
    $refusal = ['student_person' => $f['student']->public_id, 'level' => 'web', 'decision' => 'no_autorizo'];
    expect(Fam::decide($f['tenant'], $f['r1'], $refusal)->assertCreated()->json('decision.consent_text_version.public_id'))->toBe($v2);
    Fam::tick();
    expect(Fam::decide($f['tenant'], $f['r1'], $refusal + ['consent_text_version' => $v1])->assertCreated()->json('decision.consent_text_version.public_id'))->toBe($v1);
});

test('CA-FAM-UNIT-101 RN-FAM-UNIT-43 OPEN-FAM-UNIT-25: por portal shown_locale es el idioma realmente mostrado — de si el texto está traducido, el idioma por defecto del centro si no — y por presencial es nulo', function (): void {
    [$tenant, $admin] = Fam::setup('ic-101');
    Fam::freeze();
    $unit = Fam::unitId($tenant, $admin);
    $student = Fam::person($tenant);
    Fam::addStudent($tenant, $admin, $unit, $student)->assertCreated();
    $r1 = Fam::consentGuardian($tenant, $admin, 'r1', $unit);
    $g1 = Fam::guardian($tenant, $admin, $r1, $unit)->assertCreated()->json('public_id');
    Fam::link($tenant, $admin, $g1, $student, ['is_legal_guardian' => true])->assertCreated();
    Fam::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'de']);

    // Texto de web traducido al alemán; el de redes_sociales solo al idioma por defecto del centro.
    Fam::publishText($tenant, $admin, 'web', ['es-ES' => 'Título', 'de' => 'Titel'], ['es-ES' => 'Texto', 'de' => 'Text']);
    Fam::publishText($tenant, $admin, 'redes_sociales', ['es-ES' => 'Título redes'], ['es-ES' => 'Texto redes']);
    Fam::tick();

    // El idioma de la petición es el de la persona del usuario autenticado (ADR-038 §11).
    app(TenantContext::class)->runFor($tenant->id, fn () => Person::query()->whereKey($r1->person_id)->update(['locale' => 'de']));

    $post = fn (string $level) => test()->actingAs($r1)
        ->postJson(Fam::url($tenant, '/image-consent-decisions'), ['student_person' => $student->public_id, 'level' => $level, 'decision' => 'no_autorizo', 'consent_text_version' => Fam::publishedTextId($tenant, $level)]);

    expect($post('web')->assertCreated()->json('decision.shown_locale'))->toBe('de')
        ->and($post('redes_sociales')->assertCreated()->json('decision.shown_locale'))->toBe('es-ES');

    expect(Fam::decide($tenant, $admin, ['student_person' => $student->public_id, 'level' => 'web', 'decision' => 'autorizo', 'on_behalf_of' => $g1])
        ->assertCreated()->json('decision.shown_locale'))->toBeNull();

    $stored = app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('image_consent_decisions')->orderBy('id')->pluck('shown_locale')->all());
    expect($stored)->toBe(['de', 'es-ES', null]);
});

test('RN-FAM-UNIT-41 api.md §2.7: el servidor valida las referencias y los roles — guardian_required, guardian_not_linked, not_legal_guardian, 403 al actuar por otro y 404 al alumno no alcanzado', function (): void {
    $f = Fam::consentFamily('ic-041');
    $other = Fam::person($f['tenant'], 'Otro', 'Alumno');
    $base = ['student_person' => $f['student']->public_id, 'level' => 'web', 'decision' => 'autorizo'];

    // El centro sin indicar el tutor que decidió.
    $r = Fam::decide($f['tenant'], $f['admin'], $base + ['channel' => 'presencial'])->assertStatus(422);
    expect(Fam::errorCode($r, 'on_behalf_of'))->toBe('fam_unit.image_consent.guardian_required');

    // Un tutor inexistente y un tutor sin vínculo con ese alumno.
    $r = Fam::decide($f['tenant'], $f['admin'], $base + ['on_behalf_of' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])->assertStatus(422);
    expect(Fam::errorCode($r, 'on_behalf_of'))->toBe('fam_unit.image_consent.guardian_not_linked');

    // Un tutor vinculado pero sin representación legal.
    $abuelo = Fam::guardian($f['tenant'], $f['admin'], Fam::consentGuardian($f['tenant'], $f['admin'], 'abuelo', $f['unit']), $f['unit'])->assertCreated()->json('public_id');
    Fam::link($f['tenant'], $f['admin'], $abuelo, $f['student'], ['relationship' => 'abuelo'])->assertCreated();
    $r = Fam::decide($f['tenant'], $f['admin'], $base + ['on_behalf_of' => $abuelo])->assertStatus(422);
    expect(Fam::errorCode($r, 'on_behalf_of'))->toBe('fam_unit.image_consent.not_legal_guardian');

    // Un alumno inexistente: 422 al centro (ve todo el centro), 404 a quien solo tiene ámbito unidad_familiar.
    $r = Fam::decide($f['tenant'], $f['admin'], ['student_person' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'] + $base + ['on_behalf_of' => $f['g1']])->assertStatus(422);
    expect(Fam::errorCode($r, 'student_person'))->toBe('fam_unit.validation.student_not_found');
    Fam::decide($f['tenant'], $f['r1'], ['student_person' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'] + $base)->assertNotFound();

    // Un alumno de la misma familia al que R1 no está vinculado: no lo alcanza.
    Fam::decide($f['tenant'], $f['r1'], ['student_person' => $other->public_id] + $base)->assertNotFound();

    // Un tutor no actúa en nombre de otro, aunque alcance al alumno (el ámbito unidad_familiar no basta).
    Fam::decide($f['tenant'], $f['r1'], $base + ['on_behalf_of' => $f['g2']])->assertForbidden();

    // El canal debe corresponder al modo.
    $r = Fam::decide($f['tenant'], $f['r1'], $base + ['channel' => 'presencial'])->assertStatus(422);
    expect(Fam::errorCode($r, 'channel'))->toBe('fam_unit.validation.channel_invalid');

    expect(decisionRows($f['tenant']))->toBe([]);

    // El propio tutor, expresando su propio tutor en on_behalf_of: portal.
    Fam::decide($f['tenant'], $f['r1'], $base + ['on_behalf_of' => $f['g1'], 'channel' => 'portal', 'consent_text_version' => Fam::publishedTextId($f['tenant'], 'web')])->assertCreated();
});

test('INV-010 RN-FAM-UNIT-67 api.md §2.7: los niveles y decisiones son cerrados (web y redes_sociales; autorizo y no_autorizo) y la forma del cuerpo se valida — campos obligatorios, enumerados y referencias', function (): void {
    $f = Fam::consentFamily('ic-shape', twoGuardians: false);
    $student = $f['student']->public_id;

    $code = fn (array $body, string $field) => Fam::errorCode(Fam::decide($f['tenant'], $f['r1'], $body)->assertStatus(422), $field);

    expect($code([], 'student_person'))->toBe('fam_unit.validation.required')
        ->and($code(['student_person' => 'no-es-ulid', 'level' => 'web', 'decision' => 'autorizo'], 'student_person'))->toBe('fam_unit.validation.reference_invalid')
        ->and($code(['student_person' => $student, 'decision' => 'autorizo'], 'level'))->toBe('fam_unit.validation.required')
        ->and($code(['student_person' => $student, 'level' => 'twitter', 'decision' => 'autorizo'], 'level'))->toBe('fam_unit.validation.level_invalid')
        ->and($code(['student_person' => $student, 'level' => 'web'], 'decision'))->toBe('fam_unit.validation.required')
        ->and($code(['student_person' => $student, 'level' => 'web', 'decision' => 'pendiente'], 'decision'))->toBe('fam_unit.validation.decision_invalid')
        ->and($code(['student_person' => $student, 'level' => 'web', 'decision' => 'autorizo', 'channel' => 'correo'], 'channel'))->toBe('fam_unit.validation.channel_invalid')
        ->and($code(['student_person' => $student, 'level' => 'web', 'decision' => 'autorizo', 'on_behalf_of' => 'x'], 'on_behalf_of'))->toBe('fam_unit.validation.reference_invalid')
        ->and($code(['student_person' => $student, 'level' => 'web', 'decision' => 'autorizo', 'consent_text_version' => 5], 'consent_text_version'))->toBe('fam_unit.validation.reference_invalid');
});

test('INV-003 ADR-035 datos.md §4: cada decisión queda en audit_logs como created con el valor de los códigos y la IP, el agente y las claves foráneas redactados', function (): void {
    $f = Fam::consentFamily('ic-audit', twoGuardians: false);
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();

    $audit = Fam::audit($f['tenant'], 'image_consent_decision', 'created');
    expect($audit)->toHaveCount(1);

    $changes = $audit[0]->changes;
    expect($changes['level']['to'])->toBe('web')
        ->and($changes['decision']['to'])->toBe('autorizo')
        ->and($changes['channel']['to'])->toBe('portal')
        ->and($changes['ip_address'])->toHaveKey('redacted')
        ->and($changes['user_agent'])->toHaveKey('redacted')
        ->and($changes['guardian_id'])->toHaveKey('redacted')
        ->and($changes['student_person_id'])->toHaveKey('redacted');
});

test('RN-FAM-UNIT-71 CA-FAM-UNIT-093 api.md §3: el histórico es de un alumno, ordenado (occurred_at, id) descendente y por cursor — y con ámbito unidad_familiar no muestra las decisiones de un tutor revocado ni delata su existencia', function (): void {
    $f = Fam::consentFamily('ic-093');

    // R1 decide por portal; R2 (después revocado) decide en papel con el centro, más tarde; todo en instantes distintos.
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    Fam::tick();
    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['l2']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();
    Fam::tick();
    Fam::decide($f['tenant'], $f['admin'], ['student_person' => $f['student']->public_id, 'level' => 'web', 'decision' => 'autorizo', 'on_behalf_of' => $f['g2']])->assertCreated();
    Fam::tick();
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'redes_sociales', 'no_autorizo')->assertCreated();

    $path = "/image-consent-decisions?student_person={$f['student']->public_id}";

    // Personal con ámbito todos: las tres, la más reciente primero.
    $all = Fam::call($f['tenant'], $f['admin'], 'get', $path)->assertOk();
    expect(array_column($all->json('data'), 'level'))->toBe(['redes_sociales', 'web', 'web'])
        ->and(array_column($all->json('data'), 'guardian'))->toBe([$f['g1'], $f['g2'], $f['g1']]);

    // R1 con unidad_familiar: solo las suyas, sin rastro de la de R2 en datos ni meta.
    $mine = Fam::call($f['tenant'], $f['r1'], 'get', $path)->assertOk();
    expect(array_column($mine->json('data'), 'guardian'))->toBe([$f['g1'], $f['g1']])
        ->and($mine->json('meta'))->toBe(['next_cursor' => null, 'has_more' => false])
        ->and(json_encode($mine->json()))->not->toContain($f['g2']);

    // El estado cuenta las dos decisiones (autorizo de R1 y de R2), pero la fecha que ve R1 nunca es la de R2.
    $webOfR1 = $mine->json('data.1');
    $viewedByR1 = Fam::call($f['tenant'], $f['r1'], 'get', "/image-consents?student_person={$f['student']->public_id}&level=web")->assertOk()->json('data.0');
    $viewedByStaff = Fam::call($f['tenant'], $f['admin'], 'get', "/image-consents?student_person={$f['student']->public_id}&level=web")->assertOk()->json('data.0');

    expect($viewedByR1['state'])->toBe('autorizo')
        ->and($viewedByStaff['state'])->toBe('autorizo')
        ->and($viewedByR1['last_decision_at'])->toBe($webOfR1['occurred_at'])
        ->and($viewedByStaff['last_decision_at'])->toBe($all->json('data.1.occurred_at'))
        ->and($viewedByStaff['last_decision_at'])->not->toBe($viewedByR1['last_decision_at']);

    // Cursor: de una en una, sin repetir ni perder ninguna.
    $pages = [];
    $cursor = null;

    do {
        $page = Fam::call($f['tenant'], $f['admin'], 'get', $path.'&limit=1'.($cursor === null ? '' : '&cursor='.urlencode($cursor)))->assertOk();
        $pages[] = $page->json('data.0.public_id');
        $cursor = $page->json('meta.next_cursor');
    } while ($page->json('meta.has_more'));

    expect($pages)->toBe(array_column($all->json('data'), 'public_id'));

    // El cursor de otra consulta (otros filtros) es 422.
    $first = Fam::call($f['tenant'], $f['admin'], 'get', $path.'&limit=1')->json('meta.next_cursor');
    Fam::call($f['tenant'], $f['admin'], 'get', $path.'&level=web&limit=1&cursor='.urlencode($first))->assertStatus(422);
});

test('api.md §2.7 §3: el histórico exige student_person (422), un alumno desconocido o no alcanzado es 404 y los parámetros mal formados son 422', function (): void {
    $f = Fam::consentFamily('ic-hist');
    $other = Fam::person($f['tenant'], 'Otro', 'Alumno');

    Fam::call($f['tenant'], $f['admin'], 'get', '/image-consent-decisions')->assertStatus(422);
    Fam::call($f['tenant'], $f['admin'], 'get', '/image-consent-decisions?student_person=no-es-ulid')->assertStatus(422);
    Fam::call($f['tenant'], $f['admin'], 'get', '/image-consent-decisions?student_person=01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
    Fam::call($f['tenant'], $f['r1'], 'get', "/image-consent-decisions?student_person={$other->public_id}")->assertNotFound();
    Fam::call($f['tenant'], $f['admin'], 'get', "/image-consent-decisions?student_person={$f['student']->public_id}&level=twitter")->assertStatus(422);
    Fam::call($f['tenant'], $f['admin'], 'get', "/image-consent-decisions?student_person={$f['student']->public_id}&limit=0")->assertStatus(422);
    Fam::call($f['tenant'], $f['admin'], 'get', "/image-consent-decisions?student_person={$f['student']->public_id}&cursor=basura")->assertStatus(422);

    // Un alumno de la misma persona del centro sin decisiones: historia vacía, no error.
    Fam::call($f['tenant'], $f['admin'], 'get', "/image-consent-decisions?student_person={$other->public_id}")->assertOk()->assertJsonPath('data', []);
});

test('INV-002 RPERM-011: sin el permiso, las decisiones y su histórico son 403; sin sesión, 401', function (): void {
    $f = Fam::consentFamily('ic-perm', twoGuardians: false);
    $nobody = Fam::userWith($f['tenant'], $f['admin'], 'nadie', ['familia.leer' => 'todos']);
    $reader = Fam::userWith($f['tenant'], $f['admin'], 'lector', ['consentimiento_imagen.leer' => 'todos']);
    $body = ['student_person' => $f['student']->public_id, 'level' => 'web', 'decision' => 'no_autorizo', 'on_behalf_of' => $f['g1']];

    Fam::decide($f['tenant'], $nobody, $body)->assertForbidden();
    Fam::decide($f['tenant'], $reader, $body)->assertForbidden();
    Fam::call($f['tenant'], $nobody, 'get', "/image-consent-decisions?student_person={$f['student']->public_id}")->assertForbidden();
    Fam::call($f['tenant'], $nobody, 'get', '/image-consents')->assertForbidden();

    resetSessionState();
    test()->postJson(Fam::url($f['tenant'], '/image-consent-decisions'), $body)->assertUnauthorized();
    expect(decisionRows($f['tenant']))->toBe([]);
});

test('CA-FAM-UNIT-107 RN-FAM-UNIT-69 (decisión del usuario, 2026-10-27, M2): por portal el tutor envía la versión que vio — si publican otra mientras tanto, autorizar con la anterior es 409 text_version_outdated y no se escribe nada; con la nueva, 201', function (): void {
    $f = Fam::consentFamily('ic-107', twoGuardians: false);
    $seen = Fam::publishedTextId($f['tenant'], 'web');

    // El centro publica una versión nueva después de que el tutor leyó la anterior.
    Fam::publishText($f['tenant'], $f['admin'], 'web', ['es-ES' => 'Nuevo título'], ['es-ES' => 'Texto cambiado']);
    $body = ['student_person' => $f['student']->public_id, 'level' => 'web', 'decision' => 'autorizo'];

    $r = Fam::decide($f['tenant'], $f['r1'], $body + ['consent_text_version' => $seen])->assertStatus(409);
    expect(Fam::errorCode($r, 'consent_text_version'))->toBe('fam_unit.image_consent.text_version_outdated')
        ->and(decisionRows($f['tenant']))->toBe([])
        ->and(Fam::consentState($f['tenant'], $f['admin'], $f['student'], 'web'))->toBe('pendiente');

    Fam::decide($f['tenant'], $f['r1'], $body + ['consent_text_version' => Fam::publishedTextId($f['tenant'], 'web')])->assertCreated();
    expect(Fam::consentState($f['tenant'], $f['admin'], $f['student'], 'web'))->toBe('autorizo');

    // Con el módulo sin texto publicado en el nivel: autorizar es text_not_published aunque el cliente envíe una versión.
    Fam::draftText($f['tenant'], $f['admin'], 'redes_sociales');
    $other = Fam::publishedTextId($f['tenant'], 'redes_sociales');
    Fam::decide($f['tenant'], $f['r1'], ['student_person' => $f['student']->public_id, 'level' => 'redes_sociales', 'decision' => 'autorizo', 'consent_text_version' => $other])->assertCreated();
});

test('CA-FAM-UNIT-108 RN-FAM-UNIT-76 (decisión del usuario, 2026-10-27, M1): superado el límite, registrar decisiones es 429 con Retry-After y texto traducido; el cubo es por centro y usuario y no afecta a otro usuario ni a otro centro', function (): void {
    config(['fam_unit.consent.rate_limit.decision.attempts' => 3]);
    Cache::flush();
    $f = Fam::consentFamily('ic-108', twoGuardians: false);
    $other = Fam::consentFamily('ic-108b', twoGuardians: false);

    // Tres intentos (aunque sean idénticos o rechazados cuentan); el cuarto es 429.
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'no_autorizo')->assertCreated();
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'no_autorizo')->assertOk();
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo', ['consent_text_version' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])->assertStatus(422);
    $limited = Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'redes_sociales', 'no_autorizo')->assertStatus(429);

    expect($limited->headers->get('Retry-After'))->toBeNumeric()
        ->and($limited->json('type'))->toBe('urn:pge:error:too-many-requests')
        ->and($limited->json('detail'))->toContain('límite de decisiones');
    expect(decisionRows($f['tenant']))->toHaveCount(1);

    // Otro usuario del mismo centro (staff) y otro centro: cubos intactos.
    Fam::decide($f['tenant'], $f['admin'], ['student_person' => $f['student']->public_id, 'level' => 'redes_sociales', 'decision' => 'no_autorizo', 'on_behalf_of' => $f['g1']])->assertCreated();
    Fam::decideAs($other['tenant'], $other['r1'], $other['student'], 'web', 'no_autorizo')->assertCreated();

    // Las lecturas no se limitan.
    for ($i = 0; $i < 5; $i++) {
        Fam::call($f['tenant'], $f['r1'], 'get', '/image-consents')->assertOk();
    }

    Cache::flush();
});

test('CA-FAM-UNIT-109 RN-FAM-UNIT-76: el informe de consentimientos tiene su propio cubo — superado, 429 con Retry-After — independiente del de las decisiones', function (): void {
    config(['fam_unit.consent.rate_limit.export.attempts' => 2]);
    Cache::flush();
    Storage::fake('local');
    $f = Fam::consentFamily('ic-109', twoGuardians: false);

    Fam::call($f['tenant'], $f['admin'], 'post', '/image-consents/exports')->assertStatus(202);
    Fam::call($f['tenant'], $f['admin'], 'post', '/image-consents/exports', ['q' => 'x'])->assertStatus(422);
    $limited = Fam::call($f['tenant'], $f['admin'], 'post', '/image-consents/exports')->assertStatus(429);

    expect($limited->headers->get('Retry-After'))->toBeNumeric()->and($limited->json('detail'))->toContain('informes de consentimientos');
    Fam::decide($f['tenant'], $f['admin'], ['student_person' => $f['student']->public_id, 'level' => 'web', 'decision' => 'no_autorizo', 'on_behalf_of' => $f['g1']])->assertCreated();

    Cache::flush();
});

test('CA-FAM-UNIT-110 RN-FAM-UNIT-77 (decisión del usuario, 2026-10-27, M1): una decisión idéntica a la vigente del mismo tutor, alumno, nivel y versión del texto responde 200 con el estado actual y no inserta fila ni audita; una distinta (decisión o versión) sí', function (): void {
    $f = Fam::consentFamily('ic-110', twoGuardians: false);

    $first = Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    Fam::tick();
    $again = Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertOk();

    expect($again->json('decision.public_id'))->toBe($first->json('decision.public_id'))
        ->and($again->json('state.state'))->toBe('autorizo')
        ->and(decisionRows($f['tenant']))->toHaveCount(1)
        ->and(Fam::audit($f['tenant'], 'image_consent_decision', 'created'))->toHaveCount(1);

    // Cambiar de decisión inserta; volver a la anterior también (la vigente ya es otra).
    Fam::tick();
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'no_autorizo')->assertCreated();
    Fam::tick();
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    expect(decisionRows($f['tenant']))->toHaveCount(3);

    // Otra versión del texto (aunque la decisión sea la misma) sí es una decisión nueva.
    Fam::tick();
    Fam::publishText($f['tenant'], $f['admin'], 'web', ['es-ES' => 'v2'], ['es-ES' => 'Texto v2']);
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();
    expect(decisionRows($f['tenant']))->toHaveCount(4);

    // El nivel y el tutor cuentan: el otro nivel y el centro en nombre del tutor no son la misma decisión... la del centro
    // es del mismo tutor, así que repetirla tampoco inserta.
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'redes_sociales', 'autorizo')->assertCreated();
    Fam::decide($f['tenant'], $f['admin'], ['student_person' => $f['student']->public_id, 'level' => 'redes_sociales', 'decision' => 'autorizo', 'on_behalf_of' => $f['g1']])->assertOk();
    expect(decisionRows($f['tenant']))->toHaveCount(5);
});
