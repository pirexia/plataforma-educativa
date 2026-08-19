<?php

namespace App\Modules\Core\Application;

/**
 * RUX-BRAND-006/RNF-UX-002: fórmula de luminancia relativa y ratio de
 * contraste de WCAG 2.2 (idéntica a WCAG 2.1 en este punto). Se compara
 * `color_primary` contra `color_secondary` — el esquema de
 * tenant_settings solo tiene esos dos colores, así que es el único par
 * expresable; el umbral elegido es 4.5:1 (texto normal, AA), el más
 * estricto de los dos que define la norma, porque no hay garantía de que
 * estos colores se usen solo sobre texto grande.
 */
final class ContrastRatioCalculator
{
    public const REQUIRED_RATIO = 4.5;

    public function ratio(string $hexA, string $hexB): float
    {
        $luminanceA = $this->relativeLuminance($hexA);
        $luminanceB = $this->relativeLuminance($hexB);

        $lighter = max($luminanceA, $luminanceB);
        $darker = min($luminanceA, $luminanceB);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    public function meetsMinimum(string $hexA, string $hexB): bool
    {
        return $this->ratio($hexA, $hexB) >= self::REQUIRED_RATIO;
    }

    private function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = $this->channels($hex);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private function channels(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return array_map(
            fn (int $value): float => $this->linearize($value / 255),
            [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))],
        );
    }

    private function linearize(float $channel): float
    {
        return $channel <= 0.03928
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;
    }
}
