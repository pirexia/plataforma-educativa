<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * api.md §C.5, `GET /mfa-compliance`, `§C.1.1` punto 9, `CA-AUTH-136`.
 * `mfa_required` ausente ⇒ estado real; presente ⇒ vista previa
 * hipotética sin escribir nada.
 */
class IndexMfaComplianceRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string'],
            // Hallazgo propio: la regla `boolean` de Laravel solo acepta
            // true/false/0/1/'0'/'1' — un booleano JSON nativo (cuerpo),
            // nunca la cadena de texto de una query string real
            // (`?mfa_required=true`), que es como llega este parámetro al
            // ser GET. `$request->boolean()` (usado en el controlador) sí
            // es permisivo con "true"/"false"; la validación tiene que
            // aceptar lo mismo que el controlador va a leer.
            'mfa_required' => ['sometimes', Rule::in(['0', '1', 'true', 'false'])],
        ];
    }
}
