<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\SamlMetadata;
use App\Modules\Auth\Domain\SamlMetadataCertificate;
use App\Modules\Auth\Domain\SamlMetadataFailureCode;
use App\Modules\Auth\Domain\SamlMetadataValidationException;
use App\Modules\Auth\Domain\SamlMetadataValidator;
use App\Modules\Auth\Domain\SamlNameIdFormat;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * `funcional.md §G.4.2`, `api.md §G.4` (REQ-AUTH-004, 1.4c). Obtiene y
 * valida los metadatos de un IdP SAML, por URL o por XML pegado.
 *
 * Cuando el origen es una URL, las guardas 1-4 (SSRF) se reutilizan de
 * `SsrfSafeFetcher` sin una sola relajación — mismo cliente que
 * `CurlDiscoveryDocumentValidator` (`OPEN-AUTH-45`). La guarda de
 * contenido (guarda 5, específica de SAML) se aplica siempre, venga el
 * XML de donde venga.
 *
 * **XXE**: `funcional.md §G.4.2` punto 1 la exige antes que ninguna otra
 * comprobación. `DOMDocument::loadXML()` con `LIBXML_NONET` (sin acceso a
 * red durante el análisis) y **sin** `LIBXML_NOENT`/`LIBXML_DTDLOAD` no
 * resuelve entidades externas por defecto en libxml2 moderno — pero eso
 * no basta como declaración de intención: se comprueba explícitamente que
 * el documento no trae ningún nodo `DOCTYPE`, mismo patrón que
 * `OneLogin\Saml2\Utils::loadXML()` usa para lo mismo. Un documento con
 * `<!DOCTYPE …>` se rechaza sin más análisis, exista o no una `<!ENTITY>`
 * dentro: es la forma de no depender de qué expansión concreta intentara.
 */
final class CurlSamlMetadataValidator implements SamlMetadataValidator
{
    private const NS_METADATA = 'urn:oasis:names:tc:SAML:2.0:metadata';

    private const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    private const NS_SAMLP = 'urn:oasis:names:tc:SAML:2.0:protocol';

    private const BINDING_HTTP_REDIRECT = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect';

    public function __construct(
        private readonly SsrfSafeFetcher $fetcher = new SsrfSafeFetcher,
        private readonly SamlCertificateParser $certificateParser = new SamlCertificateParser,
    ) {}

    public function validateFromUrl(string $metadataUrl): SamlMetadata
    {
        try {
            $xml = $this->fetcher->fetch(
                url: $metadataUrl,
                acceptHeader: 'application/samlmetadata+xml, application/xml',
                timeoutSeconds: (int) config('auth-local.saml.metadata_timeout_seconds'),
                maxBytes: (int) config('auth-local.saml.metadata_max_bytes'),
                maxRedirects: (int) config('auth-local.saml.metadata_max_redirects'),
                insecureAllowed: (bool) config('auth-local.saml.allow_insecure_metadata'),
            );
        } catch (SsrfGuardException $e) {
            throw new SamlMetadataValidationException(match ($e->reason) {
                SsrfGuardFailureReason::UnsupportedScheme => SamlMetadataFailureCode::EsquemaNoAdmitido,
                SsrfGuardFailureReason::PrivateDestination => SamlMetadataFailureCode::DestinoNoPublico,
                SsrfGuardFailureReason::TooManyRedirects => SamlMetadataFailureCode::DemasiadasRedirecciones,
                SsrfGuardFailureReason::NoResponse => SamlMetadataFailureCode::SinRespuesta,
                SsrfGuardFailureReason::ResponseTooLarge => SamlMetadataFailureCode::RespuestaDemasiadoGrande,
            }, $e);
        }

        return $this->parseAndValidate($xml);
    }

    public function validateFromXml(string $metadataXml): SamlMetadata
    {
        return $this->parseAndValidate($metadataXml);
    }

