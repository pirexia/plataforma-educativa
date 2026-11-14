<?php

use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

// ADR-033 §8: comandos de consola sin tenant por defecto.

class RunsPerTenantProbe
{
    use RunsPerTenant;

    /** @var array<int, int> */
    public array $seen = [];

    public function run(): void
    {
        $this->eachTenant(function (Tenant $tenant): void {
            $this->seen[] = $tenant->id;
        });
    }
}

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('itera solo los tenants activos, no los suspendidos ni en_alta', function (): void {
    $activo = Tenant::factory()->create();
    Tenant::factory()->suspendido()->create();
    Tenant::factory()->create(['status' => 'en_alta']);

    $probe = new RunsPerTenantProbe;
    $probe->run();

    expect($probe->seen)->toBe([$activo->id]);
});

test('cada iteración corre con su propio tenant activo y limpia al terminar', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $observed = [];

    (new class($observed)
    {
        use RunsPerTenant;

        public function __construct(private array &$observed) {}

        public function run(TenantContext $context): void
        {
            $this->eachTenant(function () use ($context): void {
                $this->observed[] = $context->tenantId();
            });
        }
    })->run($context);

    expect($observed)->toEqualCanonicalizing([$tenantA->id, $tenantB->id])
        ->and($context->hasTenant())->toBeFalse();
});
