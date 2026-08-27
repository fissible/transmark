<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Writers;

use Fissible\Transmark\Contracts\WriterInterface;
use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\BlockQuote;
use Fissible\Transmark\Nodes\Block\CodeBlock;
use Fissible\Transmark\Nodes\Block\Heading;
use Fissible\Transmark\Nodes\Block\HorizontalRule;
use Fissible\Transmark\Nodes\Block\Image;
use Fissible\Transmark\Nodes\Block\ListItem;
use Fissible\Transmark\Nodes\Block\ListNode;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Block\Table;
use Fissible\Transmark\Nodes\Block\TableCell;
use Fissible\Transmark\Nodes\Block\TableRow;
use Fissible\Transmark\Nodes\Inline\Comment;
use Fissible\Transmark\Nodes\Inline\Emphasis;
use Fissible\Transmark\Nodes\Inline\Footnote;
use Fissible\Transmark\Nodes\Inline\InlineImage;
use Fissible\Transmark\Nodes\Inline\LineBreak;
use Fissible\Transmark\Nodes\Inline\Link;
use Fissible\Transmark\Nodes\Inline\RawHtml;
use Fissible\Transmark\Nodes\Inline\Strike;
use Fissible\Transmark\Nodes\Inline\Strong;
use Fissible\Transmark\Nodes\Inline\Superscript;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Nodes\Inline\Underline;
use Fissible\Transmark\Numbering\AbstractNum;
use Fissible\Transmark\Numbering\Level;
use Fissible\Transmark\Numbering\Num;
use Fissible\Transmark\Numbering\NumberFormat;
use Fissible\Transmark\Numbering\NumberingDefinitions;
use Fissible\Transmark\Numbering\NumberingRef;
use Fissible\Transmark\Numbering\RestartRule;
use Fissible\Transmark\Readers\DocxReader;
use Fissible\Transmark\Writers\DocxWriter;
use Fissible\Transmark\Writers\Exception\DocxWriteException;
use Fissible\Transmark\Writers\Exception\UnsupportedNodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocxWriterTest extends TestCase
{
    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const PACKAGE_RELATIONSHIPS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    public function test_empty_document_contains_a_valid_minimal_opc_package(): void
    {
        $writer = new DocxWriter();
        $parts = $this->packageParts($writer->write(new Document()));

        self::assertInstanceOf(WriterInterface::class, $writer);
        self::assertSame([
            '[Content_Types].xml',
            '_rels/.rels',
            'word/_rels/document.xml.rels',
            'word/document.xml',
            'word/styles.xml',
        ], array_keys($parts));

        foreach ($parts as $name => $contents) {
            self::assertNotSame('', $contents, $name.' must not be empty.');
            $dom = new \DOMDocument();
            self::assertTrue($dom->loadXML($contents), $name.' must be well-formed XML.');
        }

        self::assertStringContainsString('word/document.xml', $parts['[Content_Types].xml']);
        self::assertStringContainsString('word/styles.xml', $parts['[Content_Types].xml']);
    }

    public function test_paragraph_heading_quote_and_rule_roundtrip_through_docxreader(): void
    {
        $document = new Document([
            $this->paragraph('Plain'),
            new Heading(2, [new Text('Scope')]),
            new BlockQuote([new Paragraph([new Text('Quoted')], styleName: 'Quote')]),
            new HorizontalRule(),
        ]);

        $roundTripped = $this->roundTrip($document);

        self::assertCount(4, $roundTripped->content());
        self::assertSame('Plain', $this->paragraphText($roundTripped->content()[0]));
        self::assertInstanceOf(Heading::class, $roundTripped->content()[1]);
        self::assertSame(2, $roundTripped->content()[1]->level());
        self::assertInstanceOf(BlockQuote::class, $roundTripped->content()[2]);
        self::assertSame('Quote', $roundTripped->content()[2]->content()[0]->styleName());
        self::assertInstanceOf(HorizontalRule::class, $roundTripped->content()[3]);
    }

    public function test_nested_inline_properties_and_line_breaks_roundtrip(): void
    {
        $document = new Document([new Paragraph([
            new Strong([new Emphasis([new Underline([new Strike([
                new Superscript([new Text('nested')]),
            ])])])]),
            new LineBreak(),
            new Text('after'),
        ])]);

        $paragraph = $this->roundTrip($document)->content()[0];

        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertInstanceOf(Strong::class, $paragraph->inlines()[0]);
        self::assertInstanceOf(Emphasis::class, $paragraph->inlines()[0]->children()[0]);
        self::assertInstanceOf(Underline::class, $paragraph->inlines()[0]->children()[0]->children()[0]);
        self::assertInstanceOf(Strike::class, $paragraph->inlines()[0]->children()[0]->children()[0]->children()[0]);
        self::assertInstanceOf(
            Superscript::class,
            $paragraph->inlines()[0]->children()[0]->children()[0]->children()[0]->children()[0],
        );
        self::assertInstanceOf(LineBreak::class, $paragraph->inlines()[1]);
        self::assertSame('after', $paragraph->inlines()[2]->content());
    }

    public function test_code_block_uses_monospace_style_and_preserves_line_breaks(): void
    {
        $parts = $this->packageParts((new DocxWriter())->write(new Document([
            new CodeBlock("first <line>\nsecond & line", 'php'),
        ])));
        $xpath = $this->wordXPath($parts['word/document.xml']);

        self::assertSame('CodeBlock', $xpath->evaluate('string(//w:pPr/w:pStyle/@w:val)'));
        self::assertSame(1, $xpath->query('//w:br')->length);
        self::assertSame(2, $xpath->query('//w:r/w:rPr/w:rFonts')->length);
        self::assertSame('first <line>second & line', $xpath->evaluate('string(//w:p)'));
    }

    public function test_significant_whitespace_and_xml_characters_are_preserved(): void
    {
        $text = ' leading  & <tag> "quoted" trailing ';
        $bytes = (new DocxWriter())->write(new Document([$this->paragraph($text)]));
        $parts = $this->packageParts($bytes);

        self::assertStringContainsString('xml:space="preserve"', $parts['word/document.xml']);
        self::assertSame($text, $this->paragraphText((new DocxReader())->read($bytes)->content()[0]));
    }

    public function test_numbering_definitions_roundtrip_without_semantic_changes(): void
    {
        $definitions = new NumberingDefinitions(
            abstractNums: [5 => new AbstractNum(5, [
                0 => new Level(0, NumberFormat::UpperRoman, '%1.', start: 3),
                1 => new Level(
                    1,
                    NumberFormat::LowerLetter,
                    '%1.%2.',
                    isLegal: true,
                    restartRule: RestartRule::Never,
                ),
                2 => new Level(
                    2,
                    NumberFormat::Decimal,
                    '%1.%2.%3.',
                    restartRule: RestartRule::AfterIlvl,
                    restartAfterIlvl: 0,
                ),
            ], 'multilevel')],
            nums: [9 => new Num(9, 5, [1 => 7])],
        );
        $document = new Document(
            content: [new Paragraph([new Text('Numbered')], numbering: new NumberingRef(9, 1))],
            numbering: $definitions,
        );

        $roundTripped = $this->roundTrip($document);
        $actual = $roundTripped->numbering();

        self::assertSame([5], array_keys($actual->abstractNums()));
        self::assertSame([9], array_keys($actual->nums()));
        self::assertSame('multilevel', $actual->abstractNum(5)?->multiLevelType());
        self::assertSame(NumberFormat::UpperRoman, $actual->levelFor(9, 0)?->format());
        self::assertSame(3, $actual->levelFor(9, 0)?->start());
        self::assertTrue($actual->levelFor(9, 1)?->isLegal());
        self::assertSame(RestartRule::Never, $actual->levelFor(9, 1)?->restartRule());
        self::assertSame(RestartRule::AfterIlvl, $actual->levelFor(9, 2)?->restartRule());
        self::assertSame(0, $actual->levelFor(9, 2)?->restartAfterIlvl());
        self::assertSame([1 => 7], $actual->num(9)?->levelOverrides());
        self::assertSame(9, $roundTripped->content()[0]->numbering()?->numId());
        self::assertSame(1, $roundTripped->content()[0]->numbering()?->ilvl());
    }

    #[DataProvider('numberingFixtureNames')]
    public function test_simple_and_legal_numbering_refs_roundtrip(string $fixture): void
    {
        $original = $this->readNumberingFixture($fixture);
        $roundTripped = $this->roundTrip($original);

        self::assertEquals($original->numbering(), $roundTripped->numbering());
        self::assertSame(
            $this->numberingPointers($original),
            $this->numberingPointers($roundTripped),
        );
    }

    public function test_structural_lists_generate_collision_free_numbering_definitions(): void
    {
        $document = new Document(
            content: [
                new ListNode(ListNode::TYPE_ORDERED, [
                    new ListItem([
                        $this->paragraph('Parent'),
                        new ListNode(ListNode::TYPE_UNORDERED, [
                            new ListItem([$this->paragraph('Child')]),
                        ]),
                    ]),
                    new ListItem([$this->paragraph('Sibling')]),
                ], start: 3),
            ],
            numbering: new NumberingDefinitions(
                abstractNums: [10 => new AbstractNum(10, [
                    0 => new Level(0, NumberFormat::Decimal, '%1.'),
                ])],
                nums: [20 => new Num(20, 10)],
            ),
        );

        $roundTripped = $this->roundTrip($document);
        $paragraphs = $roundTripped->content();

        self::assertCount(3, $paragraphs);
        self::assertSame(['Parent', 'Child', 'Sibling'], array_map($this->paragraphText(...), $paragraphs));
        self::assertSame(21, $paragraphs[0]->numbering()?->numId());
        self::assertSame(0, $paragraphs[0]->numbering()?->ilvl());
        self::assertSame(22, $paragraphs[1]->numbering()?->numId());
        self::assertSame(1, $paragraphs[1]->numbering()?->ilvl());
        self::assertSame(21, $paragraphs[2]->numbering()?->numId());
        self::assertSame([0 => 3], $roundTripped->numbering()->num(21)?->levelOverrides());
        self::assertSame(NumberFormat::Bullet, $roundTripped->numbering()->levelFor(22, 1)?->format());
    }

    public function test_table_cells_and_colspan_are_written(): void
    {
        $document = new Document([new Table(
            rows: [new TableRow([
                new TableCell([$this->paragraph('Wide')], colspan: 2),
            ])],
            header: new TableRow([
                new TableCell([$this->paragraph('Header')]),
            ]),
        )]);
        $xml = $this->packageParts((new DocxWriter())->write($document))['word/document.xml'];
        $xpath = $this->wordXPath($xml);

        self::assertSame(1, $xpath->query('//w:tbl')->length);
        self::assertSame(2, $xpath->query('//w:tr')->length);
        self::assertSame('2', $xpath->evaluate('string(//w:gridSpan/@w:val)'));
        self::assertSame(1, $xpath->query('//w:trPr/w:tblHeader')->length);
    }

    public function test_links_receive_deterministic_external_relationships(): void
    {
        $document = new Document([new Paragraph([
            new Link('https://example.com/?a=1&b=2', [new Text('Example')], 'Example site'),
        ])]);
        $parts = $this->packageParts((new DocxWriter())->write($document));
        $relationships = new \DOMDocument();
        self::assertTrue($relationships->loadXML($parts['word/_rels/document.xml.rels']));
        $xpath = new \DOMXPath($relationships);
        $xpath->registerNamespace('pr', self::PACKAGE_RELATIONSHIPS);

        self::assertSame('rId2', $this->wordXPath($parts['word/document.xml'])->evaluate(
            'string(//w:hyperlink/@r:id)',
        ));
        self::assertSame('Example site', $this->wordXPath($parts['word/document.xml'])->evaluate(
            'string(//w:hyperlink/@w:tooltip)',
        ));
        self::assertSame(
            'https://example.com/?a=1&b=2',
            $xpath->evaluate('string(//pr:Relationship[@Id="rId2"]/@Target)'),
        );
        self::assertSame('External', $xpath->evaluate(
            'string(//pr:Relationship[@Id="rId2"]/@TargetMode)',
        ));
        self::assertSame('Example', $this->paragraphText(
            (new DocxReader())->read((new DocxWriter())->write($document))->content()[0],
        ));
    }

    public function test_unsupported_media_throws_instead_of_being_dropped(): void
    {
        $document = new Document([new Paragraph([
            new Text('Before'),
            new InlineImage('image.png', 'Image'),
        ])]);

        $this->expectException(UnsupportedNodeException::class);
        $this->expectExceptionMessage('document.content[0].inlines[1]');

        (new DocxWriter())->write($document);
    }

    #[DataProvider('unsupportedNodes')]
    public function test_every_explicitly_unsupported_node_throws(object $node): void
    {
        $document = $node instanceof Image
            ? new Document([$node])
            : new Document([new Paragraph([$node])]);

        $this->expectException(UnsupportedNodeException::class);
        $this->expectExceptionMessage($node::class);

        (new DocxWriter())->write($document);
    }

    public function test_unsupported_rowspan_throws_instead_of_being_flattened(): void
    {
        $document = new Document([new Table([
            new TableRow([new TableCell([$this->paragraph('Cell')], rowspan: 2)]),
        ])]);

        $this->expectException(DocxWriteException::class);
        $this->expectExceptionMessage('rowspan=2');

        (new DocxWriter())->write($document);
    }

    public function test_temporary_package_file_is_removed_after_success(): void
    {
        $directory = sys_get_temp_dir().'/transmark-docx-writer-test-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));

        try {
            (new DocxWriter($directory))->write(new Document([$this->paragraph('Clean')]));
            self::assertSame([], glob($directory.'/*'));
        } finally {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_temporary_file_is_removed_when_zip_creation_fails(): void
    {
        $parent = sys_get_temp_dir().'/transmark-docx-writer-failure-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($parent));
        $missingDirectory = $parent.'/missing';

        try {
            $this->expectException(DocxWriteException::class);
            $this->expectExceptionMessage('Unable to create a temporary DOCX file');

            (new DocxWriter($missingDirectory))->write(new Document([$this->paragraph('Failure')]));
        } finally {
            self::assertSame([], glob($parent.'/*'));
            rmdir($parent);
        }
    }

    public function test_invalid_numbering_references_fail_instead_of_producing_broken_ooxml(): void
    {
        $document = new Document([
            new Paragraph([new Text('Broken')], numbering: new NumberingRef(99, 0)),
        ]);

        $this->expectException(DocxWriteException::class);
        $this->expectExceptionMessage('missing numId 99');

        (new DocxWriter())->write($document);
    }

    public function test_num_pointing_to_a_missing_abstract_definition_fails(): void
    {
        $document = new Document(
            numbering: new NumberingDefinitions(nums: [9 => new Num(9, 404)]),
        );

        $this->expectException(DocxWriteException::class);
        $this->expectExceptionMessage('missing abstractNumId 404');

        (new DocxWriter())->write($document);
    }

    public function test_numbering_reference_to_a_missing_level_fails(): void
    {
        $document = new Document(
            content: [new Paragraph([new Text('Broken')], numbering: new NumberingRef(9, 2))],
            numbering: new NumberingDefinitions(
                abstractNums: [5 => new AbstractNum(5, [
                    0 => new Level(0, NumberFormat::Decimal, '%1.'),
                ])],
                nums: [9 => new Num(9, 5)],
            ),
        );

        $this->expectException(DocxWriteException::class);
        $this->expectExceptionMessage('missing level 2 for numId 9');

        (new DocxWriter())->write($document);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function numberingFixtureNames(): iterable
    {
        yield 'simple nested lists' => ['simple-nested-lists'];
        yield 'legal outline' => ['legal-outline'];
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function unsupportedNodes(): iterable
    {
        yield 'block image' => [new Image('image.png', 'Image')];
        yield 'inline image' => [new InlineImage('image.png', 'Image')];
        yield 'footnote' => [new Footnote('1', [])];
        yield 'comment' => [new Comment([], 'Reviewer')];
    }

    public function test_raw_html_inlines_are_skipped_instead_of_failing_the_conversion(): void
    {
        $document = new Document([
            new Paragraph([new Text('a '), new RawHtml('<br>'), new Text(' b')]),
        ]);

        $bytes = (new DocxWriter())->write($document);
        $reparsed = (new DocxReader())->read($bytes);

        $paragraph = $reparsed->content()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertSame('a  b', $this->paragraphText($paragraph));
    }

    private function roundTrip(Document $document): Document
    {
        return (new DocxReader())->read((new DocxWriter())->write($document));
    }

    private function readNumberingFixture(string $fixture): Document
    {
        $directory = dirname(__DIR__).'/fixtures/numbering/'.$fixture;
        $documentXml = file_get_contents($directory.'/document.xml');
        $numberingXml = file_get_contents($directory.'/numbering.xml');
        self::assertIsString($documentXml);
        self::assertIsString($numberingXml);

        return (new DocxReader())->read($this->docx([
            'word/document.xml' => $documentXml,
            'word/numbering.xml' => $numberingXml,
        ]));
    }

    /**
     * @return array<int, array{int, int}>
     */
    private function numberingPointers(Document $document): array
    {
        $pointers = [];

        foreach ($document->content() as $block) {
            if (!$block instanceof Paragraph || $block->numbering() === null) {
                continue;
            }

            $pointers[] = [$block->numbering()->numId(), $block->numbering()->ilvl()];
        }

        return $pointers;
    }

    private function paragraph(string $text): Paragraph
    {
        return new Paragraph([new Text($text)]);
    }

    private function paragraphText(mixed $block): string
    {
        self::assertInstanceOf(Paragraph::class, $block);
        $text = '';

        foreach ($block->inlines() as $inline) {
            if ($inline instanceof Text) {
                $text .= $inline->content();
            } elseif (method_exists($inline, 'children')) {
                foreach ($inline->children() as $child) {
                    if ($child instanceof Text) {
                        $text .= $child->content();
                    }
                }
            }
        }

        return $text;
    }

    /**
     * @return array<string, string>
     */
    private function packageParts(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'transmark-docx-writer-test-package-');
        self::assertIsString($path);
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));

        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $parts = [];

            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = $zip->getNameIndex($index);
                self::assertIsString($name);
                $contents = $zip->getFromIndex($index);
                self::assertIsString($contents);
                $parts[$name] = $contents;
            }

            $zip->close();
            ksort($parts);

            return $parts;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param array<string, string> $parts
     */
    private function docx(array $parts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'transmark-docx-writer-input-');
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

    private function wordXPath(string $xml): \DOMXPath
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::WORD_NAMESPACE);
        $xpath->registerNamespace(
            'r',
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        );

        return $xpath;
    }
}
