<?php

namespace App\Support\Modules\Scaffolding;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ADR-056 §3.5 (paso 1.11b): calcula, valida y escribe el esqueleto de un
 * módulo de la API. Separado del comando para poder ejecutarlo contra un
 * directorio temporal en los tests.
 *
 * Orden: `validate()` (CA-056-30, sin tocar ningún fichero) -> `plan()` (lee
 * los ficheros compartidos y calcula todo el contenido) -> `apply()` (escribe).
 * Nada se escribe si falla cualquiera de los dos primeros.
 */
final class ModuleScaffolder
{
    /** Límite de identificador de PostgreSQL (NAMEDATALEN - 1). */
    private const MAX_IDENTIFIER_LENGTH = 63;

    /** Tablas del framework que ninguna migración del repositorio crea. */
    private const FRAMEWORK_TABLES = ['migrations', 'notifications', 'personal_access_tokens'];

    /**
     * @param  string  $basePath  raíz de la aplicación (`apps/api`)
     * @param  string  $stubsPath  directorio de las plantillas
     * @param  list<string>  $declaredCodes  códigos que ya declara algún módulo (`moduleDescriptor()['code']`)
     * @param  list<string>  $morphAliases  alias que ya tiene el *morph map* (ADR-034 §3)
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $stubsPath,
        private readonly array $declaredCodes,
        private readonly array $morphAliases,
        private readonly ?CarbonImmutable $now = null,
    ) {}

    /**
     * @return list<array{0: string, 1: array<string, string>}> errores como clave de traducción y parámetros
     */
    public function validate(ModuleSpec $spec): array
    {
        $errors = [];

        if (preg_match('/^[A-Z][A-Za-z0-9]*\z/', $spec->name) !== 1 || Str::studly($spec->name) !== $spec->name) {
            $errors[] = ['name_invalid', ['name' => $spec->name]];
        } elseif (is_dir("{$this->basePath}/app/Modules/{$spec->name}")) {
            $errors[] = ['module_exists', ['name' => $spec->name]];
        }

        if ($spec->code === null || $spec->code === '') {
            $errors[] = ['option_missing', ['option' => '--code']];
        } elseif (preg_match('/^[a-z][a-z0-9_]*\z/', $spec->code) !== 1) {
            $errors[] = ['code_invalid', ['code' => $spec->code]];
        } elseif (in_array($spec->code, $this->declaredCodes, true)) {
            $errors[] = ['code_taken', ['code' => $spec->code]];
        } elseif (is_file("{$this->basePath}/lang/es/{$spec->code}.php")) {
            // El código da nombre al fichero de idioma: no se sobrescribe uno ajeno.
            $errors[] = ['lang_file_exists', ['code' => $spec->code]];
        }

        foreach (ModuleSpec::LOCALES as $locale) {
            if (trim($spec->names[$locale] ?? '') === '') {
                $errors[] = ['option_missing', ['option' => "--name-{$locale}"]];
            }
        }

        if ($spec->entity === null) {
            if ($spec->auditPolicy !== null) {
                $errors[] = ['audit_policy_without_entity', []];
            }

            return $this->withPathConflicts($spec, $errors);
        }

        if (preg_match('/^[A-Z][A-Za-z0-9]*\z/', $spec->entity) !== 1 || Str::studly($spec->entity) !== $spec->entity) {
            $errors[] = ['entity_invalid', ['entity' => $spec->entity]];
        } elseif (in_array(Str::snake($spec->entity), $this->morphAliases, true)) {
            $errors[] = ['morph_alias_taken', ['alias' => Str::snake($spec->entity)]];
        } else {
            $table = Str::snake(Str::plural($spec->entity));

            if (strlen($table) > self::MAX_IDENTIFIER_LENGTH) {
                $errors[] = ['table_too_long', ['table' => $table, 'max' => (string) self::MAX_IDENTIFIER_LENGTH]];
            } elseif (in_array($table, $this->existingTables(), true)) {
                $errors[] = ['table_taken', ['table' => $table, 'entity' => $spec->entity]];
            }
        }

        if ($spec->auditPolicy === null) {
            $errors[] = ['audit_policy_required', []];
        } elseif (! in_array($spec->auditPolicy, ModuleSpec::AUDIT_POLICIES, true)) {
            $errors[] = ['audit_policy_invalid', ['policy' => $spec->auditPolicy, 'allowed' => implode('|', ModuleSpec::AUDIT_POLICIES)]];
        }

        return $this->withPathConflicts($spec, $errors);
    }

