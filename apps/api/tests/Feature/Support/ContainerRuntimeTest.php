<?php

use Symfony\Component\Process\Process;

/**
 * ADR-080 §5 (runtime de contenedores, nivel 1; paso 0.10e-b) y CS-03: `infra/lib/container-runtime.sh` resuelve y valida
 * `CONTAINER_RUNTIME` (alias `CONTAINER_CLI`) con lista cerrada `podman`/`docker`. La prueba solo carga el lib: no ejecuta
 * ningún runtime.
 */
beforeEach(function (): void {
    if (! is_file(base_path('../../infra/lib/container-runtime.sh'))) {
        $this->markTestSkipped('La raíz del repositorio no está montada (contenedor de desarrollo solo con apps/api); CI sí la tiene.');
    }
});

/**
 * @param  array<string, string>  $env  variables a fijar; CONTAINER_RUNTIME y CONTAINER_CLI heredadas se quitan siempre
 * @return array{0: int, 1: string, 2: string} código, stdout, stderr
 */
function runContainerRuntimeLib(array $env): array
{
    $lib = base_path('../../infra/lib/container-runtime.sh');
    $process = new Process(
        ['bash', '-c', 'source "$1"; resolve_container_runtime && printf %s "$CONTAINER_RUNTIME"', 'bash', $lib],
        base_path(),
        ['CONTAINER_RUNTIME' => false, 'CONTAINER_CLI' => false, ...$env],
    );
    $process->run();

    return [$process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
}

test('ADR-080 §5 CS-03: sin variables el runtime es podman', function (): void {
    [$code, $out] = runContainerRuntimeLib([]);

    expect($code)->toBe(0)->and($out)->toBe('podman');
});

test('ADR-080 §5 CS-03: CONTAINER_RUNTIME=docker usa docker', function (): void {
    [$code, $out] = runContainerRuntimeLib(['CONTAINER_RUNTIME' => 'docker']);

    expect($code)->toBe(0)->and($out)->toBe('docker');
});

test('ADR-080 §5 CS-03: solo CONTAINER_CLI=docker (alias de respaldo) usa docker', function (): void {
    [$code, $out] = runContainerRuntimeLib(['CONTAINER_CLI' => 'docker']);

    expect($code)->toBe(0)->and($out)->toBe('docker');
});

test('ADR-080 §5 CS-03: CONTAINER_RUNTIME y CONTAINER_CLI iguales valen', function (): void {
    [$code, $out] = runContainerRuntimeLib(['CONTAINER_RUNTIME' => 'podman', 'CONTAINER_CLI' => 'podman']);

    expect($code)->toBe(0)->and($out)->toBe('podman');
});

test('ADR-080 §5 CS-03: CONTAINER_RUNTIME y CONTAINER_CLI distintos abortan', function (): void {
    [$code, $out, $stderr] = runContainerRuntimeLib(['CONTAINER_RUNTIME' => 'podman', 'CONTAINER_CLI' => 'docker']);

    expect($code)->not->toBe(0)
        ->and($out)->toBe('')
        ->and($stderr)->toContain('CONTAINER_RUNTIME')->toContain('CONTAINER_CLI');
});

test('ADR-080 §5 CS-03: un valor fuera de la lista cerrada aborta nombrando la variable', function (string $value, string $variable): void {
    [$code, $out, $stderr] = runContainerRuntimeLib([$variable => $value]);

    expect($code)->not->toBe(0)
        ->and($out)->toBe('')
        ->and($stderr)->toContain('CONTAINER_RUNTIME no válido');
})->with(function (): Generator {
    foreach (['rm', 'podman; ls', '  ', 'Docker', 'nerdctl', '$(id)', '/usr/bin/podman'] as $value) {
        yield "CONTAINER_RUNTIME «{$value}»" => [$value, 'CONTAINER_RUNTIME'];
        yield "CONTAINER_CLI «{$value}»" => [$value, 'CONTAINER_CLI'];
    }
});