    private function parseAndValidate(string $xml): SamlMetadata
    {
        $maxBytes = (int) config('auth-local.saml.metadata_max_bytes');

        // Guarda 2 (tope de tamaño), antes de tocar el analizador: un XML
        // pegado no pasó por SsrfSafeFetcher, que es donde esta guarda
        // vive para el origen URL.
        if (strlen($xml) > $maxBytes) {
            throw new SamlMetadataValidationException(SamlMetadataFailureCode::MetadatosDemasiadoGrandes);
        }

        $dom = $this->loadXmlSafely($xml);
        $maxDepth = (int) config('auth-local.saml.metadata_max_depth');

        if ($dom->documentElement !== null && $this->depthOf($dom->documentElement) > $maxDepth) {
            throw new SamlMetadataValidationException(SamlMetadataFailureCode::MetadatosDemasiadoGrandes);
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('md', self::NS_METADATA);
        $xpath->registerNamespace('ds', self::NS_DSIG);
        $xpath->registerNamespace('samlp', self::NS_SAMLP);

        $entityId = $this->extractEntityId($dom, $xpath);
        $ssoUrl = $this->extractSingleSignOnServiceUrl($xpath);
        $nameIdFormat = $this->extractNameIdFormat($xpath);
        $certificates = $this->extractSigningCertificates($xpath);

        return new SamlMetadata(
            entityId: $entityId,
            singleSignOnServiceUrl: $ssoUrl,
            nameIdFormat: $nameIdFormat,
            signingCertificates: $certificates,
        );
    }

    /**
     * Guarda 1: XML bien formado, sin `DOCTYPE`/entidad externa.
     *
     * @throws SamlMetadataValidationException
     */
    private function loadXmlSafely(string $xml): DOMDocument
    {
        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new DOMDocument;
        // LIBXML_NONET: ningún acceso a red durante el análisis, ni
        // siquiera para resolver un DTD externo si, contra lo esperado,
        // llegara a intentarlo. Deliberadamente SIN LIBXML_NOENT ni
        // LIBXML_DTDLOAD: no se sustituyen entidades ni se cargan DTD.
        $loaded = @$dom->loadXML($xml, LIBXML_NONET);

        $hasDoctype = false;

        if ($loaded) {
            foreach ($dom->childNodes as $child) {
                if ($child->nodeType === XML_DOCUMENT_TYPE_NODE) {
                    $hasDoctype = true;

                    break;
                }
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        if (! $loaded || $hasDoctype) {
            throw new SamlMetadataValidationException(SamlMetadataFailureCode::MetadatosNoValidos);
        }

        return $dom;
    }

    private function depthOf(DOMElement $element, int $current = 1): int
    {
        $max = $current;

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $max = max($max, $this->depthOf($child, $current + 1));
            }
        }

        return $max;
    }

    /**
     * Guarda 3: un `EntityDescriptor` con `entityID`, y un solo
     * `IDPSSODescriptor`. Un `EntitiesDescriptor` con una federación
     * entera dentro se rechaza.
     *
     * @throws SamlMetadataValidationException
     */
    private function extractEntityId(DOMDocument $dom, DOMXPath $xpath): string
    {
        $root = $dom->documentElement;

        if ($root === null
            || $root->localName !== 'EntityDescriptor'
            || $root->namespaceURI !== self::NS_METADATA) {
            throw new SamlMetadataValidationException(SamlMetadataFailureCode::MetadatosAmbiguos);
        }

        $entityId = $root->getAttribute('entityID');

        if ($entityId === '') {
            throw new SamlMetadataValidationException(SamlMetadataFailureCode::MetadatosAmbiguos);
        }

        $idpDescriptors = $xpath->query('/md:EntityDescriptor/md:IDPSSODescriptor');

        if ($idpDescriptors === false || $idpDescriptors->length !== 1) {
            throw new SamlMetadataValidationException(SamlMetadataFailureCode::MetadatosAmbiguos);
        }

        return $entityId;
    }

    /**
     * Guarda 4: `SingleSignOnService` con `Binding` HTTP-Redirect y
     * `https` (`funcional.md §G.0.3` desviación 1: solo se implementa
     * HTTP-Redirect de salida). `AUTH_SAML_ALLOW_INSECURE_METADATA`
     * afloja también esta comprobación —no solo la de `§G.4` sobre la
     * URL de origen—: el IdP simulado de `operacion.md §G.10` sirve su
     * `SingleSignOnService` sobre `http` en `local`/`testing`, con guarda
     * de arranque fuera de esos entornos (`SamlEnvironmentGuard`).
     *
     * @throws SamlMetadataValidationException
     */
    private function extractSingleSignOnServiceUrl(DOMXPath $xpath): string
    {
        $insecureAllowed = (bool) config('auth-local.saml.allow_insecure_metadata');

        $nodes = $xpath->query(
            '/md:EntityDescriptor/md:IDPSSODescriptor/md:SingleSignOnService[@Binding="'.self::BINDING_HTTP_REDIRECT.'"]'
        );

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $location = strtolower($node->getAttribute('Location'));
                $original = $node->getAttribute('Location');

                if ($original === '') {
                    continue;
                }

                if (str_starts_with($location, 'https://')
                    || ($insecureAllowed && str_starts_with($location, 'http://'))) {
                    return $original;
                }
            }
        }