    /**
     * Si la entrada es válida, calcula las rutas que se crearían y rechaza las
     * que ya existen: el generador nunca sobrescribe nada (CA-056-30).
     *
     * @param  list<array{0: string, 1: array<string, string>}>  $errors
     * @return list<array{0: string, 1: array<string, string>}>
     */
    private function withPathConflicts(ModuleSpec $spec, array $errors): array
    {
        if ($errors !== []) {
            return $errors;
        }

        foreach (array_keys($this->buildCreates($spec)) as $relative) {
            if (file_exists("{$this->basePath}/{$relative}")) {
                $errors[] = ['path_exists', ['path' => $relative]];
            }
        }

        return $errors;
    }

    /**
     * Tablas que ya crea alguna migración (módulos y raíz) más las del
     * framework que no pasan por una migración del repositorio.
     *
     * @return list<string>
     */
    private function existingTables(): array
    {
        $tables = self::FRAMEWORK_TABLES;
        $files = array_merge(
            glob("{$this->basePath}/app/Modules/*/Database/migrations/*.php") ?: [],
            glob("{$this->basePath}/database/migrations/*.php") ?: [],
        );

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if ($content === false) {
                continue;
            }

            preg_match_all('/(?:tenantTable\w*|Schema::create|->create)\(\s*[\'"]([a-z0-9_]+)[\'"]/', $content, $calls);
            preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?"?([a-z0-9_]+)/i', $content, $sql);

            foreach ([...$calls[1], ...array_map('strtolower', $sql[1])] as $table) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * @throws ModuleScaffoldException si algo no valida; no se ha escrito nada
     */
    public function plan(ModuleSpec $spec): ScaffoldPlan
    {
        $errors = $this->validate($spec);

        if ($errors !== []) {
            throw new ModuleScaffoldException($errors);
        }

        return new ScaffoldPlan($this->buildCreates($spec), $this->sharedEdits($spec));
    }

    /**
     * @return array<string, string> ruta relativa => contenido
     */
    private function buildCreates(ModuleSpec $spec): array
    {
        /** @var string $code */
        $code = $spec->code;
        $name = $spec->name;
        $module = "app/Modules/{$name}";

        $vars = ['name' => $name, 'code' => $code];
        $creates = [];

        $creates["{$module}/Http/routes.php"] = $this->render('routes', $vars);
        $creates["{$module}/Database/migrations/.gitkeep"] = '';
        $creates["tests/Feature/{$name}/ModuleRegistrationTest.php"] = $this->render('registration-test', $vars);

        foreach (ModuleSpec::LOCALES as $locale) {
            $creates["lang/{$locale}/{$code}.php"] = $this->render('lang', $vars);
        }

        $imports = [
            'App\Support\Modules\DeclaresModuleRegistry',
            'Illuminate\Support\ServiceProvider',
        ];
        $morphMap = '';

        if ($spec->entity !== null) {
            $entity = $spec->entity;
            $policy = (string) $spec->auditPolicy;
            $table = Str::snake(Str::plural($entity));
            $alias = Str::snake($entity);
            $entityVars = $vars + [
                'entity' => $entity,
                'table' => $table,
                'policy' => $policy,
                'policy_case' => ucfirst($policy),
            ];

            // Quitar el .gitkeep: la carpeta ya no está vacía.
            unset($creates["{$module}/Database/migrations/.gitkeep"]);
            $stamp = ($this->now ?? CarbonImmutable::now())->format('Y_m_d');
            $creates["{$module}/Database/migrations/{$stamp}_100100_create_{$table}_table.php"] = $this->render('migration', $entityVars);
            $creates["{$module}/Domain/Models/{$entity}.php"] = $this->render('model', $entityVars);
            $creates["database/factories/{$name}/{$entity}Factory.php"] = $this->render('factory', $entityVars);
            $creates["tests/Feature/{$name}/{$entity}TenantIsolationTest.php"] = $this->render('isolation-test', $entityVars);

            $imports[] = "App\\Modules\\{$name}\\Domain\\Models\\{$entity}";
            $imports[] = 'Illuminate\Database\Eloquent\Relations\Relation';
            $morphMap = "\n        // ADR-034 §3: `audit_logs` guarda el alias, nunca el FQCN.\n"
                ."        Relation::enforceMorphMap([\n"
                ."            '{$alias}' => {$entity}::class,\n"
                ."        ]);\n";
        }

        sort($imports);
        $creates["{$module}/Infrastructure/{$name}ServiceProvider.php"] = $this->render('provider', $vars + [
            'imports' => implode("\n", array_map(static fn (string $i): string => "use {$i};", $imports)),
            'morph_map' => $morphMap,
        ]);

        ksort($creates);

        return $creates;
    }

    /**
     * Escribe el plan. Solo se llama con un plan ya validado.
     */
    public function apply(ScaffoldPlan $plan): void
    {
        $conflicts = [];

        foreach (array_keys($plan->creates) as $relative) {
            if (file_exists("{$this->basePath}/{$relative}")) {
                $conflicts[] = ['path_exists', ['path' => $relative]];
            }
        }

        if ($conflicts !== []) {
            throw new ModuleScaffoldException($conflicts);
        }

        foreach ($plan->creates + $plan->edits as $relative => $content) {
            $path = "{$this->basePath}/{$relative}";
            $dir = dirname($path);

            if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new RuntimeException("No se puede crear {$dir}");
            }

            if (file_put_contents($path, $content) === false) {
                throw new RuntimeException("No se puede escribir {$path}");
            }
        }
    }

