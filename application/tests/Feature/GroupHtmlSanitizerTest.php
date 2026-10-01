<?php

namespace Tests\Feature;

use App\Services\GroupHtmlSanitizer;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroupHtmlSanitizerTest extends TestCase
{
    public function test_semantic_grammar_and_safe_links_survive_without_client_attributes(): void
    {
        $input = '<h2 class="x">Title</h2><h3 id="x">Subtitle</h3><p style="color:red" data-x="a" onclick="bad()"><b>Bold</b><br><i>Italic</i></p><ul><li>A</li></ul><ol><li>B</li></ol><blockquote>Quote</blockquote>';
        $expected = '<h2>Title</h2><h3>Subtitle</h3><p><strong>Bold</strong><br><em>Italic</em></p><ul><li>A</li></ul><ol><li>B</li></ol><blockquote>Quote</blockquote>';
        foreach (['http://example.test', 'https://example.test/path?a=1&amp;b=2', 'mailto:test@example.test', '/path'] as $href) {
            $input .= '<a href="'.$href.'" target="_blank" rel="anything">Link</a>';
            $expected .= '<a href="'.$href.'">Link</a>';
        }
        $sanitizer = app(GroupHtmlSanitizer::class);
        $this->assertSame($expected, $sanitizer->sanitize($input));
        $this->assertSame($expected, $sanitizer->sanitize($expected));
    }

    public static function unsafe(): array
    {
        return array_map(fn ($href) => [$href], ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'java&#x09;script:alert(1)', 'data:text/html,attack', '//example.test', '/\\example.test', 'ftp://example.test', 'relative', 'https://example.test&#10;attack']);
    }

    #[DataProvider('unsafe')]
    public function test_unsafe_href_is_removed(string $href): void
    {
        $this->assertSame('<a>Text</a>', app(GroupHtmlSanitizer::class)->sanitize('<a href="'.$href.'">Text</a>'));
    }

    public function test_dangerous_subtrees_comments_and_mutation_payloads_are_not_serialized(): void
    {
        $html = '<section><span>Visible</span></section><!-- comment -->';
        foreach (['script', 'style', 'iframe', 'object', 'svg', 'math', 'form', 'button', 'template'] as $tag) {
            $html .= '<'.$tag.'>ATTACK</'.$tag.'>';
        }
        $html .= '<embed src="evil"><input value="evil"><img src="x" onerror="evil()">';
        $this->assertSame('Visible', app(GroupHtmlSanitizer::class)->sanitize($html));
        $clean = app(GroupHtmlSanitizer::class)->sanitize('<p>Safe</p><math><mtext><table><mglyph><style><!--</style><img title="--><img src=x onerror=alert(1)>">');
        $this->assertStringNotContainsString('<img', $clean);
        $this->assertStringNotContainsString('<math', $clean);
    }

    public static function emptyContent(): array
    {
        return [[''], ['   '], ['<p><br></p>'], ['<p>&nbsp;&#8203;</p>'], ['<script>attack</script>']];
    }

    #[DataProvider('emptyContent')]
    public function test_empty_or_invisible_markup_is_rejected(string $html): void
    {
        $this->expectException(ValidationException::class);
        app(GroupHtmlSanitizer::class)->sanitize($html);
    }

    public function test_raw_and_expanded_sanitized_limits_are_enforced(): void
    {
        config(['groups.html_max_characters' => 20]);
        foreach ([str_repeat('a', 21), str_repeat('&', 10)] as $html) {
            try {
                app(GroupHtmlSanitizer::class)->sanitize($html);
                $this->fail('Oversized content accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('full_description_html', $exception->errors());
            }
        }
    }
}
