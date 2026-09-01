<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\DiscoveryDocument;
use App\Modules\Auth\Domain\DiscoveryDocumentValidator;
use App\Modules\Auth\Domain\DiscoveryFailureCode;
use App\Modules\Auth\Domain\DiscoveryValidationException;

/**
 * `funcional.md §F.4.2`, `RN-AUTH-113`. Las cinco guardas contra SSRF —
 * la superficie con más peso de seguridad de 1.4b (`funcional.md §F.14`
 * punto 6). Un administrador de centro proporciona una URL que este
 * servidor descarga: sin estas guardas es una petición forjada del lado
 * del servidor con un formulario delante.
 *
 * cURL directo, sin envoltorio de terceros (`RNF-MANT-007` no aplica: la
 * extensión `curl` es parte del *runtime* de PHP, no una dependencia que
 * aprobar). `CURLOPT_RESOLVE` fija la conexión a la dirección ya validada
 * (guarda 2) mientras conserva el nombre de host original para SNI y la
 * verificación del certificado — evita el TOCTOU de una revalidación de
 * DNS que resolviera distinto entre la comprobación y la conexión.
 *
 * `AUTH_SSO_ALLOW_INSECURE_DISCOVERY` afloja **las guardas 1 y 2**, no
 * solo el esquema: el emisor simulado de `operacion.md §F.10` se sirve
 * por la propia API en `local`/`testing`, y su dirección ahí es de bucle
 * local o de la red del contenedor — ambas caerían en la guarda 2 igual
 * que en el esquema. Es seguro aflojarlas juntas porque las dos
 * dependen del mismo conmutador, que a su vez tiene guarda de arranque
 * fuera de `local`/`testing` (`operacion.md §F.2.1`): sin eso, `§F.10`
 * («recorrer el flujo entero y real») no sería cierto.
 */
