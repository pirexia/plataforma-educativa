<?php

namespace App\Modules\FamUnit\Application;

use App\Models\User;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Domain\Models\ImageConsentDecision;
use App\Modules\FamUnit\Domain\Models\TermsVersion;
use App\Support\Api\ApiException;
use App\Support\Authorization\PermissionDecision;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\ScopedQuery;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/funcional.md §3.8, §4.6 a §4.8, §5.4 (`RN-FAM-UNIT-41` a `-47`, `-67` a `-72`), 1.14-b, `ADR-066`:
 * consentimiento de imagen. **Servicio sancionado por `AR-10` para consultar `ImageConsentDecision`** (recurso
 * `consentimiento_imagen`, ámbito `unidad_familiar`): el histórico pasa por `ScopedQuery`; el cálculo del estado lee
 * vínculos y decisiones en una sola sentencia (`permisos.md §3.1`).
 *
 * **El estado vigente no se guarda: se calcula en cada consulta** (`ADR-066 §5.2`, `RN-FAM-UNIT-44` sustituida). Quienes
 * deciden son los vínculos **vivos** con `is_legal_guardian` (`activo` o `revocado`, `RN-FAM-UNIT-41`); de cada uno
 * cuenta su última decisión, por `(occurred_at, id)`, entre las posteriores a `legal_guardian_verified_at` de su vínculo
 * (`RN-FAM-UNIT-72`; con la verificación nula no cuenta ninguna). `no_autorizo` si alguno tiene como vigente
 * `no_autorizo`; `autorizo` si hay al menos uno y todos tienen `autorizo`; `pendiente` en cualquier otro caso
 * (`RN-FAM-UNIT-45`). Sin caché ni memoización: el alta, la baja o la restauración de un vínculo cambian el estado en la
 * consulta siguiente, sin recálculo ni disparador que se pueda olvidar.
 *
 * Todo el SQL lleva `tenant_id` explícito además de la política RLS del motor (`INV-001`): el centro es el del
 * contexto, nunca un parámetro.
 */
final class ImageConsentService
{
    public const STATES = ['autorizo', 'no_autorizo', 'pendiente'];

    /** Ordenaciones permitidas de `GET /image-consents` (`api.md §3`): nombre de la API => expresión SQL y sentido. */
    private const SORTS = [
        'last_decision_at' => ['{date}', 'ASC NULLS FIRST'],
        '-last_decision_at' => ['{date}', 'DESC NULLS LAST'],
        'state' => ['r.state', 'ASC'],
        '-state' => ['r.state', 'DESC'],
    ];

    public const DEFAULT_SORT = 'last_decision_at';

    /**
     * El estado de cada alumno en cada nivel, a partir de la subconsulta `students` (columna `student_person_id` y las
     * que quiera propagar). Los `?` son, por orden: los de la subconsulta, el centro (vínculos) y el centro (decisiones).
     */
    private const STATE_SQL = <<<'SQL'
        WITH students AS (%s),
        deciders AS (
            SELECT l.student_person_id, l.guardian_id, l.status, l.legal_guardian_verified_at
            FROM guardian_student_links l
            WHERE l.tenant_id = ? AND l.deleted_at IS NULL AND l.is_legal_guardian
              AND l.student_person_id IN (SELECT student_person_id FROM students)
        ),
        counted AS (
            SELECT DISTINCT ON (d.student_person_id, d.level, d.guardian_id)
                d.student_person_id, d.level, d.decision, d.occurred_at, x.status AS link_status
            FROM image_consent_decisions d
            JOIN deciders x ON x.student_person_id = d.student_person_id AND x.guardian_id = d.guardian_id
            WHERE d.tenant_id = ? AND x.legal_guardian_verified_at IS NOT NULL AND d.occurred_at > x.legal_guardian_verified_at
            ORDER BY d.student_person_id, d.level, d.guardian_id, d.occurred_at DESC, d.id DESC
        ),
        deciding AS (
            SELECT student_person_id, count(*) AS total FROM deciders GROUP BY student_person_id
        ),
        tally AS (
            SELECT student_person_id, level,
                count(*) FILTER (WHERE decision = 'autorizo') AS authorized,
                count(*) FILTER (WHERE decision = 'no_autorizo') AS denied,
                max(occurred_at) AS last_at,
                max(occurred_at) FILTER (WHERE link_status = 'activo') AS last_visible_at
            FROM counted GROUP BY student_person_id, level
        )
        SELECT s.*, lv.level,
            CASE WHEN COALESCE(t.denied, 0) > 0 THEN 'no_autorizo'
                 WHEN COALESCE(g.total, 0) > 0 AND COALESCE(t.authorized, 0) = g.total THEN 'autorizo'
                 ELSE 'pendiente' END AS state,
            t.last_at, t.last_visible_at
        FROM students s
        CROSS JOIN (VALUES ('web'), ('redes_sociales')) AS lv(level)
        LEFT JOIN deciding g ON g.student_person_id = s.student_person_id
        LEFT JOIN tally t ON t.student_person_id = s.student_person_id AND t.level = lv.level
        SQL;

