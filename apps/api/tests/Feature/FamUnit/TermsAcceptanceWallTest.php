<?php

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Modules\Auth\Domain\MfaObligation;
use App\Modules\Auth\Domain\MfaObligationTrigger;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Auth\Domain\Models\UserMfaObligation;
use App\Modules\Auth\Infrastructure\MfaEnrollmentWall;
use App\Modules\FamUnit\Domain\Models\TermsAcceptance;
use App\Support\Sessions\SessionWallRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-003 (1.14-c), docs/modulos/REQ-FAM-UNIT/funcional.md §4.10, §5.4 (RN-FAM-UNIT-61 a -65), §12.7
// (CA-FAM-UNIT-070 a -077), ADR-067 §5.4 a §5.10 y §7 (CA-067-05 a -08), INV-001, INV-002, INV-003, INV-010, INV-015: el
// muro de condiciones de uso, con base de datos real (ADR-033 §10).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/** Hace que el usuario esté obligado a MFA con la gracia vencida y sin factor: el muro de MFA lo detiene (CA-AUTH-129). */
function forceMfaWall(object $tenant, User $user): void
{
    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        Role::query()->whereIn('id', $user->roles()->pluck('roles.id'))->update(['mfa_required' => true]);

        UserMfaObligation::create([
            'user_id' => $user->id,
            'obligated_since' => now()->subDays(10),
            'grace_deadline_at' => now()->subDay(),
            'trigger' => 'rol_asignado',
        ]);
    });
}

/** Decorador de `MfaPolicy` que cuenta las llamadas a `resolve()` (CA-067-05); el resto delega sin cambios. */
final class CountingMfaPolicy implements MfaPolicy
{
    public static int $resolveCalls = 0;

    public function __construct(private readonly MfaPolicy $inner) {}

    public function resolve(User $user): MfaObligation
    {
        self::$resolveCalls++;

        return $this->inner->resolve($user);
    }

    public function hasUsableFactor(User $user): bool
    {
        return $this->inner->hasUsableFactor($user);
    }

    public function hasLiveExemption(User $user): bool
    {
        return $this->inner->hasLiveExemption($user);
    }

    public function requiredByRoleCodes(User $user): array
    {
        return $this->inner->requiredByRoleCodes($user);
    }

    public function materialize(User $user, MfaObligationTrigger $trigger): void
    {
        $this->inner->materialize($user, $trigger);
    }
}

/** @return array{0: int, 1: list<string>} */
function queriesOf(callable $request): array
{
    $sql = [];
    $collecting = true;

    DB::listen(function ($query) use (&$sql, &$collecting): void {
        if ($collecting) {
            $sql[] = $query->sql;
        }
    });

    $request();
    $collecting = false;

    return [count($sql), $sql];
}

