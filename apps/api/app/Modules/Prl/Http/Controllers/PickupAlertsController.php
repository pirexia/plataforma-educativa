<?php

namespace App\Modules\Prl\Http\Controllers;

use App\Modules\Prl\Application\AlertGuidance;
use App\Modules\Prl\Application\PickupAlertService;
use App\Modules\Prl\Application\PrlReadGuard;
use App\Modules\Prl\Application\PrlRefs;
use App\Modules\Prl\Domain\Models\PickupAlert;
use App\Modules\Prl\Http\Requests\IndexPickupAlertsRequest;
use App\Modules\Prl\Http\Requests\StorePickupAlertRequest;
use App\Modules\Prl\Http\Requests\UpdatePickupAlertRequest;
use App\Modules\Prl\Http\Resources\PickupAlertResource;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-PRL/api.md §8.4 (1.14b-b, `REQ-PRL-004`, `RN-PRL-08`): alertas por persona no autorizada. Abrir: `alerta_entrega.crear`;
 * consultar: `alerta_entrega.leer`; registrar la llamada al principal y cerrar: `alerta_entrega.actualizar`. Una alerta cerrada
 * no se reabre (`409 prl.alert.closed`) ni se borra (no hay `DELETE`: es evidencia). El alta y el detalle llevan el protocolo
 * y los teléfonos (calculados al leer, nunca guardados); el listado lleva nombres, no teléfonos, y se audita con cupo (`DP-11`, enmienda 2026-11-04). **No envía nada** (`OPEN-FAM-UNIT-33`, hasta `1.19`).
 */
class PickupAlertsController extends PrlController
{
    public function store(StorePickupAlertRequest $request, PickupAlertService $service, AlertGuidance $guidance, PrlRefs $refs, PrlReadGuard $reads): JsonResponse
    {
        // El cupo se consume antes de crear nada: la respuesta lleva teléfonos (`DP-11`).
        $reads->limit();
        $result = $service->raise($request->payload(), $this->actor($request), $request->idempotencyKey());
        $alert = $result['alert'];
        $this->prepare([$alert], $refs);

        $response = $this->detail($alert, $guidance, $reads)
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/pickup-alerts/{$alert->public_id}"));

        return $result['replayed'] ? $response->header('Idempotency-Replayed', 'true') : $response;
    }

    public function index(IndexPickupAlertsRequest $request, PickupAlertService $service, PrlRefs $refs, PrlReadGuard $reads): JsonResponse
    {
        // `DP-11` (enmienda 2026-11-04): el listado devuelve nombres de alumnos y de quien abrió o cerró la alerta; cupo de lectura
        // y fila `read` sin valores (solo con resultados) antes de devolverlo, como el historial de movimientos.
        $reads->limit();
        $page = $service->listing($request->filters(), $request->integer('per_page', 25))->withQueryString();
        $this->prepare($page->items(), $refs);

        if ($page->items() !== []) {
            $first = $page->items()[0];
            $reads->audit('pickup_alert', (int) $first->id, (string) $first->public_id, PrlReadGuard::PURPOSE_ALERT, count($page->items()));
        }

        return PagePaginatedResponse::make($page, PickupAlertResource::class);
    }

    public function show(Request $request, string $publicId, PickupAlertService $service, AlertGuidance $guidance, PrlRefs $refs, PrlReadGuard $reads): PickupAlertResource
    {
        $alert = $service->find($publicId);
        $this->prepare([$alert], $refs);
        if ($alert->status === PickupAlert::STATUS_OPEN) {
            $reads->limit();
        }

        return $this->detail($alert, $guidance, $reads);
    }

    public function update(UpdatePickupAlertRequest $request, string $publicId, PickupAlertService $service, AlertGuidance $guidance, PrlRefs $refs, PrlReadGuard $reads): PickupAlertResource
    {
        $service->find($publicId);
        $alert = $service->update($publicId, $request->payload(), $this->actor($request));
        $this->prepare([$alert], $refs);
        if ($alert->status === PickupAlert::STATUS_OPEN) {
            $reads->limit();
        }

        return $this->detail($alert, $guidance, $reads);
    }

    /**
     * La alerta con el protocolo y los teléfonos de la llamada manual (solo mientras está abierta). Con teléfonos, la fila `read`
     * sin valores se escribe **antes** de devolverlos (`DP-11`); el cupo ya lo consumió el llamador.
     */
    private function detail(PickupAlert $alert, AlertGuidance $guidance, PrlReadGuard $reads): PickupAlertResource
    {
        $open = $alert->status === PickupAlert::STATUS_OPEN;

        if (! $open) {
            return (new PickupAlertResource($alert))->withGuidance(null);
        }

        $contacts = $guidance->contactsFor((int) $alert->student_person_id);
        $reads->audit('pickup_alert', (int) $alert->id, (string) $alert->public_id, PrlReadGuard::PURPOSE_ALERT, count(array_filter($contacts, static fn (?string $phone): bool => $phone !== null)));

        return (new PickupAlertResource($alert))->withGuidance(['protocol' => $guidance->protocol(), 'contacts' => $contacts]);
    }

    /**
     * @param  iterable<PickupAlert>  $rows
     */
    private function prepare(iterable $rows, PrlRefs $refs): void
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);

        $refs->preloadPersons(array_map(static fn (PickupAlert $a): int => (int) $a->student_person_id, $rows));
        $refs->preloadUsers(array_values(array_filter(array_merge(
            array_map(static fn (PickupAlert $a): int => (int) $a->raised_by, $rows),
            array_map(static fn (PickupAlert $a): ?int => $a->resolved_by === null ? null : (int) $a->resolved_by, $rows),
        ), static fn (?int $id): bool => $id !== null)));
    }
}
