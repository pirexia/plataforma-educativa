<?php

use App\Support\Tenancy\TenantMigration;
use Tests\Support\PhpScanner;

pest()->group('arch');

// REQ-ACAD-001, 1.11-a. CA-ACAD-040 (issue #387 B-4, OPEN-ACAD-14), ADR-056,
// INV-001, INV-015. `TenantMigration` corre por la conexión del propietario
// del esquema (`pgsql_owner`): DDL, RLS y disparador de curso. Solo debe
// invocarse desde migraciones (`database/migrations/`,
// `app/Modules/*/Database/migrations/`) y desde los fixtures de test
// (`tests/`, que esta regla no escanea). Cualquier otro uso en `app/`,
// `database/`, `routes/`, `bootstrap/` o `config/` es un fallo.
//
// Límite declarado (ADR-056 §3.4): ve `TenantMigration::método(` resuelto
// contra los `use` del fichero, no `$clase::método()` ni una referencia por
// cadena (`'App\\Support\\Tenancy\\TenantMigration'`).

/**
 * Exenciones por ruta (relativa a la raíz de la API). Lista CERRADA y nominal
 * (ADR-056 §3.2); solo puede reducirse.
 *
 * @return array<string, string>
 */
function tenantMigrationPathExemptions(): array
{
    return [
        'app/Support/Tenancy/TenantMigration.php' => 'define el ayudante: sus llamadas internas a sí mismo no son un uso',
    ];
}

function isMigrationPath(string $path): bool
{
    return str_starts_with($path, 'database/migrations/')
        || preg_match('#^app/Modules/[^/]+/Database/migrations/#', $path) === 1;
}

function usesTenantMigration(string $source): bool
{
    return PhpScanner::staticCallsOn($source, TenantMigration::class) !== [];
}

/**
 * @param  array<string, string>  $sources  ruta relativa a la raíz de la API => código
 * @param  list<string>  $exempt
 * @return list<string> ficheros fuera de migraciones y de las exenciones que usan el ayudante
 */
function unsanctionedTenantMigrationUsers(array $sources, array $exempt): array
{
    $found = [];

    foreach ($sources as $path => $source) {
        if (isMigrationPath($path) || in_array($path, $exempt, true)) {
            continue;
        }

        if (usesTenantMigration($source)) {
            $found[] = $path;
        }
    }

    return $found;
}

/**
 * @return array<string, string>
 */
function tenantMigrationScanSources(): array
{
    $root = base_path();
    $sources = [];

    foreach (['app', 'database', 'routes', 'bootstrap', 'config'] as $directory) {
        foreach (PhpScanner::phpFiles(base_path($directory)) as $file) {
            $sources[substr($file, strlen($root) + 1)] = file_get_contents($file);
        }
    }

    return $sources;
}

test('CA-ACAD-040 #387 B-4: solo las migraciones usan TenantMigration (ni app/, ni database/ fuera de migrations, ni routes/, bootstrap/ o config/)', function (): void {
    $sources = tenantMigrationScanSources();

    expect(count($sources))->toBeGreaterThan(100);

    // No vacuidad: el escaneo ve migraciones que SÍ lo usan (si no, el patrón
    // de detección o las rutas habrían dejado de funcionar).
    $inMigrations = array_filter($sources, static fn (string $source, string $path): bool => isMigrationPath($path) && usesTenantMigration($source), ARRAY_FILTER_USE_BOTH);

    expect(count($inMigrations))->toBeGreaterThan(10, 'CA-ACAD-040 pasaría en vacío: el escáner no ve ninguna migración que use TenantMigration');

    $violations = unsanctionedTenantMigrationUsers($sources, array_keys(tenantMigrationPathExemptions()));

    expect($violations)->toBe([], "uso de TenantMigration fuera de migraciones (corre con la conexión del propietario):\n".implode("\n", $violations));
});

test('CA-ACAD-040 ADR-056 §3.2: cada exención de ruta existe y sigue siendo necesaria (la lista solo puede reducirse)', function (): void {
    $sources = tenantMigrationScanSources();

    foreach (tenantMigrationPathExemptions() as $path => $motive) {
        expect($motive)->not->toBe('')
            ->and(isset($sources[$path]))->toBeTrue("{$path}: el fichero ya no existe, retirarlo de las exenciones");
    }

    expect(count(tenantMigrationPathExemptions()))->toBeLessThanOrEqual(1);
});

test('CA-ACAD-040 control negativo: detecta el uso real fuera de migraciones y no el de una migración, una exención, un comentario ni otra clase', function (): void {
    $uses = "<?php\nuse App\\Support\\Tenancy\\TenantMigration;\nclass X { function f() { TenantMigration::tenantTable('t', fn () => null); } }";

    $sources = [
        'app/Modules/Acad/Http/Controllers/MalController.php' => $uses,
        'database/seeders/MalSeeder.php' => $uses,
        'routes/web.php' => "<?php\n\\App\\Support\\Tenancy\\TenantMigration::guardAcademicYearWrites('t');",
        'database/migrations/2026_01_01_000000_x.php' => $uses,
        'app/Modules/Acad/Database/migrations/2026_01_01_000000_y.php' => $uses,
        'app/Support/Tenancy/Listada.php' => $uses,
        'app/Modules/Acad/Http/Controllers/Comentario.php' => "<?php\n// TenantMigration::tenantTable('t', fn () => null);\nclass C {}",
        'app/Modules/Acad/Http/Controllers/Otra.php' => "<?php\nuse Illuminate\\Support\\Facades\\Schema;\nclass O { function f() { Schema::create('t', fn () => null); } }",
    ];

    expect(unsanctionedTenantMigrationUsers($sources, ['app/Support/Tenancy/Listada.php']))->toBe([
        'app/Modules/Acad/Http/Controllers/MalController.php',
        'database/seeders/MalSeeder.php',
        'routes/web.php',
    ]);
});
