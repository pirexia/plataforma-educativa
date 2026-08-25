<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Application\PasswordChangeService;
use App\Modules\Auth\Http\Requests\StorePasswordChangeRequest;
use App\Support\Api\ApiException;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * funcional.md §4.8, `POST /auth/password-changes`. Sin permiso: se
 * autoriza por identidad del portador de la cookie.
 */
class PasswordChangesController extends Controller
{
    public function __construct(
        private readonly PasswordChangeService $service,
    ) {}

    public function store(StorePasswordChangeRequest $request): Response
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw ApiException::unauthenticated();
        }

        $this->service->change(
            $user,
            $request->string('current_password')->value(),
            $request->string('password')->value(),
            $request->session()->getId(),
        );

        return response()->noContent();
    }
}
