<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Actions\UploadContentMedia;
use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;

/**
 * The SVG counterpart of the GD re-encode in {@see UploadContentMedia}.
 *
 * A raster upload is made safe by decoding and re-emitting its pixels, so
 * nothing of the original container survives. An SVG has no pixels to
 * re-emit, so this does the equivalent for a document: it parses the upload
 * with the network and entity expansion off, then builds a *new* document
 * by copying across only the elements and attributes on the allowlists
 * below. Scripts, event handlers, foreign content, external references and
 * anything else it does not recognise never reach the output — they are not
 * "removed", they were simply never copied.
 *
 * What is kept is what a logo or illustration exported from Illustrator,
 * Figma or Inkscape actually uses: shapes, paths, text, groups, gradients,
 * clip paths, masks, symbols and their presentation attributes. A `style`
 * attribute or `<style>` element survives only when it carries no `url()`,
 * `@import` or `expression()` — the three ways CSS can reach outside the
 * file. `href` survives only as a same-document `#id` reference.
 */
final class SvgSanitizer
{
    private const string SVG_NS = 'http://www.w3.org/2000/svg';

    private const string XLINK_NS = 'http://www.w3.org/1999/xlink';

    /** @var list<string> */
    private const array ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath',
        'linearGradient', 'radialGradient', 'stop', 'pattern',
        'clipPath', 'mask', 'marker',
        'style',
    ];

    /** @var list<string> */
    private const array ATTRIBUTES = [
        // Structure and geometry.
        'id', 'class', 'x', 'y', 'width', 'height', 'viewBox', 'preserveAspectRatio',
        'd', 'points', 'cx', 'cy', 'r', 'rx', 'ry', 'x1', 'y1', 'x2', 'y2', 'dx', 'dy',
        'transform', 'version', 'xml:space', 'enable-background', 'overflow',
        // Paint.
        'fill', 'fill-opacity', 'fill-rule', 'opacity', 'color',
        'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin',
        'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset',
        'paint-order', 'vector-effect', 'shape-rendering', 'display', 'visibility',
        // Gradients, patterns, clipping and masking.
        'gradientUnits', 'gradientTransform', 'spreadMethod', 'offset', 'stop-color', 'stop-opacity',
        'patternUnits', 'patternContentUnits', 'patternTransform',
        'clip-path', 'clip-rule', 'clipPathUnits', 'mask', 'maskUnits', 'maskContentUnits',
        'markerWidth', 'markerHeight', 'markerUnits', 'refX', 'refY', 'orient',
        'marker-start', 'marker-mid', 'marker-end',
        // Text.
        'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant',
        'letter-spacing', 'word-spacing', 'text-anchor', 'dominant-baseline',
        'alignment-baseline', 'baseline-shift', 'text-decoration', 'writing-mode',
        'startOffset', 'lengthAdjust', 'textLength',
        // Same-document references only — see attribute handling.
        'href', 'xlink:href', 'style',
    ];

    /**
     * @return array{0: string, 1: int|null, 2: int|null} sanitised bytes, width, height
     */
    public function sanitize(string $contents): array
    {
        // Entity declarations are how an SVG reads files or balloons memory;
        // there is no legitimate reason for a logo to declare any.
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $contents) === 1) {
            throw new InvalidArgumentException('The SVG declares a DOCTYPE or entities, which are not allowed.');
        }

        $source = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            // No network, no entity substitution, no blank text nodes.
            $loaded = $source->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $source->documentElement;

        if (! $loaded || ! $root instanceof DOMElement || $root->localName !== 'svg' || $root->namespaceURI !== self::SVG_NS) {
            throw new InvalidArgumentException('The uploaded file is not a readable SVG image.');
        }

        $clean = new DOMDocument('1.0', 'UTF-8');
        $cleanRoot = $this->copyElement($root, $clean);

        if ($cleanRoot === null) {
            throw new InvalidArgumentException('The uploaded file is not a readable SVG image.');
        }

        $clean->appendChild($cleanRoot);

        [$width, $height] = $this->dimensions($cleanRoot);

        $binary = $clean->saveXML();

        if ($binary === false || $binary === '') {
            throw new InvalidArgumentException('The SVG could not be rebuilt.');
        }

        return [$binary, $width, $height];
    }

    /**
     * Copies one element and its allowed descendants into the clean
     * document. Returns null for an element that is not on the allowlist —
     * and with it, everything beneath it.
     */
    private function copyElement(DOMElement $from, DOMDocument $into): ?DOMElement
    {
        if ($from->namespaceURI !== self::SVG_NS || ! in_array($from->localName, self::ELEMENTS, true)) {
            return null;
        }

        $to = $into->createElementNS(self::SVG_NS, $from->localName);

        // Declared once on the root, before any child is copied, so the
        // `xlink:href` attributes beneath do not each re-declare it.
        if ($from === $from->ownerDocument?->documentElement) {
            $to->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xlink', self::XLINK_NS);
        }

        foreach ($from->attributes ?? [] as $attribute) {
            $name = $attribute->nodeName;
            $value = trim((string) $attribute->value);

            // Namespace declarations for the two namespaces we emit are
            // re-added by DOM itself; any other xmlns is dropped with its
            // content.
            if ($name === 'xmlns' || $name === 'xmlns:xlink') {
                continue;
            }

            if (! in_array($name, self::ATTRIBUTES, true) || ! $this->isSafeValue($name, $value)) {
                continue;
            }

            if ($name === 'xlink:href') {
                $to->setAttributeNS(self::XLINK_NS, 'xlink:href', $value);
            } elseif ($name === 'xml:space') {
                $to->setAttribute('xml:space', $value);
            } else {
                $to->setAttribute($name, $value);
            }
        }

        // A `<use>` whose reference was dropped (it pointed off-document)
        // has nothing left to draw.
        if ($from->localName === 'use' && ! $to->hasAttribute('href') && ! $to->hasAttributeNS(self::XLINK_NS, 'href')) {
            return null;
        }

        foreach ($from->childNodes as $child) {
            $copied = $this->copyNode($child, $into, $from->localName);

            if ($copied !== null) {
                $to->appendChild($copied);
            }
        }

        return $to;
    }

    private function copyNode(DOMNode $node, DOMDocument $into, string $parent): ?DOMNode
    {
        if ($node instanceof DOMElement) {
            return $this->copyElement($node, $into);
        }

        // Text is only meaningful inside text-bearing elements; a `<style>`
        // body gets the same CSS check as a style attribute. Comments,
        // processing instructions and CDATA wrappers are never copied.
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            $text = (string) $node->nodeValue;

            if (! in_array($parent, ['text', 'tspan', 'textPath', 'title', 'desc', 'style'], true)) {
                return null;
            }

            if ($parent === 'style' && ! $this->isSafeCss($text)) {
                return null;
            }

            return $into->createTextNode($text);
        }

        return null;
    }

    private function isSafeValue(string $name, string $value): bool
    {
        if ($name === 'href' || $name === 'xlink:href') {
            return str_starts_with($value, '#') && strlen($value) > 1;
        }

        if ($name === 'style') {
            return $this->isSafeCss($value);
        }

        // Presentation attributes may reference gradients and clip paths by
        // `url(#id)`; anything else inside url() is an external fetch.
        if (preg_match('/url\s*\(\s*["\']?\s*(?!#)/i', $value) === 1) {
            return false;
        }

        return stripos($value, 'javascript:') === false && stripos($value, 'data:') === false;
    }

    private function isSafeCss(string $css): bool
    {
        return preg_match('/url\s*\(\s*["\']?\s*(?!#)|@import|expression\s*\(|javascript:|data:|behavior\s*:/i', $css) !== 1;
    }

    /**
     * Intrinsic size for the media row, from width/height or the viewBox.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(DOMElement $svg): array
    {
        $width = $this->length($svg->getAttribute('width'));
        $height = $this->length($svg->getAttribute('height'));

        if ($width === null || $height === null) {
            $viewBox = preg_split('/[\s,]+/', trim($svg->getAttribute('viewBox'))) ?: [];

            if (count($viewBox) === 4 && is_numeric($viewBox[2]) && is_numeric($viewBox[3])) {
                $width = (int) round((float) $viewBox[2]);
                $height = (int) round((float) $viewBox[3]);
            }
        }

        // The media row's columns are unsigned smallints; a vector's nominal
        // size is advisory anyway, so an absurd one is simply not recorded.
        if ($width === null || $height === null || $width < 1 || $height < 1 || $width > 65535 || $height > 65535) {
            return [null, null];
        }

        return [$width, $height];
    }

    private function length(string $value): ?int
    {
        return preg_match('/^\s*(\d+(?:\.\d+)?)\s*(?:px)?\s*$/', $value, $m) === 1 ? (int) round((float) $m[1]) : null;
    }
}
