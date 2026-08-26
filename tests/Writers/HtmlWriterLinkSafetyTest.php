<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Writers;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Image;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\InlineImage;
use Fissible\Transmark\Nodes\Inline\Link;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Writers\HtmlWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Untrusted documents must not be able to smuggle script URIs into
 * generated HTML: link hrefs and image sources are scheme-allowlisted
 * before emission.
 */
final class HtmlWriterLinkSafetyTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function unsafeUriProvider(): array
    {
        return [
            'plain javascript' => ['javascript:alert(1)'],
            'mixed case' => ['JaVaScRiPt:alert(1)'],
            'tab obfuscation' => ["java\tscript:alert(1)"],
            'leading whitespace' => ['   javascript:alert(1)'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'html data uri' => ['data:text/html,<script>alert(1)</script>'],
        ];
    }

    #[DataProvider('unsafeUriProvider')]
    public function test_unsafe_link_hrefs_do_not_reach_the_output(string $href): void
    {
        $document = new Document([
            new Paragraph([new Link($href, [new Text('click')], null)]),
        ]);

        $html = (new HtmlWriter())->write($document);

        self::assertSame('<p>click</p>', $html);
        self::assertStringNotContainsString(':', strip_tags($html));
    }

    public function test_safe_link_schemes_are_preserved(): void
    {
        foreach ([
            'https://example.com/page?a=1',
            'http://example.com/',
            'mailto:user@example.com',
            '#section2',
            '/relative/path',
            'images/pic.png',
        ] as $href) {
            $document = new Document([
                new Paragraph([new Link($href, [new Text('go')], null)]),
            ]);

            self::assertSame(
                '<p><a href="'.$href.'">go</a></p>',
                (new HtmlWriter())->write($document),
                'Scheme for '.$href.' should be preserved.',
            );
        }
    }

    public function test_unsafe_block_image_src_is_dropped(): void
    {
        $document = new Document([
            new Image('javascript:alert(1)', 'alt text'),
        ]);

        $html = (new HtmlWriter())->write($document);

        self::assertStringNotContainsString('javascript:', $html);
        self::assertSame('', $html);
    }

    public function test_embedded_data_image_src_is_preserved(): void
    {
        $document = new Document([
            new Paragraph([new InlineImage(
                '',
                'alt text',
                null,
                data: "\x89PNG fake bytes",
                mimeType: 'image/png',
            )]),
        ]);

        $html = (new HtmlWriter())->write($document);

        self::assertStringContainsString('<img src="data:image/png;base64,', $html);
    }
}
