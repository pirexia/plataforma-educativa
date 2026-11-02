<?php

namespace App\Modules\Prl\Http\Controllers;

use App\Modules\Prl\Application\PrlRefs;
use App\Modules\Prl\Application\StudentMovementService;
use App\Modules\Prl\Domain\Models\StudentMovement;
use App\Modules\Prl\Http\Requests\IndexStudentMovementsRequest;
use App\Modules\Prl\Http\Requests\StoreStudentMovementRequest;
use App\Modules\Prl\Http\Resources\StudentMovementResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-PRL/api.md §8.4 (1.14b-b, `REQ-PRL-004`): registro y consulta de movimientos. Los movimientos son de solo anexar
 * (`RN-PRL-04`): no hay `PATCH` ni `DELETE`; una corrección es un `POST` que referencia al movimiento corregido. Registrar:
 * `entrega_menor.crear`; consultar: `entrega_menor.leer`. Con una `Idempotency-Key` repetida, la respuesta es la original
 * (`201`, con la cabecera `Idempotency-Replayed`).
 */
class StudentMovementsController extends PrlController
{
    public function store(StoreStudentMovementRequest $request, StudentMovementService $service, PrlRefs $refs): JsonResponse
    {
        $result = $service->record($request->payload(), $this->actor($request), $request->idempotencyKey());
        $movement = $result['movement'];
        $this->prepare([$movement], $service, $refs);

        $response = (new StudentMovementResource($movement))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/student-movements/{$movement->public_id}"));

        return $result['replayed'] ? $response->header('Idempotency-Replayed', 'true') : $response;
    }

    public function index(IndexStudentMovementsRequest $request, StudentMovementService $service, PrlRefs $refs): JsonResponse
    {
        $result = $service->history($request->filters(), $request->cursorValue(), $request->integer('limit', 50));
        $this->prepare($result['rows'], $service, $refs);

        return response()->json([
            'data' => StudentMovementResource::collection($result['rows'])->resolve($request),
            'meta' => ['next_cursor' => $result['next_cursor'], 'has_more' => $result['has_more']],
        ]);
    }

    public function show(Request $request, string $publicId, StudentMovementService $service, PrlRefs $refs): StudentMovementResource
    {
        $movement = $service->find($publicId);
        $this->prepare([$movement], $service, $refs);

        return new StudentMovementResource($movement);
    }

    /**
     * Precarga los nombres y `public_id` que necesita la representación (una consulta por tipo, no por fila).
     *
     * @param  list<StudentMovement>  $rows
     */
    private function prepare(array $rows, StudentMovementService $service, PrlRefs $refs): void
    {
        $refs->preloadPersons(array_values(array_filter(array_merge(
            array_map(static fn (StudentMovement $m): int => (int) $m->student_person_id, $rows),
            array_map(static fn (StudentMovement $m): ?int => $m->picked_up_by_person_id === null ? null : (int) $m->picked_up_by_person_id, $rows),
        ), static fn (?int $id): bool => $id !== null)));
        $refs->preloadUsers(array_merge(
            array_map(static fn (StudentMovement $m): int => (int) $m->delivered_by, $rows),
            array_map(static fn (StudentMovement $m): int => (int) $m->recorded_by, $rows),
        ));
        $refs->setCorrected($service->correctedPublicIds($rows));
    }
}
