<?php

namespace App\Modules\Alum\Infrastructure\Jobs;

use App\Modules\Alum\Application\EnrollmentService;
use App\Modules\Core\Domain\ExportJobLifecycle;
use App\Support\Api\ApiException;
use App\Support\Authorization\PermissionResolver;
use App\Support\Csv\CsvColumnType;
use App\Support\Csv\CsvWriter;
use App\Support\Modules\ModuleAvailability;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * REQ-ALUM/api.md §6 (1.15-b, `ADR-054`, `ADR-055`, `INV-012`): genera el CSV de la solicitud `data_exports` de
 * `kind = alum_enrollments`. La generación nunca ocurre en la petición HTTP. El contexto de tenant lo gestiona
 * `TenancyServiceProvider` a partir del `tenant_id` estampado al despachar. Cola `default` (`operacion.md §4`: conjuntos
 * acotados por centro, ≤ ~3.000 personas, `ADR-005`; no hay trabajo de más de unos segundos que justifique `long`).
 *
 * CSV de datos = contrato técnico estable (`ADR-055`): cabeceras y valores técnicos, iguales para todos los
 * solicitantes, sin literales de interfaz. Mismo ámbito y filtros que `GET /enrollments` (`ADR-054 §8.2`), con la
 * decisión de permiso recalculada **al ejecutar**: un trabajo en cola no tiene sesión y el permiso o el módulo pueden
 * retirarse entre la solicitud y la ejecución; entonces la exportación falla en vez de completarse (`INV-002`). Un curso
 * académico que deja de ser legible para el solicitante también la deja fallida.
 */
class GenerateEnrollmentsExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $dataExportId,
        public readonly string $tenantPublicId,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        ExportJobLifecycle $exports,
        PermissionResolver $permissions,
        ModuleAvailability $modules,
        EnrollmentService $enrollments,
    ): void {
        $context = $exports->start($this->dataExportId);

        if ($context === null) {
            return;
        }

        $decision = $permissions->decide($context->requester, 'matricula.exportar');

        if (! $decision->permitted || ! $modules->isEnabled('alum')) {
            $exports->fail($this->dataExportId);

            return;
        }

        /** @var array{academic_year?: ?string, course?: ?string, group?: ?string, status?: ?string} $filters */
        $filters = $context->filters;

        $csv = new CsvWriter([
            'enrollment_public_id' => CsvColumnType::Text,
            'student_public_id' => CsvColumnType::Text,
            'family_name' => CsvColumnType::Text,
            'given_name' => CsvColumnType::Text,
            'birth_date' => CsvColumnType::Text,
            'academic_year_code' => CsvColumnType::Text,
            'course_code' => CsvColumnType::Text,
            'group_name' => CsvColumnType::Text,
            'status' => CsvColumnType::Text,
            'enrolled_on' => CsvColumnType::Text,
            'withdrawn_on' => CsvColumnType::Text,
            'withdrawal_reason' => CsvColumnType::Text,
        ]);

        $rowCount = 0;

        try {
            foreach ($enrollments->exportBatches($decision, $context->requester, $filters) as $rows) {
                foreach ($rows as $row) {
                    $csv->writeRow($row);
                    $rowCount++;
                }
            }
        } catch (ApiException) {
            $exports->fail($this->dataExportId);

            return;
        }

        $objectKey = "tenants/{$this->tenantPublicId}/exports/{$context->publicId}.csv";
        Storage::disk(config('filesystems.default'))->put($objectKey, $csv->finish());

        $exports->complete($this->dataExportId, $objectKey, $rowCount);
    }

    public function failed(\Throwable $exception): void
    {
        app(ExportJobLifecycle::class)->fail($this->dataExportId);
    }
}
