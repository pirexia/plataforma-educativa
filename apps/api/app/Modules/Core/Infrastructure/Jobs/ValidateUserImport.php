<?php

namespace App\Modules\Core\Infrastructure\Jobs;

use App\Models\Role;
use App\Modules\Core\Application\UserImportCsvReader;
use App\Modules\Core\Application\UserImportRowValidator;
use App\Modules\Core\Domain\Models\UserImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    public function handle(UserImportCsvReader $reader, UserImportRowValidator $rowValidator): void
    {
        $import = UserImport::query()->find($this->userImportId);

        if ($import === null) {
            return;
        }

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

        $rolesByCode = Role::query()->get()->keyBy('code');
        $seenEmails = [];
        $seenDocuments = [];
        $allErrors = [];

        foreach ($parsed['rows'] as $row) {
            $result = $rowValidator->validate($row['data'], $row['line'], $seenEmails, $seenDocuments, $rolesByCode);

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
        $handle = fopen('php://temp', 'w+');

        if ($handle === false) {
            return;
        }

        fputcsv($handle, ['line', 'column', 'code', 'message']);

        foreach ($errors as $error) {
            fputcsv($handle, [$error['line'], $error['column'], $error['code'], $error['message']]);
        }

        rewind($handle);
        Storage::disk(config('filesystems.default'))->put($key, stream_get_contents($handle) ?: '');
        fclose($handle);
    }

    public function failed(Throwable $exception): void
    {
        UserImport::query()->find($this->userImportId)?->update(['status' => 'fallido']);
    }
}
