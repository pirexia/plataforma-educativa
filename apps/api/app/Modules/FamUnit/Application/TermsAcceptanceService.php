<?php

namespace App\Modules\FamUnit\Application;

use App\Models\User;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Domain\Models\TermsAcceptance;
use App\Modules\FamUnit\Domain\Models\TermsVersion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/funcional.md §4.10, `RN-FAM-UNIT-61` a `-65`, `api.md §2.8` (1.14-c, `ADR-067`). Aceptación de las
 * condiciones de uso: ¿qué versión está pendiente para un usuario?, registrar su aceptación (inmutable, idempotente) y
 * consultarlas (personal con `aceptacion_condiciones.leer`).
 *
 * **Sin ninguna caché ni memoización** (`ADR-067 §5.8`): `isPending()` se evalúa en cada petición de un usuario de un
 * centro con `fam_unit` activo, y publicar una versión que exige re-aceptación o activar el primer vínculo de un tutor
 * tienen que verse en la petición siguiente.
 */
final class TermsAcceptanceService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly TermsVersionService $terms,
        private readonly PersonDirectory $people,
        private readonly ImageConsentCursor $cursor,
    ) {}

    /**
     * ¿Está el usuario detenido por el muro de condiciones? **Una sola consulta**, con predicado de tenant explícito
     * además de la RLS (`INV-001`, `ADR-067 §5.7`). Verdadero si y solo si se cumplen las tres condiciones:
     *
     *  1. hay una versión `condiciones_uso` publicada (índice único parcial `terms_versions_tenant_kind_published_unique`);
     *     sin ella, nadie está obligado (`RN-FAM-UNIT-63`);
     *  2. el usuario está obligado: su persona tiene una faceta de tutor viva con al menos un vínculo `activo` y no
     *     borrado (`RN-FAM-UNIT-61`). `person_id` sale del propio `User`, sin leer `users` ni `people`;
     *  3. **no** tiene una aceptación exigible (`RN-FAM-UNIT-62`): si la versión publicada exige re-aceptación, la de esa
     *     versión; si no, cualquiera de tipo `condiciones_uso`.
     */
    public function isPending(User $user): bool
    {
        $tenantId = $this->tenant->tenantId();

        $row = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM terms_versions tv
                WHERE tv.tenant_id = ?
                  AND tv.kind = ?
                  AND tv.status = 'publicada'
                  AND tv.deleted_at IS NULL
                  AND EXISTS (
                      SELECT 1
                      FROM guardians g
                      JOIN guardian_student_links l ON l.tenant_id = g.tenant_id AND l.guardian_id = g.id
                      WHERE g.tenant_id = ?
                        AND g.person_id = ?
                        AND g.deleted_at IS NULL
                        AND l.status = 'activo'
                        AND l.deleted_at IS NULL
                  )
                  AND NOT EXISTS (
                      SELECT 1
                      FROM terms_acceptances a
                      JOIN terms_versions av ON av.tenant_id = a.tenant_id AND av.id = a.terms_version_id
                      WHERE a.tenant_id = ?
                        AND a.user_id = ?
                        AND av.kind = ?
                        AND (NOT tv.requires_reacceptance OR a.terms_version_id = tv.id)
                  )
            ) AS pending
            SQL, [
            $tenantId, TermsVersion::KIND_TERMS_OF_USE,
            $tenantId, (int) $user->person_id,
            $tenantId, (int) $user->id, TermsVersion::KIND_TERMS_OF_USE,
        ]);

        return (bool) ($row->pending ?? false);
    }

    /** La versión publicada de las condiciones de uso, o `null` si el centro no ha publicado ninguna. */
    public function published(): ?TermsVersion
    {
        return $this->terms->published(TermsVersion::KIND_TERMS_OF_USE);
    }

    /**
     * `RN-FAM-UNIT-64`, `-65`: registra la aceptación de la versión publicada de las condiciones. `$versionPublicId`
     * debe ser una versión `condiciones_uso` del centro (`422` si no existe o es de otro tipo) y la **publicada** ahora
     * (`409 version_not_current`: el tutor aceptó un texto que ya no es el vigente). Aceptar dos veces la misma versión
     * devuelve la existente (`created = false`, sin fila ni auditoría nueva). `shown_locale` es el idioma en que se
     * sirvió **realmente** el texto tras la caída de `ADR-062`, no el de la petición.
     *
     * @param  array{ip: ?string, user_agent: ?string}  $client
     * @return array{acceptance: TermsAcceptance, created: bool}
     */
    public function accept(User $user, string $versionPublicId, array $client): array
    {
        $version = $this->terms->findOrNull($versionPublicId);

        if ($version === null || $version->kind !== TermsVersion::KIND_TERMS_OF_USE) {
            throw FamUnitErrors::invalid('terms_version', 'fam_unit.validation.terms_version_not_found');
        }

        if ($version->status !== TermsVersion::STATUS_PUBLISHED) {
            throw FamUnitErrors::conflict('terms_version', 'fam_unit.terms.version_not_current');
        }

        $existing = $this->existing($user, $version);

        if ($existing !== null) {
            return ['acceptance' => $existing, 'created' => false];
        }

        try {
            $acceptance = DB::transaction(function () use ($user, $version, $client): TermsAcceptance {
                $acceptance = new TermsAcceptance;
                $acceptance->user_id = $user->id;
                $acceptance->terms_version_id = $version->id;
                $acceptance->shown_locale = $this->terms->resolve($version)['locale'];
                $acceptance->accepted_at = now();
                $acceptance->ip_address = $client['ip'];
                $acceptance->user_agent = $client['user_agent'] === null ? null : mb_substr($client['user_agent'], 0, 1000);
                $acceptance->save();

                return $acceptance;
            });
        } catch (QueryException $e) {
            // Dos peticiones simultáneas del mismo usuario: gana el índice único; la perdedora devuelve la existente.
            if (($e->errorInfo[0] ?? null) === '23505' && str_contains($e->getMessage(), 'terms_acceptances_tenant_user_version_unique')) {
                $existing = $this->existing($user, $version);

                if ($existing !== null) {
                    return ['acceptance' => $existing, 'created' => false];
                }
            }

            throw $e;
        }

        return ['acceptance' => $acceptance->setRelation('termsVersion', $version), 'created' => true];
    }

    /**
     * `GET /terms-acceptances` (`api.md §3`): cursor sobre `(accepted_at DESC, id DESC)`, filtros `terms_version` y
     * `user` por `public_id`. Un `public_id` desconocido o de otro centro no coincide con nada. El recurso de permisos
     * `aceptacion_condiciones` solo admite el ámbito `todos`, así que no pasa por `ScopedQuery`.
     *
     * @param  list<string>  $termsVersions
     * @param  list<string>  $users
     * @return array{rows: list<TermsAcceptance>, next_cursor: ?string, has_more: bool}
     */
    public function history(array $termsVersions, array $users, ?string $cursor, int $limit): array
    {
        $fingerprint = $this->cursor->fingerprint(['terms_version' => $termsVersions, 'user' => $users]);
        $query = TermsAcceptance::query()->with('termsVersion');

        if ($termsVersions !== []) {
            $query->whereHas('termsVersion', fn (Builder $q) => $q->whereIn('public_id', $termsVersions));
        }

        if ($users !== []) {
            $userIds = [];

            foreach ($users as $publicId) {
                $ref = $this->people->findUserByPublicId($publicId);

                if ($ref !== null) {
                    $userIds[] = $ref->userId;
                }
            }

            $query->whereIn('user_id', $userIds);
        }

        if ($cursor !== null) {
            [$acceptedAt, $id] = $this->cursor->decode($cursor, $fingerprint, $this->tenant->tenantId());

            $query->where(function (Builder $q) use ($acceptedAt, $id): void {
                $q->where('accepted_at', '<', $acceptedAt)
                    ->orWhere(fn (Builder $same) => $same->where('accepted_at', '=', $acceptedAt)->where('id', '<', $id));
            });
        }

        $rows = $query->orderByDesc('accepted_at')->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $last = $page->last();

        return [
            'rows' => $page->all(),
            'next_cursor' => $hasMore && $last !== null
                ? $this->cursor->encode($last->accepted_at->toIso8601String(), (int) $last->id, $fingerprint, $this->tenant->tenantId())
                : null,
            'has_more' => $hasMore,
        ];
    }

    private function existing(User $user, TermsVersion $version): ?TermsAcceptance
    {
        return TermsAcceptance::query()
            ->where('user_id', $user->id)
            ->where('terms_version_id', $version->id)
            ->first()
            ?->setRelation('termsVersion', $version);
    }
}
