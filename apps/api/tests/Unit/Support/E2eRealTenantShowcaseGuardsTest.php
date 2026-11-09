<?php

use Symfony\Component\Process\Process;

/*
 * Landing (#579, ADR-074 §4.4): el modo `showcase` de `tests/Support/e2e-real-tenant.php` conserva las garantías del soporte destructivo.
 * Estas dos se comprueban SIN base de datos: el script aborta antes de arrancar la aplicación. Las que dependen de la base
 * (solo `local`/`testing` y `plataforma`/`plataforma_test`; solo retira lo que creó él, con la triple marca slug + nombre + administrador)
 * las ejerce el arnés real, `npm run landing:shots` (`apps/web/scripts/landing-shots.sh`).
 */

function runE2eTenantSupport(array $args, array $env = []): array
{
    $process = new Process(['php', 'tests/Support/e2e-real-tenant.php', ...$args], dirname(__DIR__, 3), ['E2E_ALLOW_DESTRUCTIVE' => false, ...$env]);
    $process->run();

    return [$process->getExitCode(), $process->getErrorOutput()];
}

it('showcase solo crea el slug demo y aborta con otro (#579)', function (): void {
    [$code, $error] = runE2eTenantSupport(['setup', 'otro-centro', 'showcase'], ['E2E_ALLOW_DESTRUCTIVE' => '1']);

    expect($code)->toBe(2)
        ->and($error)->toContain('el modo showcase solo crea el slug «demo»');
});

it('showcase aborta sin E2E_ALLOW_DESTRUCTIVE=1 (#579)', function (): void {
    [$code, $error] = runE2eTenantSupport(['setup', 'demo', 'showcase']);

    expect($code)->toBe(2)
        ->and($error)->toContain('falta E2E_ALLOW_DESTRUCTIVE=1');
});
