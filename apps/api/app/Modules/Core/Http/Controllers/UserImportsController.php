<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Domain\BulkUserImporter;
use App\Modules\Core\Domain\Models\UserImport;
use App\Modules\Core\Http\Requests\StoreUserImportRequest;
use App\Modules\Core\Http\Resources\UserImportResource;
use App\Modules\Core\Infrastructure\Jobs\ExecuteUserImport;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * api.md §7 (`REQ-CORE-003`). Dos fases (funcional.md §4.4): esta clase
 * cubre subida+consulta+descarte; la ejecución (`execute`) solo cambia el
 * estado y despacha el trabajo — toda la lógica de negocio vive en
 * `BulkUserImporter`/`ExecuteUserImport` (`INV-010` en el servicio, no en
 * el controlador).
 */
class UserImportsController extends Controller
{
    public function __construct(
        private readonly BulkUserImporter $importer,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = UserImport::query()
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return PagePaginatedResponse::make($paginator, UserImportResource::class);
    }

    public function store(StoreUserImportRequest $request): JsonResponse
    {
        $import = $this->importer->import(
            $request->file('file'),
            $request->boolean('send_invitations', true),
            $request->user(),
        );

        return response()->json((new UserImportResource($import))->resolve(), 202);
    }

    public function show(string $publicId): UserImportResource
    {
        return new UserImportResource($this->findOrFail($publicId));
    }

    /**
     * `RequireIdempotencyKey` ya resolvió la cabecera `Idempotency-Key`
     * antes de llegar aquí (ADR-038 §8): este método solo decide si el
     * lote está en condiciones de ejecutarse.
     */
    public function execute(string $publicId): JsonResponse
    {
        $import = $this->findOrFail($publicId);

        if ($import->status !== 'validado') {
            throw ApiException::conflict('core.validation.import_not_validated');
        }

        $tenant = Tenant::query()->findOrFail($this->tenantContext->tenantId());

        $import->update(['status' => 'ejecutando']);

        ExecuteUserImport::dispatch($import->id, $tenant->public_id ?? '');

        return response()->json((new UserImportResource($import))->resolve(), 202);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $import = $this->findOrFail($publicId);

        if (in_array($import->status, ['ejecutando', 'completado'], true)) {
            throw ApiException::conflict('core.validation.import_already_executed');
        }

        $disk = Storage::disk(config('filesystems.default'));

        foreach ([$import->source_object_key, $import->report_object_key] as $objectKey) {
            if ($objectKey !== null) {
                $disk->delete($objectKey);
            }
        }

        $import->delete();

        return response()->json(null, 204);
    }

    private function findOrFail(string $publicId): UserImport
    {
        return UserImport::where('public_id', $publicId)->firstOrFail();
    }
}