final class CurlDiscoveryDocumentValidator implements DiscoveryDocumentValidator
{
    public function validate(string $discoveryUrl): DiscoveryDocument
    {
        $url = $discoveryUrl;
        $redirects = 0;
        $maxRedirects = (int) config('auth-local.sso.discovery_max_redirects');

        while (true) {
            $this->guardScheme($url);
            $ip = $this->resolvePublicIp($url);

            [$status, $body, $redirectUrl] = $this->fetch($url, $ip);

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $redirects++;

                if ($redirects > $maxRedirects) {
                    throw new DiscoveryValidationException(DiscoveryFailureCode::DemasiadasRedirecciones);
                }

                if ($redirectUrl === null || $redirectUrl === '') {
                    throw new DiscoveryValidationException(DiscoveryFailureCode::SinRespuesta);
                }

                $url = $redirectUrl;

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new DiscoveryValidationException(DiscoveryFailureCode::SinRespuesta);
            }

            return $this->parseAndValidateBody($body, $discoveryUrl);
        }
    }

    private function guardScheme(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === 'https') {
            return;
        }

        if ($scheme === 'http' && $this->insecureAllowed()) {
            return;
        }

        throw new DiscoveryValidationException(DiscoveryFailureCode::EsquemaNoAdmitido);
    }

    /**
     * Guarda 2. `FILTER_FLAG_NO_PRIV_RANGE` cubre `10/8`, `172.16/12`,
     * `192.168/16` y `fc00::/7`; `FILTER_FLAG_NO_RES_RANGE` cubre
     * `127/8` (bucle local, incluye `169.254.169.254` vía el rango
     * reservado de enlace local `169.254/16`), `::1` y `fe80::/10` — la
     * lista exacta de `funcional.md §F.4.2` guarda 2, mediante la
     * validación estándar de PHP en vez de una lista de CIDR propia.
     */
    private function resolvePublicIp(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new DiscoveryValidationException(DiscoveryFailureCode::SinRespuesta);
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : $this->resolveHost($host);

        if ($ip === null) {
            throw new DiscoveryValidationException(DiscoveryFailureCode::SinRespuesta);
        }

        if (! $this->isPublicIp($ip) && ! $this->insecureAllowed()) {
            throw new DiscoveryValidationException(DiscoveryFailureCode::DestinoNoPublico);
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

    private function insecureAllowed(): bool
    {
        return (bool) config('auth-local.sso.allow_insecure_discovery');
    }

    /**
     * @return array{0: int, 1: string, 2: ?string} [status, body, redirect_url]
     */
    private function fetch(string $url, string $ip): array
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $timeout = (int) config('auth-local.sso.discovery_timeout_seconds');
        $maxBytes = (int) config('auth-local.sso.discovery_max_bytes');

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
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
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
            throw new DiscoveryValidationException(DiscoveryFailureCode::RespuestaDemasiadoGrande);
        }

        if ($result === false || $errno !== 0) {
            throw new DiscoveryValidationException(DiscoveryFailureCode::SinRespuesta);
        }

        return [$status, $body, is_string($redirectUrl) && $redirectUrl !== '' ? $redirectUrl : null];
    }

    /**
     * Guarda 5.
     */
    private function parseAndValidateBody(string $body, string $originalDiscoveryUrl): DiscoveryDocument
    {
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new DiscoveryValidationException(DiscoveryFailureCode::DocumentoNoValido);
        }

        $issuer = $data['issuer'] ?? null;
        $authorizationEndpoint = $data['authorization_endpoint'] ?? null;
        $tokenEndpoint = $data['token_endpoint'] ?? null;
        $userinfoEndpoint = $data['userinfo_endpoint'] ?? null;

        if (! is_string($issuer) || $issuer === ''
            || ! is_string($authorizationEndpoint) || $authorizationEndpoint === ''
            || ! is_string($tokenEndpoint) || $tokenEndpoint === '') {
            throw new DiscoveryValidationException(DiscoveryFailureCode::DocumentoNoValido);
        }

        // OpenID Connect Discovery 1.0 §4.3.
        if ($this->originOf($issuer) === null || $this->originOf($issuer) !== $this->originOf($originalDiscoveryUrl)) {
            throw new DiscoveryValidationException(DiscoveryFailureCode::EmisorNoCoincide);
        }

        if (! $this->isHttpsOrInsecureAllowed($authorizationEndpoint) || ! $this->isHttpsOrInsecureAllowed($tokenEndpoint)) {
            throw new DiscoveryValidationException(DiscoveryFailureCode::EndpointNoSeguro);
        }

        if ($userinfoEndpoint !== null
            && (! is_string($userinfoEndpoint) || ! $this->isHttpsOrInsecureAllowed($userinfoEndpoint))) {
            throw new DiscoveryValidationException(DiscoveryFailureCode::EndpointNoSeguro);
        }

        $responseTypes = $data['response_types_supported'] ?? null;

        if (! is_array($responseTypes) || ! in_array('code', $responseTypes, true)) {
            throw new DiscoveryValidationException(DiscoveryFailureCode::FlujoNoAdmitido);
        }

        $challengeMethods = $data['code_challenge_methods_supported'] ?? null;

        if ($challengeMethods !== null
            && (! is_array($challengeMethods) || ! in_array('S256', $challengeMethods, true))) {
            throw new DiscoveryValidationException(DiscoveryFailureCode::FlujoNoAdmitido);
        }

        return new DiscoveryDocument(
            issuer: $issuer,
            authorizationEndpoint: $authorizationEndpoint,
            tokenEndpoint: $tokenEndpoint,
            userinfoEndpoint: is_string($userinfoEndpoint) && $userinfoEndpoint !== '' ? $userinfoEndpoint : null,
        );
    }

    private function isHttpsOrInsecureAllowed(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'https' || ($scheme === 'http' && $this->insecureAllowed());
    }

    private function originOf(string $url): ?string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($scheme) || $scheme === '' || ! is_string($host) || $host === '') {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT);

        return strtolower($scheme).'://'.strtolower($host).($port !== null ? ':'.$port : '');
    }
}
