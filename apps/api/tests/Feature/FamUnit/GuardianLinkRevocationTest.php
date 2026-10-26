<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use App\Modules\FamUnit\Domain\FamilyMembership;
use App\Modules\FamUnit\Domain\GuardianRecipients;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-002 (1.14-a), docs/modulos/REQ-FAM-UNIT/api.md §2.5, funcional.md §3.5 y §5.3: revocación por custodia.
// CA-FAM-UNIT-029 a -039, INV-002 (permiso propio), INV-003, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * La familia del enunciado de CA-FAM-UNIT-030: una madre M y un padre P, tutores de la unidad U, vinculados a los
 * alumnos A1 y A2 (de U).
 *
 * @return array{tenant: object, admin: User, unit: string, m: array{0: User, 1: string}, p: array{0: User, 1: string}, a1: Person, a2: Person, links: array<string, string>}
 */
function custodyFamily(string $slug): array
{
    [$tenant, $admin] = Fam::setup($slug);
    $unit = Fam::unitId($tenant, $admin);
    $m = Fam::familyUser($tenant, $admin, 'madre', $unit);
    $p = Fam::familyUser($tenant, $admin, 'padre', $unit);
    $a1 = Fam::person($tenant, 'Alumno', 'Uno');
    $a2 = Fam::person($tenant, 'Alumno', 'Dos');

    foreach ([$a1, $a2] as $student) {
        Fam::addStudent($tenant, $admin, $unit, $student)->assertCreated();
    }

    $links = [];

    foreach (['m' => $m, 'p' => $p] as $key => [, $guardian]) {
        foreach (['1' => $a1, '2' => $a2] as $n => $student) {
            $links["{$key}{$n}"] = Fam::link($tenant, $admin, $guardian, $student, ['relationship' => $key === 'm' ? 'madre' : 'padre', 'is_legal_guardian' => true])->assertCreated()->json('public_id');
        }
    }

    return compact('tenant', 'admin', 'unit', 'm', 'p', 'a1', 'a2', 'links');
}

function revoke(array $family, string $link, array $body = ['reason' => 'resolucion_judicial']): TestResponse
{
    return Fam::call($family['tenant'], $family['admin'], 'post', "/guardian-links/{$link}/revocation", $body);
}

/** Ids de persona de alumnos alcanzados por un usuario (`FamilyMembership`), en el contexto del centro. */
function reachedBy(object $tenant, User $user): array
{
    return app(TenantContext::class)->runFor($tenant->id, fn () => app(FamilyMembership::class)->studentPersonIdsReachedBy($user->person_id));
}

test('CA-FAM-UNIT-030 nota legal de REQ-FAM-UNIT-002: revocar el vínculo (P, A1) no afecta a los demás — P sigue alcanzando a A2 y M a A1 y A2', function (): void {
    $f = custodyFamily('rv-030');
    [$madre] = $f['m'];
    [$padre] = $f['p'];

    $response = revoke($f, $f['links']['p1'])->assertOk();

    expect($response->json())->toMatchArray(['status' => 'revocado', 'principal_cleared' => false])
        ->and($response->json('revocation'))->toMatchArray(['reason' => 'resolucion_judicial'])
        ->and($response->json('revocation.revoked_at'))->not->toBeNull()
        ->and($response->json('revocation.revoked_by'))->toBe($f['admin']->public_id);

    expect(reachedBy($f['tenant'], $padre))->toBe([$f['a2']->id])
        ->and(reachedBy($f['tenant'], $madre))->toEqualCanonicalizing([$f['a1']->id, $f['a2']->id]);

    // Por la API: P ve el vínculo de A2, no el de A1; M ve los suyos y los de P sobre A2 (los activos).
    $padreLinks = array_column(Fam::call($f['tenant'], $padre, 'get', '/guardian-links')->assertOk()->json('data'), 'public_id');
    expect($padreLinks)->toEqualCanonicalizing([$f['links']['m2'], $f['links']['p2']]);

    $madreLinks = array_column(Fam::call($f['tenant'], $madre, 'get', '/guardian-links')->assertOk()->json('data'), 'public_id');
    expect($madreLinks)->toEqualCanonicalizing([$f['links']['m1'], $f['links']['m2'], $f['links']['p2']]);
});