    public function __construct(
        private readonly ScopedQuery $scopedQuery,
        private readonly PermissionResolver $permissions,
        private readonly GuardianLinkService $links,
        private readonly GuardianService $guardians,
        private readonly FamilyUnitStudentService $students,
        private readonly FamilyUnitService $units,
        private readonly FamilyScope $scope,
        private readonly TermsVersionService $terms,
        private readonly PersonDirectory $people,
        private readonly TenantContext $tenant,
        private readonly ImageConsentCursor $cursor,
    ) {}

    // ─── Estado vigente ───

    /**
     * Alumnos (de la lista) cuyo estado en `$level` es `autorizo`. Una sola sentencia sea cual sea el número de alumnos.
     * Un alumno desconocido, de otro centro o sin quien decida no aparece (`pendiente`): fallo en cerrado.
     *
     * @param  list<int>  $studentPersonIds
     * @return list<int>
     */
    public function authorizedAmong(string $level, array $studentPersonIds): array
    {
        $ids = array_values(array_unique($studentPersonIds));

        if ($ids === []) {
            return [];
        }

        [$sql, $bindings] = $this->stateQuery('SELECT DISTINCT unnest(?::bigint[]) AS student_person_id', [self::pgArray($ids)]);

        $rows = DB::select("SELECT r.student_person_id FROM ({$sql}) r WHERE r.level = ? AND r.state = 'autorizo'", [...$bindings, $level]);

        return array_map(static fn (object $row): int => (int) $row->student_person_id, $rows);
    }

    /**
     * El estado de cada alumno en cada nivel (`api.md §1.5`).
     *
     * @param  list<int>  $studentPersonIds
     * @param  bool  $hideRevokedDates  con ámbito `unidad_familiar`, `last_decision_at` nunca refleja la decisión de un
     *                                  tutor revocado (`RN-FAM-UNIT-71`)
     * @return list<array{student_person_id: int, family_unit_id: ?int, level: string, state: string, last_decision_at: ?CarbonImmutable}>
     */
    public function statesOf(array $studentPersonIds, bool $hideRevokedDates): array
    {
        $ids = array_values(array_unique($studentPersonIds));

        if ($ids === []) {
            return [];
        }

        [$sql, $bindings] = $this->stateQuery('SELECT DISTINCT unnest(?::bigint[]) AS student_person_id', [self::pgArray($ids)]);

        return array_values(array_map(
            fn (object $row): array => $this->stateRow($row, $hideRevokedDates),
            DB::select("SELECT r.* FROM ({$sql}) r ORDER BY r.student_person_id, r.level", $bindings),
        ));
    }

    /**
     * Una página de estados de los alumnos vivos de las unidades que el sujeto alcanza (`GET /image-consents`) y el
     * total de filas. Parte de `family_unit_students`, así que `state=pendiente` incluye a los alumnos sin ninguna
     * decisión (`ADR-066 §5.2`).
     *
     * @param  array{family_unit: list<string>, student_person: ?list<int>, level: list<string>, state: list<string>}  $filters
     * @return array{rows: list<array{student_person_id: int, family_unit_id: ?int, level: string, state: string, last_decision_at: ?CarbonImmutable}>, total: int}
     */
    public function page(PermissionDecision $decision, User $subject, array $filters, string $sort, int $page, int $perPage): array
    {
        $hide = ! $decision->isUnrestricted();
        [$order, $direction] = self::SORTS[$sort] ?? self::SORTS[self::DEFAULT_SORT];
        // Con ámbito `unidad_familiar`, se ordena por la fecha que el sujeto puede ver (`RN-FAM-UNIT-71`).
        $order = str_replace('{date}', $hide ? 'r.last_visible_at' : 'r.last_at', $order);
        $result = $this->stateRows($decision, $subject, $filters, "{$order} {$direction}, r.student_person_id ASC, r.level ASC", $perPage, ($page - 1) * $perPage);

        return [
            'rows' => array_map(fn (object $row): array => $this->stateRow($row, $hide), $result['rows']),
            'total' => $result['total'],
        ];
    }

