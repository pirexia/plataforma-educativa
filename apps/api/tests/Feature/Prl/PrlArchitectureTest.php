<?php

use Tests\Support\ArchitectureModules;
use Tests\Support\PhpScanner;

// REQ-PRL-004 (1.14b-b), INV-007, ADR-068 §5.1, CA-PRL-013, CA-068-07, AR-01, AR-02, AR-08: el módulo `prl` solo consume la lista de
// `fam_unit` por su interfaz pública y no compara códigos de rol.

/** @return array<string, string> fuentes PHP de `app/Modules/Prl` por ruta relativa */
function prlSources(): array
{
    $root = app_path('Modules/Prl');
    $sources = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $sources[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }

    return $sources;
}

test('CA-PRL-013 AR-08 RN-PERM-46: ningún fichero de Prl contiene el código de un rol predefinido como literal ni usa la clase Role', function (): void {
    $roleCodes = ['administrador_centro', 'direccion', 'secretaria', 'administrativo', 'docente', 'tutor_grupo', 'orientador', 'coordinador_bienestar', 'estudiante', 'responsable_economico', 'bibliotecario', 'monitor_extraescolares', 'personal_sanitario', 'conserjeria_pas', 'soporte_plataforma'];
    $sources = prlSources();

    expect(count($sources))->toBeGreaterThan(15);

    foreach ($sources as $path => $source) {
        foreach (PhpScanner::stringValues($source) as $value) {
            foreach ($roleCodes as $code) {
                expect(str_contains($value, $code))->toBeFalse("{$path}: literal de rol «{$code}»");
            }
        }

        expect($source)->not->toContain('App\\Models\\Role;', $path);
    }
});

test('CA-PRL-013 AR-01 AR-02 INV-007: Prl solo importa de otros módulos su Domain sin Models, y de FamUnit únicamente la interfaz de la lista y sus objetos de valor', function (): void {
    $allowedFamUnit = ['AuthorizedPickupList', 'FamilyMembership', 'PickupBasis', 'PickupEntry', 'PickupGrant', 'StudentMatch'];

    foreach (prlSources() as $path => $source) {
        preg_match_all('/^use App\\\\Modules\\\\(\w+)\\\\([\w\\\\]+)/m', $source, $uses, PREG_SET_ORDER);

        foreach ($uses as [, $module, $rest]) {
            if ($module === 'Prl') {
                continue;
            }

            expect($rest)->toStartWith('Domain\\', "{$path}: importa {$module}\\{$rest}")
                ->and($rest)->not->toStartWith('Domain\\Models\\', "{$path}: importa un modelo de {$module}");

            if ($module === 'FamUnit') {
                expect(in_array(substr($rest, strlen('Domain\\')), $allowedFamUnit, true))->toBeTrue("{$path}: importa FamUnit\\{$rest}");
            }
        }
    }
});

test('CA-068-07 RN-PRL-01 INV-007: ninguna clase de Prl lee tablas de fam_unit ni de people/users por SQL o por el constructor', function (): void {
    $tables = ['pickup_authorizations', 'pickup_photos', 'guardian_student_links', 'guardians', 'family_units', 'family_unit_students', 'terms_versions', 'people', 'users'];

    foreach (prlSources() as $path => $source) {
        // Las migraciones declaran las claves foráneas de evidencia: son esquema, no lectura.
        if (str_starts_with($path, 'Database/')) {
            continue;
        }

        foreach ($tables as $table) {
            expect($source)->not->toMatch("/(?:DB::table|->table|from|join)\\(?\\s*['\"` ]{$table}\\b/i", "{$path}: lee la tabla {$table}");
        }
    }
});

test('AR-01 CA-PRL-013: el módulo Prl está enumerado por la regla de fronteras y sus carpetas internas quedan vedadas a los demás', function (): void {
    expect(ArchitectureModules::names())->toContain('Prl')
        ->and(ArchitectureModules::forbiddenFor('Acad'))->toContain('App\\Modules\\Prl\\Application', 'App\\Modules\\Prl\\Infrastructure');
});

arch('CA-PRL-013 AR-01: Prl no usa ninguna clase interna de otro módulo')
    ->expect('App\\Modules\\Prl')
    ->not->toUse(ArchitectureModules::forbiddenFor('Prl'));
