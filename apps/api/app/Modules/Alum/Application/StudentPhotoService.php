<?php

namespace App\Modules\Alum\Application;

use App\Models\User;
use App\Modules\Alum\Domain\Models\Student;
use App\Modules\Alum\Domain\Models\StudentPhoto;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\PermissionDecision;
use App\Support\Authorization\ScopedQuery;
use App\Support\Files\ImageRejectedException;
use App\Support\Files\ImageRejection;
use App\Support\Files\ImageSanitizer;
use App\Support\Files\ImageUploadPolicy;
use App\Support\Files\PrivateObjectStore;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * REQ-ALUM/funcional.md §4.6 `RN-ALUM-50` a `-53`, api.md §4 (1.15-c T2b, `OPEN-ALUM-08` = A): la fotografía de un alumno.
 * La imagen se valida y se **reprocesa en el servidor** (`ImageSanitizer`: tipo real JPEG/PNG, límites, sin EXIF; lo
 * recodificado es lo único que se guarda), se escribe en el *bucket* privado bajo el prefijo del centro
 * (`PrivateObjectStore`) y se sirve solo por URL firmada de caducidad corta, tras comprobar el ámbito, **auditada**
 * (`read`) y con límite de lecturas. Síncrono en la petición (`ADR-068 §5.3`).
 *
 * Servicio sancionado por `AR-10` (recurso `alumno`): consulta `StudentPhoto` y `Student`; el acotado por ámbito de la
 * lectura se hace sobre `Student` con `ScopedQuery` y la foto se busca después por `student_id` (los resolutores de
 * `alumno` solo conocen `Student`). Prohibido cualquier tratamiento automatizado de la imagen (reconocimiento facial).
 * La foto no se publica desde este módulo (`RN-ALUM-53`).
 */
final class StudentPhotoService
{
    /** Área del *bucket* (`datos.md §4`). */
    public const AREA = 'alum/student-photos';

    public function __construct(
        private readonly ScopedQuery $scopedQuery,
        private readonly ImageSanitizer $sanitizer,
        private readonly PrivateObjectStore $objects,
        private readonly PhotoReadRateLimit $rateLimit,
        private readonly AuditRecorder $audit,
    ) {}

    /** La política de `RN-ALUM-51`: solo JPEG y PNG; tamaño, píxeles y lado de `config/alum.php`. */
    public static function policy(): ImageUploadPolicy
    {
        return new ImageUploadPolicy(
            StudentPhoto::CONTENT_TYPES,
            (int) config('alum.photo.max_bytes', 5 * 1024 * 1024),
            (int) config('alum.photo.max_input_pixels', 12_000_000),
            (int) config('alum.photo.max_dimension', 1024),
        );
    }

