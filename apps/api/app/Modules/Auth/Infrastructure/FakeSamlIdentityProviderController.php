<?php

namespace App\Modules\Auth\Infrastructure;

use DOMDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\App;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;

/**
 * `operacion.md §G.10` (REQ-AUTH-004, 1.4c). El IdP SAML simulado que
 * permite recorrer el flujo entero en `local`/`testing` sin depender de
 * ningún IdP real, y sobre todo, probar los **rechazos**
 * (`RN-AUTH-117`-`123`): un IdP real no emite aserciones inválidas a
 * petición. Hermano del emisor OIDC (`FakeOidcIssuerController`), fuera
 * del grupo de tenant (`_sso-simulator`, emisor de plataforma).
 *
 * **Dos barreras contra producción** (`§G.10.3`): esta clase ni siquiera
 * se instancia si la ruta no está registrada (`routes/api.php` solo la
 * registra en `local`/`testing`), y `guardEnvironment()` aborta si de
 * algún modo se invocara fuera de esos entornos.
 *
 * El certificado y la clave son de desarrollo, generados y cacheados por
 * `FakeSamlKeyMaterial` — nunca material de producción.
 *
 * Firma la aserción con `XMLSecurityDSig`/`XMLSecurityKey`
 * (`robrichards/xmlseclibs`), **nunca** con `OneLogin\Saml2\Auth`: esta
 * clase no está en la frontera de `CA-AUTH-362` (no es la implementación
 * de `SamlIdentityProvider`), pero tampoco tiene motivo para cruzarla —
 * es más simple emitir el XML a mano, como haría cualquier IdP.
 */
class FakeSamlIdentityProviderController extends Controller
{
    public function metadata(Request $request): Response
    {
        $this->guardEnvironment();

        $material = FakeSamlKeyMaterial::get();
        $certBody = $this->stripPem($material['cert']);
        $entityId = $this->baseUrl($request).'/entity';
        $ssoUrl = route('sso-simulator.saml.sso');

        $xml = <<<XML
            <?xml version="1.0"?>
            <md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{$entityId}">
              <md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
                <md:KeyDescriptor use="signing">
                  <ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
                    <ds:X509Data><ds:X509Certificate>{$certBody}</ds:X509Certificate></ds:X509Data>
                  </ds:KeyInfo>
                </md:KeyDescriptor>
                <md:NameIDFormat>urn:oasis:names:tc:SAML:2.0:nameid-format:persistent</md:NameIDFormat>
                <md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>
                <md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified</md:NameIDFormat>
                <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="{$ssoUrl}"/>
              </md:IDPSSODescriptor>
            </md:EntityDescriptor>
            XML;

        return response($xml, 200)->header('Content-Type', 'application/samlmetadata+xml');
    }

