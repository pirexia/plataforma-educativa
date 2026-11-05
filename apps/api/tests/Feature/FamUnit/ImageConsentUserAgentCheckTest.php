<?php

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-004, docs/modulos/REQ-FAM-UNIT/datos.md §1.6, ADR-066 §5.8, issue #480 punto 1, INV-015:
// `image_consent_decisions.user_agent` tiene como máximo 512 caracteres, en el servicio y también en el motor.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** Duplica la decisión `$id` con el `user_agent` dado, con la conexión de aplicación y dentro del centro; devuelve el SQLSTATE o `null`. */
function copyDecisionWithUserAgent(int $tenantId, int $id, ?string $userAgent): ?string
{
    $connection = DB::connection('pgsql');

    try {
        $connection->transaction(function () use ($connection, $tenantId, $id, $userAgent): void {
            $connection->selectOne("SELECT set_config('app.tenant_id', ?, true)", [(string) $tenantId]);
            $connection->insert(<<<'SQL'
                INSERT INTO image_consent_decisions (tenant_id, public_id, student_person_id, level, decision, guardian_id, recorded_by, channel, consent_text_version_id, shown_locale, occurred_at, ip_address, user_agent)
                SELECT tenant_id, ?, student_person_id, level, decision, guardian_id, recorded_by, channel, consent_text_version_id, shown_locale, occurred_at, ip_address, ?
                FROM image_consent_decisions WHERE id = ?
                SQL, [strtoupper(substr(md5((string) microtime(true)), 0, 26)), $userAgent, $id]);

            throw new RuntimeException('deshacer');
        });
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? 'sin-sqlstate';
    } catch (RuntimeException) {
        return null;
    }

    return null;
}

test('REQ-FAM-UNIT-004 #480: el servicio trunca el user_agent a 512 caracteres y el motor rechaza un INSERT directo de 513 (23514), admite 512 y nulo', function (): void {
    $f = Fam::consentFamily('is-480', twoGuardians: false);

    // Una petición con un User-Agent de 2000 caracteres multibyte se registra truncada a 512 caracteres.
    test()->withHeaders(['User-Agent' => str_repeat('ñ', 2000)]);
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated();

    $row = app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('image_consent_decisions')->where('student_person_id', $f['student']->id)->selectRaw('id, char_length(user_agent) AS n')->first());
    expect((int) $row->n)->toBe(512);

    // Un INSERT directo con 513 caracteres (el CHECK cuenta caracteres, no bytes) lo rechaza el motor.
    expect(copyDecisionWithUserAgent($f['tenant']->id, (int) $row->id, str_repeat('ñ', 513)))->toBe('23514')
        ->and(copyDecisionWithUserAgent($f['tenant']->id, (int) $row->id, str_repeat('ñ', 512)))->toBeNull()
        ->and(copyDecisionWithUserAgent($f['tenant']->id, (int) $row->id, null))->toBeNull();
});
