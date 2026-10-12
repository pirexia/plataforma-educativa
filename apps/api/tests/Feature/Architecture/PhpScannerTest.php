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

test('CA-056-14 AR-15: chainsFromStaticCallOn devuelve la cadena del mismo nivel, sin argumentos ni cierres, y se detiene en ; o al cerrar la expresión', function (): void {
    $fqcn = 'App\Models\Foo';
    $source = phpScannerFixture("namespace App\\Z;\nuse App\\Models\\Foo;\nclass A { function f() {\n"
        ."Foo::query()->where('a', \$x->first())->whereIn('b', fn (\$q) => \$q->update([]))->delete();\n"
        ."Foo::find(1)?->update([]);\n"
        ."g(Foo::query())->delete();\n"
        ."return [Foo::class, Foo::CONST];\n} }");

    expect(PhpScanner::chainsFromStaticCallOn($source, $fqcn))->toBe([
        ['query', 'where', 'whereIn', 'delete'],
        ['find', 'update'],
        ['query'],
    ]);
});

// ───────── #414 (1.11-a): ampliación del escáner, cada caso con su control negativo ─────────

test('#414: stringValues() ve la concatenación estática, el heredoc, el nowdoc y la cadena interpolada', function (string $code, string $needle): void {
    $found = array_filter(PhpScanner::stringValues(phpScannerFixture($code)), static fn (string $v): bool => str_contains($v, $needle));

    expect($found)->not->toBe([]);
})->with([
    'concatenacion de dos literales' => ["\$c = 'pgsql_'.'owner';", 'pgsql_owner'],
    'concatenacion de tres, con espacios y saltos' => ["\$c = 'pgsql'\n    . '_'\n    . 'owner';", 'pgsql_owner'],
    'comillas dobles concatenadas' => ['$c = "pgsql_" . "owner";', 'pgsql_owner'],
    'heredoc' => ["\$c = <<<TXT\nconexion pgsql_owner\nTXT;\n", 'conexion pgsql_owner'],
    'nowdoc' => ["\$c = <<<'TXT'\nconexion pgsql_owner\nTXT;\n", 'conexion pgsql_owner'],
    'interpolada: fragmento literal' => ['$c = "usar pgsql_owner para {$t}";', 'usar pgsql_owner para '],
    'interpolada: fragmentos unidos sin la variable' => ['$c = "pgsql_{$t}owner";', 'pgsql_owner'],
]);

test('#414: stringValues() NO ve lo que depende de una variable ni los comentarios (límite declarado)', function (string $code): void {
    expect(PhpScanner::stringValues(phpScannerFixture($code)))->not->toContain('pgsql_owner');
})->with([
    'literal mas variable' => ["\$c = 'pgsql_'.\$sufijo;"],
    'variable mas literal' => ["\$c = \$prefijo.'owner';"],
    'config con variable' => ['$c = config($nombre);'],
    'comentario' => ["// 'pgsql_'.'owner'\n/* 'pgsql_owner' */"],
    'literales separados por una llamada' => ["\$c = ['pgsql_', 'owner'];"],
]);

test('#414: stringLiterals() conserva su contrato exacto (no incluye concatenaciones)', function (): void {
    expect(PhpScanner::stringLiterals(phpScannerFixture("\$c = 'a'.'b';")))->toBe(['a', 'b']);
});

const SCANNER_TARGET = 'App\\Support\\Tenancy\\TenantMigration';

