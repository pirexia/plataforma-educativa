<?php

namespace App\Modules\Core\Application;

/**
 * Caso límite de funcional.md §6 / OPEN-CORE-06: formato de DNI/NIE
 * (único par documentado con algoritmo de dígito de control en la
 * especificación; otros `document_type` solo se validan por no estar
 * vacíos, sin lista cerrada — el esquema los deja como `text` libre a
 * propósito, ADR-034 §1). `config('core.documents.validate_check_digit')`
 * decide si el dígito se comprueba (desactivable solo fuera de
 * producción, forzado por CoreServiceProvider).
 */
final class DocumentNumberValidator
{
    private const LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    private const NIE_PREFIX_DIGIT = ['X' => '0', 'Y' => '1', 'Z' => '2'];

    public function isValidFormat(string $documentType, string $number): bool
    {
        $number = strtoupper(trim($number));

        return match (strtoupper($documentType)) {
            'DNI' => (bool) preg_match('/^\d{8}[A-Z]$/', $number),
            'NIE' => (bool) preg_match('/^[XYZ]\d{7}[A-Z]$/', $number),
            default => $number !== '',
        };
    }

    public function isValid(string $documentType, string $number): bool
    {
        if (! $this->isValidFormat($documentType, $number)) {
            return false;
        }

        if (! config('core.documents.validate_check_digit')) {
            return true;
        }

        return match (strtoupper($documentType)) {
            'DNI' => $this->isValidDniCheckDigit($number),
            'NIE' => $this->isValidNieCheckDigit($number),
            default => true,
        };
    }

    private function isValidDniCheckDigit(string $number): bool
    {
        $digits = (int) substr($number, 0, 8);
        $letter = substr($number, 8, 1);

        return self::LETTERS[$digits % 23] === $letter;
    }

    private function isValidNieCheckDigit(string $number): bool
    {
        $prefix = self::NIE_PREFIX_DIGIT[substr($number, 0, 1)] ?? null;

        if ($prefix === null) {
            return false;
        }

        $digits = (int) ($prefix.substr($number, 1, 7));
        $letter = substr($number, 8, 1);

        return self::LETTERS[$digits % 23] === $letter;
    }
}
