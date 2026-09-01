<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Application\IdentityUnlinkingService;
use App\Modules\Auth\Domain\DestinationMasker;
use App\Modules\Auth\Domain\Models\UserIdentity;
use App\Modules\Auth\Http\Requests\DestroyIdentityRequest;
use App\Support\Api\ApiException;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * `api.md §E.5`. Autoservicio puro: sin permiso, por identidad del
 * portador de la cookie (`permisos.md §E.2`). Funciona con
 * `AUTH_OAUTH_DRIVER=none` — gestionar un vínculo que ya existe no
 * necesita proveedor (`operacion.md §E.1`): un centro que apague Google
 * tiene que dejar que sus usuarios vean y retiren los vínculos que ya
 * tenían.
 */
class IdentitiesController extends Controller
{
    public function __construct(
        private readonly IdentityUnlinkingService $unlinking,
    ) {}

    /**
     * `GET /auth/identities`. Mis cuentas externas vinculadas.
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        $user = $this->authenticatedUser();

        $identities = UserIdentity::query()->where('user_id', $user->id)->with('identityProvider')->get();

        return [
            'data' => $identities->map(fn (UserIdentity $identity): array => array_filter([
                'public_id' => $identity->public_id,
                'provider' => $identity->provider,
                // api.md §F.6, CA-AUTH-303: solo cuando hay proveedor
                // catalogado detrás — nunca el subject (permisos.md §E.5).
                'provider_display_name' => $identity->identityProvider?->display_name,
                // api.md §E.5: enmascarado con el mismo DestinationMasker
                // que 1.3b introdujo (§D.4.5) — nunca el correo entero,
                // ni siquiera al propio titular.
                'email_at_link' => DestinationMasker::maskEmail($identity->email_at_link ?? ''),
                'link_method' => $identity->link_method->value,
                'linked_at' => $identity->linked_at->toISOString(),
                'last_login_at' => $identity->last_login_at?->toISOString(),
            ], static fn (mixed $value): bool => $value !== null))->values()->all(),
            'meta' => ['total' => $identities->count()],
        ];
    }

    /**
     * `DELETE /auth/identities/{public_id}`. Desvincular.
     */
    public function destroy(DestroyIdentityRequest $request, string $publicId): Response
    {
        $user = $this->authenticatedUser();

        $this->unlinking->unlink($user, $publicId, $request->string('current_password')->value());

        return response()->noContent();
    }

    private function authenticatedUser(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw ApiException::unauthenticated();
        }

        return $user;
    }
}
