<?php

namespace App\Modules\FamUnit\Infrastructure\Jobs;

use App\Modules\Core\Domain\ExportJobLifecycle;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Application\ImageConsentFilters;
use App\Modules\FamUnit\Application\ImageConsentService;
use App\Support\Authorization\PermissionResolver;
use App\Support\Csv\CsvColumnType;
use App\Support\Csv\CsvWriter;
use App\Support\Modules\ModuleAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * REQ-FAM-UNIT/api.md §4 (1.14-b, `OPEN-FAM-UNIT-11`, `INV-012`): genera el informe de consentimientos de imagen de la
 * solicitud `data_exports` de `kind = image_consents`. La generación nunca ocurre en la petición HTTP. El contexto de
 * tenant lo gestiona `TenancyServiceProvider` a partir del `tenant_id` estampado al despachar. Cola `default` (volumen:
 * dos filas por alumno, miles como mucho; `operacion.md §4`).
 *
 * CSV de datos = contrato técnico estable (`ADR-055`): cabeceras y valores técnicos, iguales para todos los solicitantes,
 * sin literales de interfaz (este trabajo no llama a `__()` ni lee el idioma del solicitante). Esquema cerrado de
 * `api.md §4`, una fila por alumno y nivel; el estado es el **calculado en el momento de generar** (`ADR-066 §5.2`).
 *
 * El permiso se vuelve a comprobar al ejecutar (`INV-002`): un trabajo en cola no tiene sesión y el permiso o el módulo
 * pueden retirarse entre la solicitud y la ejecución; entonces la exportación falla en vez de completarse.
 */
class GenerateImageConsentsExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Filas por lote de lectura de nombres y unidades. */
    private const CHUNK = 500;

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
        ImageConsentService $consents,
        PersonDirectory $people,
    ): void {
        $context = $exports->start($this->dataExportId);

        if ($context === null) {
            return;
        }

        $decision = $permissions->decide($context->requester, 'consentimiento_imagen.exportar');

        if (! $decision->permitted || ! $modules->isEnabled('fam_unit')) {
            $exports->fail($this->dataExportId);

            return;
        }

        /** @var array{family_unit?: list<string>, student_person?: list<string>, level?: list<string>, state?: list<string>} $stored */
        $stored = $context->filters;
        $filters = ImageConsentFilters::resolve($stored, $people);

        $csv = new CsvWriter([
            'family_unit_code' => CsvColumnType::Text,
            'student_person_public_id' => CsvColumnType::Text,
            'student_display_name' => CsvColumnType::Text,
            'level' => CsvColumnType::Text,
            'state' => CsvColumnType::Text,
            'last_decision_at' => CsvColumnType::Instant,
        ]);

        $rowCount = 0;
        $chunk = [];

        foreach ($consents->exportRows($decision, $context->requester, $filters) as $row) {
            $chunk[] = $row;

            if (count($chunk) >= self::CHUNK) {
                $rowCount += $this->writeRows($csv, $chunk, $consents, $people);
                $chunk = [];
            }
        }

        $rowCount += $this->writeRows($csv, $chunk, $consents, $people);

        $objectKey = "tenants/{$this->tenantPublicId}/exports/{$context->publicId}.csv";
        Storage::disk(config('filesystems.default'))->put($objectKey, $csv->finish());

        $exports->complete($this->dataExportId, $objectKey, $rowCount);
    }

    /**
     * Escribe un lote de filas con el nombre y el código de unidad de cada alumno (dos lecturas por lote, no por fila).
     *
     * @param  list<array{student_person_id: int, family_unit_id: ?int, level: string, state: string, last_decision_at: ?CarbonImmutable}>  $rows
     */
    private function writeRows(CsvWriter $csv, array $rows, ImageConsentService $consents, PersonDirectory $people): int
    {
        if ($rows === []) {
            return 0;
        }

        $units = $consents->unitRefs(array_values(array_unique(array_filter(array_column($rows, 'family_unit_id')))));
        $persons = $people->refsByIds(array_values(array_unique(array_column($rows, 'student_person_id'))));

        foreach ($rows as $row) {
            $person = $persons[$row['student_person_id']] ?? null;

            $csv->writeRow([
                $row['family_unit_id'] === null ? null : ($units[$row['family_unit_id']]['code'] ?? null),
                $person?->publicId,
                $person === null || $person->removed ? null : $person->displayName,
                $row['level'],
                $row['state'],
                $row['last_decision_at'],
            ]);
        }

        return count($rows);
    }

    public function failed(\Throwable $exception): void
    {
        app(ExportJobLifecycle::class)->fail($this->dataExportId);
    }
}
