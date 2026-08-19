<?php

namespace App\Modules\Core\Application;

/**
 * api.md §7. Esquema de columnas fijo, sin mapeo visual (funcional.md
 * §1.10). Separador `;` o `,` autodetectado, UTF-8 con o sin BOM. Sin
 * dependencia nueva: `str_getcsv` de PHP.
 */
final class UserImportCsvReader
{
    /**
     * @var list<string>
     */
    public const EXPECTED_HEADER = [
        'email', 'given_name', 'family_name_1', 'family_name_2',
        'document_type', 'document_number', 'birth_date',
        'contact_email', 'contact_phone', 'locale', 'roles',
    ];

    /**
     * @return array{header_valid: bool, rows: list<array{line: int, data: array<string, ?string>}>}
     */
    public function parse(string $content): array
    {
        $content = $this->stripBom($content);
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];

        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }

        if ($lines === []) {
            return ['header_valid' => false, 'rows' => []];
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $header = array_map(trim(...), str_getcsv($lines[0], $delimiter));

        if ($header !== self::EXPECTED_HEADER) {
            return ['header_valid' => false, 'rows' => []];
        }

        $rows = [];

        foreach (array_slice($lines, 1) as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, $delimiter);
            $row = [];

            foreach (self::EXPECTED_HEADER as $position => $column) {
                $value = $values[$position] ?? null;
                $value = is_string($value) ? trim($value) : $value;
                $row[$column] = $value === '' || $value === null ? null : $value;
            }

            // +2: la línea 1 es la cabecera, y los índices humanos empiezan en 1.
            $rows[] = ['line' => $index + 2, 'data' => $row];
        }

        return ['header_valid' => true, 'rows' => $rows];
    }

    private function detectDelimiter(string $headerLine): string
    {
        return substr_count($headerLine, ';') >= substr_count($headerLine, ',') ? ';' : ',';
    }

    private function stripBom(string $content): string
    {
        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }
}