    /**
     * Todas las filas del informe (`POST /image-consents/exports`), de `$limit` en `$limit`, con el orden fijo del
     * esquema CSV (`ADR-054 §8.1`): unidad, alumno y nivel.
     *
     * @param  array{family_unit: list<string>, student_person: ?list<int>, level: list<string>, state: list<string>}  $filters
     * @return \Generator<int, array{student_person_id: int, family_unit_id: ?int, level: string, state: string, last_decision_at: ?CarbonImmutable}>
     */
    public function exportRows(PermissionDecision $decision, User $subject, array $filters, int $limit = 1000): \Generator
    {
        $hide = ! $decision->isUnrestricted();
        $offset = 0;

        do {
            $chunk = $this->stateRows($decision, $subject, $filters, 'r.family_unit_id ASC, r.student_person_id ASC, r.level ASC', $limit, $offset);

            foreach ($chunk['rows'] as $row) {
                yield $this->stateRow($row, $hide);
            }

            $offset += $limit;
        } while (count($chunk['rows']) === $limit);
    }

    /**
     * @param  array{family_unit: list<string>, student_person: ?list<int>, level: list<string>, state: list<string>}  $filters
     * @return array{rows: list<object>, total: int}
     */
    private function stateRows(PermissionDecision $decision, User $subject, array $filters, string $orderBy, int $limit, int $offset): array
    {
        $students = $this->students->consentStudents($decision, $subject, $filters)->toBase();
        [$sql, $bindings] = $this->stateQuery($students->toSql(), $students->getBindings());

        $where = [];

        if ($filters['level'] !== []) {
            $where[] = 'r.level = ANY(?::text[])';
            $bindings[] = self::pgArray($filters['level']);
        }

        if ($filters['state'] !== []) {
            $where[] = 'r.state = ANY(?::text[])';
            $bindings[] = self::pgArray($filters['state']);
        }

        $clause = $where === [] ? '' : 'WHERE '.implode(' AND ', $where);
        $rows = DB::select(
            "SELECT r.*, count(*) OVER () AS total FROM ({$sql}) r {$clause} ORDER BY {$orderBy} LIMIT ? OFFSET ?",
            [...$bindings, $limit, $offset],
        );

        if ($rows !== []) {
            return ['rows' => $rows, 'total' => (int) $rows[0]->total];
        }

        // Una página fuera de rango no trae filas ni el total (`count(*) OVER ()` vive en las filas): se cuenta aparte.
        return ['rows' => [], 'total' => $offset === 0 ? 0 : (int) (DB::selectOne("SELECT count(*) AS total FROM ({$sql}) r {$clause}", $bindings)->total ?? 0)];
    }

    /**
     * @param  list<mixed>  $studentBindings
     * @return array{0: string, 1: list<mixed>}
     */
    private function stateQuery(string $studentsSql, array $studentBindings): array
    {
        $tenantId = $this->tenant->tenantId();

        return [sprintf(self::STATE_SQL, $studentsSql), [...$studentBindings, $tenantId, $tenantId]];
    }

    /**
     * @return array{student_person_id: int, family_unit_id: ?int, level: string, state: string, last_decision_at: ?CarbonImmutable}
     */
    private function stateRow(object $row, bool $hideRevokedDates): array
    {
        $last = $hideRevokedDates ? ($row->last_visible_at ?? null) : ($row->last_at ?? null);

        return [
            'student_person_id' => (int) $row->student_person_id,
            'family_unit_id' => isset($row->family_unit_id) ? (int) $row->family_unit_id : null,
            'level' => (string) $row->level,
            'state' => (string) $row->state,
            'last_decision_at' => $last === null ? null : CarbonImmutable::parse((string) $last),
        ];
    }

    /**
     * Nombre y `public_id` de las unidades de esas filas, por id interno de unidad.
     *
     * @param  list<int>  $unitIds
     * @return array<int, array{public_id: string, code: string}>
     */
    public function unitRefs(array $unitIds): array
    {
        return $this->units->refsByIds($unitIds);
    }

    // ─── Registro de una decisión ───

