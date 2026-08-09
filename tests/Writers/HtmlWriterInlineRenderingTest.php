<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Writers;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Comment;
use Fissible\Transmark\Nodes\Inline\Footnote;
use Fissible\Transmark\Nodes\Inline\InlineImage;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Writers\Exception\UnsupportedHtmlNodeException;
use Fissible\Transmark\Writers\HtmlWriter;
use PHPUnit\Framework\TestCase;

final class HtmlWriterInlineRenderingTest extends TestCase
{
    public function test_renders_an_inline_image_with_alt_and_title(): void
    {
        $html = (new HtmlWriter())->write(new Document([
            new Paragraph([
                new Text('See '),
                new InlineImage('icon.png', 'An icon', 'Icon title'),
                new Text(' here'),
            ]),
        ]));

        self::assertSame(
            '<p>See <img src="icon.png" alt="An icon" title="Icon title"> here</p>',
            $html,
        );
    }

    public function test_renders_an_inline_image_without_a_title(): void
    {
        $html = (new HtmlWriter())->write(new Document([
            new Paragraph([new InlineImage('icon.png', 'An icon')]),
        ]));

        self::assertSame('<p><img src="icon.png" alt="An icon"></p>', $html);
    }

    public function test_escapes_inline_image_attributes(): void
    {
        $html = (new HtmlWriter())->write(new Document([
            new Paragraph([new InlineImage('a"b.png', '"quoted" alt', '"quoted" title')]),
        ]));

        self::assertStringNotContainsString('"quoted"', $html);
        self::assertStringContainsString('&quot;quoted&quot;', $html);
    }

    public function test_throws_on_footnote(): void
    {
        $this->expectException(UnsupportedHtmlNodeException::class);

        (new HtmlWriter())->write(new Document([
            new Paragraph([new Footnote('1', [new Paragraph([new Text('note')])])]),
        ]));
    }

    public function test_throws_on_comment(): void
    {
        $this->expectException(UnsupportedHtmlNodeException::class);

        (new HtmlWriter())->write(new Document([
            new Paragraph([new Comment([new Paragraph([new Text('remark')])])]),
        ]));
    }
}
