<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\User;
use App\Modules\Core\Domain\BulkUserImporter;
use App\Modules\Core\Domain\Models\UserImport;
use App\Modules\Core\Infrastructure\Jobs\ValidateUserImport;
use App\Support\Api\ApiException;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * api.md §7, `POST /user-imports`. `RSEC-OWASP-012`: tipo real por
 * contenido, tamaño máximo. El fichero fuente se guarda tal cual (no se
 * sanea contenido de CSV: la validación fila a fila la hace
 * `ValidateUserImport` en cola, `INV-012`).
 */
final class EloquentBulkUserImporter implements BulkUserImporter
{
    /**
     * api.md §7: "≤ 10 MB". No es un ajuste de entorno (a diferencia del
     * límite de filas, `core.import_max_rows`): es una cota fija del
     * propio endpoint.
     */
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    /**
     * @var list<string>
     */
    private const ALLOWED_MIME_TYPES = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function import(UploadedFile $file, bool $sendInvitations, User $requestedBy): UserImport
    {
        $this->assertValid($file);

        $tenant = Tenant::query()->findOrFail($this->tenantContext->tenantId());

        $import = UserImport::create([
            'original_filename' => $file->getClientOriginalName(),
            'status' => 'subido',
            'send_invitations' => $sendInvitations,
        ]);

        $objectKey = "tenants/{$tenant->public_id}/imports/{$import->public_id}/source.csv";
        $content = file_get_contents($file->getRealPath());
        Storage::disk(config('filesystems.default'))->put($objectKey, $content !== false ? $content : '');

        $import->update(['source_object_key' => $objectKey]);

        ValidateUserImport::dispatch($import->id, $tenant->public_id ?? '');

        return $import->fresh();
    }

    private function assertValid(UploadedFile $file): void
    {
        if ($file->getSize() !== false && $file->getSize() > self::MAX_SIZE_BYTES) {
            throw ApiException::payloadTooLarge();
        }

        $path = $file->getRealPath();
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $path !== false ? $finfo->file($path) : false;

        if ($realMime === false || ! in_array($realMime, self::ALLOWED_MIME_TYPES, true)) {
            throw ApiException::unsupportedMediaType();
        }
    }
}
