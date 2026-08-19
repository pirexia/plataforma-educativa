<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Domain\Models\DataExport;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * api.md §8. Primitiva compartida (funcional.md §7): estado y descarga
 * de cualquier exportación, no solo la de auditoría. Solo el
 * solicitante puede descargarla, además del permiso del recurso
 * exportado (comprobado por el middleware de la ruta según `kind`; en
 * 1.1 el único `kind` es `audit_logs`, con `auditoria.exportar`).
 */
class DataExportsController extends Controller
{
    public function show(string $publicId): JsonResponse
    {
        $export = DataExport::where('public_id', $publicId)->firstOrFail();
        $actor = Auth::user();

        if (! $actor instanceof User || $export->requested_by !== $actor->id) {
            throw ApiException::forbidden();
        }

        if ($export->status === 'fallida') {
            throw ApiException::conflict('core.validation.export_failed');
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