test('CA-FAM-UNIT-070 RN-FAM-UNIT-61 RN-FAM-UNIT-63: sin versión publicada no hay muro; con ella, el tutor con vínculo activo recibe 403 terms-acceptance-required en toda ruta no exenta y puede salir aceptando', function (): void {
    $f = Fam::termsFamily('wall-070');
    ['tenant' => $tenant, 'admin' => $admin, 'tutor' => $tutor] = $f;

    // RN-FAM-UNIT-63: sin versión publicada, nadie está obligado.
    Fam::call($tenant, $tutor, 'get', '/family-units')->assertOk();
    expect(Fam::call($tenant, $tutor, 'get', '/me')->assertOk()->json('session_walls'))->toBe([]);

    $version = Fam::publishTerms($tenant, $admin);

    // Cualquier ruta que no sea de la lista exenta efectiva: 403 con el type del muro y sin miembros de extensión.
    foreach ([['get', '/family-units'], ['get', '/guardian-links'], ['get', '/auth/sessions'], ['patch', '/me'], ['get', '/me/effective-permissions']] as [$method, $path]) {
        $r = Fam::call($tenant, $tutor, $method, $path)->assertStatus(403);

        expect($r->json('type'))->toBe('urn:pge:error:terms-acceptance-required', "{$method} {$path}")
            ->and(array_keys($r->json()))->not->toContain('params')
            ->and($r->json('title'))->not->toBe('errors.title.terms-acceptance-required')
            ->and($r->json('detail'))->not->toBe('errors.detail.terms-acceptance-required');
    }

    // L3: las seis rutas propias del muro pasan (aquí, las que existen con sesión: GET /me, GET /me/terms, el cierre de sesión).
    Fam::call($tenant, $tutor, 'get', '/me')->assertOk();
    $terms = Fam::call($tenant, $tutor, 'get', '/me/terms')->assertOk();
    expect($terms->json('required'))->toBeTrue()
        ->and($terms->json('version.public_id'))->toBe($version)
        ->and($terms->json('version.kind'))->toBe('condiciones_uso')
        ->and($terms->json('version.body'))->toBe('Texto de las condiciones de uso del centro.');

    // La herencia de exenciones de OPEN-067-01: las tres rutas de MFA que el muro de condiciones no nombra pasan sin evaluar el muro.
    Fam::call($tenant, $tutor, 'get', '/auth/mfa')->assertOk();
    expect(Fam::wallType(Fam::call($tenant, $tutor, 'post', '/auth/mfa-enrollments', ['method' => 'totp'])))->toBeNull();
    expect(Fam::wallType(Fam::call($tenant, $tutor, 'post', '/auth/mfa-factors', [])))->toBeNull();

    // Aceptar: la petición siguiente pasa.
    Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => $version])->assertCreated();
    Fam::call($tenant, $tutor, 'get', '/family-units')->assertOk();
    Fam::call($tenant, $tutor, 'patch', '/me', ['person' => ['contact_phone' => '600000000']])->assertOk();
    expect(Fam::call($tenant, $tutor, 'get', '/me/terms')->json())->toBe(['required' => false, 'version' => null])
        ->and(Fam::call($tenant, $tutor, 'get', '/me')->json('session_walls'))->toBe([]);
});

test('CA-FAM-UNIT-070 ADR-067 §5.10: GET /me expone session_walls con los muros activos y bloqueantes, en el orden del registro', function (): void {
    $f = Fam::termsFamily('wall-me');
    ['tenant' => $tenant, 'admin' => $admin, 'tutor' => $tutor] = $f;
    Fam::publishTerms($tenant, $admin);

    expect(Fam::call($tenant, $tutor, 'get', '/me')->assertOk()->json('session_walls'))->toBe(['terms-acceptance']);

    // El administrador (sin faceta de tutor) no tiene ninguno.
    expect(Fam::call($tenant, $admin, 'get', '/me')->assertOk()->json('session_walls'))->toBe([]);
});

