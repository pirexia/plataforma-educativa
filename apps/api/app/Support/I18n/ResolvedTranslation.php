<?php

namespace App\Support\I18n;

/**
 * ADR-062 §5.3: el texto resuelto y el idioma del que sale. El idioma
 * permite marcar `lang` en la presentación cuando difiere del pedido
 * (WCAG 2.2 AA 3.1.2, ADR-062 §5.4 punto 1).
 */
final readonly class ResolvedTranslation
{
    public function __construct(
        public string $text,
        public string $locale,
    ) {}
}
