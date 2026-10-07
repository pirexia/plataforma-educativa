<?php

use App\Models\AuditLog;
use App\Models\IdempotencyKey;
use App\Modules\Auth\Domain\Models\LoginAttempt;
use App\Modules\Auth\Domain\Models\MfaChallenge;
use App\Modules\Auth\Domain\Models\SamlAuthRequest;
use App\Modules\Auth\Domain\Models\SamlConsumedAssertion;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\AppendOnlyModel;
use App\Support\Tenancy\TenantModel;
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