    /**
     * Las dos ediciones de ficheros compartidos (§3.5 punto 6). Idempotentes:
     * si la línea o la entrada ya está, el fichero no cambia y no figura.
     *
     * @return array<string, string>
     */
    private function sharedEdits(ModuleSpec $spec): array
    {
        /** @var string $code */
        $code = $spec->code;
        $edits = [];

        $routesFile = 'routes/api-v1.php';
        $routes = $this->readShared($routesFile);
        $require = "require base_path('app/Modules/{$spec->name}/Http/routes.php');";

        if (! str_contains($routes, $require)) {
            $edits[$routesFile] = rtrim($routes, "\n")."\n{$require}\n";
        }

        foreach (ModuleSpec::LOCALES as $locale) {
            $file = "lang/{$locale}/modules.php";
            $content = $this->readShared($file);

            if (preg_match('/^\s*'.preg_quote("'{$code}'", '/').'\s*=>/m', $content) === 1) {
                continue;
            }

            $closing = strrpos($content, '];');

            if ($closing === false) {
                throw new ModuleScaffoldException([['shared_file_unexpected', ['file' => $file]]]);
            }

            $entry = "    '{$code}' => ".var_export(trim($spec->names[$locale] ?? ''), true).",\n";
            $edits[$file] = substr($content, 0, $closing).$entry.substr($content, $closing);
        }

        return $edits;
    }

    private function readShared(string $relative): string
    {
        $path = "{$this->basePath}/{$relative}";
        $content = is_file($path) ? file_get_contents($path) : false;

        if ($content === false) {
            throw new ModuleScaffoldException([['shared_file_unexpected', ['file' => $relative]]]);
        }

        return $content;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function render(string $stub, array $vars): string
    {
        $path = "{$this->stubsPath}/{$stub}.stub";
        $content = is_file($path) ? file_get_contents($path) : false;

        if ($content === false) {
            throw new RuntimeException("Falta la plantilla {$path}");
        }

        $replace = [];

        foreach ($vars as $key => $value) {
            $replace['{{ '.$key.' }}'] = $value;
        }

        return strtr($content, $replace);
    }
}
