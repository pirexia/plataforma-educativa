<?php

namespace App\Modules\Core\Domain;

use App\Support\Api\ApiException;
use App\Support\FeatureFlags\FeatureFlagRuleSet;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * `RN-BO-99`, `datos.md §9.6`, api.md §2.11. Lectura y escritura
 * administrativa del catálogo de *flags* y sus reglas — mismo reparto que
 * `ModuleContracting`/`ModuleCatalog` para módulos: sólo `REQ-BO` la
 * consume, la implementa `REQ-CORE` (`EloquentFeatureFlagAdministration`),
 * y es la **única** puerta por la que el backoffice toca
 * `feature_flags`/`feature_flag_rules` — nunca directamente por modelo o
 * por consulta (`CA-BO-169`).
 *
 * Motivo por el que vive aparte de `FeatureFlagEvaluator`/
 * `FeatureFlagExplainer` (§5.11.8.2 sólo describe esas dos): esta interfaz
 * cubre lo que `implementer` tiene que resolver y la especificación no
 * nombra con una firma exacta — el catálogo administrativo y las
 * escrituras—, siguiendo el mismo precedente de separación por consumidor
 * único que ya fija `ModuleContracting` (funcional.md §5.11.8).
 *
 * Todas las operaciones devuelven formas de *array* ya listas para la
 * respuesta HTTP — mismo estilo que `ModuleSubscriptionsService::
 * previewBulk()` — en vez de una jerarquía de objetos de valor propia:
 * el consumidor único es un controlador que serializa a JSON.
 */
interface FeatureFlagAdministration
{
    /**
     * `GET /feature-flags`, api.md §2.11. Catálogo materializado.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(
        ?string $moduleCode,
        ?string $status,
        ?bool $retired,
        ?string $q,
        int $perPage,
        int $page,
    ): LengthAwarePaginator;

    /**
     * `GET /feature-flags/{key}`. El *flag* y todas sus reglas vigentes.
     * `null` si la clave no existe en el catálogo — un `retired_at` **sí**
     * devuelve datos (`RN-BO-44`, `CA-BO-091`).
     *
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array;

    /**
     * `POST /feature-flags/{key}/rules/preview`, `RN-BO-68`: no escribe
     * ni bloquea nada.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException si la clave no existe o el flag está retirado
     */
    public function previewRules(string $key, FeatureFlagRuleSet $proposed): array;

    /**
     * `PUT /feature-flags/{key}/state`. Incrementa `rules_version` en la
     * misma escritura (`RN-BO-42`); las reglas no se tocan.
     *
     * @return array{public_id: string, key: string, status: string, status_reason: ?string, rules_version: int}
     *
     * @throws ApiException si la clave no existe o el flag está retirado
     */
    public function setState(string $key, string $status, string $reason, ?int $actorId): array;

    /**
     * `PUT /feature-flags/{key}/rules`, `RN-BO-104`: reemplazo del
     * conjunto resuelto por diferencia, en una transacción, con **un
     * solo** incremento de `rules_version`.
     *
     * @return array{public_id: string, key: string, rules_version: int, rules: list<array<string, mixed>>, impact: array<string, mixed>, affected_tenant_id: ?int}
     *
     * @throws ApiException si la clave no existe, el flag está retirado, o una regla es incoherente/duplicada
     */
    public function replaceRules(string $key, FeatureFlagRuleSet $proposed, string $reason, ?int $actorId): array;
}