    /**
     * `GET /_sso-simulator/saml/sso?SAMLRequest=...&sub=...&broken=...`.
     * Sin formulario intermedio a propósito (a diferencia del emisor
     * OIDC): los tests conducen este simulador con parámetros de consulta
     * directos, y es una herramienta de desarrollo, no una pantalla.
     */
    public function sso(Request $request): Response
    {
        $this->guardEnvironment();

        $samlRequest = (string) $request->query('SAMLRequest', '');
        $inflated = $samlRequest !== '' ? @gzinflate((string) base64_decode($samlRequest, true)) : false;

        $authnRequestId = null;
        $acsUrl = null;
        $spEntityId = null;

        if (is_string($inflated) && $inflated !== '') {
            [$authnRequestId, $acsUrl, $spEntityId] = $this->parseAuthnRequest($inflated);
        }

        $broken = (string) $request->query('broken', '');

        $sub = (string) $request->query('sub', 'fake-saml-subject-1');
        $nameIdFormat = (string) $request->query('name_id_format', 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent');
        $attributeName = (string) $request->query('attribute_name', '');
        $attributeValue = (string) $request->query('attribute_value', '');

        $acsUrl = (string) $request->query('acs_url_override', $acsUrl ?? '');
        $spEntityId = (string) $request->query('audience_override', $spEntityId ?? '');

        if ($broken === 'audiencia_otro_tenant') {
            $spEntityId = 'https://otro-tenant.invalid/api/v1/auth/saml/entity';
        }

        if ($broken === 'destino_incorrecto') {
            $acsUrl = 'https://otro-host.invalid/api/v1/auth/saml/x/acs';
        }

        $inResponseTo = $authnRequestId;

        if ($broken === 'sin_in_response_to') {
            $inResponseTo = null;
        } elseif ($broken === 'in_response_to_inventado') {
            $inResponseTo = 'ONELOGIN_'.bin2hex(random_bytes(16));
        }

        $now = time();
        $notBefore = $now - 60;
        $notOnOrAfter = $broken === 'caducada' ? $now - 3600 : $now + 300;

        $xml = $this->buildSignedResponse(
            responseId: '_'.bin2hex(random_bytes(16)),
            assertionId: (string) $request->query('assertion_id_override', '_'.bin2hex(random_bytes(16))),
            issueInstant: gmdate('Y-m-d\TH:i:s\Z', $now),
            notBefore: gmdate('Y-m-d\TH:i:s\Z', $notBefore),
            notOnOrAfter: gmdate('Y-m-d\TH:i:s\Z', $notOnOrAfter),
            destination: $acsUrl,
            audience: $spEntityId,
            inResponseTo: $inResponseTo,
            nameId: $broken === 'sin_name_id' ? '' : $sub,
            nameIdFormat: $nameIdFormat,
            attributeName: $attributeName,
            attributeValue: $attributeValue,
            signAssertion: $broken !== 'sin_firma',
            tamperAfterSigning: $broken === 'firma_alterada',
        );

        return response($this->autoSubmitForm($acsUrl, $xml), 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [request_id, acs_url, sp_entity_id]
     */
    private function parseAuthnRequest(string $xml): array
    {
        $dom = new DOMDocument;
        $loaded = @$dom->loadXML($xml, LIBXML_NONET);

        if (! $loaded || $dom->documentElement === null) {
            return [null, null, null];
        }

        $root = $dom->documentElement;
        $id = $root->getAttribute('ID');
        $acsUrl = $root->getAttribute('AssertionConsumerServiceURL');

        $issuerNodes = $dom->getElementsByTagName('Issuer');
        $spEntityId = $issuerNodes->length > 0 ? $issuerNodes->item(0)->textContent : null;

        return [
            $id !== '' ? $id : null,
            $acsUrl !== '' ? $acsUrl : null,
            $spEntityId !== '' ? $spEntityId : null,
        ];
    }

    private function buildSignedResponse(
        string $responseId,
        string $assertionId,
        string $issueInstant,
        string $notBefore,
        string $notOnOrAfter,
        ?string $destination,
        ?string $audience,
        ?string $inResponseTo,
        string $nameId,
        string $nameIdFormat,
        string $attributeName,
        string $attributeValue,
        bool $signAssertion,
        bool $tamperAfterSigning,
    ): string {
        $idpEntityId = $this->currentIssuer();
        $destinationAttr = $destination !== null ? ' Destination="'.htmlspecialchars($destination, ENT_QUOTES).'"' : '';
        $inResponseToAttr = $inResponseTo !== null ? ' InResponseTo="'.htmlspecialchars($inResponseTo, ENT_QUOTES).'"' : '';
        $recipientAttr = $destination !== null ? ' Recipient="'.htmlspecialchars($destination, ENT_QUOTES).'"' : '';
        $audienceXml = $audience !== null && $audience !== ''
            ? '<saml:AudienceRestriction><saml:Audience>'.htmlspecialchars($audience, ENT_QUOTES).'</saml:Audience></saml:AudienceRestriction>'
            : '';

        $nameIdXml = $nameId !== ''
            ? '<saml:NameID Format="'.htmlspecialchars($nameIdFormat, ENT_QUOTES).'">'.htmlspecialchars($nameId, ENT_QUOTES).'</saml:NameID>'
            : '<saml:NameID Format="'.htmlspecialchars($nameIdFormat, ENT_QUOTES).'"></saml:NameID>';

        $attributeXml = '';

        if ($attributeName !== '') {
            $attributeXml = '<saml:AttributeStatement><saml:Attribute Name="'.htmlspecialchars($attributeName, ENT_QUOTES).'">'.
                '<saml:AttributeValue>'.htmlspecialchars($attributeValue, ENT_QUOTES).'</saml:AttributeValue></saml:Attribute></saml:AttributeStatement>';
        }

        $assertionXml = <<<XML
            <saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$assertionId}" Version="2.0" IssueInstant="{$issueInstant}">
            <saml:Issuer>{$idpEntityId}</saml:Issuer>
            <saml:Subject>
            {$nameIdXml}
            <saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">
            <saml:SubjectConfirmationData NotOnOrAfter="{$notOnOrAfter}"{$recipientAttr}{$inResponseToAttr}/>
            </saml:SubjectConfirmation>
            </saml:Subject>
            <saml:Conditions NotBefore="{$notBefore}" NotOnOrAfter="{$notOnOrAfter}">
            {$audienceXml}
            </saml:Conditions>
            <saml:AuthnStatement AuthnInstant="{$issueInstant}" SessionIndex="_fake-session">
            <saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext>
            </saml:AuthnStatement>
            {$attributeXml}
            </saml:Assertion>
            XML;

        $responseXml = <<<XML
            <samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$responseId}" Version="2.0" IssueInstant="{$issueInstant}"{$destinationAttr}{$inResponseToAttr}>
            <saml:Issuer>{$idpEntityId}</saml:Issuer>
            <samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>
            {$assertionXml}
            </samlp:Response>
            XML;

        if (! $signAssertion) {
            return $responseXml;
        }

        $doc = new DOMDocument;
        $doc->loadXML($responseXml);
        $assertionNode = $doc->getElementsByTagName('Assertion')->item(0);

        $material = FakeSamlKeyMaterial::get();

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($material['key'], false);

        $dsig = new XMLSecurityDSig;
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $dsig->addReferenceList(
            [$assertionNode],
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
            ['id_name' => 'ID', 'overwrite' => false],
        );
        $dsig->sign($key);
        $dsig->add509Cert($material['cert'], true);

        $issuerNode = $assertionNode->getElementsByTagName('Issuer')->item(0);
        $dsig->insertSignature($assertionNode, $issuerNode->nextSibling);

        $signed = $doc->saveXML();

        if ($tamperAfterSigning) {
            // RN-AUTH-117: altera un carácter del NameID después de
            // firmar, invalidando la firma sin tocar su estructura.
            $signed = preg_replace('/(<saml:NameID[^>]*>)([^<]*)(<\/saml:NameID>)/', '$1TAMPERED$2$3', $signed, 1) ?? $signed;
        }

        return $signed;
    }

    private function currentIssuer(): string
    {
        return request()->getSchemeAndHttpHost().'/_sso-simulator/saml/entity';
    }

    private function autoSubmitForm(?string $acsUrl, string $samlResponseXml): string
    {
        $acsUrlSafe = htmlspecialchars((string) $acsUrl, ENT_QUOTES, 'UTF-8');
        $samlResponseB64 = htmlspecialchars(base64_encode($samlResponseXml), ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="es">
            <head><meta charset="utf-8"><title>IdP SAML simulado (solo desarrollo)</title></head>
            <body onload="document.forms[0].submit()">
                <p>Solo local/testing (operacion.md §G.10). Redirigiendo…</p>
                <form method="post" action="{$acsUrlSafe}">
                    <input type="hidden" name="SAMLResponse" value="{$samlResponseB64}">
                    <button type="submit">Continuar</button>
                </form>
            </body>
            </html>
            HTML;
    }

    private function stripPem(string $pem): string
    {
        return trim((string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem));
    }

    private function guardEnvironment(): void
    {
        if (! App::environment(['local', 'testing'])) {
            throw new RuntimeException('FakeSamlIdentityProviderController no está disponible fuera de local/testing.');
        }
    }

    private function baseUrl(Request $request): string
    {
        return $request->getSchemeAndHttpHost().'/_sso-simulator/saml';
    }
}
