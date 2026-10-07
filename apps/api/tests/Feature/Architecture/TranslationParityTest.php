<?php

use Illuminate\Support\Arr;

pest()->group('arch');

// ADR-056 AR-12, CA-056-16, INV-009, INV-015: para cada `lang/es/*.php`
// existen los gemelos `en`, `de` y `fr` con exactamente el mismo conjunto de
// claves aplanadas y ningún valor vacío. Generaliza `CA-AUTH-233` y
// `CA-BO-073` (que siguen en su sitio y cubren solo `auth.php` y `bo.php`).

/**
 * @return array{checked: int, violations: list<string>}
 */
function translationParityViolations(string $langPath): array
{
    $violations = [];
    $files = glob($langPath.'/es/*.php') ?: [];
    sort($files);

    $emptyValues = static function (string $label, array $flat) use (&$violations): void {
        foreach ($flat as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                $violations[] = "{$label}: literal vacío en «{$key}»";
            }
        }
    };

    foreach ($files as $file) {
        $name = basename($file);
        $reference = Arr::dot(require $file);
        $emptyValues("es/{$name}", $reference);

        foreach (['en', 'de', 'fr'] as $locale) {
            $twin = "{$langPath}/{$locale}/{$name}";

            if (! is_file($twin)) {
                $violations[] = "{$locale}/{$name}: falta el fichero gemelo";

                continue;
            }

            $flat = Arr::dot(require $twin);
            $missing = array_keys(array_diff_key($reference, $flat));
            $extra = array_keys(array_diff_key($flat, $reference));

            if ($missing !== []) {
                $violations[] = "{$locale}/{$name}: faltan claves de es: ".implode(', ', $missing);
            }
            if ($extra !== []) {
                $violations[] = "{$locale}/{$name}: claves que no están en es: ".implode(', ', $extra);
            }

            $emptyValues("{$locale}/{$name}", $flat);
        }
    }

    return ['checked' => count($files), 'violations' => $violations];
}

test('AR-12 CA-056-16 INV-009: cada lang/es/*.php tiene gemelo en en, de y fr con las mismas claves y sin literales vacíos', function (): void {
    $result = translationParityViolations(lang_path());

    expect($result['checked'])->toBeGreaterThan(0)
        ->and($result['violations'])->toBe([], implode("\n", $result['violations']));
});

test('AR-12 CA-056-16 control negativo: la comprobación detecta fichero ausente, clave que falta, clave de más y literal vacío', function (): void {
    $dir = sys_get_temp_dir().'/ar12_'.bin2hex(random_bytes(4));

    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        mkdir("{$dir}/{$locale}", 0777, true);
    }

    $write = static fn (string $locale, string $file, array $data) => file_put_contents(
        "{$dir}/{$locale}/{$file}.php",
        '<?php return '.var_export($data, true).';',
    );

    try {
        // ok.php: paridad correcta en los cuatro idiomas.
        foreach (['es', 'en', 'de', 'fr'] as $locale) {
            $write($locale, 'ok', ['a' => 'x', 'b' => ['c' => 'y']]);
        }

        // roto.php: es con un literal vacío; en completo; de sin la clave b.c y con
        // una de más; fr sin fichero.
        $write('es', 'roto', ['a' => 'x', 'b' => ['c' => 'y'], 'vacio' => '  ']);
        $write('en', 'roto', ['a' => 'x', 'b' => ['c' => 'y'], 'vacio' => 'z']);
        $write('de', 'roto', ['a' => 'x', 'sobra' => 'q', 'vacio' => 'z']);
        // fr/roto.php: ausente a propósito.

        $result = translationParityViolations($dir);

        expect($result['checked'])->toBe(2)
            ->and($result['violations'])->toBe([
                'es/roto.php: literal vacío en «vacio»',
                'de/roto.php: faltan claves de es: b.c',
                'de/roto.php: claves que no están en es: sobra',
                'fr/roto.php: falta el fichero gemelo',
            ]);
    } finally {
        foreach (glob("{$dir}/*/*.php") ?: [] as $f) {
            unlink($f);
        }
        foreach (['es', 'en', 'de', 'fr'] as $locale) {
            rmdir("{$dir}/{$locale}");
        }
        rmdir($dir);
    }
});
