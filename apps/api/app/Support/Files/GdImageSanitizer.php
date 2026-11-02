<?php

namespace App\Support\Files;

use finfo;
use GdImage;

/**
 * ADR-068 §5.3, `RN-FAM-UNIT-83`: `ImageSanitizer` sobre las extensiones `gd` (con JPEG, PNG y WebP) y `exif` (solo para
 * **leer** la orientación antes de descartarla). Son extensiones de la distribución de PHP, sin paquete de Composer; se
 * envuelven aquí (`RNF-MANT-007`). `imagick` queda descartado (ADR-068 §9.6).
 *
 * Orden, para no gastar memoria en lo que se va a rechazar: tamaño en bytes ⇒ tipo real (`finfo`) ⇒ extensión declarada
 * ⇒ dimensiones con `getimagesize()` **sin decodificar** ⇒ decodificar. La imagen que sale se construye píxel a píxel
 * desde la decodificada, así que no hereda ningún bloque de metadatos (EXIF con geolocalización, XMP, IPTC, perfiles
 * ICC): es una imagen nueva, no el original con algo quitado.
 */
final class GdImageSanitizer implements ImageSanitizer
{
    /** Extensión declarada ⇒ tipo real que debe tener (como `UploadBrandingAsset`). */
    private const EXTENSION_TO_MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    private const MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const JPEG_QUALITY = 85;

    private const WEBP_QUALITY = 85;

    private const PNG_COMPRESSION = 6;

    public function sanitize(string $path, ?string $declaredExtension, ImageUploadPolicy $policy): SanitizedImage
    {
        $size = @filesize($path);

        if ($size === false || $size < 1 || $size > $policy->maxBytes) {
            throw new ImageRejectedException($size === false || $size < 1 ? ImageRejection::UnsupportedType : ImageRejection::TooLarge);
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $mime = $detected === false ? 'application/octet-stream' : $detected;

        if (! in_array($mime, $policy->allowedMimeTypes, true)) {
            throw new ImageRejectedException(ImageRejection::UnsupportedType);
        }

        $extension = $declaredExtension === null ? '' : strtolower($declaredExtension);

        if ($extension !== '' && isset(self::EXTENSION_TO_MIME[$extension]) && self::EXTENSION_TO_MIME[$extension] !== $mime) {
            throw new ImageRejectedException(ImageRejection::TypeMismatch);
        }

        // Dimensiones leyendo solo la cabecera: una bomba de descompresión se rechaza sin decodificar.
        $info = @getimagesize($path);

        if ($info === false || $info[0] < 1 || $info[1] < 1 || $policy->maxInputPixels < $info[0] * $info[1]) {
            throw new ImageRejectedException(ImageRejection::Dimensions);
        }

        if (($info['mime'] ?? null) !== $mime) {
            throw new ImageRejectedException(ImageRejection::TypeMismatch);
        }

        $image = $this->decode($path, $mime);
        $orientation = $mime === 'image/jpeg' ? $this->orientation($path) : 1;
        $image = $this->orient($image, $orientation, $mime);
        $image = $this->shrink($image, $policy->maxSide);

        $contents = $this->encode($image, $mime);
        $recheck = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        // El resultado se vuelve a comprobar: si no es lo que se esperaba, no se guarda.
        if ($recheck !== $mime) {
            throw new ImageRejectedException(ImageRejection::TypeMismatch);
        }

        return new SanitizedImage($contents, $mime, self::MIME_TO_EXTENSION[$mime], strlen($contents));
    }

    private function decode(string $path, string $mime): GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            default => @imagecreatefromwebp($path),
        };

        if (! $image instanceof GdImage) {
            // Cabecera válida pero contenido ilegible (truncado, WebP animado...): no es lo que dice ser.
            throw new ImageRejectedException(ImageRejection::TypeMismatch);
        }

        imagepalettetotruecolor($image);

        return $image;
    }

    /** Orientación EXIF (1-8) de un JPEG; 1 si no la hay o no se puede leer. */
    private function orientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path, 'IFD0');
        $value = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return is_int($value) && $value >= 1 && $value <= 8 ? $value : 1;
    }

    private function orient(GdImage $image, int $orientation, string $mime): GdImage
    {
        // Las transformaciones están expresadas en el sentido de las agujas del reloj; `imagerotate` gira al revés.
        $steps = match ($orientation) {
            2 => [['flip']],
            3 => [['rotate', 180]],
            4 => [['flip_v']],
            5 => [['rotate', -90], ['flip']],
            6 => [['rotate', -90]],
            7 => [['rotate', 90], ['flip']],
            8 => [['rotate', 90]],
            default => [],
        };

        foreach ($steps as $step) {
            if ($step[0] === 'flip') {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            } elseif ($step[0] === 'flip_v') {
                imageflip($image, IMG_FLIP_VERTICAL);
            } else {
                $background = $mime === 'image/jpeg' ? (int) imagecolorallocate($image, 255, 255, 255) : (int) imagecolorallocatealpha($image, 0, 0, 0, 127);
                $rotated = imagerotate($image, (float) $step[1], $background);

                if ($rotated instanceof GdImage) {
                    imagealphablending($rotated, false);
                    imagesavealpha($rotated, true);
                    $image = $rotated;
                }
            }
        }

        return $image;
    }

    private function shrink(GdImage $image, int $maxSide): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= $maxSide) {
            return $image;
        }

        $ratio = $maxSide / $longest;
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));
        $target = imagecreatetruecolor($newWidth, $newHeight);

        if (! $target instanceof GdImage) {
            return $image;
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $target;
    }

    private function encode(GdImage $image, string $mime): string
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();

        match ($mime) {
            'image/jpeg' => imagejpeg($image, null, self::JPEG_QUALITY),
            'image/png' => imagepng($image, null, self::PNG_COMPRESSION),
            default => imagewebp($image, null, self::WEBP_QUALITY),
        };

        return (string) ob_get_clean();
    }
}
