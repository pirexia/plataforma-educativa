<?php

namespace App\Support\Audit;

/**
 * ADR-035 §2: intensidad de registro de valores por modelo, declarada y
 * sin valor por defecto — un modelo sin política no es Auditable.
 */
enum AuditValuePolicy: string
{
    /** Sin ningún dato personal: se registra el valor de todos los atributos. */
    case Full = 'full';

    /** Registra el valor solo de los atributos en auditRecordedAttributes(); el resto se redacta como `identifier`. */
    case Selective = 'selective';

    /** Categoría especial (salud, NEAE, convivencia): nunca se registra ningún valor, solo qué atributo cambió. */
    case Redacted = 'redacted';
}
