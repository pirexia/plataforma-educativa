<?php

use App\Support\Files\GdImageSanitizer;
use App\Support\Files\ImageRejectedException;
use App\Support\Files\ImageRejection;
use App\Support\Files\ImageUploadPolicy;
use Tests\Support\ImageFixtures;

// ADR-068 §5.3, CA-068-01, RN-FAM-UNIT-83, CA-FAM-UNIT-121, REQNF-MANT-007: GdImageSanitizer. Tipo real por contenido,
// rechazos antes de decodificar y una imagen nueva sin ningún bloque de metadatos.

/** Escribe `$bytes` en un fichero temporal que se borra al terminar el test. */
function sanitizerTemp(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($path, $bytes);
    register_shutdown_function(static fn () => @unlink($path));

    return $path;
}

function sanitizerPolicy(int $maxBytes = 5 * 1024 * 1024, int $maxPixels = 12_000_000, int $maxSide = 1024): ImageUploadPolicy
{
    return new ImageUploadPolicy(['image/jpeg', 'image/png', 'image/webp'], $maxBytes, $maxPixels, $maxSide);
}

function sanitizerRejection(string $bytes, ?string $extension, ?ImageUploadPolicy $policy = null): ?ImageRejection
{
    try {
        (new GdImageSanitizer)->sanitize(sanitizerTemp($bytes), $extension, $policy ?? sanitizerPolicy());
    } catch (ImageRejectedException $e) {
        return $e->reason;
    }

    return null;
}

/** @return list<int> marcadores de los segmentos de un JPEG hasta el inicio de los datos (SOS) */
function jpegMarkers(string $jpeg): array
{
    $markers = [];
    $offset = 2;

    while ($offset + 4 <= strlen($jpeg)) {
        $marker = ord($jpeg[$offset + 1]);
        $markers[] = $marker;

        if ($marker === 0xDA) {
            break;
        }

        $offset += 2 + unpack('n', substr($jpeg, $offset + 2, 2))[1];
    }

    return $markers;
}

test('CA-068-01 CA-FAM-UNIT-121: un JPEG con EXIF de geolocalización y orientación 6 sale girado y sin ningún bloque de metadatos', function (): void {
    $original = ImageFixtures::jpegWithExifAndGps(40, 20, 6);
    $path = sanitizerTemp($original);

    // El fixture es de verdad lo que dice ser: orientación 6 y coordenadas GPS, comentario incluido.
    $exif = exif_read_data($path, null, true);
    expect($exif['IFD0']['Orientation'])->toBe(6)
        ->and($exif['GPS']['GPSLatitudeRef'])->toBe('N')
        ->and($original)->toContain('comentario-secreto-del-movil')
        ->and(ImageFixtures::size($original))->toBe([40, 20]);

    $result = (new GdImageSanitizer)->sanitize($path, 'jpg', sanitizerPolicy());

    // Girado: 40x20 con orientación 6 es 20x40 al verlo.
    expect(ImageFixtures::size($result->contents))->toBe([20, 40])
        ->and($result->mimeType)->toBe('image/jpeg')
        ->and($result->extension)->toBe('jpg')
        ->and($result->byteSize)->toBe(strlen($result->contents));

    // En el sentido correcto: la mitad izquierda (azul) de la imagen original queda arriba al girar 90° a la derecha.
    $rotated = imagecreatefromstring($result->contents);
    $top = imagecolorsforindex($rotated, imagecolorat($rotated, 10, 5));
    $bottom = imagecolorsforindex($rotated, imagecolorat($rotated, 10, 35));
    expect($top['blue'])->toBeGreaterThan($top['red'])->and($bottom['red'])->toBeGreaterThan($bottom['blue']);

    // Sin metadatos: ni APP1 (EXIF/XMP), ni APP13 (IPTC), ni APP2 (perfil ICC), ni el comentario del original (el único
    // COM que queda es el que escribe el propio codificador de `gd`).
    $markers = jpegMarkers($result->contents);
    expect($markers)->not->toContain(0xE1)->not->toContain(0xED)->not->toContain(0xE2)
        ->and($result->contents)->not->toContain('comentario-secreto-del-movil')->not->toContain('Exif');

    $after = sanitizerTemp($result->contents);
    $reread = @exif_read_data($after, null, true);
    expect($reread === false || (! isset($reread['GPS']) && ! isset($reread['IFD0']['Orientation'])))->toBeTrue();
});

