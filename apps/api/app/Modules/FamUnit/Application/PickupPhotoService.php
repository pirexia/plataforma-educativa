<?php

namespace App\Modules\FamUnit\Application;

use App\Models\User;
use App\Modules\Core\Domain\PersonRef;
use App\Modules\FamUnit\Domain\Models\PickupPhoto;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\PermissionDecision;
use App\Support\Files\ImageRejectedException;
use App\Support\Files\ImageRejection;
use App\Support\Files\ImageSanitizer;
use App\Support\Files\ImageUploadPolicy;
use App\Support\Files\PrivateObjectStore;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * REQ-FAM-UNIT/funcional.md §21.6 `RN-FAM-UNIT-83`, `-84`, `-92`, `-93`; api.md §8.2 (1.14b-a, `ADR-068 §5.3`): la fotografía
 * de recogida de una persona. La imagen se valida y se **reprocesa en el servidor** (`ImageSanitizer`: tipo real, límites,
 * sin metadatos; lo recodificado es lo único que se guarda), se escribe en el *bucket* privado bajo el prefijo del centro
 * (`PrivateObjectStore`) y se sirve solo por URL firmada de caducidad corta tras comprobar permiso, **auditada** y con límite.
 * Síncrono en la petición (`ADR-068 §5.3`): el trabajo está acotado por la política.
 *
 * Prohibido cualquier tratamiento automatizado de la imagen (reconocimiento facial, comparación): la verificación la hace
 * una persona (`RN-FAM-UNIT-83`). Las consultas de `PickupPhoto` van por `PickupListService` (`AR-10`).
 */
final class PickupPhotoService
{
    /** Área del *bucket* (`datos.md §8.1`). */
    public const AREA = 'fam-unit/pickup-photos';

    public function __construct(
        private readonly PickupListService $list,
        private readonly ImageSanitizer $sanitizer,
        private readonly PrivateObjectStore $objects,
        private readonly PickupReadRateLimit $rateLimit,
        private readonly AuditRecorder $audit,
    ) {}

    /** La política de `RN-FAM-UNIT-83`: 5 MB, 1024 px de lado y tipos JPEG, PNG y WebP por defecto. */
    public static function policy(): ImageUploadPolicy
    {
        return new ImageUploadPolicy(
            ImageUploadPolicy::SUPPORTED_TYPES,
            (int) config('fam_unit.pickup.photo.max_bytes', 5 * 1024 * 1024),
            (int) config('fam_unit.pickup.photo.max_input_pixels', 12_000_000),
            (int) config('fam_unit.pickup.photo.max_dimension', 1024),
        );
    }

    /**
     * Sube la fotografía (alta o sustitución). Si la imagen se rechaza no se escribe nada; si falla el alta en base de datos,
     * el objeto recién escrito se borra.
     *
     * @throws ApiException `413`, `415` o `422 fam_unit.photo.type_mismatch` / `fam_unit.photo.dimensions`; `503` si el almacenamiento falla
     */
    public function upload(PersonRef $person, UploadedFile $file): PickupPhoto
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw FamUnitErrors::invalid('file', 'fam_unit.validation.required');
        }

        try {
            $image = $this->sanitizer->sanitize($path, pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION) ?: null, self::policy());
        } catch (ImageRejectedException $e) {
            throw match ($e->reason) {
                ImageRejection::TooLarge => ApiException::payloadTooLarge(),
                ImageRejection::UnsupportedType => ApiException::unsupportedMediaType(),
                ImageRejection::TypeMismatch => FamUnitErrors::invalid('file', 'fam_unit.photo.type_mismatch'),
                ImageRejection::Dimensions => FamUnitErrors::invalid('file', 'fam_unit.photo.dimensions'),
            };
        }

        try {
            $key = $this->objects->put(self::AREA, $image->contents, $image->extension);
        } catch (Throwable) {
            // `operacion.md §12.2`: el alta y sustitución de fotos falla sin escribir nada si el almacenamiento no responde.
            throw ApiException::unavailable();
        }

        try {
            return $this->list->replacePhoto($person->id, $key, $image->mimeType, $image->byteSize)['photo'];
        } catch (Throwable $e) {
            $this->objects->delete($key);

            throw $e;
        }
    }

    /** Baja de la fotografía (`DELETE …/photo`): baja lógica; el objeto lo purga el barrido. Idempotente. */
    public function remove(PersonRef $person): void
    {
        $this->list->removePhoto($person->id);
    }

    /**
     * La URL firmada de la fotografía de una persona (`GET …/photo`): **auditada** (`read`, sujeto la persona de la foto) y con
     * límite. `404` si la persona no tiene fotografía o el sujeto no puede verla: con ámbito `unidad_familiar` la fotografía
     * de un tercero no se sirve a la familia (`OPEN-FAM-UNIT-43`, abierta: fallo en cerrado).
     *
     * @return array{url: string, expires_at: string}
     */
    public function signedUrl(PersonRef $person, PermissionDecision $decision, User $subject): array
    {
        $this->rateLimit->hit(AuditActor::resolveUserId());

        $photo = $this->list->scopedLivePhoto($person->id, $decision, $subject);

        if ($photo === null) {
            throw ApiException::notFound();
        }

        $expiresAt = now()->addMinutes(max(1, (int) config('fam_unit.pickup.photo.signed_url_ttl_minutes', 5)));
        $url = $this->objects->temporaryUrl((string) $photo->object_key, $expiresAt);

        // `ADR-068 §5.4`: la fila de lectura antes de devolver nada; si falla, la petición falla y la URL no sale.
        $this->audit->recordRead('person', $person->id, $person->publicId, ['purpose' => 'gestion', 'entries' => 1, 'photos' => 1]);

        return ['url' => $url, 'expires_at' => $expiresAt->toIso8601ZuluString()];
    }
}
