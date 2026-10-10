<?php

namespace App\Modules\Core\Infrastructure\Jobs;

use App\Http\Middleware\ResolveApiLocale;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Application\UserImportCsvReader;
use App\Modules\Core\Application\UserImportRowValidator;
use App\Modules\Core\Domain\Models\UserImport;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Support\Csv\CsvColumnType;
use App\Support\Csv\CsvWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * funcional.md §4.4 fase 1, operacion.md §4: cola `core-imports`, **1**
 * reintento (un fallo de validación es determinista). Nunca escribe una
 * fila de negocio (`RN-CORE-20`): solo lee el fichero fuente y produce el
 * informe de errores.
 */
class ValidateUserImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $userImportId,
        public readonly string $tenantPublicId,
    ) {
        $this->onQueue('core-imports');
    }

    public function handle(UserImportCsvReader $reader, UserImportRowValidator $rowValidator, TenantSettingsReader $settings): void
    {
        $import = UserImport::query()->find($this->userImportId);

        if ($import === null) {
            return;
        }

        // #285 / OPEN-CORE-38 = A (S9, RN-CORE-34): `message` y el informe se
        // escriben en el idioma de quien subió el lote, no en el del proceso
        // del trabajo. Se restaura al terminar: con la cola `sync` el
        // proceso es el de la petición.
        $previousLocale = App::getLocale();
        App::setLocale($this->resolveLocale($import, $settings));

        try {
            $this->validate($import, $reader, $rowValidator);
        } finally {
            App::setLocale($previousLocale);
        }
    }

    /**
     * Misma precedencia que RN-CORE-34: `person.locale` de quien subió el
     * lote si está entre los idiomas activos del centro; si no, el idioma
     * por defecto del centro. Sin esquema nuevo (`user_imports.created_by`).
     */
    private function resolveLocale(UserImport $import, TenantSettingsReader $settings): string
    {
        $uploader = $import->created_by !== null ? User::query()->with('person')->find($import->created_by) : null;
        $locale = $uploader?->person?->locale;

        $resolved = is_string($locale) && in_array($locale, $settings->activeLocales(), true)
            ? $locale
            : $settings->defaultLocale();

        return ResolveApiLocale::TO_LARAVEL_LOCALE[$resolved] ?? 'es';
    }

    private function validate(UserImport $import, UserImportCsvReader $reader, UserImportRowValidator $rowValidator): void
    {

        $import->update(['status' => 'validando']);

        $content = Storage::disk(config('filesystems.default'))->get($import->source_object_key);
        $parsed = $reader->parse((string) $content);

        if (! $parsed['header_valid']) {
            $import->update([
                'status' => 'fallido',
                'row_count' => 0,
                'error_count' => 1,
                'error_summary' => [[
                    'line' => 1,
                    'column' => 'header',
                    'code' => 'cabecera_desconocida',
                    'message' => __('core.validation.import_unknown_header'),
                ]],
                'validated_at' => now(),
            ]);

            return;
        }

        if ($parsed['too_many_rows']) {
            $import->update([
                'status' => 'fallido',
                'row_count' => 0,
                'error_count' => 1,
                'error_summary' => [[
                    'line' => 1,
                    'column' => 'file',
                    'code' => 'limite_filas_superado',
                    'message' => __('core.import.limite_filas_superado', ['max' => config('core.import_max_rows')]),
                ]],
                'validated_at' => now(),
            ]);

            return;
        }

        $actor = $import->created_by !== null ? User::query()->find($import->created_by) : null;

        // INV-002 (issue #339): sin quien subió el lote no se puede comprobar RPERM-013
        // sobre los roles de cada fila, así que no se valida con comprobaciones de menos.
        // Mismo criterio que `ExecuteUserImport`.
        if ($actor === null) {
            $import->update(['status' => 'fallido', 'validated_at' => now()]);

            return;
        }

        $rolesByCode = Role::query()->get()->keyBy('code');
        $seenEmails = [];
        $seenDocuments = [];
        $allErrors = [];

        foreach ($parsed['rows'] as $row) {
            $result = $rowValidator->validate($row['data'], $row['line'], $seenEmails, $seenDocuments, $rolesByCode, $actor);

            foreach ($result['errors'] as $error) {
                $allErrors[] = $error;
            }
        }

        $reportKey = "tenants/{$this->tenantPublicId}/imports/{$import->public_id}/report.csv";
        $this->writeReport($reportKey, $allErrors);

        $import->update([
            'status' => 'validado',
            'row_count' => count($parsed['rows']),
            'error_count' => count(array_unique(array_column($allErrors, 'line'))),
            'error_summary' => array_slice($allErrors, 0, 50),
            'report_object_key' => $reportKey,
            'validated_at' => now(),
        ]);
    }

    /**
     * @param  list<array{line: int, column: string, code: string, message: string}>  $errors
     */
    private function writeReport(string $key, array $errors): void
    {
        $csv = new CsvWriter([
            'line' => CsvColumnType::Integer,
            'column' => CsvColumnType::Text,
            'code' => CsvColumnType::Text,
            'message' => CsvColumnType::Text,
        ]);

        foreach ($errors as $error) {
            $csv->writeRow([$error['line'], $error['column'], $error['code'], $error['message']]);
        }

        Storage::disk(config('filesystems.default'))->put($key, $csv->finish());
    }

    public function failed(Throwable $exception): void
    {
        UserImport::query()->find($this->userImportId)?->update(['status' => 'fallido']);
    }
}
