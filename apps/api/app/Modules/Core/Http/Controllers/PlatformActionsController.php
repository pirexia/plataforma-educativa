<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Application\PlatformActionsCursorCodec;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * REQ-BO-007, api.md §2.9. «Consultable por el propio centro en lo que
 * le afecte»: el dato nace en `REQ-BO` (`admin_action_logs`), pero la
 * ruta y el permiso son de `REQ-CORE` (INV-007) — un usuario de tenant
 * no debe conocer ni la existencia del host del backoffice.
 *
 * Corre sobre la conexión `pgsql` del tenant (`plataforma_app`), con la
 * política `tenant_visibility` de `ADR-047 §4.3` filtrando por RLS y el
 * `GRANT SELECT` de seis columnas enumeradas (`datos.md §4.3`) limitando
 * la proyección — nunca `SELECT *` (CA-BO-099). El filtro explícito por
 * `affected_tenant_id` es defensa en profundidad: la RLS ya lo hace.
 */
class PlatformActionsController extends Controller
{
    /**
     * @var list<string>
     */
    private const GRANTED_COLUMNS = ['public_id', 'occurred_at', 'action', 'affected_tenant_id', 'subject_type', 'subject_public_id'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PlatformActionsCursorCodec $cursorCodec,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        $filters = array_filter([
            'action' => $request->input('action'),
        ], fn ($v) => $v !== null);

        $query = DB::connection('pgsql')->table('admin_action_logs')
            ->select(self::GRANTED_COLUMNS)
            ->where('affected_tenant_id', $tenantId);

        if (isset($filters['action'])) {
            $query->whereIn('action', explode(',', (string) $filters['action']));
        }

        $fingerprint = $this->cursorCodec->fingerprint($filters);
        $limit = min($request->integer('limit', 50), 200);

        if ($request->filled('cursor')) {
            [$occurredAt, $publicId] = $this->cursorCodec->decode($request->string('cursor')->value(), $fingerprint, $tenantId);

            $query->where(function ($w) use ($occurredAt, $publicId): void {
                $w->where('occurred_at', '<', $occurredAt)
                    ->orWhere(function ($w2) use ($occurredAt, $publicId): void {
                        $w2->where('occurred_at', $occurredAt)->where('public_id', '<', $publicId);
                    });
            });
        }

        $rows = $query->orderByDesc('occurred_at')->orderByDesc('public_id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $nextCursor = null;

        if ($hasMore && $rows->isNotEmpty()) {
            $last = $rows->last();
            $nextCursor = $this->cursorCodec->encode((string) $last->occurred_at, $last->public_id, $fingerprint, $tenantId);
        }

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'public_id' => $row->public_id,
                'occurred_at' => $row->occurred_at,
                'action' => $row->action,
                'affected_tenant_public_id' => null, // es siempre el propio tenant; no expone el id interno.
                'subject_type' => $row->subject_type,
                'subject_public_id' => $row->subject_public_id,
            ])->all(),
            'meta' => ['next_cursor' => $nextCursor, 'has_more' => $hasMore],
        ]);
    }
}
