<?php

namespace Tests\Support;

use GdImage;

/**
 * ADR-068 §5.3, REQ-FAM-UNIT-005: imágenes sintéticas para las pruebas de la primitiva de ficheros privados y de las
 * fotografías de recogida. Ninguna es una fotografía de una persona (`REQ-SEED-005`): rectángulos de color.
 */
final class ImageFixtures
{
    public static function canvas(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 60, 60));
        imagefilledrectangle($image, 0, 0, (int) ($width / 2), $height, (int) imagecolorallocate($image, 30, 90, 200));

        return $image;
    }

    public static function jpeg(int $width = 40, int $height = 20): string
    {
        ob_start();
        imagejpeg(self::canvas($width, $height), null, 90);

        return (string) ob_get_clean();
    }

    public static function png(int $width = 40, int $height = 20): string
    {
        ob_start();
        imagepng(self::canvas($width, $height));

        return (string) ob_get_clean();
    }

    public static function webp(int $width = 40, int $height = 20): string
    {
        ob_start();
        imagewebp(self::canvas($width, $height), null, 90);

        return (string) ob_get_clean();
    }

    /**
     * Un JPEG con orientación EXIF 6 (girar 90° a la derecha), un bloque GPS con latitud (la geolocalización del
     * `CA-068-01`) y un comentario `COM` reconocible. Es la imagen de un móvil: ancha en los píxeles, vertical al verla.
     */
    public static function jpegWithExifAndGps(int $width = 40, int $height = 20, int $orientation = 6): string
    {
        $jpeg = self::jpeg($width, $height);

        $gpsIfdOffset = 38;
        $ratiosOffset = 68;
        $tiff = "MM\x00\x2A\x00\x00\x00\x08"
            // IFD0: orientación y puntero al IFD de GPS.
            .pack('n', 2)
            .pack('nnNn', 0x0112, 3, 1, $orientation)."\x00\x00"
            .pack('nnNN', 0x8825, 4, 1, $gpsIfdOffset)
            .pack('N', 0)
            // IFD de GPS: GPSLatitudeRef = "N" y GPSLatitude = 40° 25' 0".
            .pack('n', 2)
            ."\x00\x01\x00\x02\x00\x00\x00\x02N\x00\x00\x00"
            .pack('nnNN', 0x0002, 5, 3, $ratiosOffset)
            .pack('N', 0)
            .pack('NNNNNN', 40, 1, 25, 1, 0, 1);

        $exif = "Exif\x00\x00".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
        $comment = 'comentario-secreto-del-movil';
        $com = "\xFF\xFE".pack('n', strlen($comment) + 2).$comment;

        return substr($jpeg, 0, 2).$app1.$com.substr($jpeg, 2);
    }

    /** Un PNG con la cabecera de unas dimensiones enormes y sin datos de imagen: no hay que decodificarlo para rechazarlo. */
    public static function pngClaiming(int $width, int $height): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1A\n"
            .pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr))
            .pack('N', 0).'IEND'.pack('N', crc32('IEND'));
    }

    public static function svg(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script><rect width="10" height="10"/></svg>';
    }

    /** Cabecera `ftyp heic` mínima: el tipo real que detecta `finfo` es `image/heic`. */
    public static function heic(): string
    {
        return "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".str_repeat("\x00", 64);
    }

    /** @return array{0: int, 1: int} ancho y alto de una imagen en memoria */
    public static function size(string $bytes): array
    {
        $info = getimagesizefromstring($bytes);

        return [(int) ($info[0] ?? 0), (int) ($info[1] ?? 0)];
    }
}
