<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\Role;
use App\Modules\Auth\Domain\MfaComplianceDirectory;
use App\Modules\Auth\Domain\MfaComplianceSummary;
use App\Modules\Auth\Http\Requests\IndexMfaComplianceRequest;
use App\Support\Api\ApiException;
use Illuminate\Routing\Controller;

/**
 * api.md §C.5, `GET /mfa-compliance`, `§C.1.1` punto 9, `CA-AUTH-136`.
 * Vista previa de usuarios afectados y estado real de cumplimiento, con
 * el mismo endpoint. Permiso `mfa.leer` (declarado en `AuthServiceProvider`,
 * `permisos.md §3`): es una consulta de `REQ-AUTH` sobre la obligatoriedad,
 * no una operación sobre el recurso `roles` de `REQ-CORE` (`§C.2.2`).
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
}
