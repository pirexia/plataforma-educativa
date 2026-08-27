<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\Role;
use App\Modules\Auth\Domain\MfaComplianceDirectory;
use App\Modules\Auth\Domain\MfaComplianceSummary;
use App\Modules\Auth\Http\Requests\IndexMfaComplianceRequest;
use App\Modules\Auth\Http\Requests\IndexMfaComplianceUsersRequest;
use App\Modules\Auth\Http\Resources\MfaComplianceUserResource;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;

/**
 * api.md §C.5, `GET /mfa-compliance`, `§C.1.1` punto 9, `CA-AUTH-136`.
 * Vista previa de usuarios afectados y estado real de cumplimiento, con
 * el mismo endpoint. Permiso `mfa.leer` (declarado en `AuthServiceProvider`,
 * `permisos.md §3`): es una consulta de `REQ-AUTH` sobre la obligatoriedad,
 * no una operación sobre el recurso `roles` de `REQ-CORE` (`§C.2.2`).
 *
 * `users()` — `GET /mfa-compliance/users` — es el listado individualizado,
 * restaurado en 1.3 el 2026-08-27 (decisión del usuario: un subagente
 * anterior lo había recortado a `1.3b` sin autorización, agrupándolo por
 * error con la excepción temporal que sí se movió correctamente).
 */
class MfaComplianceController extends Controller
{
    public function __construct(
        private readonly MfaComplianceDirectory $directory,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function index(IndexMfaComplianceRequest $request): array
    {
        $role = Role::query()->where('public_id', $request->string('role')->value())->first();

        if ($role === null) {
            throw ApiException::notFound();
        }

        $summary = $request->has('mfa_required')
            ? $this->directory->preview($role, $request->boolean('mfa_required'))
            : $this->directory->current($role);

        return $this->present($summary);
    }

    public function users(IndexMfaComplianceUsersRequest $request): JsonResponse
    {
        $rows = $this->directory->listUsers($this->resolveStates($request));

        $perPage = $request->integer('per_page', 25);
        $page = $request->integer('page', 1);

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
        );

        return PagePaginatedResponse::make($paginator, MfaComplianceUserResource::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MfaComplianceSummary $summary): array
    {
        return [
            'role' => [
                'public_id' => $summary->rolePublicId,
                'code' => $summary->roleCode,
            ],
            'mfa_required' => $summary->mfaRequired,
            'preview' => $summary->preview,
            'users_total' => $summary->usersTotal,
            'users_enrolled' => $summary->usersEnrolled,
            'users_obligated' => $summary->usersObligated,
            'users_in_grace' => $summary->usersInGrace,
            'users_enforced' => $summary->usersEnforced,
            'users_exempt' => $summary->usersExempt,
        ];
    }

    /**
     * `obligated` es un alias sobre `pending`+`past_deadline`
     * (`MfaComplianceUserRow`, docblock de `MfaComplianceDirectory`):
     * el dominio no tiene un tercer estado propio con ese nombre.
     *
     * @return list<string>
     */
    private function resolveStates(IndexMfaComplianceUsersRequest $request): array
    {
        if (! $request->filled('state')) {
            return [];
        }

        $states = [];

        foreach (explode(',', $request->string('state')->value()) as $token) {
            if ($token === 'obligated') {
                $states[] = 'pending';
                $states[] = 'past_deadline';

                continue;
            }

            $states[] = $token;
        }

        return array_values(array_unique($states));
    }
}
