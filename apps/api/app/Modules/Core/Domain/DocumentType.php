<?php

namespace App\Modules\Core\Domain;

/**
 * REQ-CORE-003, RN-CORE-90 (funcional.md §14.6.4, issue #292). Catálogo
 * cerrado de tipos de documento de identidad de `people.document_type`.
 * Es la **única fuente de verdad** en servidor: de ella se derivan la regla
 * de validación, el `enum` de OpenAPI y, en la entrega siguiente
 * (OPEN-CORE-52 = A), el `CHECK` de base de datos. Interfaz pública del
 * módulo (INV-007): `REQ-ALUM`, `REQ-FAM-UNIT` y `REQ-RRHH` la consumen
 * por aquí, nunca copiando la lista ni leyendo la tabla.
 *
 * Códigos en minúsculas (OPEN-CORE-47 = A), sin `otro` (OPEN-CORE-46 = B).
 * El catálogo es de plataforma, igual para todos los centros.
 */
enum DocumentType: string
{
    case Dni = 'dni';
    case Nie = 'nie';
    case Pasaporte = 'pasaporte';

    /**
     * @return list<string> códigos canónicos, en el orden de declaración
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Grafía exacta (la API es un cliente técnico, OPEN-CORE-50 = A).
     */
    public static function fromCode(?string $code): ?self
    {
        return $code === null ? null : self::tryFrom($code);
    }

    /**
     * Grafía tolerante de la importación CSV (OPEN-CORE-50 = A): sin
     * distinguir mayúsculas y con espacios alrededor.
     */
    public static function fromLooseCode(?string $code): ?self
    {
        return $code === null ? null : self::tryFrom(strtolower(trim($code)));
    }

    /**
     * RN-CORE-92 / OPEN-CORE-49 = A: forma canónica del número. Sin espacios
     * al principio ni al final y en mayúsculas en todos los tipos; además,
     * en `dni` y `nie`, sin espacios ni guiones intermedios. Los puntos no
     * se quitan: `12.345.678-Z` es un error de formato.
     */
    public function normalizeNumber(string $number): string
    {
        $number = strtoupper(trim($number));

        return match ($this) {
            self::Dni, self::Nie => (string) preg_replace('/[\s-]+/', '', $number),
            self::Pasaporte => $number,
        };
    }
}
