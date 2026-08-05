<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Writers;

use Fissible\Transmark\Contracts\WriterInterface;
use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\ListItem;
use Fissible\Transmark\Nodes\Block\ListNode;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Link;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Numbering\AbstractNum;
use Fissible\Transmark\Numbering\Level;
use Fissible\Transmark\Numbering\Num;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingDefinitions;
use Fissible\Transmark\Numbering\NumberingRef;
use Fissible\Transmark\Readers\DocxReader;
use Fissible\Transmark\Writers\HtmlWriter;
use PHPUnit\Framework\TestCase;

final class HtmlWriterSimpleListsTest extends TestCase
{
    public function test_nested_listnode_listitem_renders_as_nested_ol(): void
    {
        $writer = new HtmlWriter();
        $document = new Document([
            new ListNode(ListNode::TYPE_ORDERED, [
                new ListItem([
                    $this->paragraph('Parent'),
                    new ListNode(ListNode::TYPE_ORDERED, [
                        new ListItem([
                            $this->paragraph('Child'),
                            new ListNode(ListNode::TYPE_ORDERED, [
                                new ListItem([$this->paragraph('Grandchild')]),
                            ]),
                        ]),
                    ]),
                ]),
            ]),
        ]);

        self::assertInstanceOf(WriterInterface::class, $writer);
        self::assertSame(
            '<ol><li>Parent<ol><li>Child<ol><li>Grandchild</li></ol></li></ol></li></ol>',
            $writer->write($document),
        );
    }

    public function test_unordered_listnode_renders_as_ul(): void
    {
        $document = new Document([
            new ListNode(ListNode::TYPE_UNORDERED, [
                new ListItem([$this->paragraph('First')]),
                new ListItem([$this->paragraph('Second')]),
            ]),
        ]);

        self::assertSame(
            '<ul><li>First</li><li>Second</li></ul>',
            (new HtmlWriter())->write($document),
        );
    }

    public function test_ordered_listnode_preserves_a_non_default_start(): void
    {
        $document = new Document([
            new ListNode(
                ListNode::TYPE_ORDERED,
                [new ListItem([$this->paragraph('Third')])],
                start: 3,
            ),
        ]);

        self::assertSame(
            '<ol start="3"><li>Third</li></ol>',
            (new HtmlWriter())->write($document),
        );
    }

    public function test_simple_nested_lists_fixture_renders_as_native_ol_not_literal_labels(): void
    {
        $document = (new DocxReader())->read($this->fixtureDocx('simple-nested-lists'));

        $html = (new HtmlWriter())->write($document);

        self::assertSame(
            '<h1>Sample Agreement</h1><ol><li>Term of Agreement'
            .'<ol><li>Initial Term</li><li>Renewal'
            .'<ol style="list-style-type: lower-alpha"><li>Automatic renewal</li><li>Notice of non-renewal'
            .'<ol style="list-style-type: lower-roman"><li>Written notice</li><li>Delivery method</li></ol>'
            .'</li></ol></li></ol></li><li>Termination</li></ol>',
            $html,
        );
        self::assertStringNotContainsString('1. Term of Agreement', $html);
    }

    public function test_lowerroman_format_maps_to_css_list_style_type(): void
    {
        $document = new Document(
            content: [new Paragraph(
                [new Text('Written notice')],
                numbering: new NumberingRef(10, 0),
            )],
            numbering: new NumberingDefinitions(
                abstractNums: [1 => new AbstractNum(1, [
                    0 => new Level(0, NumberFormat::LowerRoman, '%1'),
                ])],
                nums: [10 => new Num(10, 1)],
            ),
        );

        self::assertSame(
            '<ol style="list-style-type: lower-roman"><li>Written notice</li></ol>',
            (new HtmlWriter())->write($document),
        );
    }

    public function test_numbered_bullet_paragraphs_render_as_ul(): void
    {
        $document = new Document(
            content: [new Paragraph(
                [new Text('Bullet item')],
                numbering: new NumberingRef(10, 0),
            )],
            numbering: new NumberingDefinitions(
                abstractNums: [1 => new AbstractNum(1, [
                    0 => new Level(0, NumberFormat::Bullet, '•'),
                ])],
                nums: [10 => new Num(10, 1)],
            ),
        );

        self::assertSame(
            '<ul><li>Bullet item</li></ul>',
            (new HtmlWriter())->write($document),
        );
    }

    public function test_numid_is_not_native_when_any_used_level_is_legal(): void
    {
        $document = new Document(
            content: [
                new Paragraph([new Text('Parent')], numbering: new NumberingRef(10, 0)),
                new Paragraph([new Text('Child')], numbering: new NumberingRef(10, 1)),
            ],
            numbering: new NumberingDefinitions(
                abstractNums: [1 => new AbstractNum(1, [
                    0 => new Level(0, NumberFormat::Decimal, '%1'),
                    1 => new Level(1, NumberFormat::Decimal, '%1.%2', isLegal: true),
                ])],
                nums: [10 => new Num(10, 1)],
            ),
        );

        self::assertSame(
            '<p class="numbered-paragraph legal-level-0">1 Parent</p>'
            .'<p class="numbered-paragraph legal-level-1">1.1 Child</p>',
            (new HtmlWriter())->write($document),
        );
    }

    public function test_text_content_is_html_escaped(): void
    {
        $document = new Document([
            new Paragraph([
                new Text('<script>alert("unsafe")</script> & '),
                new Link('https://example.com/?a=1&b="2"', [new Text('<read>')], 'A "title"'),
            ]),
        ]);

        self::assertSame(
            '<p>&lt;script&gt;alert(&quot;unsafe&quot;)&lt;/script&gt; &amp; '
            .'<a href="https://example.com/?a=1&amp;b=&quot;2&quot;" title="A &quot;title&quot;">'
            .'&lt;read&gt;</a></p>',
            (new HtmlWriter())->write($document),
        );
    }

    private function paragraph(string $text): Paragraph
    {
        return new Paragraph([new Text($text)]);
    }

    private function fixtureDocx(string $name): string
    {
        $fixturePath = dirname(__DIR__).'/fixtures/numbering/'.$name;
        $documentXml = file_get_contents($fixturePath.'/document.xml');
        $numberingXml = file_get_contents($fixturePath.'/numbering.xml');
        self::assertIsString($documentXml);
        self::assertIsString($numberingXml);

        return $this->docx([
            'word/document.xml' => $documentXml,
            'word/numbering.xml' => $numberingXml,
        ]);
    }

    /**
     * @param array<string, string> $parts
     */
    private function docx(array $parts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'transmark-html-writer-test-');
        self::assertIsString($path);

        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));

            foreach ($parts as $partPath => $contents) {
                self::assertTrue($zip->addFromString($partPath, $contents));
            }

            self::assertTrue($zip->close());
            $bytes = file_get_contents($path);
            self::assertIsString($bytes);

            return $bytes;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
