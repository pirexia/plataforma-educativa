<?php

use Tests\Support\ArchitectureModules;

pest()->group('arch');

// ADR-056 AR-09, CA-056-10, ADR-044 §8 (2), ADR-044 §4.4, INV-015: CABLE
// TRAMPA, no regla. `ADR-044 §8` exige un evento `read` de auditoría en todo
// permiso de categoría especial (salud, NEAE, convivencia), pero no hay
// consumidor que fije el mecanismo. Hoy ningún permiso declarado tiene
// `is_special_category = true`; cuando aparezca el primero este test falla
// hasta que su especificación diseñe el mecanismo de auditoría de lectura y
// sustituya este cable por la regla real.

/**
 * @param  list<array{code: string, is_special_category?: bool}>  $permissions
 * @return list<string> códigos con `is_special_category = true`
 */
function specialCategoryPermissions(array $permissions): array
{
    return array_values(array_map(
        static fn (array $p): string => $p['code'],
        array_filter($permissions, static fn (array $p): bool => ($p['is_special_category'] ?? false) === true),
    ));
}

test('AR-09 CA-056-10: ningún permiso declarado tiene is_special_category = true (cable trampa de ADR-044 §8 (2))', function (): void {
    $permissions = ArchitectureModules::declaredPermissions();

    expect(count($permissions))->toBeGreaterThan(0);

    $special = specialCategoryPermissions($permissions);

    expect($special)->toBe([], 'permisos de categoría especial declarados: '.implode(', ', $special)
        .'. Antes de mezclar hay que diseñar el mecanismo de auditoría de LECTURA que exige ADR-044 §4.4 y sustituir este cable '
        .'por la regla real (ADR-056 AR-09, ADR-044 §8 (2)): ningún permiso especial sin evento `read`.');
});

test('AR-09 CA-056-10 control negativo: la función detecta un permiso is_special_category = true', function (): void {
    $permissions = [
        ['code' => 'a.leer'],
        ['code' => 'salud.leer', 'is_special_category' => true],
        ['code' => 'b.leer', 'is_special_category' => false],
    ];

    expect(specialCategoryPermissions($permissions))->toBe(['salud.leer']);
});
