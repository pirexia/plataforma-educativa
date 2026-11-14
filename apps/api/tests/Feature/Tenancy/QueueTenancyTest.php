<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// ADR-033 §8: el worker de colas es el vector de fuga más probable — jobs
// de tenants distintos en secuencia sobre el mismo proceso de larga vida.

class TenantAwareTestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var array<int, int|null> */
    public static array $observed = [];

    public function handle(): void
    {
        $context = app(TenantContext::class);

        self::$observed[] = $context->hasTenant() ? $context->tenantId() : null;
    }
}

afterEach(function (): void {
    // delete(), no truncate(): TRUNCATE exige un privilegio que
    // plataforma_app no tiene (y no debería tener) a propósito.
    DB::table('jobs')->delete();
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('createPayloadUsing estampa el tenant activo en el payload del job', function (): void {
    $context = app(TenantContext::class);
    $tenant = Tenant::factory()->create();

    $context->enter($tenant->id);
    TenantAwareTestJob::dispatch()->onConnection('database');
    $context->leave();

    $row = DB::table('jobs')->latest('id')->first();
    $payload = json_decode($row->payload, true);

    expect($payload['tenant_id'])->toBe($tenant->id);
});

test('sin tenant activo, el payload lleva tenant_id null', function (): void {
    TenantAwareTestJob::dispatch()->onConnection('database');

    $row = DB::table('jobs')->latest('id')->first();
    $payload = json_decode($row->payload, true);

    expect($payload['tenant_id'])->toBeNull();
});

test('el worker entra en el tenant del job y sale al terminar', function (): void {
    $context = app(TenantContext::class);
    $tenant = Tenant::factory()->create();
    TenantAwareTestJob::$observed = [];

    $context->enter($tenant->id);
    TenantAwareTestJob::dispatch()->onConnection('database');
    $context->leave();

    expect($context->hasTenant())->toBeFalse();

    Artisan::call('queue:work', [
        'connection' => 'database',
        '--once' => true,
        '--queue' => 'default',
    ]);

    expect(TenantAwareTestJob::$observed)->toBe([$tenant->id])
        ->and($context->hasTenant())->toBeFalse();
});

test('dos jobs de tenants distintos seguidos no se contaminan entre sí', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $context = app(TenantContext::class);
    TenantAwareTestJob::$observed = [];

    // dispatch() como expresión de retorno de una función flecha (en vez de
    // como sentencia suelta) es precisamente el caso que documenta el
    // aviso de TenantContext::runFor(): PendingDispatch se envía en su
    // __destruct(), que con `return $callback();` dentro de runFor() no
    // llega hasta DESPUÉS del finally que restaura el tenant — el job se
    // etiquetaría con el tenant equivocado. Por eso aquí se fuerza el
    // envío dentro del propio closure, no como su valor de retorno.
    $context->runFor($tenantA->id, function (): void {
        TenantAwareTestJob::dispatch()->onConnection('database');
    });
    $context->runFor($tenantB->id, function (): void {
        TenantAwareTestJob::dispatch()->onConnection('database');
    });

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default']);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default']);

    expect(TenantAwareTestJob::$observed)->toBe([$tenantA->id, $tenantB->id]);
});

test('Looping aborta el worker si el contexto no está limpio al empezar un ciclo', function (): void {
    $context = app(TenantContext::class);
    $tenant = Tenant::factory()->create();
    $context->enter($tenant->id);

    expect(fn () => event(new Looping('database', 'default')))
        ->toThrow(RuntimeException::class);

    $context->leave();
});

test('Looping no aborta si el contexto está limpio', function (): void {
    expect(fn () => event(new Looping('database', 'default')))
        ->not->toThrow(RuntimeException::class);
});
