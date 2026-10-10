<?php

namespace App\Modules\Core\Application;

use App\Modules\Core\Domain\DocumentType;

/**
 * Caso límite de funcional.md §6 / OPEN-CORE-06 y RN-CORE-90 a 93
 * (§14.6.4): formato de cada tipo del catálogo cerrado `DocumentType` y,
 * en `dni`/`nie`, dígito de control. `pasaporte` solo exige formato
 * alfanumérico (`[A-Z0-9]`, 1 a 32 caracteres, OPEN-CORE-51 = A): no lleva
 * dígito de control propio. `config('core.documents.validate_check_digit')`
 * decide si el dígito se comprueba (desactivable solo fuera de
 * producción, forzado por CoreServiceProvider); **el formato se exige
 * siempre**.
 *
 * Trabaja sobre el tipo ya resuelto y normaliza el número antes de validar
 * (RN-CORE-92): quien guarda debe guardar `normalize()`.
 */
final class DocumentNumberValidator
{
    private const LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    private const NIE_PREFIX_DIGIT = ['X' => '0', 'Y' => '1', 'Z' => '2'];

    public function normalize(DocumentType $type, string $number): string
    {
        return $type->normalizeNumber($number);
    }

    public function isValidFormat(DocumentType $type, string $number): bool
    {
        $number = $this->normalize($type, $number);

        return match ($type) {
            DocumentType::Dni => (bool) preg_match('/^\d{8}[A-Z]$/', $number),
            DocumentType::Nie => (bool) preg_match('/^[XYZ]\d{7}[A-Z]$/', $number),
            DocumentType::Pasaporte => (bool) preg_match('/^[A-Z0-9]{1,32}$/', $number),
        };
    }

    public function isValid(DocumentType $type, string $number): bool
    {
        if (! $this->isValidFormat($type, $number)) {
            return false;
        }

        if (! config('core.documents.validate_check_digit')) {
            return true;
        }

        $number = $this->normalize($type, $number);

        return match ($type) {
            DocumentType::Dni => $this->isValidDniCheckDigit($number),
            DocumentType::Nie => $this->isValidNieCheckDigit($number),
            DocumentType::Pasaporte => true,
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
