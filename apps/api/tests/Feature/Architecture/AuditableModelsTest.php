<?php

use App\Models\AuditLog;
use App\Models\IdempotencyKey;
use App\Modules\Auth\Domain\Models\LoginAttempt;
use App\Modules\Auth\Domain\Models\MfaChallenge;
use App\Modules\Auth\Domain\Models\SamlAuthRequest;
use App\Modules\Auth\Domain\Models\SamlConsumedAssertion;
use App\Support\Audit\Auditable;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Tenancy\AppendOnlyModel;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Architecture\RawTenantIdModel;
use Tests\Fixtures\Architecture\TypedTenantIdModel;
use Tests\Support\PhpScanner;

pest()->group('arch');

// ADR-056 AR-06, CA-056-07, INV-003, ADR-035, INV-015: toda subclase concreta
// de `TenantModel` o `AppendOnlyModel` implementa `Auditable` — un modelo de
// negocio sin rastro es un incumplimiento de INV-003 que hoy solo se ve en
// revisión. Por reflexión sobre las clases cargadas (patrón de
// `IsolationBatteryTest` #9).

/**
 * Excepciones: lista CERRADA y nominal (ADR-056 §3.2). Solo puede
 * reducirse; añadir una exige especificación aprobada por el usuario
 * (OPEN-056-02). Cada motivo está documentado en el `datos.md` citado.
 *
 * @return array<class-string, string> clase => motivo y referencia
 */
function auditableExceptions(): array
{
    return [
        AuditLog::class => 'es el propio rastro de auditoría (docs/modulos/REQ-CORE/datos.md, Parte 0.9)',
        IdempotencyKey::class => 'registro técnico de deduplicación sin datos personales (docs/modulos/REQ-CORE/datos.md §A.5)',
        LoginAttempt::class => 'telemetría de intentos de acceso; auditarla duplicaría cada fila e inundaría audit_logs (docs/modulos/REQ-AUTH/datos.md §A.1)',
        MfaChallenge::class => 'segundo paso pendiente de un login, estado transitorio (docs/modulos/REQ-AUTH/datos.md §C.4)',
        SamlAuthRequest::class => 'estado transitorio de protocolo SAML de cinco minutos (docs/modulos/REQ-AUTH/datos.md §G.4.1)',
        SamlConsumedAssertion::class => 'estado transitorio de protocolo SAML, mismo argumento que §G.4.1 (docs/modulos/REQ-AUTH/datos.md §G.4.2)',
    ];
}

/**
 * Subclases concretas de TenantModel/AppendOnlyModel bajo `app/`.
 *
 * @return list<class-string>
 */
function tenantAndAppendOnlyModels(): array
{
    $models = [];
    $appPath = app_path();

    foreach (PhpScanner::phpFiles($appPath) as $file) {
        $relative = substr($file, strlen($appPath) + 1, -4);
        $class = 'App\\'.str_replace('/', '\\', $relative);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            continue;
        }

        if (is_subclass_of($class, TenantModel::class) || is_subclass_of($class, AppendOnlyModel::class)) {
            $models[] = $class;
        }
    }

    return $models;
}

test('AR-06 CA-056-07 INV-003: todo TenantModel/AppendOnlyModel concreto implementa Auditable salvo excepción nominal', function (): void {
    $models = tenantAndAppendOnlyModels();
    $exceptions = array_keys(auditableExceptions());

    expect(count($models))->toBeGreaterThan(20);

    $without = array_values(array_filter(
        $models,
        static fn (string $class): bool => ! is_subclass_of($class, Auditable::class) && ! in_array($class, $exceptions, true),
    ));

    expect($without)->toBe([], 'modelos sin Auditable (INV-003, ADR-035): '.implode(', ', $without));

    // Implementar la interfaz sin el trait no emite ningún rastro (INV-003).
    $withoutTrait = array_values(array_filter(
        $models,
        static fn (string $class): bool => ! in_array($class, $exceptions, true)
            && ! in_array(RecordsAuditTrail::class, class_uses_recursive($class), true),
    ));

    expect($withoutTrait)->toBe([], 'modelos Auditable sin RecordsAuditTrail (INV-003, ADR-035): '.implode(', ', $withoutTrait));
});

test('AR-06 CA-056-15: cada excepción de Auditable sigue siendo un modelo de tenant que no implementa Auditable', function (): void {
    $models = tenantAndAppendOnlyModels();

    foreach (auditableExceptions() as $class => $reason) {
        expect($reason)->not->toBe('');
        expect(in_array($class, $models, true))->toBeTrue("{$class} ya no es TenantModel/AppendOnlyModel concreto: retirar de la lista de AR-06");

        expect(is_subclass_of($class, Auditable::class))->toBeFalse("{$class} ya implementa Auditable: retirar de la lista de excepciones de AR-06");
    }

    expect(array_keys(auditableExceptions()))->toHaveCount(6);
});

