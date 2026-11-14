<?php

namespace App\Support\Audit;

use App\Support\I18n\Locales;

/**
 * ADR-035 §4: único camino por el que un valor de atributo puede llegar a
 * `audit_logs.changes`. Ningún otro código de la aplicación serializa
 * atributos hacia esa columna. Los cinco pasos de evaluación son
 * deterministas y se ejecutan en este orden exacto — no reordenar.
 *
 * Columnas `*_translations` (ADR-062 §5.5): se desglosan por idioma y la clave
 * resultante lleva un punto (`name_translations.es-ES`). **Esa clave no es una
 * columna ni un atributo del modelo**, solo una etiqueta de `changes`; la
 * política del modelo (`Selective`, `$auditRecordedAttributes`) se evalúa
 * siempre por el atributo real `<campo>_translations`, nunca por la clave con
 * punto. Quien consuma `changes` no debe tratar esa clave como nombre de
 * columna.
 */
final class AuditChangeBuilder
{
    /**
     * @param  array<int, string>  $secretPatterns
     */
    public function __construct(
        private readonly array $secretPatterns,
        private readonly int $maxValueLength,
    ) {}

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $rawChanges  atributo => [from, to]
     * @return array<string, array<string, mixed>>
     */
    public function build(Auditable $model, array $rawChanges): array
    {
        $entries = [];

        foreach ($rawChanges as $attribute => [$from, $to]) {
            $perLocale = $this->translationsBreakdown($attribute, $from, $to);

            if ($perLocale === null) {
                $entries[$attribute] = $this->buildEntry($model, $attribute, $from, $to);

                continue;
            }

            // ADR-062 §5.5: una entrada por idioma que cambió, con la política
            // del modelo y el tope aplicados a cada una (los pasos 1-3 miran el
            // atributo `<campo>_translations`; el paso 4, el valor del idioma).
            foreach ($perLocale as $locale => [$localeFrom, $localeTo]) {
                $entries["{$attribute}.{$locale}"] = $this->buildEntry($model, $attribute, $localeFrom, $localeTo);
            }
        }

        return $entries;
    }

    /**
     * ADR-062 §5.5, ADR-035 §4 (ampliación): las columnas cuyo nombre termina
     * en `_translations` se desglosan por idioma. `null` si el atributo no es
     * de ese tipo o alguno de los dos lados no es un objeto JSON legible
     * (entonces se aplica el camino ordinario al valor entero). Acepta el
     * valor como `array` (original con *cast*) o como cadena JSON (lo
     * serializado por `getDirty()`).
     *
     * @return array<string, array{0: mixed, 1: mixed}>|null idioma => [from, to], solo los que cambian
     */
    private function translationsBreakdown(string $attribute, mixed $from, mixed $to): ?array
    {
        if (! str_ends_with($attribute, '_translations')) {
            return null;
        }

        $before = $this->decodeTranslations($from);
        $after = $this->decodeTranslations($to);

        if ($before === null || $after === null) {
            return null;
        }

        $locales = array_values(array_unique([...Locales::VOCABULARY, ...array_keys($before), ...array_keys($after)]));
        $changed = [];

        foreach ($locales as $locale) {
            $localeFrom = $before[$locale] ?? null;
            $localeTo = $after[$locale] ?? null;

            if ($localeFrom !== $localeTo) {
                $changed[(string) $locale] = [$localeFrom, $localeTo];
            }
        }

        return $changed;
    }

    /**
     * @return array<array-key, mixed>|null `[]` para ausente (alta), `null` si no es un objeto legible
     */
    private function decodeTranslations(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntry(Auditable $model, string $attribute, mixed $from, mixed $to): array
    {
        // Paso 1: secreto. Absoluto, no depende de la política del modelo
        // (ADR-034 §3). Sin banderas de vacío: ni siquiera se insinúa si
        // había algo antes.
        if ($this->isSecret($model, $attribute)) {
            return ['redacted' => 'secret'];
        }

        $flags = [
            'from_empty' => $this->isEmpty($from),
            'to_empty' => $this->isEmpty($to),
        ];

        // Paso 2: política Redacted (categoría especial).
        if ($model->auditValuePolicy() === AuditValuePolicy::Redacted) {
            return ['redacted' => 'special', ...$flags];
        }

        // Paso 3: Selective y fuera de la lista de inclusión — falla en
        // cerrado (ADR-035 §2).
        if ($model->auditValuePolicy() === AuditValuePolicy::Selective
            && ! in_array($attribute, $model->auditRecordedAttributes(), true)) {
            return ['redacted' => 'identifier', ...$flags];
        }

        // Paso 4: tope de tamaño, sobre el valor codificado. Nunca se
        // trunca (ADR-035 §5).
        if ($this->exceedsSizeCap($from) || $this->exceedsSizeCap($to)) {
            return ['redacted' => 'oversized', ...$flags];
        }

        // Paso 5: se registra el valor.
        return ['from' => $from, 'to' => $to];
    }

    private function isSecret(Auditable $model, string $attribute): bool
    {
        if (in_array($attribute, $model->auditSecretAttributes(), true)) {
            return true;
        }

        foreach ($this->secretPatterns as $pattern) {
            if (fnmatch($pattern, $attribute, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    private function exceedsSizeCap(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        $encoded = json_encode($value);

        return $encoded !== false && mb_strlen($encoded) > $this->maxValueLength;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
