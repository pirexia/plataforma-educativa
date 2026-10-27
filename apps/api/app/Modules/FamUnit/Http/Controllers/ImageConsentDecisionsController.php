<?php

namespace App\Modules\FamUnit\Http\Controllers;

use App\Modules\FamUnit\Application\ConsentRateLimit;
use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Application\FamilyUnitStudentService;
use App\Modules\FamUnit\Application\ImageConsentService;
use App\Modules\FamUnit\Domain\Models\ImageConsentDecision;
use App\Modules\FamUnit\Http\Requests\IndexImageConsentDecisionsRequest;
use App\Modules\FamUnit\Http\Requests\StoreImageConsentDecisionRequest;
use App\Modules\FamUnit\Http\Resources\ImageConsentDecisionResource;
use App\Modules\FamUnit\Http\Resources\ImageConsentResource;
use Illuminate\Http\JsonResponse;

/**
 * REQ-FAM-UNIT/api.md §2.7 (1.14-b). Las decisiones de imagen son inmutables (`RN-FAM-UNIT-42`): no hay `PATCH` ni
 * `DELETE`. El histórico de un alumno va por cursor (`ADR-038 §4.4`); el alta responde con la decisión y el estado
 * resultante, calculado tras la escritura.
 */
class ImageConsentDecisionsController extends FamUnitController
{
    public function index(IndexImageConsentDecisionsRequest $request, ImageConsentService $service, FamilyRefs $refs): JsonResponse
    {
        $result = $service->history(
            $this->decision($request),
            $this->actor($request),
            $request->studentPerson(),
            $request->level(),
            $request->cursorValue(),
            $request->integer('limit', 50),
        );

        $this->preload($refs, $result['rows']);

        return response()->json([
            'data' => ImageConsentDecisionResource::collection($result['rows'])->resolve(),
            'meta' => ['next_cursor' => $result['next_cursor'], 'has_more' => $result['has_more']],
        ]);
    }

    public function store(StoreImageConsentDecisionRequest $request, ImageConsentService $service, FamilyUnitStudentService $students, FamilyRefs $refs, ConsentRateLimit $rateLimit): JsonResponse
    {
        $rateLimit->hit('decision', $this->actor($request));

        $result = $service->record(
            $request->payload(),
            $this->actor($request),
            $this->decision($request),
            ['ip' => $request->ip(), 'user_agent' => $request->userAgent()],
        );

        $this->preload($refs, [$result['decision']]);
        $refs->preloadPersons([$result['state']['student_person_id']]);
        $unit = $students->unitOfStudent($result['state']['student_person_id']);

        return response()->json([
            'decision' => (new ImageConsentDecisionResource($result['decision']))->resolve($request),
            'state' => (new ImageConsentResource($result['state'] + ['family_unit' => $unit['public_id'] ?? null]))->resolve($request),
        ], $result['created'] ? 201 : 200);
    }

    /**
     * @param  list<ImageConsentDecision>  $rows
     */
    private function preload(FamilyRefs $refs, array $rows): void
    {
        $refs->preloadPersons(array_map(static fn ($row): int => (int) $row->student_person_id, $rows));
        $refs->preloadGuardians(array_map(static fn ($row): int => (int) $row->guardian_id, $rows));
        $refs->preloadActors(array_map(static fn ($row): int => (int) $row->recorded_by, $rows));
    }
}
