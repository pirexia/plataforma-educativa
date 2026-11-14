<?php

namespace App\Modules\Auth\Infrastructure;

/**
 * Las cuatro guardas contra SSRF comunes a cualquier descarga que este
 * servidor haga a partir de una URL que indica un administrador de
 * centro: esquema `https`, destino público, tope de redirecciones y
 * tope de tiempo/tamaño de la respuesta. Extraído de
 * `CurlDiscoveryDocumentValidator` (`funcional.md §F.4.2`, `RN-AUTH-113`)
 * para que `CurlSamlMetadataValidator` lo reutilice tal cual —
 * `OPEN-AUTH-45`: *"reutilizando las cinco guardas y el mismo cliente (es
 * el mismo problema, ya resuelto y ya revisado en 1.4b)"*.
 *
 * La guarda de contenido (guarda 5: qué forma tiene el documento
 * descargado) es específica de cada dominio —JSON de descubrimiento OIDC
 * o XML de metadatos SAML— y no vive aquí: cada consumidor la aplica
 * sobre el cuerpo que devuelve `fetch()`.
 *
 * cURL directo, sin envoltorio de terceros (`RNF-MANT-007` no aplica: la
 * extensión `curl` es parte del *runtime* de PHP). `CURLOPT_RESOLVE` fija
 * la conexión a la dirección ya validada mientras conserva el nombre de
 * host original para SNI y verificación de certificado — evita el TOCTOU
 * de una revalidación de DNS que resolviera distinto entre la
 * comprobación y la conexión.
 */
final class SsrfSafeFetcher
{
    /**
     * @throws SsrfGuardException
     */
    public function fetch(
        string $url,
        string $acceptHeader,
        int $timeoutSeconds,
        int $maxBytes,
        int $maxRedirects,
        bool $insecureAllowed,
    ): string {
        $redirects = 0;

        while (true) {
            $this->guardScheme($url, $insecureAllowed);
            $ip = $this->resolvePublicIp($url, $insecureAllowed);

            [$status, $body, $redirectUrl] = $this->fetchOnce($url, $ip, $acceptHeader, $timeoutSeconds, $maxBytes);

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $redirects++;

                if ($redirects > $maxRedirects) {
                    throw new SsrfGuardException(SsrfGuardFailureReason::TooManyRedirects);
                }

                if ($redirectUrl === null || $redirectUrl === '') {
                    throw new SsrfGuardException(SsrfGuardFailureReason::NoResponse);
                }

                $url = $redirectUrl;

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new SsrfGuardException(SsrfGuardFailureReason::NoResponse);
            }

            return $body;
        }
    }

    /**
     * Guarda 1.
     *
     * @throws SsrfGuardException
     */
    private function guardScheme(string $url, bool $insecureAllowed): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === 'https') {
            return;
        }

        if ($scheme === 'http' && $insecureAllowed) {
            return;
        }

        throw new SsrfGuardException(SsrfGuardFailureReason::UnsupportedScheme);
    }

    /**
     * Guarda 2. `FILTER_FLAG_NO_PRIV_RANGE` cubre `10/8`, `172.16/12`,
     * `192.168/16` y `fc00::/7`; `FILTER_FLAG_NO_RES_RANGE` cubre `127/8`
     * (bucle local, incluye `169.254.169.254` vía el rango reservado de
     * enlace local `169.254/16`), `::1` y `fe80::/10`.
     *
     * @throws SsrfGuardException
     */
    private function resolvePublicIp(string $url, bool $insecureAllowed): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new SsrfGuardException(SsrfGuardFailureReason::NoResponse);
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : $this->resolveHost($host);

        if ($ip === null) {
            throw new SsrfGuardException(SsrfGuardFailureReason::NoResponse);
        }

        if (! $this->isPublicIp($ip) && ! $insecureAllowed) {
            throw new SsrfGuardException(SsrfGuardFailureReason::PrivateDestination);
        }

        return $ip;
    }

    private function resolveHost(string $host): ?string
    {
        $aRecords = @dns_get_record($host, DNS_A) ?: [];
        $ip = $aRecords[0]['ip'] ?? null;

        if (is_string($ip) && $ip !== '') {
            return $ip;
        }

        $aaaaRecords = @dns_get_record($host, DNS_AAAA) ?: [];
        $ipv6 = $aaaaRecords[0]['ipv6'] ?? null;

        return is_string($ipv6) && $ipv6 !== '' ? $ipv6 : null;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * @return array{0: int, 1: string, 2: ?string} [status, body, redirect_url]
     *
     * @throws SsrfGuardException
     */
    private function fetchOnce(string $url, string $ip, string $acceptHeader, int $timeoutSeconds, int $maxBytes): array
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $body = '';
        $truncated = false;

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            // Fija la conexión a la IP ya validada (guarda 2), sin
            // repetir la resolución DNS al conectar: TOCTOU cerrado. El
            // nombre de host original se conserva para SNI/verificación
            // de certificado.
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ["Accept: {$acceptHeader}"],
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$truncated, $maxBytes): int {
                $body .= $chunk;

                if (strlen($body) > $maxBytes) {
                    $truncated = true;

                    // Cualquier valor distinto de strlen($chunk) aborta
                    // la transferencia (guarda 4).
                    return -1;
                }

                return strlen($chunk);
            },
        ]);

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($truncated) {
            throw new SsrfGuardException(SsrfGuardFailureReason::ResponseTooLarge);
        }

        if ($result === false || $errno !== 0) {
            throw new SsrfGuardException(SsrfGuardFailureReason::NoResponse);
        }

        return [$status, $body, is_string($redirectUrl) && $redirectUrl !== '' ? $redirectUrl : null];
    }
}