    /**
     * `RN-FAM-UNIT-41`, `-43`, `-67` a `-69`: registra una decisión (inmutable) y devuelve la fila con el estado
     * resultante. Dos modos, según quién la registra:
     *
     * - **El propio tutor** (canal `portal`): vínculo **activo** propio con representación legal; la versión es la
     *   publicada y `shown_locale` el idioma realmente mostrado (`OPEN-FAM-UNIT-25`).
     * - **El centro en nombre de un tutor** (`on_behalf_of`, canal `presencial`, ámbito `todos`): el tutor debe estar
     *   entre quienes deciden; si su vínculo está `revocado`, solo con `revocacion_tutor.leer` (si no, el revocado es
     *   invisible y se responde como si no estuviera vinculado, `RN-FAM-UNIT-40`).
     *
     * @param  array{student_person: string, level: string, decision: string, on_behalf_of: ?string, channel: ?string, consent_text_version: ?string}  $input
     * @param  array{ip: ?string, user_agent: ?string}  $client
     * @return array{decision: ImageConsentDecision, state: array{student_person_id: int, family_unit_id: ?int, level: string, state: string, last_decision_at: ?CarbonImmutable}}
     */
    public function record(array $input, User $actor, PermissionDecision $decision, array $client): array
    {
        $student = $this->people->findByPublicId($input['student_person']);
        $unrestricted = $decision->isUnrestricted();

        $ownPersonId = $this->people->personIdForUser((int) $actor->id);
        $ownGuardianId = $ownPersonId === null ? null : $this->guardians->guardianIdForPerson($ownPersonId);
        $ownLink = $student !== null && $ownGuardianId !== null ? $this->links->liveLink($ownGuardianId, $student->id) : null;
        $ownActive = $ownLink !== null && $ownLink->status === 'activo';
        $ownPublicId = $ownGuardianId === null ? null : ($this->guardians->publicIdsByIds([$ownGuardianId])[$ownGuardianId] ?? null);

        $onBehalfOf = $input['on_behalf_of'];
        $self = $onBehalfOf === null || ($ownPublicId !== null && $onBehalfOf === $ownPublicId);

        if ($self && $ownActive) {
            // El propio tutor: portal.
            if (! (bool) $ownLink->is_legal_guardian) {
                throw FamUnitErrors::invalid('student_person', 'fam_unit.image_consent.not_legal_guardian');
            }

            $channel = 'portal';
            $guardianId = (int) $ownGuardianId;
        } elseif (! $unrestricted) {
            // Con ámbito `unidad_familiar`: un alumno que no alcanza no existe (404); alcanzado, no puede actuar por otro.
            throw $ownActive ? ApiException::forbidden() : ApiException::notFound();
        } else {
            // El centro en nombre de un tutor.
            if ($student === null) {
                throw FamUnitErrors::invalid('student_person', 'fam_unit.validation.student_not_found');
            }

            if ($onBehalfOf === null) {
                throw FamUnitErrors::invalid('on_behalf_of', 'fam_unit.image_consent.guardian_required');
            }

            $guardian = $this->guardians->findOrNull($onBehalfOf);
            $link = $guardian === null ? null : $this->links->liveLink((int) $guardian->id, $student->id);

            // Un vínculo revocado sin `revocacion_tutor.leer` es invisible (`RN-FAM-UNIT-40`): igual que no vinculado.
            if ($link === null || ($link->status === 'revocado' && ! $this->permissions->decide($actor, 'revocacion_tutor.leer')->permitted)) {
                throw FamUnitErrors::invalid('on_behalf_of', 'fam_unit.image_consent.guardian_not_linked');
            }

            if (! (bool) $link->is_legal_guardian) {
                throw FamUnitErrors::invalid('on_behalf_of', 'fam_unit.image_consent.not_legal_guardian');
            }

            $channel = 'presencial';
            $guardianId = (int) $guardian->id;
        }

        if ($input['channel'] !== null && $input['channel'] !== $channel) {
            throw FamUnitErrors::invalid('channel', 'fam_unit.validation.channel_invalid');
        }

        [$version, $shownLocale] = $this->textFor($input['level'], $input['decision'], $channel, $input['consent_text_version']);

        $row = DB::transaction(function () use ($student, $input, $guardianId, $actor, $channel, $version, $shownLocale, $client): ImageConsentDecision {
            $row = new ImageConsentDecision;
            $row->student_person_id = $student->id;
            $row->level = $input['level'];
            $row->decision = $input['decision'];
            $row->guardian_id = $guardianId;
            $row->recorded_by = $actor->id;
            $row->channel = $channel;
            $row->consent_text_version_id = $version?->id;
            $row->shown_locale = $shownLocale;
            $row->occurred_at = now();
            $row->ip_address = $client['ip'];
            $row->user_agent = $client['user_agent'] === null ? null : mb_substr($client['user_agent'], 0, 512);
            $row->save();

            return $row;
        });

        $row->setRelation('termsVersion', $version);
        $state = $this->statesOf([$student->id], ! $unrestricted);
        $mine = array_values(array_filter($state, static fn (array $s): bool => $s['level'] === $input['level']));

        return ['decision' => $row, 'state' => $mine[0]];
    }