test('CA-FAM-UNIT-031 RN-FAM-UNIT-36: el tutor revocado no ve ningún vínculo revocado ni la unidad de un alumno que ya no alcanza (404 como si no existiera)', function (): void {
    [$tenant, $admin] = Fam::setup('rv-031');
    $unit = Fam::unitId($tenant, $admin);
    $other = Fam::unitId($tenant, $admin, 'OTRA');
    [$padre, $guardian] = Fam::familyUser($tenant, $admin, 'padre', $unit);
    $a1 = Fam::person($tenant, 'Unico', 'Alumno');
    Fam::addStudent($tenant, $admin, $unit, $a1)->assertCreated();
    $link = Fam::link($tenant, $admin, $guardian, $a1)->assertCreated()->json('public_id');

    // Antes de revocar lo ve todo.
    Fam::call($tenant, $padre, 'get', "/family-units/{$unit}")->assertOk();
    Fam::call($tenant, $padre, 'get', "/guardian-links/{$link}")->assertOk();

    Fam::call($tenant, $admin, 'post', "/guardian-links/{$link}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();

    expect(Fam::call($tenant, $padre, 'get', '/guardian-links')->assertOk()->json('data'))->toBe([])
        ->and(Fam::call($tenant, $padre, 'get', '/family-units')->assertOk()->json('data'))->toBe([])
        ->and(Fam::call($tenant, $padre, 'get', '/guardians')->assertOk()->json('data'))->toBe([]);

    Fam::call($tenant, $padre, 'get', "/guardian-links/{$link}")->assertNotFound();
    Fam::call($tenant, $padre, 'get', "/family-units/{$unit}")->assertNotFound();
    Fam::call($tenant, $padre, 'get', "/guardians/{$guardian}")->assertNotFound();
    Fam::call($tenant, $padre, 'get', "/family-units/{$unit}/students")->assertNotFound();

    // El 404 es el mismo que el de un id que no existe, y el filtro por el alumno revocado no es error: devuelve vacío.
    $missing = Fam::call($tenant, $padre, 'get', '/guardian-links/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
    $revokedBody = Fam::call($tenant, $padre, 'get', "/guardian-links/{$link}")->assertNotFound()->json();
    expect(Arr::except($revokedBody, ['instance', 'request_id']))->toBe(Arr::except($missing->json(), ['instance', 'request_id']));
    expect(Fam::call($tenant, $padre, 'get', "/guardian-links?student_person={$a1->public_id}")->assertOk()->json('data'))->toBe([]);
    Fam::call($tenant, $padre, 'get', "/family-units/{$other}")->assertNotFound();
});

test('CA-FAM-UNIT-032 RN-FAM-UNIT-34: revocada en la petición N, en la N+1 ya no alcanza al alumno — sin caché ni cierre de sesión — y sigue viendo a sus otros hijos', function (): void {
    $f = custodyFamily('rv-032');
    [$padre] = $f['p'];

    // Petición N-1: ve a los dos hijos (esto no debe quedar en ninguna caché).
    expect(array_column(Fam::call($f['tenant'], $padre, 'get', "/guardian-links?student_person={$f['a1']->public_id}")->assertOk()->json('data'), 'public_id'))->toContain($f['links']['p1']);
    expect(reachedBy($f['tenant'], $padre))->toEqualCanonicalizing([$f['a1']->id, $f['a2']->id]);

    revoke($f, $f['links']['p1'])->assertOk();

    // Petición N+1, misma sesión del tutor.
    Fam::call($f['tenant'], $padre, 'get', "/guardian-links/{$f['links']['p1']}")->assertNotFound();
    expect(Fam::call($f['tenant'], $padre, 'get', "/guardian-links?student_person={$f['a1']->public_id}")->assertOk()->json('data'))->toBe([])
        ->and(reachedBy($f['tenant'], $padre))->toBe([$f['a2']->id]);
    Fam::call($f['tenant'], $padre, 'get', "/guardian-links/{$f['links']['p2']}")->assertOk();
});

test('CA-FAM-UNIT-033 funcional.md §7.1 §7.2 ADR-032: FamilyMembership::isAccessRevoked(P, A1) es true, y GuardianRecipients para A1 no devuelve a P pero sí a M', function (): void {
    $f = custodyFamily('rv-033');
    [$madre] = $f['m'];
    [$padre] = $f['p'];

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f, $padre): void {
        $membership = app(FamilyMembership::class);

        expect($membership->isAccessRevoked($padre->person_id, $f['a1']->id))->toBeFalse();
    });

    revoke($f, $f['links']['p1'])->assertOk();

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f, $padre, $madre): void {
        $membership = app(FamilyMembership::class);

        expect($membership->isAccessRevoked($padre->person_id, $f['a1']->id))->toBeTrue()
            ->and($membership->isAccessRevoked($padre->person_id, $f['a2']->id))->toBeFalse()
            ->and($membership->isAccessRevoked($madre->person_id, $f['a1']->id))->toBeFalse();

        $recipients = app(GuardianRecipients::class)->recipientsFor([$f['a1']->id]);
        $both = app(GuardianRecipients::class)->recipientsFor([$f['a1']->id, $f['a2']->id]);

        expect(array_map(static fn ($r): int => $r->userId, $recipients))->toBe([$madre->id])
            ->and(array_map(static fn ($r): int => $r->userId, $both))->toBe([$madre->id, $padre->id]);

        // P sigue siendo destinatario por A2 solamente.
        $forPadre = array_values(array_filter($both, static fn ($r): bool => $r->userId === $padre->id))[0];
        expect($forPadre->studentPersonIds)->toBe([$f['a2']->id]);

        // Los tutores activos de A1 ya no incluyen a P.
        expect(array_map(static fn ($g): int => $g->guardianPersonId, $membership->activeGuardiansOf($f['a1']->id)))->toBe([$madre->person_id]);
    });
});