    /**
     * Sube la fotografía (alta o sustitución, `RN-ALUM-50`). Si la imagen se rechaza no se escribe nada; si falla el alta en
     * base de datos, el objeto recién escrito se borra. La anterior se da de baja en la misma transacción.
     *
     * @throws ApiException `422 alum.photo.*`; `503` si el almacenamiento falla
     */
    public function upload(Student $student, UploadedFile $file): StudentPhoto
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw AlumErrors::invalid('file', 'alum.photo.file_required');
        }

        try {
            $image = $this->sanitizer->sanitize($path, pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION) ?: null, self::policy());
        } catch (ImageRejectedException $e) {
            throw AlumErrors::invalid('file', match ($e->reason) {
                ImageRejection::TooLarge => 'alum.photo.too_large',
                ImageRejection::UnsupportedType => 'alum.photo.type_unsupported',
                ImageRejection::TypeMismatch, ImageRejection::Dimensions => 'alum.photo.invalid_image',
            });
        }

        try {
            $key = $this->objects->put(self::AREA, $image->contents, $image->extension);
        } catch (Throwable) {
            // `operacion.md`: el alta y la sustitución fallan sin escribir nada si el almacenamiento no responde.
            throw ApiException::unavailable();
        }

        try {
            return DB::transaction(function () use ($student, $key, $image): StudentPhoto {
                // Bloqueo del alumno (como el patrón de `PickupListService::lockPerson`): serializa subidas concurrentes y
                // la baja del alumno; un alumno dado de baja entre tanto es `404`.
                $locked = Student::query()->whereKey($student->id)->lockForUpdate()->first();

                if ($locked === null) {
                    throw (new ModelNotFoundException)->setModel(Student::class);
                }

                StudentPhoto::query()->where('student_id', $locked->id)->lockForUpdate()->first()?->delete();

                $photo = new StudentPhoto;
                $photo->student_id = $locked->id;
                $photo->object_key = $key;
                $photo->content_type = $image->mimeType;
                $photo->byte_size = $image->byteSize;
                $photo->save();

                return $photo;
            });
        } catch (Throwable $e) {
            $this->objects->delete($key);

            throw $e;
        }
    }

    /** Baja lógica de la fotografía viva (`DELETE …/photo`); el objeto queda para el barrido de huérfanos. Idempotente. */
    public function remove(Student $student): void
    {
        DB::transaction(function () use ($student): void {
            Student::query()->whereKey($student->id)->lockForUpdate()->first();
            StudentPhoto::query()->where('student_id', $student->id)->lockForUpdate()->first()?->delete();
        });
    }

    /**
     * Purga la fotografía viva de un alumno que se da de baja (`datos.md §6`): fila y objeto. Se llama dentro de la
     * transacción de la baja con el alumno ya bloqueado; el objeto se borra al confirmarla (si la baja se deshace, no se pierde).
     */
    public function purgeLive(Student $student): void
    {
        $photo = StudentPhoto::query()->where('student_id', $student->id)->first();

        if ($photo === null) {
            return;
        }

        $key = (string) $photo->object_key;
        $photo->forceDelete();

        DB::afterCommit(function () use ($key): void {
            try {
                $this->objects->delete($key);
            } catch (Throwable) {
                // El objeto huérfano lo recogerá el barrido; la baja del alumno no depende del almacenamiento.
            }
        });
    }

    /**
     * Qué alumnos de la lista tienen fotografía viva (`has_photo` de la ficha).
     *
     * @param  list<int>  $studentIds
     * @return array<int, true>
     */
    public function liveStudentIds(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        $out = [];

        foreach (StudentPhoto::query()->whereIn('student_id', $studentIds)->pluck('student_id') as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    /**
     * La URL firmada de la fotografía (`GET …/photo`): `404` si el alumno no existe, no se alcanza (`RN-ALUM-64`) o no tiene
     * fotografía. **Auditada** (`read`, `RN-ALUM-52`) antes de devolver nada y con límite de lecturas.
     *
     * @return array{url: string, expires_at: string}
     */
    public function signedUrl(string $studentPublicId, PermissionDecision $decision, User $subject): array
    {
        $this->rateLimit->hit(AuditActor::resolveUserId());

        $student = $this->scopedQuery->constrain(Student::query(), 'alumno', $decision, $subject)
            ->where('public_id', $studentPublicId)
            ->firstOrFail();
        $photo = StudentPhoto::query()->where('student_id', $student->id)->first();

        if ($photo === null) {
            throw ApiException::notFound();
        }

        $expiresAt = now()->addMinutes(max(1, (int) config('alum.photo.signed_url_ttl_minutes', 5)));
        $url = $this->objects->temporaryUrl((string) $photo->object_key, $expiresAt);

        // `RN-ALUM-52`: la fila de lectura antes de devolver nada; si falla, la petición falla y la URL no sale.
        $this->audit->recordRead('alum_student_photo', (int) $photo->id, $photo->public_id, ['purpose' => 'gestion', 'entries' => 1, 'student' => $student->public_id]);

        return ['url' => $url, 'expires_at' => $expiresAt->toIso8601ZuluString()];
    }
}
