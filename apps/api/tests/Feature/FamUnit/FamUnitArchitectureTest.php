<?php

use Tests\Support\ArchitectureModules;
use Tests\Support\PhpScanner;

pest()->group('arch');

// REQ-FAM-UNIT (1.14-a), CA-FAM-UNIT-083, ADR-056 AR-01, AR-02, AR-08, INV-007, INV-015: ningún fichero del módulo
// compara códigos de rol (el control de acceso es por permisos y los roles personalizados son ciudadanos de primera,
// `RN-PERM-46`), el módulo solo usa de otros su `Domain` (sin `Domain\Models`) y ningún otro módulo usa sus clases
// internas. Complementa las reglas globales, que ya enumeran el módulo por sistema de ficheros.

/** @return array<string, string> ruta relativa a `app/Modules/FamUnit` => código */
function famUnitSources(): array
{
    $root = app_path('Modules/FamUnit');
    $sources = [];

    foreach (PhpScanner::phpFiles($root) as $file) {
        $sources[substr($file, strlen($root) + 1)] = (string) file_get_contents($file);
    }

    return $sources;
}

test('CA-FAM-UNIT-083 AR-08 RN-PERM-46: ningún fichero de FamUnit contiene el código de un rol predefinido como literal ni usa la clase Role', function (): void {
    $roleCodes = ['administrador_centro', 'direccion', 'secretaria', 'administrativo', 'docente', 'tutor_grupo', 'orientador', 'coordinador_bienestar', 'estudiante', 'responsable_economico', 'bibliotecario', 'monitor_extraescolares', 'personal_sanitario', 'conserjeria_pas', 'soporte_plataforma'];
    $sources = famUnitSources();

    expect(count($sources))->toBeGreaterThan(30);

    foreach ($sources as $path => $source) {
        foreach (PhpScanner::stringValues($source) as $value) {
            foreach ($roleCodes as $code) {
                expect(str_contains($value, $code))->toBeFalse("{$path}: literal de rol «{$code}»");
            }
        }

        expect($source)->not->toContain('App\\Models\\Role;', $path);
    }
});

test('CA-FAM-UNIT-083 AR-01 AR-02 INV-007: FamUnit solo importa de otros módulos su Domain sin Models (en la práctica, Core\\Domain)', function (): void {
    foreach (famUnitSources() as $path => $source) {
        preg_match_all('/^use App\\\\Modules\\\\(\w+)\\\\([\w\\\\]+)/m', $source, $uses, PREG_SET_ORDER);

        foreach ($uses as [, $module, $rest]) {
            if ($module === 'FamUnit') {
                continue;
            }

            expect($rest)->toStartWith('Domain\\', "{$path}: importa {$module}\\{$rest}")
                ->and($rest)->not->toStartWith('Domain\\Models\\', "{$path}: importa un modelo de {$module}");
        }
    }
});

test('AR-01 CA-FAM-UNIT-083: el módulo FamUnit está enumerado por la regla de fronteras y sus carpetas internas quedan vedadas a los demás', function (): void {
    expect(ArchitectureModules::names())->toContain('FamUnit')
        ->and(ArchitectureModules::forbiddenFor('Acad'))->toContain(
            'App\\Modules\\FamUnit\\Application',
            'App\\Modules\\FamUnit\\Infrastructure',
            'App\\Modules\\FamUnit\\Http',
            'App\\Modules\\FamUnit\\Domain\\Models',
        )
        ->and(ArchitectureModules::forbiddenFor('FamUnit'))->toContain('App\\Modules\\Core\\Application', 'App\\Modules\\Core\\Domain\\Models');
});

arch('CA-FAM-UNIT-083 AR-01: FamUnit no usa ninguna clase interna de otro módulo')
    ->expect('App\\Modules\\FamUnit')
    ->not->toUse(ArchitectureModules::forbiddenFor('FamUnit'));

arch('CA-FAM-UNIT-083 INV-007: las interfaces públicas de FamUnit\\Domain no dependen de modelos Eloquent')
    ->expect('App\\Modules\\FamUnit\\Domain\\FamilyMembership')
    ->not->toUse('Illuminate\\Database\\Eloquent');
