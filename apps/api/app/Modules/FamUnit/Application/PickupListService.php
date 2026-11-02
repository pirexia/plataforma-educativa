<?php

namespace App\Modules\FamUnit\Application;

use App\Models\User;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Modules\FamUnit\Domain\Models\PickupAuthorization;
use App\Modules\FamUnit\Domain\Models\PickupPhoto;
use App\Support\Api\ApiException;
use App\Support\Authorization\PermissionDecision;
use App\Support\Authorization\ScopedQuery;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/funcional.md §21.6 (`RN-FAM-UNIT-78` a `-88`), datos.md §8, 1.14b-a, `ADR-068`: **único servicio
 * sancionado por `AR-10` para consultar `PickupAuthorization` y `PickupPhoto`** (recurso `autorizado_recogida`, ámbito
 * `unidad_familiar`; patrón de `ImageConsentService`). Todo el acceso a las dos tablas vive aquí: autorizaciones,
 * fotografías, la composición de la lista de un alumno y la vigencia calculada. Los demás servicios del módulo
 * (`PickupAuthorizationService`, `PickupPhotoService`, `PickupPersonService`, `EloquentAuthorizedPickupList`) lo orquestan.
 *
 * **La lista de un alumno no se guarda: se calcula en cada consulta** (`RN-FAM-UNIT-79`, `-81`, como el estado de imagen de
 * `ADR-066 §5.2`). En el instante T contiene: (a) la persona de cada tutor con vínculo **vivo y `activo`**; (b) cada persona
 * con autorización `permanente` vigente (no retirada ni dada de baja, `valid_from ≤ hoy ≤ valid_until`); (c) cada persona con
 * autorización `puntual` de hoy; (b) y (c) solo con fotografía viva de la persona (`RN-FAM-UNIT-84`); y se excluye a toda
 * persona para la que exista un vínculo vivo `revocado` con ese alumno, **sea cual sea la base** (`RN-FAM-UNIT-80`, issue
 * #473). La exclusión usa el predicado de `FamilyMembership::isAccessRevoked()` y va **en la misma sentencia** que compone
 * la lista, no en una segunda llamada. «Hoy» es la fecha civil del centro (zona horaria del tenant, `funcional.md §16.3`
 * regla 8) según el reloj del servidor; ninguna consulta recibe el instante (`OPEN-068-08`).
 *
 * Todo el SQL lleva `tenant_id` explícito además de la política RLS del motor (`INV-001`): el centro es el del contexto,
 * nunca un parámetro. Sin memoización entre llamadas (`RN-FAM-UNIT-81`).
 */
final class PickupListService
{
    /** Vínculo vivo `revocado` entre la persona `%1$s` y el alumno `%2$s`: el predicado de `isAccessRevoked()`. */
    private const REVOKED_SQL = <<<'SQL'
        EXISTS (
            SELECT 1 FROM guardian_student_links rl
            JOIN guardians rg ON rg.tenant_id = rl.tenant_id AND rg.id = rl.guardian_id AND rg.deleted_at IS NULL
            WHERE rl.tenant_id = ? AND rg.person_id = %1$s AND rl.student_person_id = %2$s
              AND rl.status = 'revocado' AND rl.deleted_at IS NULL
        )
        SQL;

    /** Fotografía viva de la persona `%1$s` (`RN-FAM-UNIT-84`). */
    private const PHOTO_SQL = <<<'SQL'
        EXISTS (
            SELECT 1 FROM pickup_photos ph
            WHERE ph.tenant_id = ? AND ph.person_id = %1$s AND ph.deleted_at IS NULL
        )
        SQL;

    public function __construct(
        private readonly ScopedQuery $scopedQuery,
        private readonly TenantContext $tenant,
        private readonly TenantSettingsReader $settings,
        private readonly FamilyScope $scope,
    ) {}

    /** La fecha civil de hoy en la zona horaria del centro (`Y-m-d`), con el reloj del servidor. */
    public function today(): string
    {
        return now()->setTimezone($this->settings->timezone())->toDateString();
    }

    // ─── Lista calculada ───

    /**
     * Las bases por las que figuran personas en la lista del alumno **ahora**, ya sin las personas excluidas por
     * revocación. Una sola sentencia. Ordenadas: tutores, después terceros y puntuales. No comprueba que la persona o el
     * alumno estén vivos en `Core` (eso lo hace el llamador con `PersonDirectory`).
     *
     * @param  list<int>|null  $onlyPersonIds  restringe a esas personas (`authorizedAmong`, `isAuthorized`); `[]` ⇒ ninguna
     * @return list<array{person_id: int, basis: string, reference: string, relationship: string, valid_from: ?string, valid_until: ?string}>
     */
    public function compose(int $studentPersonId, ?array $onlyPersonIds = null): array
    {
        if ($onlyPersonIds === []) {
            return [];
        }

        $tenantId = $this->tenant->tenantId();
        $today = $this->today();
        $revoked = sprintf(self::REVOKED_SQL, 'b.person_id', '?');
        $photo = sprintf(self::PHOTO_SQL, 'a.person_id');
        $bindings = [
            // (a) tutores
            $tenantId, $studentPersonId,
            // (b) y (c) autorizaciones: alumno, hoy tres veces, y la fotografía
            $tenantId, $studentPersonId, $today, $today, $today, $tenantId,
            // exclusión por revocación
            $tenantId, $studentPersonId,
        ];
        $only = '';

        if ($onlyPersonIds !== null) {
            $only = ' AND b.person_id = ANY(?::bigint[])';
            $bindings[] = self::pgArray($onlyPersonIds);
        }

        $rows = DB::select(<<<SQL
            WITH bases AS (
                SELECT g.person_id, 'tutor' AS basis, l.public_id AS reference, l.relationship AS relationship,
                       NULL::date AS valid_from, NULL::date AS valid_until
                FROM guardian_student_links l
                JOIN guardians g ON g.tenant_id = l.tenant_id AND g.id = l.guardian_id AND g.deleted_at IS NULL
                WHERE l.tenant_id = ? AND l.student_person_id = ? AND l.status = 'activo' AND l.deleted_at IS NULL
                UNION ALL
                SELECT a.person_id, CASE a.kind WHEN 'permanente' THEN 'tercero' ELSE 'puntual' END, a.public_id, a.relationship,
                       a.valid_from, a.valid_until
                FROM pickup_authorizations a
                WHERE a.tenant_id = ? AND a.student_person_id = ? AND a.withdrawn_at IS NULL AND a.deleted_at IS NULL
                  AND ((a.kind = 'permanente' AND a.valid_from <= CAST(? AS date) AND (a.valid_until IS NULL OR a.valid_until >= CAST(? AS date)))
                    OR (a.kind = 'puntual' AND a.valid_from = CAST(? AS date)))
                  AND {$photo}
            )
            SELECT b.person_id, b.basis, b.reference, b.relationship, b.valid_from, b.valid_until
            FROM bases b
            WHERE NOT {$revoked}{$only}
            ORDER BY CASE b.basis WHEN 'tutor' THEN 0 WHEN 'tercero' THEN 1 ELSE 2 END, b.person_id, b.reference
            SQL, $bindings);

        return array_map(static fn (object $row): array => [
            'person_id' => (int) $row->person_id,
            'basis' => (string) $row->basis,
            'reference' => (string) $row->reference,
            'relationship' => (string) $row->relationship,
            'valid_from' => $row->valid_from === null ? null : (string) $row->valid_from,
            'valid_until' => $row->valid_until === null ? null : (string) $row->valid_until,
        ], $rows);
    }

    // ─── Autorizaciones ───

    /** Detalle sin acotar por ámbito (escrituras): `404` si no existe o es de otro centro. */
    public function find(string $publicId): PickupAuthorization
    {
        return PickupAuthorization::query()->with('termsVersion')->where('public_id', $publicId)->firstOrFail();
    }

    /** `404` si no existe, es de otro centro o el sujeto no alcanza al alumno (`RN-FAM-UNIT-91`). */
    public function findScoped(string $publicId, PermissionDecision $decision, User $subject): PickupAuthorization
    {
        return $this->scopedQuery->constrain(PickupAuthorization::query()->with('termsVersion'), 'autorizado_recogida', $decision, $subject)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /**
     * @param  array{student_person?: list<int>, person?: list<int>, kind: list<string>, effective: ?bool}  $filters  personas ya resueltas a ids internos (un `public_id` desconocido no está en la lista)
     * @return Builder<PickupAuthorization>
     */
    public function listing(PermissionDecision $decision, User $subject, array $filters): Builder
    {
        $query = $this->scopedQuery->constrain(PickupAuthorization::query()->with('termsVersion'), 'autorizado_recogida', $decision, $subject);

        foreach (['student_person' => 'student_person_id', 'person' => 'person_id'] as $filter => $column) {
            if (array_key_exists($filter, $filters)) {
                $query->whereIn($column, $filters[$filter]);
            }
        }

        if ($filters['kind'] !== []) {
            $query->whereIn('kind', $filters['kind']);
        }

        if ($filters['effective'] !== null) {
            [$sql, $bindings] = $this->effectiveSql();
            $query->whereRaw($filters['effective'] ? $sql : "NOT ({$sql})", $bindings);
        }

        return $query;
    }

    /**
     * La condición «vigente ahora» de una autorización (`RN-FAM-UNIT-82`, `-84`, `-80`) sobre la tabla
     * `pickup_authorizations`: la misma que compone la lista, para el filtro `effective`.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function effectiveSql(): array
    {
        $tenantId = $this->tenant->tenantId();
        $today = $this->today();
        $photo = sprintf(self::PHOTO_SQL, 'pickup_authorizations.person_id');
        $revoked = sprintf(self::REVOKED_SQL, 'pickup_authorizations.person_id', 'pickup_authorizations.student_person_id');

        return [
            "(pickup_authorizations.withdrawn_at IS NULL AND pickup_authorizations.deleted_at IS NULL
              AND ((pickup_authorizations.kind = 'permanente' AND pickup_authorizations.valid_from <= CAST(? AS date) AND (pickup_authorizations.valid_until IS NULL OR pickup_authorizations.valid_until >= CAST(? AS date)))
                OR (pickup_authorizations.kind = 'puntual' AND pickup_authorizations.valid_from = CAST(? AS date)))
              AND {$photo} AND NOT {$revoked})",
            [$today, $today, $today, $tenantId, $tenantId],
        ];
    }

    /**
     * Si cada autorización es vigente ahora y, si no, por qué (`api.md §8.1`: `retirada`, `fuera_de_vigencia`, `sin_foto`,
     * `persona_excluida`; en ese orden de precedencia). Dos consultas para todas.
     *
     * @param  iterable<PickupAuthorization>  $authorizations
     * @return array<int, array{effective: bool, reason: ?string}> por id interno de autorización
     */
    public function effectivenessOf(iterable $authorizations): array
    {
        $rows = [];

        foreach ($authorizations as $authorization) {
            $rows[] = $authorization;
        }

        if ($rows === []) {
            return [];
        }

        $personIds = array_values(array_unique(array_map(static fn (PickupAuthorization $a): int => (int) $a->person_id, $rows)));
        $studentIds = array_values(array_unique(array_map(static fn (PickupAuthorization $a): int => (int) $a->student_person_id, $rows)));
        $withPhoto = array_flip(array_map('intval', PickupPhoto::query()->whereIn('person_id', $personIds)->pluck('person_id')->all()));

        $excluded = [];

        foreach (DB::select(<<<'SQL'
            SELECT DISTINCT rg.person_id, rl.student_person_id
            FROM guardian_student_links rl
            JOIN guardians rg ON rg.tenant_id = rl.tenant_id AND rg.id = rl.guardian_id AND rg.deleted_at IS NULL
            WHERE rl.tenant_id = ? AND rl.status = 'revocado' AND rl.deleted_at IS NULL
              AND rg.person_id = ANY(?::bigint[]) AND rl.student_person_id = ANY(?::bigint[])
            SQL, [$this->tenant->tenantId(), self::pgArray($personIds), self::pgArray($studentIds)]) as $pair) {
            $excluded[(int) $pair->person_id.':'.(int) $pair->student_person_id] = true;
        }

        $today = $this->today();
        $result = [];

        foreach ($rows as $a) {
            $from = $a->valid_from?->toDateString();
            $until = $a->valid_until?->toDateString();
            $inWindow = $a->kind === PickupAuthorization::KIND_PUNCTUAL
                ? $from === $today
                : $from !== null && $from <= $today && ($until === null || $until >= $today);

            $reason = match (true) {
                $a->withdrawn_at !== null => 'retirada',
                ! $inWindow => 'fuera_de_vigencia',
                ! isset($withPhoto[(int) $a->person_id]) => 'sin_foto',
                isset($excluded[(int) $a->person_id.':'.(int) $a->student_person_id]) => 'persona_excluida',
                default => null,
            };

            $result[(int) $a->id] = ['effective' => $reason === null, 'reason' => $reason];
        }

        return $result;
    }

    /**
     * Alta de la autorización ya validada por `PickupAuthorizationService` (`RN-FAM-UNIT-85`, `-86`, `-88`). Se rellena por
     * instancia (AR-15). Las violaciones de los dos únicos parciales las traduce quien llama.
     *
     * @param  array{student_person_id: int, person_id: int, relationship: string, kind: string, valid_from: string, valid_until: ?string, reason: ?string, authorized_by_guardian_id: int, terms_version_id: int, channel: string, authorized_at: CarbonImmutable}  $values
     * @param  array{ip: ?string, user_agent: ?string}  $client
     */
    public function create(array $values, User $actor, array $client): PickupAuthorization
    {
        $authorization = new PickupAuthorization;
        $authorization->student_person_id = $values['student_person_id'];
        $authorization->person_id = $values['person_id'];
        $authorization->relationship = $values['relationship'];
        $authorization->kind = $values['kind'];
        $authorization->valid_from = $values['valid_from'];
        $authorization->valid_until = $values['valid_until'];
        $authorization->reason = $values['reason'];
        $authorization->authorized_by_guardian_id = $values['authorized_by_guardian_id'];
        $authorization->terms_version_id = $values['terms_version_id'];
        $authorization->channel = $values['channel'];
        $authorization->authorized_at = $values['authorized_at'];
        $authorization->recorded_by = $actor->id;
        $authorization->ip_address = $client['ip'];
        $authorization->user_agent = $client['user_agent'] === null ? null : mb_substr($client['user_agent'], 0, 512);
        $authorization->save();

        return $authorization->load('termsVersion');
    }

    /** `RN-FAM-UNIT-88`: ¿ya hay una autorización viva (no retirada) idéntica? */
    public function liveDuplicateExists(int $studentPersonId, int $personId, string $kind, string $validFrom): bool
    {
        return PickupAuthorization::query()
            ->where('student_person_id', $studentPersonId)->where('person_id', $personId)->where('kind', $kind)
            ->whereNull('withdrawn_at')
            ->when($kind === PickupAuthorization::KIND_PUNCTUAL, fn (Builder $q) => $q->where('valid_from', $validFrom))
            ->exists();
    }

    /** La autorización con bloqueo de fila, para cambiar su vigencia o retirarla. */
    public function lockedForUpdate(string $publicId): PickupAuthorization
    {
        return PickupAuthorization::query()->with('termsVersion')->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
    }

    /** ¿Figura la persona en alguna autorización viva (no dada de baja), retirada o no? (Alcance de `/pickup-persons`.) */
    public function personHasLiveAuthorization(int $personId): bool
    {
        return PickupAuthorization::query()->where('person_id', $personId)->exists();
    }

    // ─── Fotografías ───

    /** La fotografía viva de la persona, o `null`. */
    public function livePhoto(int $personId): ?PickupPhoto
    {
        return PickupPhoto::query()->where('person_id', $personId)->first();
    }

    /** La fotografía viva de la persona acotada por el ámbito del sujeto: con `unidad_familiar` nunca (`OPEN-FAM-UNIT-43`). */
    public function scopedLivePhoto(int $personId, PermissionDecision $decision, User $subject): ?PickupPhoto
    {
        return $this->scopedQuery->constrain(PickupPhoto::query(), 'autorizado_recogida', $decision, $subject)->where('person_id', $personId)->first();
    }

    /**
     * Las fotografías vivas de esas personas, por id de persona.
     *
     * @param  list<int>  $personIds
     * @return array<int, PickupPhoto>
     */
    public function photosAmong(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        $photos = [];

        foreach (PickupPhoto::query()->whereIn('person_id', array_values(array_unique($personIds)))->get() as $photo) {
            $photos[(int) $photo->person_id] = $photo;
        }

        return $photos;
    }

    /**
     * `RN-FAM-UNIT-83`: sustituye la fotografía viva de la persona (baja lógica de la anterior y alta de la nueva, en una
     * transacción y bajo un bloqueo consultivo de la persona: dos subidas simultáneas se serializan). El objeto anterior
     * queda huérfano y lo purga el barrido.
     *
     * @return array{photo: PickupPhoto, replaced: bool}
     */
    public function replacePhoto(int $personId, string $objectKey, string $mimeType, int $byteSize): array
    {
        return DB::transaction(function () use ($personId, $objectKey, $mimeType, $byteSize): array {
            $this->lockPerson($personId);

            $previous = PickupPhoto::query()->where('person_id', $personId)->lockForUpdate()->first();
            $previous?->delete();

            $photo = new PickupPhoto;
            $photo->person_id = $personId;
            $photo->object_key = $objectKey;
            $photo->mime_type = $mimeType;
            $photo->byte_size = $byteSize;
            $photo->save();

            return ['photo' => $photo, 'replaced' => $previous !== null];
        });
    }

    /** Baja lógica de la fotografía viva de la persona; `false` si no tenía. El objeto lo purga el barrido. */
    public function removePhoto(int $personId): bool
    {
        return DB::transaction(function () use ($personId): bool {
            $this->lockPerson($personId);
            $photo = PickupPhoto::query()->where('person_id', $personId)->lockForUpdate()->first();
            $photo?->delete();

            return $photo !== null;
        });
    }

    // ─── Barrido de fotografías huérfanas (`datos.md §8.8`) ───

    /**
     * Claves de objeto de todas las fotografías **vivas** del centro: las que el barrido no debe tocar.
     *
     * @return list<string>
     */
    public function liveObjectKeys(): array
    {
        return array_values(array_map('strval', PickupPhoto::query()->pluck('object_key')->all()));
    }

    /**
     * `OPEN-FAM-UNIT-37`: personas con fotografía viva de más de `$graceDays` días que **no son tutoras** y no tienen
     * ninguna autorización vigente ni futura, ni retirada o caducada dentro del plazo de gracia.
     *
     * @return list<int> ids de persona
     */
    public function personIdsWithExpiredPhoto(int $graceDays): array
    {
        $now = now()->toImmutable();
        $tenantId = $this->tenant->tenantId();
        $cutoffDay = $now->setTimezone($this->settings->timezone())->subDays($graceDays)->toDateString();

        $rows = DB::select(<<<'SQL'
            SELECT p.person_id
            FROM pickup_photos p
            WHERE p.tenant_id = ? AND p.deleted_at IS NULL AND p.created_at < ?
              AND NOT EXISTS (SELECT 1 FROM guardians g WHERE g.tenant_id = p.tenant_id AND g.person_id = p.person_id AND g.deleted_at IS NULL)
              AND NOT EXISTS (
                  SELECT 1 FROM pickup_authorizations a
                  WHERE a.tenant_id = p.tenant_id AND a.person_id = p.person_id AND a.deleted_at IS NULL
                    AND ((a.withdrawn_at IS NULL AND (a.valid_until IS NULL OR a.valid_until >= CAST(? AS date)))
                      OR (a.withdrawn_at IS NOT NULL AND a.withdrawn_at >= ?))
              )
            SQL, [$tenantId, $now->subDays($graceDays), $cutoffDay, $now->subDays($graceDays)]);

        return array_map(static fn (object $row): int => (int) $row->person_id, $rows);
    }

    // ─── Alumno alcanzado ───

    /**
     * `404` si el sujeto no alcanza al alumno. Con ámbito `todos` alcanza a cualquiera (la existencia de la persona la
     * comprueba el llamador); con `unidad_familiar`, solo a los alumnos con vínculo **activo** con su faceta de tutor
     * (`RN-FAM-UNIT-91`): un tutor revocado no alcanza al alumno.
     */
    public function assertStudentReachable(PermissionDecision $decision, User $subject, int $studentPersonId): void
    {
        if ($decision->isUnrestricted()) {
            return;
        }

        $reach = $this->scope->reachableStudentIds($subject);

        if ($reach === null || ! $reach->where('student_person_id', $studentPersonId)->exists()) {
            throw ApiException::notFound();
        }
    }

    /** @param  list<int>  $ids */
    private static function pgArray(array $ids): string
    {
        return '{'.implode(',', array_map('intval', $ids)).'}';
    }

    /** Bloqueo consultivo de la transacción por centro y persona: serializa las altas y bajas de su fotografía. */
    private function lockPerson(int $personId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["fam_unit_pickup_photo:{$this->tenant->tenantId()}:{$personId}"]);
    }
}