        throw new SamlMetadataValidationException(SamlMetadataFailureCode::BindingNoAdmitido);
    }

    /**
     * Guarda 6: `NameIDFormat`, si viene, dentro de la lista blanca del
     * motor (`transient` no admitido, `RN-AUTH-123`). Si el IdP no
     * declara ninguno, se cataloga como `unspecified` — el valor por
     * defecto del estándar cuando el elemento está ausente.
     *
     * @throws SamlMetadataValidationException
     */
    private function extractNameIdFormat(DOMXPath $xpath): SamlNameIdFormat
    {
        $nodes = $xpath->query('/md:EntityDescriptor/md:IDPSSODescriptor/md:NameIDFormat');

        if ($nodes === false || $nodes->length === 0) {
            return SamlNameIdFormat::Unspecified;
        }

        foreach ($nodes as $node) {
            $urn = trim($node->textContent);
            $format = SamlNameIdFormat::fromUrn($urn);

            if ($format !== null) {
                return $format;
            }
        }

        // Ninguno de los declarados está en la lista blanca (por ejemplo,
        // solo transient): se rechaza, no se cae a un valor por defecto
        // que el IdP no pidió.
        throw new SamlMetadataValidationException(SamlMetadataFailureCode::FormatoDeIdentificadorNoAdmitido);
    }

    /**
     * Guarda 5: al menos un `KeyDescriptor use="signing"` con un X.509
     * analizable y no caducado (`RN-AUTH-117`). `not_before`/`not_after`
     * se extraen del propio certificado, nunca tecleados (`RN-AUTH-126`).
     * Un certificado individual no analizable, caducado o por debajo del
     * tamaño mínimo de clave se descarta sin invalidar los demás; si no
     * queda ninguno válido, la validación entera falla.
     *
     * @return list<SamlMetadataCertificate>
     *
     * @throws SamlMetadataValidationException
     */
    private function extractSigningCertificates(DOMXPath $xpath): array
    {
        $nodes = $xpath->query(
            '/md:EntityDescriptor/md:IDPSSODescriptor/md:KeyDescriptor[not(@use) or @use="signing"]'.
            '/ds:KeyInfo/ds:X509Data/ds:X509Certificate'
        );

        $certificates = [];

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $parsed = $this->certificateParser->parse(trim($node->textContent));

                if ($parsed !== null) {
                    $certificates[] = $parsed;
                }
            }
        }

        if ($certificates === []) {
            throw new SamlMetadataValidationException(SamlMetadataFailureCode::SinCertificadoDeFirma);
        }

        return $certificates;
    }
}
