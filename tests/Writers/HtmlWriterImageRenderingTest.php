<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Writers;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Image;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\InlineImage;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Writers\HtmlWriter;
use PHPUnit\Framework\TestCase;

final class HtmlWriterImageRenderingTest extends TestCase
{
    public function test_renders_a_block_image_with_embedded_data_as_a_base64_data_uri(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n fake but distinctive bytes";
        $image = new Image('word/media/image1.png', 'A photo', 'Photo title', $bytes, 'image/png', 640, 480);

        $html = (new HtmlWriter())->write(new Document([$image]));

        $expectedSrc = 'data:image/png;base64,'.base64_encode($bytes);
        self::assertSame(
            '<img src="'.$expectedSrc.'" alt="A photo" title="Photo title" width="640" height="480">',
            $html,
        );
    }

    public function test_decoding_the_rendered_data_uri_reproduces_the_original_bytes(): void
    {
        $bytes = random_bytes(64);
        $image = new Image('image1.jpg', '', null, $bytes, 'image/jpeg');

        $html = (new HtmlWriter())->write(new Document([$image]));

        preg_match('/src="data:image\/jpeg;base64,([^"]+)"/', $html, $matches);
        self::assertSame($bytes, base64_decode($matches[1], true));
    }

    public function test_renders_a_block_image_without_embedded_data_as_a_plain_src_reference(): void
    {
        $image = new Image('https://example.com/photo.jpg', 'A photo');

        $html = (new HtmlWriter())->write(new Document([$image]));

        self::assertSame('<img src="https://example.com/photo.jpg" alt="A photo">', $html);
    }

    public function test_omits_width_and_height_attributes_when_not_declared(): void
    {
        $image = new Image('photo.jpg', '', null, 'bytes', 'image/jpeg');

        $html = (new HtmlWriter())->write(new Document([$image]));

        self::assertStringNotContainsString('width', $html);
        self::assertStringNotContainsString('height', $html);
    }

    public function test_omits_title_when_not_declared(): void
    {
        $image = new Image('photo.jpg');

        $html = (new HtmlWriter())->write(new Document([$image]));

        self::assertStringNotContainsString('title', $html);
    }

    public function test_escapes_block_image_attributes(): void
    {
        $image = new Image('a"b.png', '"quoted" alt', '"quoted" title');

        $html = (new HtmlWriter())->write(new Document([$image]));

        self::assertStringNotContainsString('"quoted"', $html);
        self::assertStringContainsString('&quot;quoted&quot;', $html);
    }

    public function test_renders_an_inline_image_with_embedded_data_as_a_base64_data_uri(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n fake icon bytes";
        $inlineImage = new InlineImage('word/media/image2.png', 'An icon', null, $bytes, 'image/png', 32, 32);

        $html = (new HtmlWriter())->write(new Document([
            new Paragraph([new Text('See '), $inlineImage, new Text(' here')]),
        ]));

        $expectedSrc = 'data:image/png;base64,'.base64_encode($bytes);
        self::assertSame(
            '<p>See <img src="'.$expectedSrc.'" alt="An icon" width="32" height="32"> here</p>',
            $html,
        );
    }

    public function test_inline_image_without_embedded_data_still_renders_a_plain_src_reference(): void
    {
        // Regression: this is the pre-existing behavior from #47 - make
        // sure adding data-URI support didn't break the plain src path.
        $html = (new HtmlWriter())->write(new Document([
            new Paragraph([new InlineImage('icon.png', 'An icon', 'Icon title')]),
        ]));

        self::assertSame('<p><img src="icon.png" alt="An icon" title="Icon title"></p>', $html);
    }
}