test('CA-FAM-UNIT-071 CA-FAM-UNIT-077 RN-FAM-UNIT-64 RN-FAM-UNIT-65: la aceptación registra usuario, versión, idioma mostrado, instante, IP y agente; es inmutable e idempotente (201 y luego 200 con la misma fila)', function (): void {
    $f = Fam::termsFamily('wall-071');
    ['tenant' => $tenant, 'admin' => $admin, 'tutor' => $tutor] = $f;
    $version = Fam::publishTerms($tenant, $admin);
    Fam::freeze('2026-10-28 09:00:00');

    $first = test()->actingAs($tutor)->json('POST', Fam::url($tenant, '/me/terms-acceptances'), ['terms_version' => $version], ['User-Agent' => 'PruebaNavegador/1.0'])->assertCreated();

    expect(array_keys($first->json()))->toBe(['public_id', 'user', 'terms_version', 'version_number', 'shown_locale', 'accepted_at'])
        ->and($first->json())->toMatchArray(['user' => $tutor->public_id, 'terms_version' => $version, 'version_number' => 1, 'shown_locale' => 'es-ES'])
        ->and($first->json('accepted_at'))->toContain('2026-10-28T09:00:00');

    $row = Fam::row($tenant, 'terms_acceptances', $first->json('public_id'));
    expect($row->user_id)->toBe($tutor->id)
        ->and($row->shown_locale)->toBe('es-ES')
        ->and($row->user_agent)->toBe('PruebaNavegador/1.0')
        ->and($row->ip_address)->not->toBeNull();

    // CA-FAM-UNIT-077: la segunda vez devuelve la existente y no crea fila ni auditoría nueva.
    $second = Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => $version])->assertOk();
    expect($second->json('public_id'))->toBe($first->json('public_id'))
        ->and(app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('terms_acceptances')->count()))->toBe(1)
        ->and(Fam::audit($tenant, 'terms_acceptance', 'created'))->toHaveCount(1);

    // INV-003 y ADR-035: auditada, sin IP ni agente de usuario en los valores registrados.
    $changes = json_encode(Fam::audit($tenant, 'terms_acceptance', 'created')[0]->changes);
    expect($changes)->not->toContain('PruebaNavegador')->and($changes)->toContain('shown_locale');

    // Inmutable en el modelo y en el motor.
    app(TenantContext::class)->runFor($tenant->id, function () use ($row): void {
        $model = TermsAcceptance::query()->where('public_id', $row->public_id)->firstOrFail();
        $model->shown_locale = 'en';

        expect(fn () => $model->save())->toThrow(LogicException::class, 'append-only')
            ->and(fn () => $model->delete())->toThrow(LogicException::class, 'append-only')
            ->and(fn () => DB::transaction(fn () => DB::table('terms_acceptances')->where('id', $row->id)->update(['shown_locale' => 'en'])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('terms_acceptances')->where('id', $row->id)->delete()))->toThrow(QueryException::class);
    });
});

test('CA-FAM-UNIT-071 RN-FAM-UNIT-65: shown_locale es el idioma realmente mostrado tras la caída de ADR-062, no el de la petición', function (): void {
    $f = Fam::termsFamily('wall-locale');
    ['tenant' => $tenant, 'admin' => $admin, 'tutor' => $tutor] = $f;
    Fam::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en']);
    // El idioma de la petición lo fija el del usuario autenticado (ResolveApiLocale): el tutor lee en inglés.
    app(TenantContext::class)->runFor($tenant->id, fn () => Person::query()->whereKey($tutor->person_id)->update(['locale' => 'en']));
    $tutor->unsetRelation('person');
    $version = Fam::publishTerms($tenant, $admin, false, ['es-ES' => 'Condiciones'], ['es-ES' => 'Texto en español']);

    // Petición en inglés, texto solo en español: se sirve (y se registra) es-ES.
    $r = Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => $version])->assertCreated();
    expect($r->json('shown_locale'))->toBe('es-ES');

    // Con traducción al inglés, se registra en.
    $en = Fam::publishTerms($tenant, $admin, true, ['es-ES' => 'Condiciones', 'en' => 'Terms'], ['es-ES' => 'Texto en español', 'en' => 'Text in English']);
    $terms = Fam::call($tenant, $tutor, 'get', '/me/terms')->assertOk();
    expect($terms->json('version.body'))->toBe('Text in English');

    $r = Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => $en])->assertCreated();
    expect($r->json('shown_locale'))->toBe('en');
});

