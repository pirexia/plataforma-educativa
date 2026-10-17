<?php

use App\Support\Modules\Scaffolding\ModuleScaffolder;
use App\Support\Modules\Scaffolding\ModuleScaffoldException;
use App\Support\Modules\Scaffolding\ModuleSpec;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;

// ADR-056 §3.5, CA-056-30 a -33, INV-007, INV-009, INV-015. Comprueba el
// COMPORTAMIENTO del generador (qué valida, qué escribe y qué no, que sea
// idempotente) sobre un árbol temporal. NO compara el texto de las plantillas
// con ficheros de referencia (§3.5 lo prohíbe): que lo generado cumple las
// reglas lo prueba el job de CI de CA-056-35, que lo genera de verdad y le
// pasa `arch`, sus tests y Larastan.

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/make-module-'.bin2hex(random_bytes(6));

    mkdir("{$this->base}/app/Modules", 0775, true);
    mkdir("{$this->base}/routes", 0775, true);
    copy(base_path('routes/api-v1.php'), "{$this->base}/routes/api-v1.php");

    foreach (ModuleSpec::LOCALES as $locale) {
        mkdir("{$this->base}/lang/{$locale}", 0775, true);
        copy(lang_path("{$locale}/modules.php"), "{$this->base}/lang/{$locale}/modules.php");
    }
});

afterEach(function (): void {
    makeModuleDelete($this->base);
});

function makeModuleDelete(string $path): void
{
    if (is_dir($path) && ! is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                makeModuleDelete("{$path}/{$entry}");
            }
        }
        rmdir($path);

        return;
    }

    unlink($path);
}

/** Un generador sobre el árbol temporal, con los códigos y alias reales de la aplicación. */
function makeModuleScaffolder(string $base): ModuleScaffolder
{
    return new ModuleScaffolder(
        basePath: $base,
        stubsPath: base_path('stubs/module'),
        declaredCodes: ['core', 'auth', 'curso', 'acad'],
        morphAliases: array_keys(Relation::morphMap()),
        now: CarbonImmutable::parse('2026-10-17'),
    );
}

function makeModuleSpec(array $override = []): ModuleSpec
{
    $values = $override + [
        'name' => 'Matricula',
        'code' => 'matricula',
        'names' => ['es' => 'Matrícula', 'en' => 'Enrolment', 'de' => 'Einschreibung', 'fr' => 'Inscription'],
        'entity' => null,
        'auditPolicy' => null,
    ];

    return new ModuleSpec($values['name'], $values['code'], $values['names'], $values['entity'], $values['auditPolicy']);
}

/** @return array<string, string> ruta relativa => hash de contenido, de todo lo que hay bajo el árbol */
function makeModuleSnapshot(string $base): array
{
    $snapshot = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($base) + 1);
        $snapshot[$relative] = $item->isDir() ? 'dir' : md5((string) file_get_contents($item->getPathname()));
    }

    ksort($snapshot);

    return $snapshot;
}

dataset('make-module invalid inputs', [
    'nombre no StudlyCase' => [['name' => 'matricula'], 'name_invalid'],
    'código con mayúsculas' => [['code' => 'Matricula'], 'code_invalid'],
    'código que empieza por dígito' => [['code' => '1matricula'], 'code_invalid'],
    'código ya declarado por otro módulo' => [['code' => 'acad'], 'code_taken'],
    'falta el nombre en francés' => [['names' => ['es' => 'a', 'en' => 'b', 'de' => 'c', 'fr' => '']], 'option_missing'],
    'falta el nombre en español' => [['names' => ['en' => 'b', 'de' => 'c', 'fr' => 'd']], 'option_missing'],
    'falta el código' => [['code' => null], 'option_missing'],
    'entidad no StudlyCase' => [['entity' => 'cosa', 'auditPolicy' => 'full'], 'entity_invalid'],
    'entidad sin política de auditoría' => [['entity' => 'Cosa'], 'audit_policy_required'],
    'política de auditoría desconocida' => [['entity' => 'Cosa', 'auditPolicy' => 'todo'], 'audit_policy_invalid'],
    'política de auditoría sin entidad' => [['auditPolicy' => 'full'], 'audit_policy_without_entity'],
    'alias de morph map ya usado' => [['entity' => 'Course', 'auditPolicy' => 'full'], 'morph_alias_taken'],
]);

