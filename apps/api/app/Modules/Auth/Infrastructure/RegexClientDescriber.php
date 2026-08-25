<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\ClientDescriber;
use App\Modules\Auth\Domain\ClientDescription;
use App\Modules\Auth\Domain\ClientDeviceType;

/**
 * funcional.md §B.6.4, OPEN-AUTH-17 (aprobada 2026-08-25: análisis propio,
 * sin dependencia externa, `CLAUDE.md §1`). Media docena de expresiones
 * regulares, deliberadamente pobre: un `User-Agent` no reconocido produce
 * `desconocido` y no rompe nada (`CA-AUTH-097`) — el resultado no
 * participa nunca en la decisión de `RN-AUTH-46`, así que el coste de
 * equivocarse es una etiqueta fea, no un aviso de más ni de menos.
 */
final class RegexClientDescriber implements ClientDescriber
{
    public function describe(?string $userAgent): ClientDescription
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return new ClientDescription('desconocido', 'desconocido', ClientDeviceType::Desconocido);
        }

        return new ClientDescription(
            $this->browser($userAgent),
            $this->platform($userAgent),
            $this->deviceType($userAgent),
        );
    }

    private function browser(string $userAgent): string
    {
        return match (true) {
            preg_match('/Edg\//i', $userAgent) === 1 => 'Edge',
            preg_match('/OPR\/|Opera/i', $userAgent) === 1 => 'Opera',
            preg_match('/Firefox\//i', $userAgent) === 1 => 'Firefox',
            preg_match('/Chrome\//i', $userAgent) === 1 => 'Chrome',
            preg_match('/Version\/.*Safari\//i', $userAgent) === 1 => 'Safari',
            default => 'desconocido',
        };
    }

    private function platform(string $userAgent): string
    {
        return match (true) {
            preg_match('/Windows NT/i', $userAgent) === 1 => 'Windows',
            preg_match('/Mac OS X/i', $userAgent) === 1 => 'macOS',
            preg_match('/Android/i', $userAgent) === 1 => 'Android',
            preg_match('/iPhone OS|CPU OS/i', $userAgent) === 1 => 'iOS',
            preg_match('/Linux/i', $userAgent) === 1 => 'Linux',
            default => 'desconocido',
        };
    }

    private function deviceType(string $userAgent): ClientDeviceType
    {
        if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit|bingpreview/i', $userAgent) === 1) {
            return ClientDeviceType::Bot;
        }

        if (preg_match('/iPad/i', $userAgent) === 1
            || (preg_match('/Android/i', $userAgent) === 1 && preg_match('/Mobile/i', $userAgent) !== 1)) {
            return ClientDeviceType::Tableta;
        }

        if (preg_match('/Mobi|iPhone|iPod/i', $userAgent) === 1) {
            return ClientDeviceType::Movil;
        }

        return ClientDeviceType::Escritorio;
    }
}
