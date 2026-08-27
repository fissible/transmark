<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Writers;

use Fissible\Transmark\Attributes;
use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Heading;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Code;
use Fissible\Transmark\Nodes\Inline\Link;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Numbering\AbstractNum;
use Fissible\Transmark\Numbering\Level;
use Fissible\Transmark\Numbering\Num;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingDefinitions;
use Fissible\Transmark\Numbering\NumberingRef;
use Fissible\Transmark\Readers\HtmlReader;
use Fissible\Transmark\Writers\HtmlWriter;
use PHPUnit\Framework\TestCase;

/**
 * Every node carries an Attributes bag (id, classes, data). The HTML leg
 * is its first real consumer: HtmlReader populates id/class from the
 * source elements and HtmlWriter emits them back, so they survive an
 * HTML read -> write round-trip.
 */
final class HtmlWriterAttributesTest extends TestCase
{
    public function test_heading_emits_id_and_class_attributes(): void
    {
        $document = new Document([
            new Heading(2, [new Text('Scope')], new Attributes(id: 'scope', classes: ['section', 'title'])),
        ]);

        self::assertSame(
            '<h2 id="scope" class="section title">Scope</h2>',
            (new HtmlWriter())->write($document),
        );
    }

    public function test_paragraph_emits_id_and_class_attributes(): void
    {
        $document = new Document([
            new Paragraph([new Text('Hello')], attributes: new Attributes(id: 'intro', classes: ['lead'])),
        ]);

        self::assertSame(
            '<p id="intro" class="lead">Hello</p>',
            (new HtmlWriter())->write($document),
        );
    }

    public function test_link_emits_id_and_class_attributes(): void
    {
        $document = new Document([
            new Paragraph([new Link('https://example.com', [new Text('link')], attributes: new Attributes(id: 'l1', classes: ['btn']))]),
        ]);

        self::assertSame(
            '<p><a href="https://example.com" id="l1" class="btn">link</a></p>',
            (new HtmlWriter())->write($document),
        );
    }

    public function test_code_emits_class_attribute(): void
    {
        $document = new Document([
            new Paragraph([new Code('x', new Attributes(classes: ['lang-php']))]),
        ]);

        self::assertSame(
            '<p><code class="lang-php">x</code></p>',
            (new HtmlWriter())->write($document),
        );
    }

    public function test_legal_numbered_paragraph_merges_user_classes(): void
    {
        $definitions = (new NumberingDefinitions())
            ->withAbstractNum(new AbstractNum(0, [
                0 => new Level(0, NumberFormat::Decimal, '%1', isLegal: true),
            ]))
            ->withNum(new Num(1, 0));
        $document = new Document([
            new Paragraph([new Text('x')], numbering: new NumberingRef(1, 0), attributes: new Attributes(classes: ['user'])),
        ], $definitions);

        $html = (new HtmlWriter())->write($document);

        self::assertStringContainsString('<p class="numbered-paragraph legal-level-0 user">', $html);
    }

    public function test_attributes_values_are_escaped(): void
    {
        $document = new Document([
            new Paragraph([new Text('x')], attributes: new Attributes(id: 'a"b', classes: ['c"d'])),
        ]);

        self::assertSame(
            '<p id="a&quot;b" class="c&quot;d">x</p>',
            (new HtmlWriter())->write($document),
        );
    }

    public function test_id_and_class_survive_an_html_round_trip(): void
    {
        $html = '<h2 id="scope" class="section title">Scope</h2>'
            .'<p id="intro" class="lead">Hello <a href="https://example.com" id="l1" class="btn">link</a></p>';

        self::assertSame($html, (new HtmlWriter())->write((new HtmlReader())->read($html)));
    }
}
