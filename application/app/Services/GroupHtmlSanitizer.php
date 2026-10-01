<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Validation\ValidationException;

class GroupHtmlSanitizer
{
    private const ALLOWED = ['p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'h2', 'h3', 'blockquote', 'a'];

    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'svg', 'math',
        'template', 'noscript', 'textarea', 'select', 'title', 'head', 'xmp', 'plaintext'];

    public function sanitize(string $html): string
    {
        $limit = (int) config('groups.html_max_characters');
        if (! mb_check_encoding($html, 'UTF-8') || mb_strlen($html, 'UTF-8') > $limit) {
            throw ValidationException::withMessages(['full_description_html' => 'Полное описание превышает допустимый размер.']);
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $body = $document->getElementsByTagName('body')->item(0);
        $clean = $loaded && $body ? $this->children($body) : '';
        $visible = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_replace('/[\s\p{Z}\p{Cf}]+/u', '', $visible) === '') {
            throw ValidationException::withMessages(['full_description_html' => 'Введите содержательный текст полного описания.']);
        }
        if (mb_strlen($clean, 'UTF-8') > $limit) {
            throw ValidationException::withMessages(['full_description_html' => 'Полное описание превышает допустимый размер.']);
        }

        return $clean;
    }

    private function children(DOMNode $parent): string
    {
        $html = '';
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMText) {
                $html .= $this->escape($node->data);
            } elseif ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                if (in_array($tag, self::DROP, true)) {
                    continue;
                }
                $tag = match ($tag) {
                    'b' => 'strong', 'i' => 'em', default => $tag
                };
                $content = $this->children($node);
                if (! in_array($tag, self::ALLOWED, true)) {
                    $html .= $content;

                    continue;
                }
                $href = $node->getAttribute('href');
                $attribute = $tag === 'a' && $this->safeHref($href) ? ' href="'.$this->escape($href).'"' : '';
                // Rebuild only this small grammar; never serialize an untrusted DOM subtree.
                $html .= $tag === 'br' ? '<br>' : '<'.$tag.$attribute.'>'.$content.'</'.$tag.'>';
            }
        }

        return $html;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function safeHref(string $href): bool
    {
        if ($href === '' || preg_match('/[\x00-\x20\x7f\\\\]/u', $href)) {
            return false;
        }
        if (str_starts_with($href, '/')) {
            return ! str_starts_with($href, '//');
        }
        if (preg_match('/\Amailto:.+\z/i', $href)) {
            return true;
        }

        return preg_match('/\Ahttps?:\/\//i', $href) && filter_var($href, FILTER_VALIDATE_URL) !== false;
    }
}
