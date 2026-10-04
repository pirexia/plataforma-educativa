<?php

namespace App\Modules\Core\Infrastructure\Jobs;

use App\Models\User;
use App\Modules\Core\Domain\Models\DataExport;
use App\Modules\Core\Infrastructure\UserListFilter;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\ScopedQuery;
use App\Support\Csv\CsvColumnType;
use App\Support\Csv\CsvWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * api.md §14.1, `funcional.md §14.11.1` (RN-CORE-85, 1.9b). Cola
 * `core-exports`, 3 reintentos, como `GenerateAuditLogExport`. INV-012: la
 * generación nunca ocurre en la petición HTTP. El contexto de tenant lo
 * gestiona TenancyServiceProvider a partir del `tenant_id` estampado al
 * despachar.
 *
 * CSV de datos = contrato técnico estable (ADR-055): cabeceras y valores
 * técnicos, iguales para todos los solicitantes. Por eso este trabajo **no**
 * llama a `__()`/`trans()` ni lee `person.locale` del solicitante
 * (CA-CORE-226). El esquema es cerrado y **no** contiene `document_type`,
 * `document_number` ni `birth_date` (OPEN-CORE-32 = B, INV-008): añadirlos
 * exige decisión expresa del usuario con su base legal.
 *
 * RN-PERM-15: el ámbito del solicitante se vuelve a resolver aquí (un trabajo
 * en cola no tiene sesión). Con el único ámbito admitido (`todos`) no reduce
 * filas, pero la llamada existe para que un ámbito futuro no la necesite
 * añadir.
 */
class GenerateUserExport implements ShouldQueue
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
        $this->onQueue('core-exports');
    }

    public function handle(PermissionResolver $permissions, ScopedQuery $scopedQuery): void
    {
        $export = DataExport::query()->with('requester')->find($this->dataExportId);

        if ($export === null) {
            return;
        }

        // INV-002, denegar por defecto (issue #280): sin solicitante (borrado entre la
        // solicitud y la ejecución) no hay ámbito que aplicar, así que no se exporta nada.
        if ($export->requester === null) {
            $export->update([
                'status' => 'fallida',
                'error_code' => 'core.export.generation_failed',
            ]);

            return;
        }

        $export->update(['status' => 'generando']);

        $query = UserListFilter::apply(
            User::query()->with(['person', 'roles']),
            $export->filters ?? [],
        );

        $decision = $permissions->decide($export->requester, 'usuario.exportar');
        $query = $scopedQuery->constrain($query, 'usuario', $decision, $export->requester);

        // ADR-054 §8.1: orden fijo, independiente del `sort` de la pantalla;
        // `public_id` desempata de forma única.
        $query->join('people', 'people.id', '=', 'users.person_id')
            ->orderBy('people.family_name_1')
            ->orderBy('people.given_name')
            ->orderBy('users.public_id')
            ->select('users.*');

        $objectKey = "tenants/{$this->tenantPublicId}/exports/{$export->public_id}.csv";
        $disk = Storage::disk(config('filesystems.default'));

        $csv = new CsvWriter([
            'public_id' => CsvColumnType::Text,
            'status' => CsvColumnType::Text,
            'deleted_at' => CsvColumnType::Instant,
            'created_at' => CsvColumnType::Instant,
            'email' => CsvColumnType::Text,
            'given_name' => CsvColumnType::Text,
            'family_name_1' => CsvColumnType::Text,
            'family_name_2' => CsvColumnType::Text,
            'contact_email' => CsvColumnType::Text,
            'contact_phone' => CsvColumnType::Text,
            'locale' => CsvColumnType::Text,
            'roles' => CsvColumnType::Text,
        ]);

        $rowCount = 0;

        $query->chunk(1000, function ($chunk) use ($csv, &$rowCount): void {
            foreach ($chunk as $user) {
                $roleCodes = $user->roles->pluck('code')->all();
                sort($roleCodes, SORT_STRING);

                $csv->writeRow([
                    $user->public_id,
                    $user->status->value,
                    $user->deleted_at,
                    $user->created_at,
                    $user->email,
                    $user->person->given_name,
                    $user->person->family_name_1,
                    self::nullIfEmpty($user->person->family_name_2),
                    self::nullIfEmpty($user->person->contact_email),
                    self::nullIfEmpty($user->person->contact_phone),
                    $user->person->locale,
                    $roleCodes === [] ? null : implode('|', $roleCodes),
                ]);
                $rowCount++;
            }
        });

        $disk->put($objectKey, $csv->finish());

        $export->update([
            'status' => 'completada',
            'object_key' => $objectKey,
            'row_count' => $rowCount,
            'completed_at' => now(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        DataExport::query()->find($this->dataExportId)?->update([
            'status' => 'fallida',
            'error_code' => 'core.export.generation_failed',
        ]);
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
