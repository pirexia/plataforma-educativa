<?php

/**
 * RN-CORE-47, ADR-054 §10.1, issue #270: `App\Support\Csv\CsvWriter` es la
 * única vía de escribir CSV. Se recorren los *tokens* de PHP (no el texto)
 * para que un comentario que mencione `fputcsv` no dispare falsos positivos.
 */

/**
 * @return list<string> ficheros de `$root` que llaman a `fputcsv` (función o método)
 */
function filesCallingFputcsv(string $root, string $allowed): array
{
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getRealPath() === $allowed) {
            continue;
        }

        if (sourceCallsFputcsv((string) file_get_contents($file->getRealPath()))) {
            $offenders[] = $file->getRealPath();
        }
    }

    return $offenders;
}

function sourceCallsFputcsv(string $source): bool
{
    $tokens = token_get_all($source);

    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && strtolower(ltrim($token[1], '\\')) === 'fputcsv') {
            return true;
        }
    }

    return false;
}

test('RN-CORE-47 (#270): ningún fichero de apps/api/app llama a fputcsv fuera de CsvWriter', function (): void {
    $writer = realpath(dirname(__DIR__, 3).'/app/Support/Csv/CsvWriter.php');

    expect(filesCallingFputcsv(dirname(__DIR__, 3).'/app', $writer))->toBe([]);
});

test('RN-CORE-47 (#270): el detector no es vacuo: ve fputcsv como función, como método y en CsvWriter, e ignora comentarios', function (): void {
    expect(sourceCallsFputcsv('<?php fputcsv($h, []);'))->toBeTrue()
        ->and(sourceCallsFputcsv('<?php $file->fputcsv([]);'))->toBeTrue()
        ->and(sourceCallsFputcsv('<?php \\fputcsv($h, []);'))->toBeTrue()
        ->and(sourceCallsFputcsv("<?php // fputcsv\n/* fputcsv */ \$a = 'fputcsv';"))->toBeFalse();

    // Sin la exclusión, CsvWriter sí aparece: la regla tiene algo que atrapar.
    $writer = realpath(dirname(__DIR__, 3).'/app/Support/Csv/CsvWriter.php');
    expect(filesCallingFputcsv(dirname(__DIR__, 3).'/app', ''))->toContain($writer);
});