test('CA-056-30 ADR-056: una entrada inválida falla con su error y no toca ningún fichero', function (array $override, string $expectedKey): void {
    $before = makeModuleSnapshot($this->base);
    $scaffolder = makeModuleScaffolder($this->base);

    try {
        $scaffolder->plan(makeModuleSpec($override));
        $thrown = null;
    } catch (ModuleScaffoldException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(ModuleScaffoldException::class)
        ->and(array_column($thrown->errors, 0))->toContain($expectedKey)
        ->and(makeModuleSnapshot($this->base))->toBe($before);
})->with('make-module invalid inputs');

test('CA-056-30 ADR-056: si el módulo ya existe, o su fichero de idioma, falla sin tocar nada', function (): void {
    mkdir("{$this->base}/app/Modules/Matricula", 0775, true);
    file_put_contents("{$this->base}/lang/es/ajeno.php", "<?php\n\nreturn [];\n");
    $before = makeModuleSnapshot($this->base);
    $scaffolder = makeModuleScaffolder($this->base);

    $keysFor = function (ModuleSpec $spec) use ($scaffolder): array {
        try {
            $scaffolder->plan($spec);
        } catch (ModuleScaffoldException $e) {
            return array_column($e->errors, 0);
        }

        return [];
    };

    expect($keysFor(makeModuleSpec()))->toContain('module_exists')
        ->and($keysFor(makeModuleSpec(['name' => 'Otro', 'code' => 'ajeno'])))->toContain('lang_file_exists')
        ->and(makeModuleSnapshot($this->base))->toBe($before);
});

test('CA-056-33 ADR-035: --entity sin --audit-policy falla y el mensaje cita ADR-035', function (): void {
    $this->artisan('make:module', [
        'name' => 'ZzSinPolitica',
        '--code' => 'zz_sin_politica',
        '--name-es' => 'a',
        '--name-en' => 'b',
        '--name-de' => 'c',
        '--name-fr' => 'd',
        '--entity' => 'Cosa',
    ])->expectsOutputToContain('ADR-035')->assertExitCode(1);

    // Falló antes de escribir: ni el módulo ni su fichero de idioma existen.
    expect(is_dir(app_path('Modules/ZzSinPolitica')))->toBeFalse()
        ->and(is_file(lang_path('es/zz_sin_politica.php')))->toBeFalse();
});

test('CA-056-31 ADR-056: sin --entity genera las piezas 1-5 de §3.5 y ningún directorio vacío más', function (): void {
    $scaffolder = makeModuleScaffolder($this->base);
    $plan = $scaffolder->plan(makeModuleSpec());

    expect(array_keys($plan->creates))->toBe([
        'app/Modules/Matricula/Database/migrations/.gitkeep',
        'app/Modules/Matricula/Http/routes.php',
        'app/Modules/Matricula/Infrastructure/MatriculaServiceProvider.php',
        'lang/de/matricula.php',
        'lang/en/matricula.php',
        'lang/es/matricula.php',
        'lang/fr/matricula.php',
        'tests/Feature/Matricula/ModuleRegistrationTest.php',
    ]);

    $scaffolder->apply($plan);

    // Cada directorio creado contiene al menos un fichero o un subdirectorio.
    foreach (makeModuleSnapshot($this->base) as $relative => $kind) {
        if ($kind === 'dir') {
            expect(count(scandir("{$this->base}/{$relative}") ?: []))->toBeGreaterThan(2, "directorio vacío: {$relative}");
        }
    }
});

test('CA-056-31 ADR-056: con --entity añade migración, modelo, factoría y test de aislamiento, y no deja .gitkeep ni Http/Controllers', function (): void {
    $plan = makeModuleScaffolder($this->base)->plan(makeModuleSpec(['entity' => 'Aula', 'auditPolicy' => 'selective']));

    expect(array_keys($plan->creates))->toContain(
        'app/Modules/Matricula/Database/migrations/2026_10_17_100100_create_aulas_table.php',
        'app/Modules/Matricula/Domain/Models/Aula.php',
        'database/factories/Matricula/AulaFactory.php',
        'tests/Feature/Matricula/AulaTenantIsolationTest.php',
    )->not->toContain('app/Modules/Matricula/Database/migrations/.gitkeep');

    foreach (array_keys($plan->creates) as $path) {
        expect($path)->not->toContain('/Controllers/')->not->toContain('/Application/');
    }
});

test('CA-056-31 ADR-056: todo PHP generado, con y sin --entity, es sintácticamente válido', function (): void {
    foreach ([[], ['entity' => 'Aula', 'auditPolicy' => 'redacted']] as $override) {
        $plan = makeModuleScaffolder($this->base)->plan(makeModuleSpec($override));

        foreach ($plan->creates + $plan->edits as $path => $content) {
            if (str_ends_with($path, '.php')) {
                // TOKEN_PARSE lanza ParseError ante un error de sintaxis.
                expect(token_get_all($content, TOKEN_PARSE))->not->toBeEmpty($path);
            }
        }
    }
});

test('CA-056-32 ADR-056: las dos ediciones compartidas son idempotentes: repetirlas no duplica líneas', function (): void {
    $scaffolder = makeModuleScaffolder($this->base);
    $spec = makeModuleSpec();

    $scaffolder->apply($scaffolder->plan($spec));

    // Se descarta el módulo pero quedan las dos ediciones compartidas.
    makeModuleDelete("{$this->base}/app/Modules/Matricula");
    foreach (ModuleSpec::LOCALES as $locale) {
        unlink("{$this->base}/lang/{$locale}/matricula.php");
    }

    $again = $scaffolder->plan($spec);
    $scaffolder->apply($again);

    expect($again->edits)->toBe([])
        ->and(substr_count((string) file_get_contents("{$this->base}/routes/api-v1.php"), 'app/Modules/Matricula/Http/routes.php'))->toBe(1);

    foreach (ModuleSpec::LOCALES as $locale) {
        expect(substr_count((string) file_get_contents("{$this->base}/lang/{$locale}/modules.php"), "'matricula' =>"))->toBe(1);
    }
});