test('RN-FAM-UNIT-64 api.md §2.8: POST /me/terms-acceptances valida en servidor (INV-010): terms_version ausente 422, desconocida o de otro tipo 422, no vigente 409', function (): void {
    $f = Fam::termsFamily('wall-post');
    ['tenant' => $tenant, 'admin' => $admin, 'tutor' => $tutor] = $f;

    $old = Fam::publishTerms($tenant, $admin);
    $current = Fam::publishTerms($tenant, $admin);
    $image = Fam::publishText($tenant, $admin, 'web');
    $draftId = Fam::call($tenant, $admin, 'post', '/terms-versions', ['kind' => 'condiciones_uso', 'title_translations' => ['es-ES' => 'B'], 'body_translations' => ['es-ES' => 'B']])->assertCreated()->json('public_id');

    $r = Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', [])->assertStatus(422);
    expect(Fam::errorCode($r, 'terms_version'))->toBe('fam_unit.validation.required');

    $r = Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => 'no-es-un-ulid'])->assertStatus(422);
    expect(Fam::errorCode($r, 'terms_version'))->toBe('fam_unit.validation.reference_invalid');

    foreach (['01JZZZZZZZZZZZZZZZZZZZZZZZ', $image] as $notTerms) {
        $r = Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => $notTerms])->assertStatus(422);
        expect(Fam::errorCode($r, 'terms_version'))->toBe('fam_unit.validation.terms_version_not_found');
    }

    // Una retirada o un borrador no son la versión vigente: 409, y no se registra nada.
    foreach ([$old, $draftId] as $notCurrent) {
        $r = Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => $notCurrent])->assertStatus(409);
        expect(Fam::errorCode($r, 'terms_version'))->toBe('fam_unit.terms.version_not_current');
    }

    expect(app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('terms_acceptances')->count()))->toBe(0);
    Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => $current])->assertCreated();
});

test('CA-FAM-UNIT-072 RN-FAM-UNIT-62: publicar una versión sin re-aceptación no vuelve a mostrar el muro; una que la exige, sí', function (): void {
    $f = Fam::termsFamily('wall-072');
    ['tenant' => $tenant, 'admin' => $admin, 'tutor' => $tutor] = $f;

    $v1 = Fam::publishTerms($tenant, $admin);
    Fam::call($tenant, $tutor, 'get', '/family-units')->assertStatus(403);
    Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => $v1])->assertCreated();
    Fam::call($tenant, $tutor, 'get', '/family-units')->assertOk();

    // v2 sin re-aceptación: basta haber aceptado cualquier versión anterior.
    Fam::publishTerms($tenant, $admin, false);
    Fam::call($tenant, $tutor, 'get', '/family-units')->assertOk();
    expect(Fam::call($tenant, $tutor, 'get', '/me/terms')->json('required'))->toBeFalse();

    // v3 exigiendo re-aceptación: el muro vuelve en la petición siguiente (CA-067-07, sin caché).
    $v3 = Fam::publishTerms($tenant, $admin, true);
    Fam::call($tenant, $tutor, 'get', '/family-units')->assertStatus(403);
    $terms = Fam::call($tenant, $tutor, 'get', '/me/terms')->assertOk();
    expect($terms->json('required'))->toBeTrue()->and($terms->json('version.public_id'))->toBe($v3)->and($terms->json('version.requires_reacceptance'))->toBeTrue();

    Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => $v3])->assertCreated();
    Fam::call($tenant, $tutor, 'get', '/family-units')->assertOk();
});

test('RN-FAM-UNIT-62: el usuario que nunca aceptó ninguna ve el muro aunque la publicada no exija re-aceptación', function (): void {
    $f = Fam::termsFamily('wall-062');
    ['tenant' => $tenant, 'admin' => $admin, 'tutor' => $tutor] = $f;

    Fam::publishTerms($tenant, $admin, false);
    Fam::publishTerms($tenant, $admin, false);

    Fam::call($tenant, $tutor, 'get', '/family-units')->assertStatus(403);
});

