<?php

use Tests\Support\PhpScanner;

pest()->group('arch');

// ADR-056 §3.2 punto 5, CA-056-14, INV-015: casos fijos del escáner de
// tokens compartido. Demuestran que detecta lo que debe (incluidas las dos
// formas que escaparon al primer detector de `CA-PERM-092`, issue #167:
// operador nullsafe y nombre dinámico entre llaves) y que NO detecta
// comentarios ni cadenas.

function phpScannerFixture(string $body): string
{
    return "<?php\n".$body;
}

test('CA-056-14: callsMethod detecta ->m(, ?->m(, ->{\'m\'}( y ?->{"m"}(', function (string $code): void {
    expect(PhpScanner::callsMethod(phpScannerFixture($code), 'runAsPlatform'))->toBeTrue();
})->with([
    'flecha' => ['$c->runAsPlatform(X::A, fn () => 1);'],
    'nullsafe (#167)' => ['$c?->runAsPlatform(X::A, fn () => 1);'],
    'dinamica comilla simple (#167)' => ["\$c->{'runAsPlatform'}(X::A);"],
    'dinamica nullsafe comilla doble (#167)' => ['$c?->{"runAsPlatform"}(X::A);'],
    'con espacios y saltos' => ["\$c\n    ->\n    runAsPlatform\n    (X::A);"],
]);

test('CA-056-14: callsMethod NO detecta comentarios, cadenas, propiedades, funciones sueltas ni otros métodos', function (string $code): void {
    expect(PhpScanner::callsMethod(phpScannerFixture($code), 'runAsPlatform'))->toBeFalse();
})->with([
    'comentario de linea' => ['// $c->runAsPlatform(X::A);'],
    'comentario de bloque' => ['/* $c->runAsPlatform(X::A); */'],
    'docblock' => ["/**\n * Usa \$c?->runAsPlatform() al explicarlo.\n */"],
    'cadena simple' => ["\$m = 'TenantContext::runAsPlatform() no se puede usar';"],
    'cadena en excepcion' => ["throw new RuntimeException('no con \$c->runAsPlatform(X)');"],
    'propiedad sin llamada' => ['$x = $c->runAsPlatform;'],
    'funcion suelta' => ['runAsPlatform(X::A);'],
    'llamada estatica' => ['Context::runAsPlatform(X::A);'],
    'otro metodo' => ['$c->runAsPlatformLater(X::A);'],
    'dinamica de otro nombre' => ["\$c->{'otherName'}(X::A);"],
    'dinamica sin llamada' => ["\$x = \$c->{'runAsPlatform'};"],
]);

test('CA-056-14: declaresFunction detecta la declaración y no menciones', function (): void {
    expect(PhpScanner::declaresFunction(phpScannerFixture('class A { public function runAsPlatform(int $x): void {} }'), 'runAsPlatform'))->toBeTrue()
        ->and(PhpScanner::declaresFunction(phpScannerFixture('class A { public function other(): void {} }'), 'runAsPlatform'))->toBeFalse()
        ->and(PhpScanner::declaresFunction(phpScannerFixture('// function runAsPlatform() {}'), 'runAsPlatform'))->toBeFalse()
        ->and(PhpScanner::declaresFunction(phpScannerFixture("\$s = 'function runAsPlatform(';"), 'runAsPlatform'))->toBeFalse();
});

test('CA-056-14: stringLiterals devuelve cadenas constantes y excluye comentarios', function (): void {
    $source = phpScannerFixture(<<<'PHP'
        // 'en_comentario'
        /* "en_bloque" */
        $a = 'administrador_centro';
        $b = "soporte_plataforma";
        $c = ['uno' => 'dos'];
        /** @var 'en_docblock' $x */
        PHP);

    expect(PhpScanner::stringLiterals($source))->toBe(['administrador_centro', 'soporte_plataforma', 'uno', 'dos'])
        ->and(PhpScanner::hasStringLiteral($source, 'administrador_centro'))->toBeTrue()
        ->and(PhpScanner::hasStringLiteral($source, 'en_comentario'))->toBeFalse()
        ->and(PhpScanner::hasStringLiteral($source, 'en_docblock'))->toBeFalse();
});

test('CA-056-14: staticCallsOn resuelve la clase por use, alias, nombre completo y namespace propio', function (): void {
    $fqcn = 'App\Models\AuditLog';

    $viaUse = phpScannerFixture("namespace App\\Foo;\nuse App\\Models\\AuditLog;\nclass A { function f() { return AuditLog::query()->get(); } }");
    $viaAlias = phpScannerFixture("namespace App\\Foo;\nuse App\\Models\\AuditLog as Rastro;\nclass A { function f() { return Rastro::where('a', 1); } }");
    $viaFqcn = phpScannerFixture("namespace App\\Foo;\nclass A { function f() { return \\App\\Models\\AuditLog::find(1); } }");
    $viaPrefix = phpScannerFixture("namespace App\\Foo;\nuse App\\Models;\nclass A { function f() { return Models\\AuditLog::all(); } }");
    $sameNamespace = phpScannerFixture("namespace App\\Models;\nclass A { function f() { return AuditLog::first(); } }");

    expect(PhpScanner::staticCallsOn($viaUse, $fqcn))->toBe(['query'])
        ->and(PhpScanner::staticCallsOn($viaAlias, $fqcn))->toBe(['where'])
        ->and(PhpScanner::staticCallsOn($viaFqcn, $fqcn))->toBe(['find'])
        ->and(PhpScanner::staticCallsOn($viaPrefix, $fqcn))->toBe(['all'])
        ->and(PhpScanner::staticCallsOn($sameNamespace, $fqcn))->toBe(['first']);
});

test('CA-056-14: staticCallsOn NO cuenta ::class, otra clase homónima, comentarios ni cadenas', function (): void {
    $fqcn = 'App\Models\AuditLog';

    $classConstant = phpScannerFixture("namespace App\\Foo;\nuse App\\Models\\AuditLog;\nclass A { function f() { return [AuditLog::class => 'x']; } }");
    $homonym = phpScannerFixture("namespace App\\Foo;\nuse Other\\Ns\\AuditLog;\nclass A { function f() { return AuditLog::query(); } }");
    $noImportSameName = phpScannerFixture("namespace App\\Foo;\nclass A { function f() { return AuditLog::query(); } }");
    $commentAndString = phpScannerFixture("namespace App\\Foo;\nuse App\\Models\\AuditLog;\n// AuditLog::query()\n\$s = 'AuditLog::query()';");

    expect(PhpScanner::staticCallsOn($classConstant, $fqcn))->toBe([])
        ->and(PhpScanner::staticCallsOn($homonym, $fqcn))->toBe([])
        ->and(PhpScanner::staticCallsOn($noImportSameName, $fqcn))->toBe([])
        ->and(PhpScanner::staticCallsOn($commentAndString, $fqcn))->toBe([]);
});

test('CA-056-14: phpFiles recorre app/ (no vacío) y devuelve rutas ordenadas', function (): void {
    $files = PhpScanner::phpFiles(base_path('app'));

    expect(count($files))->toBeGreaterThan(100);
    $sorted = $files;
    sort($sorted);
    expect($files)->toBe($sorted);
});
