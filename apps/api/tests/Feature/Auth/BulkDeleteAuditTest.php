<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Application\MfaFactorRemovalService;
use App\Modules\Auth\Application\MfaRecoveryCodeService;
use App\Modules\Auth\Application\MfaResetService;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Auth\Domain\Models\MfaRecoveryCode;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Issue #380, AR-15, INV-003, ADR-035: los borrados de factores y códigos de
// respaldo MFA se hacían con `Modelo::query()->where()->delete()`, que no
// dispara los eventos de modelo y por tanto no auditaba. Ahora van por
// instancia: cada fila borrada deja su fila `deleted` en `audit_logs`.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/**
 * Filas `deleted` de auditoría de las filas dadas del modelo dado.
 *
 * @param  class-string<Model>  $model
 * @param  list<int>  $ids
 */
function deletedAuditCount(string $model, array $ids): int
{
    return AuditLog::query()
        ->where('auditable_type', (new $model)->getMorphClass())
        ->whereIn('auditable_id', $ids)
        ->where('event', 'deleted')
        ->count();
}

test('INV-003 #380: regenerar los códigos de respaldo audita el borrado de cada código del lote anterior', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('bulk-380-a');

    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $password): void {
        $service = app(MfaRecoveryCodeService::class);
        $service->generateInitialBatch($user);
        $old = MfaRecoveryCode::query()->where('user_id', $user->id)->pluck('id')->all();

        expect($old)->not->toBe([]);

        $service->regenerate($user, $password);

        expect(deletedAuditCount(MfaRecoveryCode::class, $old))->toBe(count($old));
    });
});

test('INV-003 #380: el restablecimiento de MFA por el administrador audita el borrado de los códigos de respaldo', function (): void {
    Queue::fake();
    [$tenant, $target] = provisionActiveUser('bulk-380-b');
    $actor = app(TenantContext::class)->runFor($tenant->id, fn () => User::factory()->for(Person::factory()->create())->create(['status' => UserStatus::Activo]));

    app(TenantContext::class)->runFor($tenant->id, function () use ($actor, $target): void {
        app(MfaRecoveryCodeService::class)->generateInitialBatch($target);
        $codes = MfaRecoveryCode::query()->where('user_id', $target->id)->pluck('id')->all();

        app(MfaResetService::class)->reset($actor, $target, 'Pérdida del dispositivo del usuario.');

        expect(deletedAuditCount(MfaRecoveryCode::class, $codes))->toBe(count($codes));
    });
});

test('INV-003 #380: retirar el último factor audita el borrado de los códigos de respaldo', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('bulk-380-c');
    createConfirmedTotpFactor($tenant, $user);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $password): void {
        app(MfaRecoveryCodeService::class)->generateInitialBatch($user);
        $codes = MfaRecoveryCode::query()->where('user_id', $user->id)->pluck('id')->all();
        $factor = MfaFactor::query()->where('user_id', $user->id)->firstOrFail();

        app(MfaFactorRemovalService::class)->remove($user, $factor->public_id, $password);

        expect(deletedAuditCount(MfaRecoveryCode::class, $codes))->toBe(count($codes));
    });
});

test('INV-003 #380: abrir un segundo alta de factor por correo audita el borrado del alta pendiente anterior', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('bulk-380-d');
    enableEmailMfaMethod($tenant);
    $cookie = sessionCookieValue(loginFor($tenant->slug, $user->email, $password));

    $first = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'email'])
        ->assertStatus(201);
    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'email'])
        ->assertStatus(201);

    app(TenantContext::class)->runFor($tenant->id, function () use ($first): void {
        $firstId = MfaFactor::withTrashed()->where('public_id', $first->json('public_id'))->firstOrFail()->id;

        expect(deletedAuditCount(MfaFactor::class, [$firstId]))->toBe(1);
    });
});