test('CA-FAM-UNIT-073 RN-FAM-UNIT-61: no está obligado quien no es tutor (docente), ni un tutor sin ningún vínculo activo, ni uno con el vínculo revocado; sí lo está al activarse el primer vínculo', function (): void {
    $f = Fam::termsFamily('wall-073');
    ['tenant' => $tenant, 'admin' => $admin, 'tutor' => $tutor, 'link' => $link, 'unit' => $unit] = $f;
    Fam::publishTerms($tenant, $admin);

    $docente = Fam::userWith($tenant, $admin, 'docente', ['familia.leer' => 'todos']);
    Fam::call($tenant, $docente, 'get', '/family-units')->assertOk();
    expect(Fam::call($tenant, $docente, 'get', '/me')->json('session_walls'))->toBe([]);

    // El administrador tampoco.
    Fam::call($tenant, $admin, 'get', '/family-units')->assertOk();

    // Vínculo revocado: deja de estar obligado en la petición siguiente (sin caché).
    Fam::call($tenant, $tutor, 'get', '/family-units')->assertStatus(403);
    Fam::call($tenant, $admin, 'post', "/guardian-links/{$link}/revocation", ['reason' => 'resolucion_judicial'])->assertSuccessful();
    Fam::call($tenant, $tutor, 'get', '/family-units')->assertOk();

    // Levantar la revocación (CA-067-07): el muro reaparece en la petición siguiente.
    Fam::call($tenant, $admin, 'delete', "/guardian-links/{$link}/revocation")->assertSuccessful();
    Fam::call($tenant, $tutor, 'get', '/family-units')->assertStatus(403);

    // Tutor de otra unidad sin vínculos: no obligado.
    $otherUnit = Fam::unitId($tenant, $admin, 'OTRA');
    [$sinVinculo] = Fam::familyUser($tenant, $admin, 'sinvinculo', $otherUnit);
    Fam::call($tenant, $sinVinculo, 'get', '/family-units')->assertOk();
});

test('CA-067-07 ADR-067 §5.8: sin caché entre peticiones — un tutor sin muro al que se le activa el primer vínculo a mitad de sesión recibe el 403 en la petición siguiente', function (): void {
    [$tenant, $admin] = Fam::setup('wall-067-07');
    $unit = Fam::unitId($tenant, $admin);
    $student = Fam::person($tenant, 'Alumno', 'Tarde');
    Fam::addStudent($tenant, $admin, $unit, $student)->assertCreated();
    [$tutor, $guardian] = Fam::familyUser($tenant, $admin, 'tutor', $unit);
    Fam::publishTerms($tenant, $admin);

    // Tutor sin vínculo activo: pasa, y la petición se repite sin muro.
    Fam::call($tenant, $tutor, 'get', '/family-units')->assertOk();
    Fam::call($tenant, $tutor, 'get', '/family-units')->assertOk();

    Fam::link($tenant, $admin, $guardian, $student)->assertCreated();

    expect(Fam::wallType(Fam::call($tenant, $tutor, 'get', '/family-units')->assertStatus(403)))->toBe('urn:pge:error:terms-acceptance-required');
});

test('CA-FAM-UNIT-074 RN-FAM-UNIT-60: una versión publicada de condiciones no se edita (409) y al publicar otra la anterior pasa a retirada en la misma transacción', function (): void {
    [$tenant, $admin] = Fam::setup('wall-074');

    $v1 = Fam::publishTerms($tenant, $admin);
    $patch = Fam::call($tenant, $admin, 'patch', "/terms-versions/{$v1}", ['body_translations' => ['es-ES' => 'Cambio']])->assertStatus(409);
    expect(Fam::errorCode($patch, 'status'))->toBe('fam_unit.terms.not_draft');

    // Otro tipo publicado no se toca.
    $image = Fam::publishText($tenant, $admin, 'web');
    $v2 = Fam::publishTerms($tenant, $admin, true);

    expect(Fam::row($tenant, 'terms_versions', $v1)->status)->toBe('retirada')
        ->and(Fam::row($tenant, 'terms_versions', $v2)->status)->toBe('publicada')
        ->and(Fam::row($tenant, 'terms_versions', $v2)->requires_reacceptance)->toBeTrue()
        ->and(Fam::row($tenant, 'terms_versions', $image)->status)->toBe('publicada');
});

