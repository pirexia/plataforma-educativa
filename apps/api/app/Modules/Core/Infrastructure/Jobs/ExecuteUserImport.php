<?php

namespace App\Modules\Core\Infrastructure\Jobs;

use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Application\CreateUser;
use App\Modules\Core\Application\UserImportCsvReader;
use App\Modules\Core\Application\UserImportRowValidator;
use App\Modules\Core\Domain\Events\UserImportCompleted;
use App\Modules\Core\Domain\Models\UserImport;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditActor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * funcional.md §4.4 fase 2, operacion.md §4: cola `core-imports`, 3
 * reintentos. Revalida cada fila (§6: dos ejecuciones con claves de
 * idempotencia distintas no deben duplicar) y crea cada usuario en su
 * propia transacción (`CreateUser` ya envuelve la suya): un fallo aislado
 * no tumba el lote.
 */
class ExecuteUserImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $userImportId,
        public readonly string $tenantPublicId,
    ) {
        $this->onQueue('core-imports');
    }

    public function handle(UserImportCsvReader $reader, UserImportRowValidator $rowValidator, CreateUser $createUser): void
    {
        $import = UserImport::query()->find($this->userImportId);

        if ($import === null) {
            return;
        }

        $actor = $import->created_by !== null ? User::query()->find($import->created_by) : null;

        if ($actor === null) {
            $import->update(['status' => 'fallido']);
            event(new UserImportCompleted($import->tenant_id, $import->public_id, 0));

            return;
        }

        // RecordsAuthorship (created_by/updated_by de cada Person/User
        // creado) lee Auth::id(): fijarlo aquí, dentro del job, es lo que
        // hace que el rastro de autoría apunte a quien pidió la
        // importación en vez de quedarse en null.
        Auth::setUser($actor);

        $content = Storage::disk(config('filesystems.default'))->get($import->source_object_key);
        $parsed = $reader->parse((string) $content);

        $rolesByCode = Role::query()->get()->keyBy('code');
        $seenEmails = [];
        $seenDocuments = [];
        $createdCount = 0;

        AuditActor::actingAs('import', function () use (
            $parsed, $rowValidator, &$seenEmails, &$seenDocuments, $rolesByCode, $createUser, $import, $actor, &$createdCount,
        ): void {
            foreach ($parsed['rows'] as $row) {
                $result = $rowValidator->validate($row['data'], $row['line'], $seenEmails, $seenDocuments, $rolesByCode);

                if ($result['errors'] !== []) {
                    continue;
                }

                $data = $row['data'];
                $roleIds = $rolesByCode->only($result['roles'])->pluck('public_id')->values()->all();

                try {
                    $createUser->create([
                        'email' => strtolower((string) $data['email']),
                        'person' => [
                            'given_name' => $data['given_name'],
                            'family_name_1' => $data['family_name_1'],
                            'family_name_2' => $data['family_name_2'],
                            'birth_date' => $data['birth_date'],
                            'document_type' => $data['document_type'],
                            'document_number' => $data['document_number'],
                            'contact_email' => $data['contact_email'],
                            'contact_phone' => $data['contact_phone'],
                            'locale' => $data['locale'],
                        ],
                        'role_ids' => $roleIds,
                        'send_invitation' => (bool) $import->send_invitations,
                    ], $actor);

                    $createdCount++;
                } catch (ApiException) {
                    // funcional.md §4.4 paso 7: un fallo aislado (p. ej. una
                    // fila que ya se creó en un reintento anterior) no
                    // tumba el lote — se cuenta como no creada y sigue.
                }
            }
        });

        $import->update([
            'status' => 'completado',
            'created_count' => $createdCount,
            'executed_at' => now(),
        ]);

        event(new UserImportCompleted($import->tenant_id, $import->public_id, $createdCount));
    }

    public function failed(Throwable $exception): void
    {
        UserImport::query()->find($this->userImportId)?->update(['status' => 'fallido']);
    }
}