// ADR-056 AR-06 (#378 B4), INV-001, INV-003: el comprobador de arriba solo ve
// subclases de `TenantModel`/`AppendOnlyModel`; un modelo con `tenant_id` que
// extienda `Model` directamente escaparía de INV-001 (sin scope de tenant) y
// de INV-003. Se contrasta el ESQUEMA REAL (`pg_catalog`, como AR-04/AR-05)
// con el catálogo de modelos: ningún modelo concreto de `app/` puede mapear
// una tabla con columna `tenant_id` sin ser `TenantModel`/`AppendOnlyModel`.
//
// Alcance deliberado: NO se exige que toda tabla con `tenant_id` tenga
// modelo (las tablas pivote como `role_user` y las de sondeo de los tests no
// lo tienen, y exigirlo sería un falso positivo); solo que los modelos que
// existen estén bien tipados.

/**
 * @return list<string> tablas del esquema con una columna `tenant_id`
 */
function tablesWithTenantIdColumn(string $schema, string $tablePrefix = ''): array
{
    $rows = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT DISTINCT c.relname AS tabla
        FROM pg_attribute a
        JOIN pg_class c ON c.oid = a.attrelid AND c.relkind IN ('r', 'p')
        JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = ?
        WHERE a.attnum > 0 AND NOT a.attisdropped AND a.attname = 'tenant_id' AND c.relname LIKE ?
        ORDER BY 1
        SQL, [$schema, $tablePrefix.'%']);

    return array_map(fn (object $r): string => $r->tabla, $rows);
}

/**
 * Modelos concretos de `$models` cuya tabla tiene `tenant_id` y que no son
 * `TenantModel`/`AppendOnlyModel`.
 *
 * @param  list<class-string<Model>>  $models
 * @param  list<string>  $tenantTables
 * @return list<string> `clase (tabla)`
 */
function modelsOnTenantTablesWithoutTenantBase(array $models, array $tenantTables): array
{
    $violations = [];

    foreach ($models as $class) {
        $table = (new $class)->getTable();

        if (in_array($table, $tenantTables, true)
            && ! is_subclass_of($class, TenantModel::class)
            && ! is_subclass_of($class, AppendOnlyModel::class)) {
            $violations[] = "{$class} ({$table})";
        }
    }

    sort($violations);

    return $violations;
}

/**
 * @return list<class-string<Model>> todo modelo Eloquent concreto bajo `app/`
 */
function concreteEloquentModels(): array
{
    $models = [];
    $appPath = app_path();

    foreach (PhpScanner::phpFiles($appPath) as $file) {
        $class = 'App\\'.str_replace('/', '\\', substr($file, strlen($appPath) + 1, -4));

        if (class_exists($class) && ! (new ReflectionClass($class))->isAbstract() && is_subclass_of($class, Model::class)) {
            $models[] = $class;
        }
    }

    return $models;
}

test('AR-06 INV-001 INV-003 #378 B4: ningún modelo sobre una tabla con tenant_id extiende Model directamente', function (): void {
    $tenantTables = tablesWithTenantIdColumn('public');

    // Guardia de que la comprobación ve algo: el esquema migrado tiene tablas
    // de tenant y los modelos concretos las cubren.
    expect(count($tenantTables))->toBeGreaterThan(20)
        ->and(count(concreteEloquentModels()))->toBeGreaterThan(20);

    $violations = modelsOnTenantTablesWithoutTenantBase(concreteEloquentModels(), $tenantTables);

    expect($violations)->toBe([], 'modelos con tenant_id que no extienden TenantModel/AppendOnlyModel (INV-001, INV-003): '.implode(', ', $violations));
});

test('AR-06 #378 B4: control negativo permanente — detecta un Model con tenant_id y respeta el modelo bien tipado', function (): void {
    $owner = DB::connection('pgsql_owner');
    $owner->beginTransaction();

    try {
        $owner->statement('CREATE TABLE ar06_ctrl_widgets (id bigint PRIMARY KEY, tenant_id bigint NOT NULL)');
        $owner->statement('CREATE TABLE ar06_ctrl_gadgets (id bigint PRIMARY KEY, tenant_id bigint NOT NULL)');
        $owner->statement('CREATE TABLE ar06_ctrl_sin_tenant (id bigint PRIMARY KEY)');

        $tables = tablesWithTenantIdColumn('public', 'ar06_ctrl_');

        expect($tables)->toBe(['ar06_ctrl_gadgets', 'ar06_ctrl_widgets'])
            ->and(modelsOnTenantTablesWithoutTenantBase([RawTenantIdModel::class, TypedTenantIdModel::class], $tables))
            ->toBe([RawTenantIdModel::class.' (ar06_ctrl_widgets)']);
    } finally {
        $owner->rollBack();
    }
});
