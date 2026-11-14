<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Modules\Acad\Application\GroupTutorsService;
use App\Modules\Acad\Http\Requests\ReplaceGroupTutorsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-ACAD/api.md §12.1, §12.2 (tutores de un grupo): `{"data": [{public_id,
 * name}]}` sin paginar. Permisos del recurso `asignacion_docente`.
 */
class GroupTutorsController extends StructureController
{
    public function index(Request $request, string $groupPublicId, GroupTutorsService $service): JsonResponse
    {
        return response()->json(['data' => $service->list($groupPublicId, $this->actor($request))]);
    }

    public function update(ReplaceGroupTutorsRequest $request, string $groupPublicId, GroupTutorsService $service): JsonResponse
    {
        return response()->json(['data' => $service->replace($groupPublicId, $request->tutors(), $this->actor($request))]);
    }
}