test('CA-FAM-UNIT-075 RN-FAM-UNIT-65: publicar condiciones sin texto en el idioma por defecto del centro es 422 default_locale_missing', function (): void {
    [$tenant, $admin] = Fam::setup('wall-075');

    // El borrador solo admite idiomas activos y exige el por defecto al escribirse: se crea con el inglés como defecto y
    // después el centro vuelve a es-ES (el texto ya no tiene el idioma por defecto).
    Fam::setLocales($tenant, $admin, 'en', ['es-ES', 'en']);
    $draft = Fam::call($tenant, $admin, 'post', '/terms-versions', [
        'kind' => 'condiciones_uso', 'title_translations' => ['en' => 'Terms'], 'body_translations' => ['en' => 'Body'],
    ])->assertCreated()->json('public_id');
    Fam::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en']);

    $r = Fam::call($tenant, $admin, 'post', "/terms-versions/{$draft}/publish", ['requires_reacceptance' => true])->assertStatus(422);
    expect(Fam::errorCode($r, 'title_translations'))->toBe('fam_unit.terms.default_locale_missing')
        ->and(Fam::row($tenant, 'terms_versions', $draft)->status)->toBe('borrador');
});

test('CA-067-06 ADR-067 §5.4: con el MFA y las condiciones pendientes manda el MFA; las rutas de alta de MFA pasan, la aceptación no hasta cumplirlo, y después sí', function (): void {
    $f = Fam::termsFamily('wall-067-06');
    ['tenant' => $tenant, 'admin' => $admin, 'tutor' => $tutor] = $f;
    $version = Fam::publishTerms($tenant, $admin);
    forceMfaWall($tenant, $tutor);

    // GET /me exento de los dos: lista los dos muros, en el orden del registro.
    expect(Fam::call($tenant, $tutor, 'get', '/me')->assertOk()->json('session_walls'))->toBe(['mfa-enrollment', 'terms-acceptance']);

    // Las rutas de alta de MFA pasan sin evaluar ni el de condiciones.
    Fam::call($tenant, $tutor, 'get', '/auth/mfa')->assertOk();
    $enroll = Fam::call($tenant, $tutor, 'post', '/auth/mfa-enrollments', ['method' => 'totp'])->assertCreated();

    // La aceptación y cualquier otra ruta: 403 del MFA, no del de condiciones.
    foreach ([['post', '/me/terms-acceptances', ['terms_version' => $version]], ['get', '/family-units', []], ['get', '/me/terms', []]] as [$method, $path, $body]) {
        $r = Fam::call($tenant, $tutor, $method, $path, $body)->assertStatus(403);
        expect($r->json('type'))->toBe('urn:pge:error:mfa-enrollment-required', "{$method} {$path}");
    }

    // Cumple el MFA (confirma el factor): la aceptación pasa y el resto responde el muro de condiciones.
    Fam::call($tenant, $tutor, 'post', '/auth/mfa-factors', ['enrollment' => $enroll->json('public_id'), 'code' => currentTotpCode($enroll->json('secret'))])->assertCreated();
    expect(Fam::wallType(Fam::call($tenant, $tutor, 'get', '/family-units')->assertStatus(403)))->toBe('urn:pge:error:terms-acceptance-required');
    Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => $version])->assertCreated();
    Fam::call($tenant, $tutor, 'get', '/family-units')->assertOk();
});

test('CA-067-05 ADR-067 §5.6: MfaPolicy::resolve no se llama en una ruta exenta del MFA y se llama una sola vez en una ruta no exenta', function (): void {
    [$tenant, $admin] = Fam::setup('wall-067-05');
    $user = Fam::userWith($tenant, $admin, 'cualquiera', ['familia.leer' => 'todos']);

    // `scoped()` se resuelve de nuevo en cada petición: el decorador cuenta las llamadas de la petición (no una instancia
    // capturada, ADR-067 §5.2).
    app()->extend(MfaPolicy::class, static fn (MfaPolicy $inner): MfaPolicy => new CountingMfaPolicy($inner));

    // Ruta de L4 (GET /tenant/branding): el atajo de exención va antes de blocks(), así que el muro no llama a resolve().
    CountingMfaPolicy::$resolveCalls = 0;
    Fam::call($tenant, $user, 'get', '/tenant/branding');
    expect(CountingMfaPolicy::$resolveCalls)->toBe(0);

    // Ruta no exenta, sin GET /me que la llame por su cuenta: exactamente una vez.
    CountingMfaPolicy::$resolveCalls = 0;
    Fam::call($tenant, $user, 'get', '/family-units')->assertOk();
    expect(CountingMfaPolicy::$resolveCalls)->toBe(1);
});

