<?php

declare(strict_types=1);

namespace App\EventScraping\Infrastructure;

/**
 * Lo mínimo para leer HTML ajeno con XPath.
 *
 * `DOMDocument` viene con PHP y basta: un parser más fino no arregla que la web
 * cambie de maqueta, que es lo que de verdad rompe un scraper.
 */
final class Html
{
    public static function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        // Sin la declaración, libxml lee el documento como Latin-1 y las tildes
        // salen rotas.
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, \LIBXML_NOERROR | \LIBXML_NOWARNING);

        return new \DOMXPath($doc);
    }

    /** Selector por clase, que es casi siempre lo único estable de una maqueta. */
    public static function hasClass(string $class): string
    {
        return sprintf('contains(concat(" ", normalize-space(@class), " "), " %s ")', $class);
    }

    public static function text(\DOMXPath $xp, string $query, ?\DOMNode $context = null): ?string
    {
        $node = $xp->query($query, $context)?->item(0);
        if ($node === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

        return $text === '' ? null : $text;
    }

    public static function attr(\DOMXPath $xp, string $query, string $attribute, ?\DOMNode $context = null): ?string
    {
        $node = $xp->query($query, $context)?->item(0);
        if (!$node instanceof \DOMElement) {
            return null;
        }

        $value = trim($node->getAttribute($attribute));

        return $value === '' ? null : $value;
    }

    public static function absolute(?string $url, string $base): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        $parts  = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');

        return $origin . '/' . ltrim($url, '/');
    }

    /** Texto plano y corto para la descripción: sin HTML, sin espacios de más. */
    public static function clean(?string $text, int $max = 600): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