test('CA-068-01: PNG y WebP salen recodificados en su formato, y una imagen mayor que el lado máximo se reduce', function (): void {
    $sanitizer = new GdImageSanitizer;

    foreach ([['png', ImageFixtures::png(300, 200), 'image/png'], ['webp', ImageFixtures::webp(300, 200), 'image/webp'], ['jpeg', ImageFixtures::jpeg(300, 200), 'image/jpeg']] as [$extension, $bytes, $mime]) {
        $result = $sanitizer->sanitize(sanitizerTemp($bytes), $extension, sanitizerPolicy(maxSide: 100));

        [$width, $height] = ImageFixtures::size($result->contents);
        expect($result->mimeType)->toBe($mime)->and([$width, $height])->toBe([100, 67]);
    }

    // Por debajo del lado máximo no se amplía.
    $small = $sanitizer->sanitize(sanitizerTemp(ImageFixtures::png(30, 20)), 'png', sanitizerPolicy());
    expect(ImageFixtures::size($small->contents))->toBe([30, 20]);
});

test('CA-068-01: una imagen que declara más píxeles que el máximo se rechaza sin decodificar', function (): void {
    // Cabecera de 20000 x 20000 px (400 MP) sin datos de imagen: si se intentara decodificar, agotaría la memoria.
    $bomb = ImageFixtures::pngClaiming(20000, 20000);

    expect(sanitizerRejection($bomb, 'png'))->toBe(ImageRejection::Dimensions)
        // Y un tope de píxeles bajo rechaza una imagen legible.
        ->and(sanitizerRejection(ImageFixtures::png(100, 100), 'png', sanitizerPolicy(maxPixels: 5000)))->toBe(ImageRejection::Dimensions);
});

test('CA-068-01 CA-FAM-UNIT-121: un SVG, un HEIC y un PNG con extensión .jpg se rechazan, cada uno por su motivo', function (): void {
    expect(sanitizerRejection(ImageFixtures::svg(), 'svg'))->toBe(ImageRejection::UnsupportedType)
        // Un SVG que se disfraza de .png sigue siendo SVG por contenido.
        ->and(sanitizerRejection(ImageFixtures::svg(), 'png'))->toBe(ImageRejection::UnsupportedType)
        ->and(sanitizerRejection(ImageFixtures::heic(), 'heic'))->toBe(ImageRejection::UnsupportedType)
        ->and(sanitizerRejection(ImageFixtures::png(), 'jpg'))->toBe(ImageRejection::TypeMismatch)
        ->and(sanitizerRejection(ImageFixtures::jpeg(), 'png'))->toBe(ImageRejection::TypeMismatch)
        // Texto plano y fichero vacío.
        ->and(sanitizerRejection('no soy una imagen', 'jpg'))->toBe(ImageRejection::UnsupportedType)
        ->and(sanitizerRejection('', 'jpg'))->toBe(ImageRejection::UnsupportedType);
});

test('CA-FAM-UNIT-121: un fichero por encima del máximo de bytes se rechaza antes que nada, aunque sea de un tipo no admitido', function (): void {
    $big = ImageFixtures::png(200, 200);

    expect(sanitizerRejection($big, 'png', sanitizerPolicy(maxBytes: 100)))->toBe(ImageRejection::TooLarge)
        ->and(sanitizerRejection(str_repeat(ImageFixtures::svg(), 30), 'svg', sanitizerPolicy(maxBytes: 100)))->toBe(ImageRejection::TooLarge);
});

test('ADR-068 §5.3: una extensión desconocida o ausente no impide una imagen legítima; un contenido truncado se rechaza', function (): void {
    $sanitizer = new GdImageSanitizer;

    expect($sanitizer->sanitize(sanitizerTemp(ImageFixtures::png()), null, sanitizerPolicy())->mimeType)->toBe('image/png')
        ->and($sanitizer->sanitize(sanitizerTemp(ImageFixtures::png()), 'bin', sanitizerPolicy())->mimeType)->toBe('image/png');

    $png = ImageFixtures::png(200, 200);
    expect(sanitizerRejection(substr($png, 0, 80), 'png'))->not->toBeNull();
});

test('ADR-068 §5.3: ImageUploadPolicy rechaza tipos que no sabe tratar (nunca SVG) y límites no positivos', function (): void {
    expect(fn () => new ImageUploadPolicy(['image/svg+xml'], 1, 1, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ImageUploadPolicy([], 1, 1, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ImageUploadPolicy(['image/png'], 0, 1, 1))->toThrow(InvalidArgumentException::class);
});