test('CA-067-08 ADR-067 §5.8: con fam_unit activo, un docente hace exactamente una consulta más que sin el muro; con fam_unit desactivado, ninguna a tablas de fam_unit y un tutor pendiente no ve el muro', function (): void {
    $f = Fam::termsFamily('wall-067-08');
    ['tenant' => $tenant, 'admin' => $admin, 'tutor' => $tutor] = $f;
    Fam::publishTerms($tenant, $admin);
    $docente = Fam::userWith($tenant, $admin, 'docente', ['familia.leer' => 'todos']);

    $original = app(SessionWallRegistry::class);
    $onlyMfa = new SessionWallRegistry;
    $onlyMfa->register(MfaEnrollmentWall::class);

    $request = fn () => Fam::call($tenant, $docente, 'get', '/auth/sessions')->assertOk();
    // La caché de módulos (`modules:fam_unit:enabled`, 300 s) es de Redis y compartida entre los procesos de la suite en
    // paralelo: otro test puede vaciarla entre medias y provocar una lectura de `module_subscriptions` que no es del muro.
    $withoutModuleCache = static fn (array $sql): array => array_values(array_filter($sql, static fn (string $q): bool => ! str_contains($q, 'module_subscriptions')));

    try {
        // Caliente la caché de módulos y todo lo memoizable.
        $request();

        app()->instance(SessionWallRegistry::class, $onlyMfa);
        [, $withoutWall] = queriesOf($request);

        app()->instance(SessionWallRegistry::class, $original);
        [, $withWall] = queriesOf($request);
    } finally {
        app()->instance(SessionWallRegistry::class, $original);
    }

    $withoutWall = $withoutModuleCache($withoutWall);
    $withWall = $withoutModuleCache($withWall);

    // Exactamente una consulta más, y es la del muro (ADR-067 §5.7: una sola consulta, con predicado de tenant).
    expect(count($withWall) - count($withoutWall))->toBe(1);

    $wallQueries = array_filter($withWall, static fn (string $q): bool => str_contains($q, 'terms_versions') && str_contains($q, 'terms_acceptances'));
    expect($wallQueries)->toHaveCount(1);

    // Módulo descontratado: ninguna consulta a tablas de fam_unit y el tutor pendiente no ve el muro.
    Fam::disableModule($tenant);

    [, $sql] = queriesOf(fn () => Fam::call($tenant, $tutor, 'get', '/auth/sessions')->assertOk());
    $touched = array_filter($sql, static fn (string $q): bool => preg_match('/\b(guardians|guardian_student_links|terms_versions|terms_acceptances)\b/', $q) === 1);

    expect($touched)->toBe([])
        ->and(Fam::call($tenant, $tutor, 'get', '/me')->assertOk()->json('session_walls'))->toBe([]);

    // Los endpoints del módulo responden module-disabled (y no el muro): el tutor no queda encerrado.
    expect(Fam::call($tenant, $tutor, 'get', '/me/terms')->assertStatus(403)->json('type'))->toBe('urn:pge:error:module-disabled');
    expect(Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])->assertStatus(403)->json('type'))->toBe('urn:pge:error:module-disabled');
});