test('CA-FAM-UNIT-034 RN-FAM-UNIT-33: revocar uno revocado o levantar uno activo es 409 invalid_transition', function (): void {
    $f = custodyFamily('rv-034');

    $lift = fn (string $link) => Fam::call($f['tenant'], $f['admin'], 'delete', "/guardian-links/{$link}/revocation");

    expect($lift($f['links']['p1'])->assertStatus(409)->json('errors.status.0.code'))->toBe('fam_unit.link.invalid_transition');

    revoke($f, $f['links']['p1'])->assertOk();
    expect(revoke($f, $f['links']['p1'])->assertStatus(409)->json('errors.status.0.code'))->toBe('fam_unit.link.invalid_transition');

    $lift($f['links']['p1'])->assertOk();
    expect($lift($f['links']['p1'])->assertStatus(409)->json('errors.status.0.code'))->toBe('fam_unit.link.invalid_transition');

    // Un vínculo que no existe o es de otro centro: 404.
    revoke($f, '01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
    $lift('01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
});

test('CA-FAM-UNIT-035 RN-FAM-UNIT-37: levantar la revocación devuelve el vínculo a activo, P vuelve a alcanzar a A1, y audit_logs conserva las dos transiciones con autor y fecha', function (): void {
    $f = custodyFamily('rv-035');
    [$padre] = $f['p'];

    revoke($f, $f['links']['p1'])->assertOk();
    expect(reachedBy($f['tenant'], $padre))->toBe([$f['a2']->id]);

    $lifted = Fam::call($f['tenant'], $f['admin'], 'delete', "/guardian-links/{$f['links']['p1']}/revocation")->assertOk();

    expect($lifted->json('status'))->toBe('activo')
        ->and($lifted->json('revocation'))->toBeNull()
        ->and(reachedBy($f['tenant'], $padre))->toEqualCanonicalizing([$f['a1']->id, $f['a2']->id]);

    $row = Fam::row($f['tenant'], 'guardian_student_links', $f['links']['p1']);
    expect($row->revocation_reason)->toBeNull()->and($row->revoked_at)->toBeNull()->and($row->revoked_by)->toBeNull();

    $updates = array_values(array_filter(Fam::audit($f['tenant'], 'guardian_student_link', 'updated'), static fn (AuditLog $a): bool => array_key_exists('status', $a->changes)));

    expect($updates)->toHaveCount(2)
        ->and($updates[0]->changes['status'])->toBe(['to' => 'revocado', 'from' => 'activo'])
        ->and($updates[0]->changes['revocation_reason'])->toBe(['to' => 'resolucion_judicial', 'from' => null])
        ->and($updates[1]->changes['status'])->toBe(['to' => 'activo', 'from' => 'revocado'])
        ->and($updates[0]->actor_user_id)->toBe($f['admin']->id)
        ->and($updates[1]->actor_user_id)->toBe($f['admin']->id)
        ->and($updates[0]->occurred_at)->not->toBeNull();
});

test('CA-FAM-UNIT-036 RN-FAM-UNIT-31: el vínculo que era contacto de emergencia deja de serlo al revocarse, en la misma transacción, y el alumno puede tener otro', function (): void {
    $f = custodyFamily('rv-036');

    Fam::call($f['tenant'], $f['admin'], 'patch', "/guardian-links/{$f['links']['p1']}", ['is_emergency_contact' => true])->assertOk();

    $revoked = revoke($f, $f['links']['p1'])->assertOk();

    expect($revoked->json('is_emergency_contact'))->toBeFalse();
    Fam::call($f['tenant'], $f['admin'], 'patch', "/guardian-links/{$f['links']['m1']}", ['is_emergency_contact' => true])->assertOk();

    // Levantar no devuelve la marca.
    $lifted = Fam::call($f['tenant'], $f['admin'], 'delete', "/guardian-links/{$f['links']['p1']}/revocation")->assertOk();
    expect($lifted->json('is_emergency_contact'))->toBeFalse();
});

test('CA-FAM-UNIT-037 INV-002: sin revocacion_tutor.crear no se revoca (403) aunque se pueda editar el vínculo; sin eliminar no se levanta; y PATCH no acepta status', function (): void {
    $f = custodyFamily('rv-037');
    $editor = Fam::userWith($f['tenant'], $f['admin'], 'editor', ['vinculo_tutor.actualizar' => 'todos', 'vinculo_tutor.leer' => 'todos']);

    Fam::call($f['tenant'], $editor, 'post', "/guardian-links/{$f['links']['p1']}/revocation", ['reason' => 'resolucion_judicial'])->assertForbidden();
    Fam::call($f['tenant'], $editor, 'patch', "/guardian-links/{$f['links']['p1']}", ['status' => 'revocado'])->assertStatus(422);

    revoke($f, $f['links']['p1'])->assertOk();
    Fam::call($f['tenant'], $editor, 'delete', "/guardian-links/{$f['links']['p1']}/revocation")->assertForbidden();

    // Quien solo puede revocar (crear) no puede levantar ni leer el detalle de la revocación.
    $revoker = Fam::userWith($f['tenant'], $f['admin'], 'revocador', ['revocacion_tutor.crear' => 'todos', 'vinculo_tutor.leer' => 'todos']);
    Fam::call($f['tenant'], $revoker, 'delete', "/guardian-links/{$f['links']['p1']}/revocation")->assertForbidden();
    revoke($f, $f['links']['m1'])->assertOk();

    $anon = Fam::userWith($f['tenant'], $f['admin'], 'nada', ['usuario.leer' => 'todos']);
    Fam::call($f['tenant'], $anon, 'post', "/guardian-links/{$f['links']['p2']}/revocation", ['reason' => 'resolucion_judicial'])->assertForbidden();
});

test('api.md §2.5: el motivo es obligatorio y de vocabulario cerrado (solo resolucion_judicial); no hay campo de texto libre', function (): void {
    $f = custodyFamily('rv-reason');

    expect(Fam::errorCode(revoke($f, $f['links']['p1'], [])->assertStatus(422), 'reason'))->toBe('fam_unit.validation.required')
        ->and(Fam::errorCode(revoke($f, $f['links']['p1'], ['reason' => 'otra_cosa'])->assertStatus(422), 'reason'))->toBe('fam_unit.validation.reason_invalid')
        ->and(Fam::errorCode(revoke($f, $f['links']['p1'], ['reason' => 5])->assertStatus(422), 'reason'))->toBe('fam_unit.validation.reason_invalid');

    // Un texto libre adicional se ignora: no se guarda en ninguna parte.
    revoke($f, $f['links']['p1'], ['reason' => 'resolucion_judicial', 'notes' => 'Sentencia 123/2026 del Juzgado nº 4'])->assertOk();
    $row = (array) Fam::row($f['tenant'], 'guardian_student_links', $f['links']['p1']);
    expect(json_encode($row))->not->toContain('Sentencia');

    $audit = json_encode(array_map(static fn (AuditLog $a): array => $a->changes, Fam::audit($f['tenant'], 'guardian_student_link')));
    expect($audit)->not->toContain('Sentencia');
});

test('CA-FAM-UNIT-029 RN-FAM-UNIT-38: revocar el último vínculo activo del tutor principal con alumnos de su unidad deja la unidad sin principal; mientras le quede otro, sigue siéndolo', function (): void {
    $f = custodyFamily('rv-029');
    Fam::call($f['tenant'], $f['admin'], 'patch', "/family-units/{$f['unit']}", ['principal_guardian' => $f['p'][1]])->assertOk();

    // P conserva el vínculo con A2: sigue siendo principal.
    $first = revoke($f, $f['links']['p1'])->assertOk();
    expect($first->json('principal_cleared'))->toBeFalse();
    Fam::call($f['tenant'], $f['admin'], 'get', "/family-units/{$f['unit']}")->assertOk()->assertJsonPath('principal_guardian', $f['p'][1]);

    // El último: la unidad queda sin principal en la misma transacción.
    $last = revoke($f, $f['links']['p2'])->assertOk();
    expect($last->json('principal_cleared'))->toBeTrue();
    Fam::call($f['tenant'], $f['admin'], 'get', "/family-units/{$f['unit']}")->assertOk()->assertJsonPath('principal_guardian', null);

    // Otro tutor revocado que no era principal no cambia nada.
    $madre = revoke($f, $f['links']['m1'])->assertOk();
    expect($madre->json('principal_cleared'))->toBeFalse();
});

test('CA-FAM-UNIT-038 RN-FAM-UNIT-35: un vínculo revocado no cuenta como alcance, destinatario ni contacto de emergencia, y no puede marcarse como tal (422 emergency_contact_revoked)', function (): void {
    $f = custodyFamily('rv-038');
    [$padre] = $f['p'];

    revoke($f, $f['links']['p1'])->assertOk();

    $response = Fam::call($f['tenant'], $f['admin'], 'patch', "/guardian-links/{$f['links']['p1']}", ['is_emergency_contact' => true])->assertStatus(422);
    expect(Fam::errorCode($response, 'is_emergency_contact'))->toBe('fam_unit.link.emergency_contact_revoked');

    // Otros atributos del vínculo revocado siguen siendo editables, y desmarcar la emergencia no es error.
    Fam::call($f['tenant'], $f['admin'], 'patch', "/guardian-links/{$f['links']['p1']}", ['is_emergency_contact' => false, 'access_level' => 'lectura_escritura'])->assertOk();

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f, $padre): void {
        expect(app(FamilyMembership::class)->studentPersonIdsReachedBy($padre->person_id))->toBe([$f['a2']->id])
            ->and(array_map(static fn ($r): int => $r->userId, app(GuardianRecipients::class)->recipientsFor([$f['a1']->id])))->not->toContain($padre->id)
            ->and(DB::table('guardian_student_links')->where('is_emergency_contact', true)->where('status', 'revocado')->count())->toBe(0);
    });
});

