<?php

namespace App\Utils;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class RichTextSanitizer
{
    private const ALLOWED_TAGS = [
        'div',
        'p',
        'br',
        'strong',
        'b',
        'em',
        'i',
        'u',
        'ul',
        'ol',
        'li',
        'a',
        'h2',
        'h3',
        'blockquote',
        'pre',
        'code',
        'hr',
        'img',
    ];

    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
    ];

    public static function sanitize(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $content = function_exists('mb_convert_encoding')
            ? mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')
            : $html;

        $document->loadHTML(
            $content,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        self::cleanNodes($document);

        $sanitized = $document->saveHTML();
        return trim($sanitized ?? '');
    }

    private static function cleanNodes(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*');
        if (!$nodes) {
            return;
        }

        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $node = $nodes->item($i);
            if (!$node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->nodeName);
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                self::unwrapNode($node);
                continue;
            }

            $allowedAttributes = self::ALLOWED_ATTRIBUTES[$tag] ?? [];
            if ($node->hasAttributes()) {
                foreach (iterator_to_array($node->attributes) as $attribute) {
                    $name = strtolower($attribute->nodeName);
                    if (!in_array($name, $allowedAttributes, true)) {
                        $node->removeAttributeNode($attribute);
                    }
                }
            }

            if ($tag === 'a') {
                $href = trim($node->getAttribute('href'));
                if ($href === '' || !preg_match('/^(https?:|mailto:|tel:|#|\/|\.\/|\.\.\/)/i', $href)) {
                    $node->removeAttribute('href');
                }

                if ($node->getAttribute('target') === '_blank') {
                    $node->setAttribute('rel', 'noopener noreferrer');
                } else {
                    $node->removeAttribute('target');
                    $node->removeAttribute('rel');
                }
            }

            if ($tag === 'img') {
                $src = trim($node->getAttribute('src'));
                if ($src === '' || !preg_match('/^(https?:|data:image\/|\/|\.\/|\.\.\/)/i', $src)) {
                    $node->parentNode?->removeChild($node);
                    continue;
                }

                foreach (['width', 'height'] as $dimension) {
                    $value = $node->getAttribute($dimension);
                    if ($value !== '' && !ctype_digit($value)) {
                        $node->removeAttribute($dimension);
                    }
                }
            }
        }
    }

    private static function unwrapNode(DOMNode $node): void
    {
        $parent = $node->parentNode;
        if (!$parent) {
            return;
        }

        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }
}
