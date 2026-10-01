<?php

use App\Support\Csv\CsvColumnType;
use App\Support\Csv\CsvWriter;

/**
 * ADR-054 §10, RN-CORE-36/47/48, issue #270 (antes #268).
 */
const CSV_BOM = "\xEF\xBB\xBF";

/**
 * Lee el CSV producido con un lector independiente del escritor.
 *
 * @return list<list<string|null>>
 */
function parseCsv(string $contents): array
{
    expect(str_starts_with($contents, CSV_BOM))->toBeTrue();

    $stream = fopen('php://temp', 'w+');
    fwrite($stream, substr($contents, 3));
    rewind($stream);

    $rows = [];

    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($stream);

    return $rows;
}

function singleTextCell(string $value): string
{
    $csv = new CsvWriter(['c' => CsvColumnType::Text]);
    $csv->writeRow([$value]);

    return parseCsv($csv->finish())[1][0];
}

test('RN-CORE-48 (#270): una celda de texto que empieza por = + - @ tab CR o LF se escribe con apóstrofo', function (string $dangerous): void {
    expect(singleTextCell($dangerous))->toBe("'{$dangerous}");
})->with(['=1+1', '+1', '-1', '@SUM(A1)', "\ttab", "\rcr", "\nlf", '-', '=']);

test('RN-CORE-48 (#270): espacio en blanco inicial seguido de = + - @ también se neutraliza', function (string $dangerous): void {
    expect(singleTextCell($dangerous))->toBe("'{$dangerous}");
})->with([' =1+1', '  +1', ' -1', " \t@x", "\u{00A0}=1", "\u{2003}-2", " \n=1"]);

test('RN-CORE-48 (#270): texto legítimo no se modifica, incluido el que empieza por espacio sin signo', function (string $safe): void {
    expect(singleTextCell($safe))->toBe($safe);
})->with(['normal', 'Álvarez', ' Juan', 'a=b', 'user@example.com', '1-2', "a\n=1", "'ya", '  ']);

test('RN-CORE-48 (#270): una cadena que no es UTF-8 válido se neutraliza (falla en cerrado)', function (): void {
    expect(CsvWriter::neutralize("\xC3\x28x"))->toBe("'\xC3\x28x");
});

test('RN-CORE-48 (#270): los enteros se escriben sin apóstrofo; la cadena "-5" sí lo lleva', function (): void {
    $csv = new CsvWriter(['n' => CsvColumnType::Integer, 't' => CsvColumnType::Text]);
    $csv->writeRow([-1, '-1']);
    $csv->writeRow([-500, '-5']);
    $csv->writeRow([null, null]);

    expect(parseCsv($csv->finish()))->toBe([
        ['n', 't'],
        ['-1', "'-1"],
        ['-500', "'-5"],
        ['', ''],
    ]);
});

test('RN-CORE-48 (#270): el instante se escribe en ISO 8601 con desfase y nunca lleva apóstrofo', function (): void {
    $csv = new CsvWriter(['at' => CsvColumnType::Instant]);
    $csv->writeRow([new DateTimeImmutable('2026-01-31 10:15:00', new DateTimeZone('Europe/Madrid'))]);

    expect(parseCsv($csv->finish())[1][0])->toBe('2026-01-31T09:15:00+00:00');
});

test('RN-CORE-48 (#270): la cabecera también se neutraliza', function (): void {
    $csv = new CsvWriter(['=cmd' => CsvColumnType::Text, ' @x' => CsvColumnType::Text, 'ok' => CsvColumnType::Integer]);

    expect(parseCsv($csv->finish())[0])->toBe(["'=cmd", "' @x", 'ok']);
});

test('RN-CORE-48 (#270): el tipo nunca se infiere; un valor que no es del tipo declarado lanza excepción', function (): void {
    $text = new CsvWriter(['c' => CsvColumnType::Text]);
    expect(fn () => $text->writeRow([-5]))->toThrow(InvalidArgumentException::class);

    $int = new CsvWriter(['c' => CsvColumnType::Integer]);
    expect(fn () => $int->writeRow(['-5']))->toThrow(InvalidArgumentException::class);

    $instant = new CsvWriter(['c' => CsvColumnType::Instant]);
    expect(fn () => $instant->writeRow(['2026-01-01']))->toThrow(InvalidArgumentException::class);

    $two = new CsvWriter(['a' => CsvColumnType::Text, 'b' => CsvColumnType::Text]);
    expect(fn () => $two->writeRow(['solo uno']))->toThrow(InvalidArgumentException::class);
});

test('ADR-054 §10.3 (RN-CORE-47): BOM UTF-8, coma, CRLF, cabecera siempre y comilla RFC 4180 sin carácter de escape', function (): void {
    $csv = new CsvWriter(['a' => CsvColumnType::Text, 'b' => CsvColumnType::Text]);
    $csv->writeRow(['dice "hola", adiós', 'barra\\"comilla']);

    $contents = $csv->finish();

    expect($contents)->toBe(CSV_BOM."a,b\r\n\"dice \"\"hola\"\", adiós\",\"barra\\\"\"comilla\"\r\n");
    expect(parseCsv($contents)[1])->toBe(['dice "hola", adiós', 'barra\\"comilla']);
});

test('RN-CORE-47: un CSV sin filas conserva la cabecera y finish() solo se puede llamar una vez', function (): void {
    $csv = new CsvWriter(['a' => CsvColumnType::Text]);

    expect($csv->finish())->toBe(CSV_BOM."a\r\n");
    expect(fn () => $csv->finish())->toThrow(LogicException::class);
    expect(fn () => $csv->writeRow(['x']))->toThrow(LogicException::class);
});