test('INV-002 ADR-067 §5.12: la aceptación y la consulta propia son por identidad, pero GET /terms-acceptances exige aceptacion_condiciones.leer y no hay PATCH ni DELETE', function (): void {
    $f = Fam::termsFamily('wall-perm');
    ['tenant' => $tenant, 'admin' => $admin, 'tutor' => $tutor] = $f;
    $version = Fam::publishTerms($tenant, $admin);
    Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => $version])->assertCreated();

    // El tutor no tiene el permiso del listado administrativo.
    Fam::call($tenant, $tutor, 'get', '/terms-acceptances')->assertStatus(403);

    $list = Fam::call($tenant, $admin, 'get', '/terms-acceptances')->assertOk();
    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0'))->toMatchArray(['user' => $tutor->public_id, 'terms_version' => $version, 'version_number' => 1, 'shown_locale' => 'es-ES'])
        ->and(array_keys($list->json('data.0')))->not->toContain('ip_address', 'user_agent')
        ->and($list->json('meta'))->toBe(['next_cursor' => null, 'has_more' => false]);

    $acceptance = $list->json('data.0.public_id');
    Fam::call($tenant, $admin, 'patch', "/terms-acceptances/{$acceptance}", [])->assertStatus(404);
    Fam::call($tenant, $admin, 'delete', "/terms-acceptances/{$acceptance}")->assertStatus(404);
});

test('api.md §3: GET /terms-acceptances pagina por cursor (accepted_at DESC, id DESC), filtra por terms_version y user, y rechaza ULID mal formados y cursores ajenos', function (): void {
    $f = Fam::termsFamily('wall-list');
    ['tenant' => $tenant, 'admin' => $admin, 'tutor' => $tutor, 'unit' => $unit] = $f;
    Fam::freeze('2026-10-28 09:00:00');

    $v1 = Fam::publishTerms($tenant, $admin);
    Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => $v1])->assertCreated();
    Fam::tick();

    $v2 = Fam::publishTerms($tenant, $admin, true);
    Fam::call($tenant, $tutor, 'post', '/me/terms-acceptances', ['terms_version' => $v2])->assertCreated();
    Fam::tick();

    [$other] = Fam::familyUser($tenant, $admin, 'otro', Fam::unitId($tenant, $admin, 'X2'));
    Fam::call($tenant, $other, 'post', '/me/terms-acceptances', ['terms_version' => $v2])->assertCreated();

    $page1 = Fam::call($tenant, $admin, 'get', '/terms-acceptances?limit=2')->assertOk();
    expect($page1->json('data'))->toHaveCount(2)->and($page1->json('meta.has_more'))->toBeTrue();

    $page2 = Fam::call($tenant, $admin, 'get', '/terms-acceptances?limit=2&cursor='.urlencode($page1->json('meta.next_cursor')))->assertOk();
    $all = array_merge(array_column($page1->json('data'), 'public_id'), array_column($page2->json('data'), 'public_id'));

    expect($page2->json('data'))->toHaveCount(1)->and($page2->json('meta.has_more'))->toBeFalse()->and(array_unique($all))->toHaveCount(3);

    expect(Fam::call($tenant, $admin, 'get', "/terms-acceptances?terms_version={$v1}")->json('data'))->toHaveCount(1)
        ->and(Fam::call($tenant, $admin, 'get', "/terms-acceptances?user={$tutor->public_id}")->json('data'))->toHaveCount(2)
        ->and(Fam::call($tenant, $admin, 'get', '/terms-acceptances?user=01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertOk()->json('data'))->toBe([]);

    Fam::call($tenant, $admin, 'get', '/terms-acceptances?user=mal')->assertStatus(422);
    Fam::call($tenant, $admin, 'get', '/terms-acceptances?limit=0')->assertStatus(422);

    // Un cursor emitido con otros filtros es 422.
    $cursor = $page1->json('meta.next_cursor');
    $r = Fam::call($tenant, $admin, 'get', "/terms-acceptances?limit=2&terms_version={$v2}&cursor=".urlencode($cursor))->assertStatus(422);
    expect(Fam::errorCode($r, 'cursor'))->toBe('fam_unit.validation.cursor_invalid');
});
