<?php

namespace App\Modules\Backoffice\Http\Controllers;

use App\Modules\Backoffice\Application\AdminActionLogRecorder;
use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\Models\PlatformIpAllowlistEntry;
use App\Modules\Backoffice\Http\Requests\StorePlatformIpAllowlistRequest;
use App\Modules\Backoffice\Http\Resources\PlatformIpAllowlistResource;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * REQ-BO-007, api.md §2.3. `DELETE` no comprueba si el solicitante se
 * está dejando fuera a sí mismo, deliberado: una red corporativa tiene
 * varias salidas, y la salida real de un bloqueo total es la consola del
 * servidor (RN-BO-07, operacion.md §5).
 */
class PlatformIpAllowlistController extends Controller
{
    public function __construct(
        private readonly AdminActionLogRecorder $recorder,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = PlatformIpAllowlistEntry::query()
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return PagePaginatedResponse::make($paginator, PlatformIpAllowlistResource::class);
    }

    public function store(StorePlatformIpAllowlistRequest $request): JsonResponse
    {
        $entry = PlatformIpAllowlistEntry::create([
            'cidr' => $request->string('cidr')->value(),
            'description' => $request->string('description')->value(),
            'enabled' => true,
        ]);

        $this->recorder->record(
            action: AdminActionLogAction::IpPermitidaAnadida,
            subjectPublicId: $entry->public_id,
            context: ['cidr' => $entry->cidr, 'description' => $entry->description],
        );

        return response()->json(new PlatformIpAllowlistResource($entry), 201);
    }

    public function destroy(string $publicId): Response
    {
        $entry = PlatformIpAllowlistEntry::query()->where('public_id', $publicId)->first();

        if ($entry === null) {
            throw ApiException::notFound();
        }

        $entry->delete();

        $this->recorder->record(
            action: AdminActionLogAction::IpPermitidaRetirada,
            subjectPublicId: $entry->public_id,
            context: ['cidr' => $entry->cidr],
        );

        return response()->noContent();
    }
}
