<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Modules\Acad\Application\GroupSubjectsService;
use App\Modules\Acad\Http\Requests\ReplaceGroupSubjectsRequest;
use App\Modules\Acad\Http\Resources\SubjectResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-ACAD/api.md §1.6, §2 (asignaturas de un grupo): `{"data": [Subject…]}`
 * sin paginar. La fila de `group_subjects` no se expone.
 */
class GroupSubjectsController extends StructureController
{
    public function index(Request $request, string $groupPublicId, GroupSubjectsService $service): JsonResponse
    {
        $subjects = $service->list($groupPublicId, $this->actor($request));

        return response()->json(['data' => SubjectResource::collection($subjects->load('course'))->resolve()]);
    }

    public function update(ReplaceGroupSubjectsRequest $request, string $groupPublicId, GroupSubjectsService $service): JsonResponse
    {
        $subjects = $service->replace($groupPublicId, $request->subjects(), $this->actor($request));

        return response()->json(['data' => SubjectResource::collection($subjects->load('course'))->resolve()]);
    }
}
