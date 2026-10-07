<?php

use App\Models\Role;
use App\Modules\Auth\Domain\MfaComplianceDirectory;
use App\Modules\Auth\Http\Controllers\MfaComplianceController;
use App\Modules\Auth\Infrastructure\Console\GrantLockoutPermissionsCommand;
use App\Modules\Auth\Infrastructure\EloquentMfaComplianceDirectory;
use App\Modules\Auth\Infrastructure\Listeners\MaterializeMfaObligationsForRole;
use Tests\Support\PhpScanner;

pest()->group('arch');

// ADR-056 AR-08, CA-056-09, ADR-044 §8 (1), ADR-044 §4.10, INV-002, INV-015:
// el control de acceso se decide por permisos, no por código de rol
// (`if ($user->esDocente())` es deuda en cuanto exista un rol
// personalizado). Dos vigilancias:
//
// 1. `App\Models\Role` solo se usa en `App\Modules\Core`,
//    `App\Support\Authorization`, `App\Models` y `App\Providers`. Un módulo
//    que necesite saber algo de roles lo pide por una interfaz de
//    `Core\Domain` (INV-007).
// 2. Los literales `'administrador_centro'` y `'soporte_plataforma'` (los
//    dos códigos de rol sin homónimo de dominio; ADR-056 §3.3 explica por
//    qué no se vigilan los dieciséis) solo en la lista de ficheros.
//
// Límite declarado: el escáner no ve cadenas con variables interpoladas ni
// heredocs (ver `Tests\Support\PhpScanner`), ni un código de rol obtenido
// de la base de datos.

/**
 * Clases de Auth que usan `Role` hoy: `roles.mfa_required` es atributo del
 * rol (`REQ-AUTH-003`), no control de acceso. Lista CERRADA y nominal
 * (ADR-056 §3.2): solo puede reducirse; ampliarla exige especificación
 * aprobada por el usuario (OPEN-056-02/OPEN-056-05).
 *
 * @return array<class-string, string>
 */
function roleUsageExceptions(): array
{
    return [
        MfaComplianceDirectory::class => 'REQ-AUTH-003: cumplimiento de MFA por rol (atributo `roles.mfa_required`)',
        MfaComplianceController::class => 'REQ-AUTH-003: cumplimiento de MFA por rol (atributo `roles.mfa_required`)',
        GrantLockoutPermissionsCommand::class => 'REQ-AUTH-001: concede permisos de bloqueo al rol administrador (siembra)',
        EloquentMfaComplianceDirectory::class => 'REQ-AUTH-003: cumplimiento de MFA por rol (atributo `roles.mfa_required`)',
        MaterializeMfaObligationsForRole::class => 'REQ-AUTH-003: materializa obligaciones al cambiar `roles.mfa_required`',
    ];
}

/**
 * Ficheros (relativos a `app/`) con un literal de código de rol: invariantes
 * de negocio o siembra, no control de acceso. Lista CERRADA y nominal.
 *
 * @return array<string, string>
 */
function roleLiteralExceptions(): array
{
    return [
        'Modules/Core/Application/SchoolAdministratorGuard.php' => 'invariante de negocio: nunca sin administrador de centro',
        'Modules/Core/Application/ReplaceUserRoles.php' => 'invariante de negocio: el último administrador no pierde el rol',
        'Modules/Core/Http/Controllers/UsersController.php' => 'invariante de negocio: protección del administrador de centro',
        'Modules/Core/Application/ProvisionTenantDefaults.php' => 'siembra del primer administrador del centro',
        'Modules/Core/Infrastructure/Console/GrantRoleAdministrationCommand.php' => 'siembra: concesión de administración de roles',
        'Modules/Auth/Infrastructure/Console/GrantLockoutPermissionsCommand.php' => 'siembra: concesión de permisos de bloqueo',
    ];
}

const ROLE_CODE_LITERALS = ['administrador_centro', 'soporte_plataforma'];

// Un solo objetivo (`Role`); la lista de usuarios permitidos es la de §3.3
// más las cinco clases nominales.
arch('AR-08 CA-056-09 ADR-044 §8: App\Models\Role solo se usa en Core, Support\Authorization, Models, Providers y las excepciones nominales')
    ->expect(Role::class)
    ->toOnlyBeUsedIn([
        'App\Modules\Core',
        'App\Support\Authorization',
        'App\Models',
        'App\Providers',
        ...array_keys(roleUsageExceptions()),
    ]);

foreach (array_keys(roleUsageExceptions()) as $class) {
    arch("AR-08 CA-056-15: la excepción {$class} sigue usando Role, si no, retirarla de la lista")
        ->expect($class)
        ->toUse(Role::class);
}

test('AR-08 CA-056-09: los literales de código de rol administrador_centro / soporte_plataforma solo aparecen en la lista de ficheros', function (): void {
    $appPath = app_path();
    $files = PhpScanner::phpFiles($appPath);
    $found = [];

    foreach ($files as $file) {
        $source = file_get_contents($file);

        foreach (ROLE_CODE_LITERALS as $literal) {
            if (PhpScanner::hasStringLiteral($source, $literal)) {
                $found[substr($file, strlen($appPath) + 1)] = true;
            }
        }
    }

    expect(count($files))->toBeGreaterThan(100);

    $outside = array_values(array_diff(array_keys($found), array_keys(roleLiteralExceptions())));
    expect($outside)->toBe([], 'literal de código de rol fuera de la lista (comprobar permisos, no roles): '.implode(', ', $outside));

    $stale = array_values(array_diff(array_keys(roleLiteralExceptions()), array_keys($found)));
    expect($stale)->toBe([], 'excepciones de AR-08 que ya no hacen falta, retirarlas de la lista: '.implode(', ', $stale));
});

test('AR-08 CA-056-09: las listas de excepciones son las de ADR-056 §3.3 (5 clases y 6 ficheros)', function (): void {
    expect(roleUsageExceptions())->toHaveCount(5)
        ->and(roleLiteralExceptions())->toHaveCount(6);
});