    /**
     * `RN-FAM-UNIT-68`, `-69`, `-43`: la versión del texto que se registra y el idioma en que se mostró.
     *
     * @return array{0: ?TermsVersion, 1: ?string}
     */
    private function textFor(string $level, string $decision, string $channel, ?string $requestedVersion): array
    {
        $kind = self::kindOf($level);

        if ($channel === 'portal') {
            // Por `portal` el cuerpo no elige versión: la publicada en ese momento.
            if ($requestedVersion !== null) {
                throw FamUnitErrors::invalid('consent_text_version', 'fam_unit.image_consent.text_version_invalid');
            }

            $version = $this->terms->published($kind);
        } elseif ($requestedVersion !== null) {
            // Por `presencial`, una `publicada` o `retirada` del tipo del nivel; nunca un `borrador` ni otro tipo.
            $version = $this->terms->findOrNull($requestedVersion);

            if ($version === null || $version->kind !== $kind || ! in_array($version->status, [TermsVersion::STATUS_PUBLISHED, TermsVersion::STATUS_RETIRED], true)) {
                throw FamUnitErrors::invalid('consent_text_version', 'fam_unit.image_consent.text_version_invalid');
            }
        } else {
            $version = $this->terms->published($kind);
        }

        if ($decision === 'autorizo' && $version === null) {
            throw FamUnitErrors::invalid('decision', 'fam_unit.image_consent.text_not_published');
        }

        $shown = $channel === 'portal' && $version !== null ? $this->terms->resolve($version)['locale'] : null;

        return [$version, $shown];
    }

    /** El tipo de texto legal que corresponde a un nivel (`RN-FAM-UNIT-69`). */
    public static function kindOf(string $level): string
    {
        return $level === 'web' ? TermsVersion::KIND_IMAGE_WEB : TermsVersion::KIND_IMAGE_SOCIAL;
    }

    // ─── Histórico ───

    /**
     * Histórico de decisiones de un alumno, `(occurred_at DESC, id DESC)`, por cursor. `404` si el alumno no existe o el
     * sujeto no lo alcanza. Con ámbito `unidad_familiar` omite las decisiones de tutores con el vínculo revocado
     * (`RN-FAM-UNIT-71`, el resolutor).
     *
     * @return array{rows: list<ImageConsentDecision>, next_cursor: ?string, has_more: bool, student_person_id: int}
     */
    public function history(PermissionDecision $decision, User $subject, string $studentPublicId, ?string $level, ?string $cursor, int $limit): array
    {
        $student = $this->people->findByPublicId($studentPublicId) ?? throw ApiException::notFound();

        if (! $decision->isUnrestricted()) {
            $reach = $this->scope->reachableStudentIds($subject);

            if ($reach === null || ! (clone $reach)->where('student_person_id', $student->id)->exists()) {
                throw ApiException::notFound();
            }
        }

        $fingerprint = $this->cursor->fingerprint(['student_person' => $studentPublicId, 'level' => $level]);
        $query = $this->scopedQuery->constrain(ImageConsentDecision::query()->with('termsVersion'), 'consentimiento_imagen', $decision, $subject)
            ->where('student_person_id', $student->id);

        if ($level !== null) {
            $query->where('level', $level);
        }

        if ($cursor !== null) {
            [$occurredAt, $id] = $this->cursor->decode($cursor, $fingerprint, $this->tenant->tenantId());

            $query->where(function (Builder $q) use ($occurredAt, $id): void {
                $q->where('occurred_at', '<', $occurredAt)
                    ->orWhere(fn (Builder $same) => $same->where('occurred_at', '=', $occurredAt)->where('id', '<', $id));
            });
        }

        $rows = $query->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $last = $page->last();

        return [
            'rows' => $page->all(),
            'next_cursor' => $hasMore && $last !== null
                ? $this->cursor->encode($last->occurred_at->toIso8601String(), (int) $last->id, $fingerprint, $this->tenant->tenantId())
                : null,
            'has_more' => $hasMore,
            'student_person_id' => $student->id,
        ];
    }

    // ─── Auxiliares ───

    /**
     * Literal de array de PostgreSQL para un parámetro `?::bigint[]` o `?::text[]`. Solo recibe enteros y valores de
     * vocabularios cerrados ya validados, nunca texto libre.
     *
     * @param  list<int|string>  $values
     */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(static fn (int|string $v): string => (string) $v, $values)).'}';
    }
}
