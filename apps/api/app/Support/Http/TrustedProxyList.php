<?php

namespace App\Support\Http;

use InvalidArgumentException;

/**
 * Lista de proxies de confianza (`TRUSTED_PROXIES`, issue #484, `REQ-FAM-UNIT-004`, `ADR-066 §5.8`, `ADR-028`). Solo desde
 * estas direcciones se acepta `X-Forwarded-For` para resolver `$request->ip()`; desde cualquier otra se ignora (un cliente de Internet no
 * puede falsear su IP, que es el dato probatorio de `image_consent_decisions.ip_address` y la base de la lista blanca del
 * *backoffice*). Sin variable, rige la red de Quadlet (`infra/quadlet/plataforma.network`): el único origen que reenvía son
 * los contenedores de esa red, entre ellos Traefik (limitación: cualquiera de ellos, no solo Traefik, puede falsearla; `operacion.md §13.3`) (`ADR-028`: ninguna IP de contenedor escrita a mano, por eso la subred).
 *
 * Aborta el arranque —igual que las demás guardas de entorno— con un comodín (`*`, `0.0.0.0/0`, `::/0`) fuera de `local` y
 * `testing`, o con una entrada que no sea una IP ni un CIDR.
 */
final class TrustedProxyList
{
    /** Subred de `plataforma.network` (Traefik, `api@` y `web` comparten red). */
    public const DEFAULT = '10.89.10.0/24';

    private const WILDCARDS = ['*', '**', '0.0.0.0/0', '::/0'];

    /**
     * @return list<string>
     */
    public static function parse(?string $raw, string $appEnv): array
    {
        $raw = $raw === null || trim($raw) === '' ? self::DEFAULT : $raw;
        $entries = array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $e): bool => $e !== ''));

        foreach ($entries as $entry) {
            if (in_array($entry, self::WILDCARDS, true)) {
                if (in_array($appEnv, ['local', 'testing'], true)) {
                    continue;
                }

                throw new InvalidArgumentException("TRUSTED_PROXIES no admite el comodín '{$entry}' en {$appEnv}: indica la red del proxy (p. ej. ".self::DEFAULT.').');
            }

            if (! self::isIpOrCidr($entry)) {
                throw new InvalidArgumentException("TRUSTED_PROXIES: '{$entry}' no es una IP ni un CIDR válido.");
            }
        }

        return $entries;
    }

    private static function isIpOrCidr(string $entry): bool
    {
        [$ip, $mask] = array_pad(explode('/', $entry, 2), 2, null);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($mask === null) {
            return true;
        }

        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($mask) && (int) $mask <= $max;
    }
}
