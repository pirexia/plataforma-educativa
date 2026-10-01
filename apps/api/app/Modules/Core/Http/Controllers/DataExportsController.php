<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Domain\Models\DataExport;
use App\Support\Api\ApiException;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * api.md §8 y §14.2. Primitiva compartida (funcional.md §7): estado y
 * descarga de cualquier exportación, no solo la de auditoría. Solo el
 * solicitante puede descargarla, además del permiso del recurso
 * exportado, resuelto aquí por `kind` (RN-CORE-86, S3).
 */
class DataExportsController extends Controller
{
    /**
     * RN-CORE-86: correspondencia cerrada `kind` → permiso de exportar del
     * recurso. Nunca se deduce del nombre del `kind`; un `kind` que no esté
     * aquí se deniega (RPERM-011) hasta que el paso que lo añada la amplíe.
     *
     * @var array<string, string>
     */
    private const PERMISSION_BY_KIND = [
        'audit_logs' => 'auditoria.exportar',
        'users' => 'usuario.exportar',
    ];

    public function __construct(private readonly PermissionResolver $permissions) {}

    public function show(string $publicId): JsonResponse
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            throw ApiException::unauthenticated();
        }

        // Un public_id de otro tenant no existe para este (RLS): 404, no 403.
        $export = DataExport::where('public_id', $publicId)->firstOrFail();

        $permission = self::PERMISSION_BY_KIND[$export->kind] ?? null;

        if ($permission === null
            || $export->requested_by !== $actor->id
            || ! $this->permissions->can($actor, $permission)) {
            throw ApiException::forbidden();
        }

        // S4, OPEN-CORE-39 = A: una exportación fallida es un estado, no un
        // conflicto — 200 con `status: "fallida"` para que el cliente deje de
        // esperar y muestre el motivo (`error_code`).
        if ($export->status === 'fallida') {
            return response()->json([
                'public_id' => $export->public_id,
                'kind' => $export->kind,
                'status' => $export->status,
                'row_count' => null,
                'download_url' => null,
                'expires_at' => $export->expires_at,
                'error_code' => $export->error_code,
            ]);
        }

        if ($export->status !== 'completada') {
            throw ApiException::conflict('core.validation.export_not_ready');
        }

        if ($export->expires_at->isPast()) {
            throw ApiException::gone();
        }

        $url = Storage::disk(config('filesystems.default'))->temporaryUrl(
            $export->object_key,
            now()->addMinutes((int) config('core.signed_url_ttl_minutes')),
        );

        return response()->json([
            'public_id' => $export->public_id,
            'kind' => $export->kind,
            'status' => $export->status,
            'row_count' => $export->row_count,
            'download_url' => $url,
            'expires_at' => $export->expires_at,
        ]);
    }
}
