<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\ExternalIdentityException;
use App\Modules\Auth\Domain\ExternalIdentityFailure;
use App\Modules\Auth\Domain\SamlIdentity;
use App\Modules\Auth\Domain\SamlIdentityProvider;
use App\Modules\Auth\Domain\SamlNameIdFormat;
use Carbon\CarbonImmutable;
use OneLogin\Saml2\Response as OneLoginResponse;
use OneLogin\Saml2\Settings as OneLoginSettings;
use OneLogin\Saml2\Utils as OneLoginUtils;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Throwable;

/**
 * `funcional.md §G.3.5`, `§G.3.6` (REQ-AUTH-004, 1.4c). El envoltorio de
 * `SAML-Toolkits/php-saml` 4.x. **Frontera del paquete `RNF-MANT-007`**:
 * es el único fichero autorizado a importar `OneLogin\Saml2\*`
 * (`CA-AUTH-362`) — junto con `EloquentSamlIdentityProviderRegistry`, que
 * solo construye `Settings` sin invocar `Auth`.
 *
 * **`OneLogin\Saml2\Auth` no se instancia jamás**, ni aquí ni en ningún
 * otro sitio. La superficie completa usada en el camino de entrada es
 * `new Response($settings, $xmlBase64)` + `$response->isValid($requestId)`.
 * El `AuthnRequest` de salida **no** se construye con
 * `OneLogin\Saml2\AuthnRequest`: esa clase genera su propio `ID`
 * internamente y no admite uno externo, y la interfaz de este envoltorio
 * exige que el `AuthnRequest` lleve el `ID` que la aplicación ya generó y
 * persistió en `saml_auth_requests` **antes** de llamar aquí
 * (`funcional.md §G.4.3` puntos 3.4-3.5) — así que se construye a mano,
 * con el mismo formato XML que la biblioteca usaría, y se firma
 * replicando `Auth::buildRequestSignature()` con `XMLSecurityKey`
 * (`RobRichards\XMLSecLibs`, que no es `OneLogin\Saml2\*`).
 *
 * `strict`, `wantAssertionsSigned`, `wantMessagesSigned` y
 * `rejectUnsolicitedResponsesWithInResponseTo` se fijan a `true` en
 * `EloquentSamlIdentityProviderRegistry::forProvider()`, verificado por
 * reflexión en `CA-AUTH-336` (`RN-AUTH-117`). `wantNameId` se fija a
 * `false`: la ausencia de `NameID` no es un fallo de protocolo aquí —es
 * `RN-AUTH-123`, resuelta por el llamador como `sin_cuenta`, no como
 * `error_proveedor`— y con `wantNameId = true` la biblioteca lanzaría en
 * vez de devolver un valor vacío que este envoltorio pueda traducir.
 */
final class OneLoginSamlIdentityProvider implements SamlIdentityProvider
{
    public function __construct(
        private readonly OneLoginSettings $settings,
        private readonly string $spEntityId,
        private readonly string $acsUrl,
        private readonly string $idpSsoUrl,
        private readonly SamlNameIdFormat $nameIdFormat,
        private readonly bool $signAuthnRequests,
        private readonly ?string $spSigningKeyPem,
        private readonly string $tenantOrigin,
        /** `funcional.md §G.5.1`. `null` ⇒ el correo sale del propio `NameID` (solo posible con `name_id_format = emailAddress`, garantizado por el `CHECK` de `datos.md §G.3`). */
        private readonly ?string $emailAttribute,
    ) {}

    public function buildAuthnRequest(string $requestId): string
    {
        $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
        $spEntityId = htmlspecialchars($this->spEntityId, ENT_QUOTES);
        $acsUrl = htmlspecialchars($this->acsUrl, ENT_QUOTES);
        $destination = htmlspecialchars($this->idpSsoUrl, ENT_QUOTES);
        $nameIdFormatUrn = htmlspecialchars($this->nameIdFormat->urn(), ENT_QUOTES);
        $requestIdAttr = htmlspecialchars($requestId, ENT_QUOTES);

        $xml = <<<AUTHNREQUEST
<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$requestIdAttr}" Version="2.0" IssueInstant="{$issueInstant}" Destination="{$destination}" ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" AssertionConsumerServiceURL="{$acsUrl}"><saml:Issuer>{$spEntityId}</saml:Issuer><samlp:NameIDPolicy Format="{$nameIdFormatUrn}" AllowCreate="true"/></samlp:AuthnRequest>
AUTHNREQUEST;

        $deflated = gzdeflate($xml);
        $samlRequest = base64_encode($deflated);

        // `lowercaseUrlencoding = false` es el valor por defecto de
        // php-saml (`Settings::_addDefaultValues()`), así que la firma se
        // calcula sobre una cadena con la codificación clásica de
        // `urlencode()` (mismo criterio que `Auth::buildMessageSignature()`
        // en su rama por defecto) y la URL final se construye con la
        // misma codificación: el IdP tiene que verificar exactamente los
        // mismos bytes que se firmaron.
        $params = ['SAMLRequest' => $samlRequest];

