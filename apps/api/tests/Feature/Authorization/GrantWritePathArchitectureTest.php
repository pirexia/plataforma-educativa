<?php

/**
 * REQ-PERM/funcional.md §12.1, RN-PERM-19: toda escritura de
 * `permission_role` pasa por el modelo (`create`/`save`/`delete`), nunca
 * por `attach()`/`detach()`/`sync()` sobre una relación `belongsToMany` —
 * esas no disparan eventos de modelo y dejarían la auditoría de
 * concesiones muda en la práctica, exactamente el estado del que 1.5
 * parte para `role_user` (issue #165).
 *
 * CA-PERM-087.
 */
test('CA-PERM-087: ningún fichero de apps/api/app usa attach/detach/sync sobre permissionGrants() o Role::permissionGrants', function (): void {
    $appPath = base_path('app');
    $checked = 0;
    $offending = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();

        // El propio modelo Role declara la relación permissionGrants(); no
        // es una escritura.
        if (str_ends_with($path, 'app/Models/Role.php')) {
            continue;
        }

        $checked++;
        $code = file_get_contents($path);

        if (preg_match('/permissionGrants\(\)\s*->\s*(attach|detach|sync)\s*\(/', $code) === 1) {
            $offending[] = $path;
        }
    }

    expect($checked)->toBeGreaterThan(0);
    expect($offending)->toBe([], 'Escritura de permission_role por attach/detach/sync encontrada en: '.implode(', ', $offending));
});
