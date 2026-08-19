<?php

namespace App\Modules\Core\Application;

use App\Support\Api\ApiException;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * funcional.md §4.2, operacion.md §5, `RSEC-OWASP-012`. Un SVG servido
 * desde el propio dominio del centro (bucket privado, pero sin sanear
 * sería XSS con el origen del centro si algún día se sirviera inline) es
 * un vector real: se elimina `<script>`, cualquier manejador `on*`,
 * `<foreignObject>` (permite HTML embebido) y toda referencia externa
 * (`href`/`xlink:href`/`src` a otro origen, o `javascript:`).
 *
 * Sin dependencia nueva (`RNF-MANT-007`): `ext-dom`, ya presente en PHP.
 */
final class SvgSanitizer
{
    private const DANGEROUS_ELEMENTS = ['script', 'foreignObject'];

    private const URL_ATTRIBUTES = ['href', 'xlink:href', 'src'];

    public function sanitize(string $raw): string
    {
        $document = $this->parse($raw);

        $this->removeDangerousElements($document);
        $this->removeDangerousAttributes($document);

        $output = $document->saveXML($document->documentElement);

        if ($output === false || $output === '') {
            $this->fail();
        }

        return $output;
    }

    private function parse(string $raw): DOMDocument
    {
        // Se retira el DOCTYPE antes de parsear: un SVG legítimo no lo
        // necesita, y es el vector de entidades externas (XXE) más común.
        // LIBXML_NONET evita además cualquier acceso de red durante el
        // parseo, defensa en profundidad sobre lo anterior.
        $withoutDoctype = preg_replace('/<!DOCTYPE[^>]*>/i', '', $raw);

        if ($withoutDoctype === null) {
            $this->fail();
        }

        $previousSetting = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = @$document->loadXML($withoutDoctype, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        $root = $document->documentElement;

        if (! $loaded || $root === null || strtolower($root->localName ?? $root->nodeName) !== 'svg') {
            $this->fail();
        }

        return $document;
    }

    private function removeDangerousElements(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        foreach (self::DANGEROUS_ELEMENTS as $tagName) {
            $nodes = [];

            foreach ($xpath->query("//*[local-name()='{$tagName}']") ?: [] as $node) {
                $nodes[] = $node;
            }

            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    private function removeDangerousAttributes(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $elements = [];

        foreach ($xpath->query('//*') ?: [] as $element) {
            $elements[] = $element;
        }

        foreach ($elements as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $attributesToRemove = [];
            $attributes = $element->attributes;

            for ($i = 0; $i < $attributes->length; $i++) {
                $attribute = $attributes->item($i);

                if ($attribute === null) {
                    continue;
                }

                $name = strtolower($attribute->nodeName);

                if (str_starts_with($name, 'on')) {
                    $attributesToRemove[] = $attribute->nodeName;

                    continue;
                }

                if (in_array($name, self::URL_ATTRIBUTES, true) && $this->isExternalReference($attribute->nodeValue)) {
                    $attributesToRemove[] = $attribute->nodeName;
                }
            }

            foreach ($attributesToRemove as $attributeName) {
                $element->removeAttribute($attributeName);
            }
        }
    }

    private function isExternalReference(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $value = trim($value);

        if ($value === '' || str_starts_with($value, '#')) {
            return false;
        }

        $lower = strtolower($value);

        return (bool) preg_match('#^(https?:)?//#i', $value)
            || str_starts_with($lower, 'javascript:')
            || str_starts_with($lower, 'data:text/html');
    }

    private function fail(): never
    {
        throw ApiException::validation([
            'file' => [[
                'code' => 'core.validation.svg_unrepairable',
                'message' => __('core.validation.svg_unrepairable'),
                'params' => [],
            ]],
        ]);
    }
}
