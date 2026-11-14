<?php

namespace App\Console\Commands;

use App\Support\Modules\DeclaresModuleRegistry;
use App\Support\Modules\ModuleServiceProviderDiscovery;
use App\Support\Modules\Scaffolding\ModuleScaffolder;
use App\Support\Modules\Scaffolding\ModuleScaffoldException;
use App\Support\Modules\Scaffolding\ModuleSpec;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * ADR-056 §3.5 (paso 1.11b, CA-056-30 a -33): genera el esqueleto de un
 * módulo de la API. Todo mensaje pasa por `make-module.*` de `lang/` (INV-009).
 * No genera la parte web (OPEN-056-07): el contenedor de la API no ve `apps/web`.
 */
class MakeModuleCommand extends Command
{
    protected $signature = 'make:module
        {name : Nombre del módulo en StudlyCase (p. ej. Matricula)}
        {--code= : Código del módulo, [a-z][a-z0-9_]* (p. ej. matricula)}
        {--name-es= : Nombre del módulo en es-ES}
        {--name-en= : Nombre del módulo en en}
        {--name-de= : Nombre del módulo en de}
        {--name-fr= : Nombre del módulo en fr}
        {--entity= : Entidad de tenant a generar (StudlyCase, singular); sin ella solo se genera el esqueleto mínimo}
        {--audit-policy= : Política de auditoría de la entidad, obligatoria con --entity (full|selective|redacted, ADR-035)}';

    protected $description = 'Genera el esqueleto de un módulo de la API (ADR-056 §3.5)';

    public function handle(): int
    {
        $spec = new ModuleSpec(
            name: (string) $this->argument('name'),
            code: $this->stringOption('code'),
            names: [
                'es' => $this->stringOption('name-es'),
                'en' => $this->stringOption('name-en'),
                'de' => $this->stringOption('name-de'),
                'fr' => $this->stringOption('name-fr'),
            ],
            entity: $this->stringOption('entity'),
            auditPolicy: $this->stringOption('audit-policy'),
        );

        $scaffolder = new ModuleScaffolder(
            basePath: base_path(),
            stubsPath: base_path('stubs/module'),
            declaredCodes: $this->declaredCodes(),
            morphAliases: array_keys(Relation::morphMap()),
        );

        try {
            $plan = $scaffolder->plan($spec);
        } catch (ModuleScaffoldException $e) {
            $this->error(__('make-module.failed'));

            foreach ($e->errors as [$key, $params]) {
                $this->line('  - '.__("make-module.errors.{$key}", $params));
            }

            return self::FAILURE;
        }

        $scaffolder->apply($plan);

        $this->info(__('make-module.created', ['name' => $spec->name, 'count' => count($plan->creates)]));

        foreach (array_keys($plan->creates) as $path) {
            $this->line("  + {$path}");
        }

        foreach (array_keys($plan->edits) as $path) {
            $this->line('  ~ '.__('make-module.edited', ['file' => $path]));
        }

        $this->newLine();
        $this->line(__('make-module.next_steps', ['module' => mb_strtolower($spec->name)]));

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }

    /**
     * Códigos que ya declara algún módulo (descriptor de su `ServiceProvider`).
     *
     * @return list<string>
     */
    private function declaredCodes(): array
    {
        $codes = [];

        foreach (ModuleServiceProviderDiscovery::discover(app_path('Modules')) as $provider) {
            $instance = new $provider(app());

            if ($instance instanceof DeclaresModuleRegistry) {
                $codes[] = $instance->moduleDescriptor()['code'];
            }
        }

        return $codes;
    }
}
