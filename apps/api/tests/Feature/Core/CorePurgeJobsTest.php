<?php

use App\Models\IdempotencyKey;
use App\Modules\Core\Domain\Models\DataExport;
use App\Modules\Core\Domain\Models\TenantSetting;
use App\Modules\Core\Domain\Models\UserInvitation;
use App\Modules\Core\Infrastructure\Jobs\PurgeExpiredExports;
use App\Modules\Core\Infrastructure\Jobs\PurgeExpiredIdempotencyKeys;
use App\Modules\Core\Infrastructure\Jobs\PurgeExpiredInvitations;
use App\Modules\Core\Infrastructure\Jobs\PurgeOrphanBrandingAssets;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * operacion.md §4. Purgas programadas de REQ-CORE. Cada job se despacha
 * dentro de TenantContext::runFor() para que el listener de
 * TenancyServiceProvider (payload tenant_id) fije el contexto correcto
 * antes de handle() — mismo patrón que PurgeCoreMaintenanceCommand.
 */
beforeEach(function (): void {
    Mail::fake();
    Storage::fake('local');
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

test('PurgeExpiredInvitations borra lógicamente las invitaciones caducadas hace más de 30 días', function (): void {
    [$tenant, $admin] = provisionCoreTenant('purge-invitations');

    // provisionCoreTenant() ya emite la invitación del Administrador de
    // Centro (funcional.md §4.7 paso 5): se reutiliza y se envejece en
    // vez de crear una segunda, que violaría la única invitación viva
    // por usuario (RN-CORE-09).
    $invitationId = app(TenantContext::class)->runFor($tenant->id, function () use ($admin) {
        $invitation = UserInvitation::where('user_id', $admin->id)->firstOrFail();
        $invitation->update(['expires_at' => now()->subDays(31)]);

        return $invitation->id;
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($tenant): void {
        PurgeExpiredInvitations::dispatch($tenant->id);
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($invitationId): void {
        expect(UserInvitation::find($invitationId))->toBeNull();
        expect(UserInvitation::withTrashed()->find($invitationId))->not->toBeNull();
    });
});

test('PurgeExpiredExports borra el objeto y la fila de una exportación vencida', function (): void {
    [$tenant, $admin] = provisionCoreTenant('purge-exports');

    $objectKey = "tenants/{$tenant->public_id}/exports/test.csv";
    Storage::disk('local')->put($objectKey, 'a,b,c');

    $exportId = app(TenantContext::class)->runFor($tenant->id, function () use ($admin, $objectKey) {
        $export = DataExport::create([
            'kind' => 'audit_logs',
            'format' => 'csv',
            'status' => 'completada',
            'object_key' => $objectKey,
            'requested_by' => $admin->id,
            'expires_at' => now()->subDay(),
        ]);

        return $export->id;
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($tenant): void {
        PurgeExpiredExports::dispatch($tenant->id);
    });

    Storage::disk('local')->assertMissing($objectKey);

    app(TenantContext::class)->runFor($tenant->id, function () use ($exportId): void {
        expect(DataExport::withTrashed()->find($exportId))->toBeNull();
    });
});

test('PurgeOrphanBrandingAssets borra el activo huérfano con más de 24h y conserva el referenciado', function (): void {
    [$tenant, $admin] = provisionCoreTenant('purge-branding-2');

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');

    $first = UploadedFile::fake()->createWithContent('logo.png', $png);
    test()->actingAs($admin)
        ->call('PUT', coreApiUrl($tenant->slug, '/tenant/settings/assets/logo'), [], [], ['file' => $first])
        ->assertOk();

    $orphanKey = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => TenantSetting::query()->first()->logo_object_key,
    );

    $second = UploadedFile::fake()->createWithContent('logo.png', $png);
    test()->actingAs($admin)
        ->call('PUT', coreApiUrl($tenant->slug, '/tenant/settings/assets/logo'), [], [], ['file' => $second])
        ->assertOk();

    $currentKey = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => TenantSetting::query()->first()->logo_object_key,
    );

    // El primer objeto queda huérfano; se envejece su mtime más allá de
    // las 24h que exige la purga (Storage::fake() escribe en disco real
    // bajo storage/framework/testing, así que touch() sí lo modifica).
    touch(Storage::disk('local')->path($orphanKey), time() - 90000);

    app(TenantContext::class)->runFor($tenant->id, function () use ($tenant): void {
        PurgeOrphanBrandingAssets::dispatch($tenant->id);
    });

    Storage::disk('local')->assertMissing($orphanKey);
    Storage::disk('local')->assertExists($currentKey);
});

test('PurgeExpiredIdempotencyKeys borra físicamente las claves vencidas de cualquier tenant', function (): void {
    [$tenant] = provisionCoreTenant('purge-idem');

    $id = app(TenantContext::class)->runFor($tenant->id, function () {
        $key = IdempotencyKey::create([
            'endpoint' => 'user-imports.execute',
            'idempotency_key' => (string) Str::ulid(),
            'request_body_hash' => hash('sha256', ''),
            'status' => 'completado',
            'response_status' => 202,
            'response_body' => ['status' => 'ok'],
            'expires_at' => now()->subHour(),
        ]);

        return $key->id;
    });

    PurgeExpiredIdempotencyKeys::dispatch();

    app(TenantContext::class)->runAsPlatform(function () use ($id): void {
        expect(IdempotencyKey::find($id))->toBeNull();
    });
});
