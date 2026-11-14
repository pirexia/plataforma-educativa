<?php

namespace App\Modules\Alum\Http\Controllers;

use App\Modules\Alum\Application\StudentPhotoService;
use App\Modules\Alum\Application\StudentService;
use App\Modules\Alum\Http\Requests\UploadStudentPhotoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REQ-ALUM/api.md §4 (1.15-c T2b, `OPEN-ALUM-08` = A). `PUT` y `DELETE` exigen `alumno.actualizar` con ámbito `todos`; `GET`,
 * `alumno.leer` acotado por ámbito (`RN-ALUM-64`), y devuelve una URL firmada de caducidad corta, nunca el binario, sin
 * almacenamiento en caché y con fila de auditoría `read` (`RN-ALUM-52`).
 */
class StudentPhotosController extends AlumController
{
    public function put(UploadStudentPhotoRequest $request, string $publicId, StudentService $students, StudentPhotoService $photos): JsonResponse
    {
        $student = $students->find($publicId);
        $photos->upload($student, $request->photo());

        return response()->json(['has_photo' => true])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $publicId, StudentPhotoService $photos): JsonResponse
    {
        return response()->json($photos->signedUrl($publicId, $this->decision($request), $this->actor($request)))
            ->header('Cache-Control', 'no-store');
    }

    public function destroy(string $publicId, StudentService $students, StudentPhotoService $photos): Response
    {
        $photos->remove($students->find($publicId));

        return response()->noContent();
    }
}
