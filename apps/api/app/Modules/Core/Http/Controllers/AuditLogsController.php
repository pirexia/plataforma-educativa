<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Domain\AuditQuery;
use App\Modules\Core\Domain\ExportRequestService;
use App\Modules\Core\Http\Requests\IndexAuditLogsRequest;
use App\Modules\Core\Http\Resources\AuditLogResource;
use App\Support\Api\ApiException;
use App\Support\Authorization\PermissionDecision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * api.md §8 (`REQ-CORE-005`). Paginación por cursor, no por página
 * (ADR-038 §4.2: `audit_logs` es un flujo de eventos append-only).
 *
 * REQ-PERM/funcional.md §6, §7.2-§7.3 (1.5): el resolutor real de este
 * paso. `RequirePermission` deja la `PermissionDecision` de
 * `auditoria.leer`/`.exportar` en los atributos de la petición
 * (`permission_decision`) — el listado y la exportación se acotan con
 * ella, nunca con un filtro construido a mano.
 */
class AuditLogsController extends Controller
{
    public function index(IndexAuditLogsRequest $request, AuditQuery $auditQuery): JsonResponse
    {
        $actor = $this->actor($request);
        $decision = $this->decision($request);

        $filters = array_filter([
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'actor_id' => $request->input('actor_id'),
            'actor_type' => $request->input('actor_type'),
            'event' => $request->filled('event') ? explode(',', (string) $request->string('event')) : null,
            'auditable_type' => $request->filled('auditable_type') ? explode(',', (string) $request->string('auditable_type')) : null,
            'auditable_id' => $request->input('auditable_id'),
            'module' => $request->input('module'),
        ], fn ($value) => $value !== null);

        // funcional.md §6.1, RN-PERM-14 (CA-PERM-011): auditable_id es el
        // "detalle implícito" de este listado — si el ámbito del sujeto no
        // alcanza ninguna entrada de esa entidad (que sí existe), 404,
        // nunca una lista vacía con 200 ni 403.
        if (isset($filters['auditable_id']) && ! $auditQuery->isAuditableVisible($filters['auditable_id'], $decision, $actor)) {
            throw ApiException::notFound();
        }

        $result = $auditQuery->search($filters, $request->input('cursor'), $request->integer('limit', 50), $decision, $actor);

        return response()->json([
            'data' => AuditLogResource::collection($result['logs'])->resolve(),
            'meta' => [
                'next_cursor' => $result['next_cursor'],
                'has_more' => $result['has_more'],
            ],
        ]);
    }

    /**
     * api.md §8, `POST /audit-logs/exports`. Mismos filtros de §4.5 más
     * `format`. En 1.1 solo `csv` (`pdf` diferido a 1.17).
     */
    public function storeExport(Request $request, ExportRequestService $exports): JsonResponse
    {
        $request->validate([
            'format' => ['required', 'in:csv,pdf'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'event' => ['sometimes', 'array'],
            'auditable_type' => ['sometimes', 'array'],
        ]);

        if ($request->input('format') === 'pdf') {
            throw ApiException::validation([
                'format' => [[
                    'code' => 'core.validation.pdf_export_not_available',
                    'message' => __('core.validation.pdf_export_not_available'),
                    'params' => [],
                ]],
            ]);
        }

        $actor = $this->actor($request);

        $export = $exports->request('audit_logs', 'csv', $request->only(['from', 'to', 'event', 'auditable_type']), $actor);

        return response()->json(['public_id' => $export->public_id, 'status' => $export->status], 202);
    }

    private function actor(Request $request): User
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            throw ApiException::unauthenticated();
        }

        return $actor;
    }

    private function decision(Request $request): ?PermissionDecision
    {
        $decision = $request->attributes->get('permission_decision');

        return $decision instanceof PermissionDecision ? $decision : null;
    }
}