test('#414: staticCallsOn() resuelve use agrupado, namespace con llaves, parent::/static::/self:: heredados e invocables por literal', function (string $code): void {
    expect(PhpScanner::staticCallsOn(phpScannerFixture($code), SCANNER_TARGET))->not->toBe([]);
})->with([
    'use agrupado' => ["namespace A;\nuse App\\Support\\Tenancy\\{Tenant, TenantMigration};\nclass X { function f() { TenantMigration::tenantTable('t'); } }"],
    'use agrupado con alias' => ["namespace A;\nuse App\\Support\\Tenancy\\{Tenant, TenantMigration as TM};\nclass X { function f() { TM::tenantTable('t'); } }"],
    'use varios en una sentencia' => ["namespace A;\nuse Foo\\Bar, App\\Support\\Tenancy\\TenantMigration;\nclass X { function f() { TenantMigration::tenantTable('t'); } }"],
    'namespace con llaves' => ["namespace A {\n    use App\\Support\\Tenancy\\TenantMigration;\n    class X { function f() { TenantMigration::tenantTable('t'); } }\n}"],
    'segundo namespace con llaves' => ["namespace One { use Other\\TenantMigration; class P {} }\nnamespace Two { use App\\Support\\Tenancy\\TenantMigration; class Q { function f() { TenantMigration::tenantTable('t'); } } }"],
    'parent::' => ["use App\\Support\\Tenancy\\TenantMigration;\nclass X extends TenantMigration { function f() { parent::tenantTable('t'); } }"],
    'static:: en subclase' => ["use App\\Support\\Tenancy\\TenantMigration;\nclass X extends TenantMigration { function f() { static::tenantTable('t'); } }"],
    'self:: en subclase' => ["use App\\Support\\Tenancy\\TenantMigration;\nclass X extends TenantMigration { function f() { self::tenantTable('t'); } }"],
    'call_user_func con Clase::class' => ["use App\\Support\\Tenancy\\TenantMigration;\nclass X { function f() { call_user_func([TenantMigration::class, 'tenantTable'], 't'); } }"],
    'invocable con cadenas' => ["class X { function f() { call_user_func(['App\\\\Support\\\\Tenancy\\\\TenantMigration', 'tenantTable'], 't'); } }"],
    'invocable cadena Clase::metodo' => ["class X { function f() { call_user_func('App\\\\Support\\\\Tenancy\\\\TenantMigration::tenantTable', 't'); } }"],
    'invocable cadena con barra inicial' => ["class X { function f() { array_map('\\\\App\\\\Support\\\\Tenancy\\\\TenantMigration::tenantTable', []); } }"],
    'first-class callable' => ["use App\\Support\\Tenancy\\TenantMigration;\nclass X { function f() { \$g = TenantMigration::tenantTable(...); } }"],
]);

test('#414: staticCallsOn() NO confunde: otra clase homónima, static::/self:: sin extender, la clase sola, ni lo dinámico (límite declarado)', function (string $code): void {
    expect(PhpScanner::staticCallsOn(phpScannerFixture($code), SCANNER_TARGET))->toBe([]);
})->with([
    'homonima de otro namespace' => ["namespace Other;\nclass X { function f() { TenantMigration::tenantTable('t'); } }"],
    'use de otra clase homonima' => ["namespace A;\nuse Other\\TenantMigration;\nclass X { function f() { TenantMigration::tenantTable('t'); } }"],
    'use de la primera llave no vale en la segunda' => ["namespace One { use App\\Support\\Tenancy\\TenantMigration; class P {} }\nnamespace Two { class Q { function f() { TenantMigration::tenantTable('t'); } } }"],
    'static:: en clase que no extiende' => ["use App\\Support\\Tenancy\\TenantMigration;\nclass X { function f() { static::tenantTable('t'); self::otro(); } }"],
    'parent:: de otro padre' => ["class X extends Other\\Base { function f() { parent::tenantTable('t'); } }"],
    'la clase sola sin llamada' => ["use App\\Support\\Tenancy\\TenantMigration;\n\$c = TenantMigration::class;"],
    'invocable de otra clase' => ["use Other\\Thing;\ncall_user_func([Thing::class, 'tenantTable']);"],
    'cadena de otra clase' => ["call_user_func('Other\\\\Thing::tenantTable');"],
    'cadena sin metodo' => ["\$c = 'App\\\\Support\\\\Tenancy\\\\TenantMigration::';"],
    'comentario' => ["// TenantMigration::tenantTable('t');\n/* call_user_func([TenantMigration::class, 'tenantTable']); */"],
    'dinamica con la clase en una variable (limite declarado)' => ["use App\\Support\\Tenancy\\TenantMigration;\nclass X { function f(\$clase) { \$clase::tenantTable('t'); } }"],
    'nombre armado en ejecucion (limite declarado)' => ["class X { function f(\$m) { call_user_func('App\\\\Support\\\\Tenancy\\\\TenantMigration::'.\$m); } }"],
]);
