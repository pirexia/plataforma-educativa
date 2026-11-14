<?php

namespace App\Support\Csv;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

/**
 * ADR-054 §10, RN-CORE-47/48 (amplía RN-CORE-36, issues #268/#270): la
 * única vía de escribir CSV en `apps/api`. Ningún otro fichero llama a
 * `fputcsv` (test de arquitectura `tests/Unit/Csv/CsvArchitectureTest`).
 *
 * - Esquema tipado: cada columna declara su `CsvColumnType`; el tipo nunca
 *   se infiere del valor (una cadena `"-5"` y un entero `-5` son cosas
 *   distintas). `null` es una celda vacía en cualquier tipo. Un valor que
 *   no corresponde al tipo declarado lanza `InvalidArgumentException`.
 * - Neutralización solo sobre texto (cabecera incluida): apóstrofo inicial
 *   con la expresión de `ADR-054 §10.2`. Enteros e instantes no se tocan.
 * - Dialecto `ADR-054 §10.3`: coma, UTF-8 con BOM, CRLF, comillas dobles
 *   RFC 4180, sin carácter de escape, cabecera siempre presente.
 */
final class CsvWriter
{
    private const NEUTRALIZE_PATTERN = '/^(?:[=+\-@\t\r\n]|[\s\p{Z}]+[=+\-@])/u';

    /** @var resource|null */
    private $stream;

    /** @var list<CsvColumnType> */
    private array $types;

    /**
     * @param  array<string, CsvColumnType>  $columns  nombre de cabecera => tipo
     */
    public function __construct(array $columns)
    {
        if ($columns === []) {
            throw new InvalidArgumentException('A CSV needs at least one column.');
        }

        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new LogicException('Cannot open a temporary stream for the CSV.');
        }

        $this->stream = $stream;
        $this->types = array_values($columns);

        fwrite($stream, "\xEF\xBB\xBF");
        $this->put(array_map(self::neutralize(...), array_map('strval', array_keys($columns))));
    }

    /**
     * @param  list<string|int|DateTimeInterface|null>  $values  en el orden del esquema
     */
    public function writeRow(array $values): void
    {
        if (count($values) !== count($this->types)) {
            throw new InvalidArgumentException('The row does not match the declared columns.');
        }

        $cells = [];

        foreach ($values as $index => $value) {
            $cells[] = $this->cell($this->types[$index], $value);
        }

        $this->put($cells);
    }

    /** Contenido completo (BOM + cabecera + filas) y cierre del flujo. */
    public function finish(): string
    {
        if ($this->stream === null) {
            throw new LogicException('The CSV is already finished.');
        }

        rewind($this->stream);
        $contents = stream_get_contents($this->stream);
        fclose($this->stream);
        $this->stream = null;

        return $contents === false ? '' : $contents;
    }

    /**
     * Solo cadenas (RN-CORE-48). Falla en cerrado: una cadena que no es
     * UTF-8 válido no se puede evaluar con `/u` (`preg_match` devuelve
     * `false`) y se neutraliza.
     */
    public static function neutralize(string $value): string
    {
        return preg_match(self::NEUTRALIZE_PATTERN, $value) === 0 ? $value : "'{$value}";
    }

    private function cell(CsvColumnType $type, string|int|DateTimeInterface|null $value): string
    {
        if ($value === null) {
            return '';
        }

        return match ($type) {
            CsvColumnType::Text => is_string($value)
                ? self::neutralize($value)
                : throw new InvalidArgumentException('A text column only accepts strings.'),
            CsvColumnType::Integer => is_int($value)
                ? (string) $value
                : throw new InvalidArgumentException('An integer column only accepts integers.'),
            CsvColumnType::Instant => $value instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM)
                : throw new InvalidArgumentException('An instant column only accepts date-time objects.'),
        };
    }

    /**
     * @param  list<string>  $cells
     */
    private function put(array $cells): void
    {
        if ($this->stream === null) {
            throw new LogicException('The CSV is already finished.');
        }

        fputcsv($this->stream, $cells, ',', '"', '', "\r\n");
    }
}