        if ($this->signAuthnRequests && $this->spSigningKeyPem !== null) {
            $signAlgorithm = XMLSecurityKey::RSA_SHA256;
            $signable = 'SAMLRequest='.urlencode($samlRequest).'&SigAlg='.urlencode($signAlgorithm);

            $key = new XMLSecurityKey($signAlgorithm, ['type' => 'private']);
            $key->loadKey($this->spSigningKeyPem, false);

            $params['SigAlg'] = $signAlgorithm;
            $params['Signature'] = base64_encode($key->signData($signable));
        }

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC1738);
        $separator = str_contains($this->idpSsoUrl, '?') ? '&' : '?';

        return $this->idpSsoUrl.$separator.$query;
    }

    public function validateAssertion(string $samlResponse, string $expectedRequestId): SamlIdentity
    {
        // `funcional.md §G.3.5`: la URL propia se fija a mano a partir
        // del host de tenant ya resuelto por ResolveTenant, nunca desde
        // $_SERVER — es lo que hace que Destination se compare contra un
        // valor que ponemos nosotros y no contra lo que Traefik reenvíe
        // (ADR-028).
        if ($this->tenantOrigin !== '') {
            OneLoginUtils::setBaseURL($this->tenantOrigin);
        }

        try {
            $response = new OneLoginResponse($this->settings, $samlResponse);
        } catch (Throwable $e) {
            throw new ExternalIdentityException(ExternalIdentityFailure::AssertionInvalid, previous: $e);
        }

        if (! $response->isValid($expectedRequestId)) {
            throw new ExternalIdentityException(
                ExternalIdentityFailure::AssertionInvalid,
                $response->getError() ?? 'saml_assertion_invalid',
            );
        }

        try {
            $assertionId = $response->getAssertionId();
            $notOnOrAfterTimestamp = $response->getAssertionNotOnOrAfter();
        } catch (Throwable $e) {
            throw new ExternalIdentityException(ExternalIdentityFailure::AssertionInvalid, previous: $e);
        }

        // Response::getAssertionNotOnOrAfter() declara @var int en el
        // vendor, pero la propiedad que devuelve solo se asigna dentro de
        // una rama condicional de isValid() — sin SubjectConfirmationData
        // válida sigue sin inicializar (null real en tiempo de ejecución),
        // pese a lo que dice su PHPDoc. La comprobación es defensiva
        // contra ese desajuste, no redundante.
        // @phpstan-ignore function.alreadyNarrowedType
        if (! is_string($assertionId) || $assertionId === '' || ! is_int($notOnOrAfterTimestamp)) {
            throw new ExternalIdentityException(ExternalIdentityFailure::AssertionInvalid, 'saml_assertion_missing_id');
        }

        // RN-AUTH-123: la ausencia, vacuidad o formato no admitido del
        // NameID NO es un fallo de protocolo — es asunto del llamador
        // (`sin_cuenta`, byte a byte igual que "no hay cuenta"). Por eso
        // wantNameId = false en la configuración: getNameId()/
        // getNameIdFormat() devuelven null en vez de lanzar.
        try {
            $rawNameId = $response->getNameId();
            $rawNameIdFormat = $response->getNameIdFormat();
        } catch (Throwable) {
            $rawNameId = null;
            $rawNameIdFormat = null;
        }

        $nameId = '';
        $matchedFormat = $this->nameIdFormat;

        if (is_string($rawNameId) && $rawNameId !== ''
            && is_string($rawNameIdFormat)
            && SamlNameIdFormat::fromUrn($rawNameIdFormat) === $this->nameIdFormat) {
            $nameId = $rawNameId;
        }

        $email = $nameId !== '' ? $this->resolveEmail($response, $nameId) : null;

        return new SamlIdentity(
            nameId: $nameId,
            nameIdFormat: $matchedFormat,
            email: $email,
            assertionId: $assertionId,
            notOnOrAfter: CarbonImmutable::createFromTimestampUTC($notOnOrAfterTimestamp),
        );
    }

    /**
     * `funcional.md §G.5.1`. Si `email_attribute` está configurado, el
     * correo sale de ese atributo; si es `NULL` y el `NameIDFormat`
     * catalogado es `emailAddress`, el propio `NameID` es el correo. El
     * valor se comprueba en cerrado: un atributo sin forma de dirección
     * de correo no empareja — no se normaliza aquí (recorte/minúsculas es
     * cosa de `LoginService::normalize()`, aplicado por el llamador,
     * mismo criterio que OIDC), solo se descarta si es claramente
     * inutilizable.
     */
    private function resolveEmail(OneLoginResponse $response, string $nameId): ?string
    {
        if ($this->emailAttribute === null) {
            return $this->nameIdFormat === SamlNameIdFormat::EmailAddress ? $nameId : null;
        }

        try {
            $attributes = $response->getAttributes();
        } catch (Throwable) {
            return null;
        }

        $values = $attributes[$this->emailAttribute] ?? null;
        $value = is_array($values) ? ($values[0] ?? null) : null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        return filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }
}
