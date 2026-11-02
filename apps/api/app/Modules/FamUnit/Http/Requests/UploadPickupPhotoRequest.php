<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\FamUnit\Application\FamUnitErrors;
use App\Support\Api\ApiException;
use Illuminate\Http\UploadedFile;

/**
 * REQ-FAM-UNIT/api.md §8.2, `PUT /pickup-persons/{person}/photo` (multipart, campo `file`). Solo comprueba que llegue un
 * fichero, y **después** de resolver la persona de la ruta (así una persona inexistente es `404` y no `422`); un fichero que
 * PHP rechazó por tamaño es `413`. El tipo real, el tamaño, las dimensiones y el reprocesado los resuelve
 * `PickupPhotoService` con `ImageSanitizer` (`INV-010`: no es una regla de Laravel sobre un campo aislado).
 */
class UploadPickupPhotoRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function photo(): UploadedFile
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            throw FamUnitErrors::invalid('file', 'fam_unit.validation.required');
        }

        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw ApiException::payloadTooLarge();
        }

        if (! $file->isValid()) {
            throw FamUnitErrors::invalid('file', 'fam_unit.validation.required');
        }

        return $file;
    }
}
