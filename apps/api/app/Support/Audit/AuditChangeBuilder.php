<?php

namespace App\Support\Audit;

/**
 * ADR-035 §4: único camino por el que un valor de atributo puede llegar a
 * `audit_logs.changes`. Ningún otro código de la aplicación serializa
 * atributos hacia esa columna. Los cinco pasos de evaluación son
 * deterministas y se ejecutan en este orden exacto — no reordenar.
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
            $entries[$attribute] = $this->buildEntry($model, $attribute, $from, $to);
        }

        return $entries;
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
