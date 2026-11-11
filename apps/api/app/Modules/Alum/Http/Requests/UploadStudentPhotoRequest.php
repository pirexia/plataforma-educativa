<?php

namespace App\Modules\Alum\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Alum\Application\AlumErrors;
use Illuminate\Http\UploadedFile;

/**
 * REQ-ALUM/api.md §4, `PUT /students/{public_id}/photo` (multipart, campo `file`). Solo comprueba que llegue un fichero, y
 * **después** de resolver el alumno de la ruta (así un alumno inexistente es `404` y no `422`). Un fichero que PHP rechazó
 * por tamaño es `422 alum.photo.too_large` (`CA-ALUM-056`). El tipo real, el tamaño, las dimensiones y el reprocesado los
 * resuelve `StudentPhotoService` con `ImageSanitizer` (`INV-010`).
 */
class UploadStudentPhotoRequest extends ApiFormRequest
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
            throw AlumErrors::invalid('file', 'alum.photo.file_required');
        }

        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw AlumErrors::invalid('file', 'alum.photo.too_large');
        }

        if (! $file->isValid()) {
            throw AlumErrors::invalid('file', 'alum.photo.file_required');
        }

        return $file;
    }
}
