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
 * Las guardas 1-4 (esquema, destino público, redirecciones, tiempo/tamaño)
 * viven en `SsrfSafeFetcher`, compartido con `CurlSamlMetadataValidator`
 * desde 1.4c (`OPEN-AUTH-45`: "reutilizando las cinco guardas y el mismo
 * cliente"). Esta clase solo traduce `SsrfGuardFailureReason` a
 * `DiscoveryFailureCode` y aplica la guarda 5, específica de OIDC (forma
 * del documento de descubrimiento).
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
    public function __construct(
        private readonly SsrfSafeFetcher $fetcher = new SsrfSafeFetcher,
    ) {}

    public function validate(string $discoveryUrl): DiscoveryDocument
    {
        try {
            $body = $this->fetcher->fetch(
                url: $discoveryUrl,
                acceptHeader: 'application/json',
                timeoutSeconds: (int) config('auth-local.sso.discovery_timeout_seconds'),
                maxBytes: (int) config('auth-local.sso.discovery_max_bytes'),
                maxRedirects: (int) config('auth-local.sso.discovery_max_redirects'),
                insecureAllowed: (bool) config('auth-local.sso.allow_insecure_discovery'),
            );
        } catch (SsrfGuardException $e) {
            throw new DiscoveryValidationException(match ($e->reason) {
                SsrfGuardFailureReason::UnsupportedScheme => DiscoveryFailureCode::EsquemaNoAdmitido,
                SsrfGuardFailureReason::PrivateDestination => DiscoveryFailureCode::DestinoNoPublico,
                SsrfGuardFailureReason::TooManyRedirects => DiscoveryFailureCode::DemasiadasRedirecciones,
                SsrfGuardFailureReason::NoResponse => DiscoveryFailureCode::SinRespuesta,
                SsrfGuardFailureReason::ResponseTooLarge => DiscoveryFailureCode::RespuestaDemasiadoGrande,
            }, $e);
        }

        return $this->parseAndValidateBody($body, $discoveryUrl);
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

    private function insecureAllowed(): bool
    {
        return (bool) config('auth-local.sso.allow_insecure_discovery');
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