test('CA-FAM-UNIT-039 RN-FAM-UNIT-37: revocar y levantar no cambian la representación legal ni su verificación', function (): void {
    $f = custodyFamily('rv-039');
    $before = Fam::row($f['tenant'], 'guardian_student_links', $f['links']['p1']);

    expect($before->is_legal_guardian)->toBeTrue()->and($before->legal_guardian_verified_at)->not->toBeNull();

    revoke($f, $f['links']['p1'])->assertOk();
    $revoked = Fam::row($f['tenant'], 'guardian_student_links', $f['links']['p1']);

    Fam::call($f['tenant'], $f['admin'], 'delete', "/guardian-links/{$f['links']['p1']}/revocation")->assertOk();
    $lifted = Fam::row($f['tenant'], 'guardian_student_links', $f['links']['p1']);

    foreach ([$revoked, $lifted] as $row) {
        expect($row->is_legal_guardian)->toBe($before->is_legal_guardian)
            ->and($row->legal_guardian_verified_at)->toBe($before->legal_guardian_verified_at)
            ->and($row->legal_guardian_verified_by)->toBe($before->legal_guardian_verified_by);
    }
});

test('RN-FAM-UNIT-36 api.md §1.4: quien tiene revocacion_tutor.leer ve el vínculo revocado con su motivo, fecha y autor; quien no, no ve el campo revocation (D-2)', function (): void {
    $f = custodyFamily('rv-036b');
    revoke($f, $f['links']['p1'])->assertOk();

    $reviewer = Fam::userWith($f['tenant'], $f['admin'], 'revisor', ['vinculo_tutor.leer' => 'todos', 'revocacion_tutor.leer' => 'todos']);
    $plain = Fam::userWith($f['tenant'], $f['admin'], 'plano', ['vinculo_tutor.leer' => 'todos']);

    $withDetail = Fam::call($f['tenant'], $reviewer, 'get', "/guardian-links/{$f['links']['p1']}")->assertOk();
    $without = Fam::call($f['tenant'], $plain, 'get', "/guardian-links/{$f['links']['p1']}")->assertOk();

    expect($withDetail->json('revocation.reason'))->toBe('resolucion_judicial')
        ->and($withDetail->json('revocation.revoked_by'))->toBe($f['admin']->public_id)
        ->and($without->json())->not->toHaveKey('revocation')
        ->and($without->json('status'))->toBe('revocado');

    $listed = Fam::call($f['tenant'], $reviewer, 'get', '/guardian-links?status=revocado')->assertOk();
    expect($listed->json('meta.total'))->toBe(1)->and($listed->json('data.0.revocation.reason'))->toBe('resolucion_judicial');
});
