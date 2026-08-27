<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Readers;

use Fissible\Transmark\Contracts\ReaderInterface;
use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\BlockQuote;
use Fissible\Transmark\Nodes\Block\Heading;
use Fissible\Transmark\Nodes\Block\HorizontalRule;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Block\Table;
use Fissible\Transmark\Nodes\Block\TableCell;
use Fissible\Transmark\Nodes\Block\TableRow;
use Fissible\Transmark\Nodes\Inline\Emphasis;
use Fissible\Transmark\Nodes\Inline\InlineImage;
use Fissible\Transmark\Nodes\Inline\LineBreak;
use Fissible\Transmark\Nodes\Inline\Strike;
use Fissible\Transmark\Nodes\Inline\Strong;
use Fissible\Transmark\Nodes\Inline\Subscript;
use Fissible\Transmark\Nodes\Inline\Superscript;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Nodes\Inline\Underline;
use Fissible\Transmark\Ooxml\Exception\InvalidPackageException;
use Fissible\Transmark\Readers\DocxReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocxReaderTest extends TestCase
{
    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function test_plain_paragraph_reads_as_an_unnumbered_paragraph(): void
    {
        $reader = new DocxReader();

        $document = $reader->read($this->docxWithDocumentXml($this->documentXml(
            '<w:p><w:r><w:t>Hello world</w:t></w:r></w:p>',
        )));

        self::assertInstanceOf(ReaderInterface::class, $reader);
        self::assertCount(1, $document->content());
        $paragraph = $document->content()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertFalse($paragraph->isNumbered());
        self::assertNull($paragraph->styleName());
        self::assertCount(1, $paragraph->inlines());
        self::assertInstanceOf(Text::class, $paragraph->inlines()[0]);
        self::assertSame('Hello world', $paragraph->inlines()[0]->content());
    }

    public function test_heading_style_produces_a_heading_node_with_correct_level(): void
    {
        $document = $this->readBody(
            '<w:p>'
            .'<w:pPr><w:pStyle w:val="Heading2"/></w:pPr>'
            .'<w:r><w:t>Scope</w:t></w:r>'
            .'</w:p>',
        );

        self::assertCount(1, $document->content());
        $heading = $document->content()[0];
        self::assertInstanceOf(Heading::class, $heading);
        self::assertSame(2, $heading->level());
        self::assertInstanceOf(Text::class, $heading->inlines()[0]);
        self::assertSame('Scope', $heading->inlines()[0]->content());
    }

    /**
     * @param array<string, array{int, int}> $expected
     */
    #[DataProvider('numberingFixtures')]
    public function test_numbered_paragraphs_carry_exact_source_numid_and_ilvl(
        string $fixtureName,
        array $expected,
    ): void {
        $documentXml = file_get_contents(
            __DIR__.'/../fixtures/numbering/'.$fixtureName.'/document.xml',
        );
        $numberingXml = file_get_contents(
            __DIR__.'/../fixtures/numbering/'.$fixtureName.'/numbering.xml',
        );
        self::assertIsString($documentXml);
        self::assertIsString($numberingXml);

        $document = (new DocxReader())->read($this->docx([
            'word/document.xml' => $documentXml,
            'word/numbering.xml' => $numberingXml,
        ]));

        $actual = [];
        foreach ($document->content() as $block) {
            if (!$block instanceof Paragraph || !$block->isNumbered()) {
                continue;
            }

            $numbering = $block->numbering();
            self::assertNotNull($numbering);
            $actual[$this->paragraphText($block)] = [$numbering->numId(), $numbering->ilvl()];
        }

        self::assertSame($expected, $actual);
        $firstNumbering = array_values($expected)[0];
        self::assertNotNull($document->numbering()->num($firstNumbering[0]));
    }

    public function test_bold_and_italic_on_one_run_nest_correctly(): void
    {
        $document = $this->readBody(
            '<w:p><w:r><w:rPr><w:b/><w:i/></w:rPr><w:t>Important</w:t></w:r></w:p>',
        );

        $paragraph = $document->content()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertCount(1, $paragraph->inlines());

        $strong = $paragraph->inlines()[0];
        self::assertInstanceOf(Strong::class, $strong);
        self::assertCount(1, $strong->children());

        $emphasis = $strong->children()[0];
        self::assertInstanceOf(Emphasis::class, $emphasis);
        self::assertCount(1, $emphasis->children());
        self::assertInstanceOf(Text::class, $emphasis->children()[0]);
        self::assertSame('Important', $emphasis->children()[0]->content());
    }

    public function test_line_break_produces_a_linebreak_node(): void
    {
        $document = $this->readBody(
            '<w:p><w:r><w:t>Before</w:t><w:br/><w:t>After</w:t></w:r></w:p>',
        );

        $paragraph = $document->content()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertCount(3, $paragraph->inlines());
        self::assertInstanceOf(Text::class, $paragraph->inlines()[0]);
        self::assertInstanceOf(LineBreak::class, $paragraph->inlines()[1]);
        self::assertInstanceOf(Text::class, $paragraph->inlines()[2]);
        self::assertSame('Before', $paragraph->inlines()[0]->content());
        self::assertSame('After', $paragraph->inlines()[2]->content());
    }

    public function test_unrecognized_content_does_not_throw_and_supported_text_is_preserved(): void
    {
        $document = $this->readBody(
            '<w:p>'
            .'<w:empty/>'
            .'<w:custom><w:r><w:t>Flattened</w:t></w:r></w:custom>'
            .'<w:r><w:drawing/><w:t> text</w:t><w:unknown/></w:r>'
            .'</w:p>'
            .'<w:unsupported/>',
        );

        self::assertCount(1, $document->content());
        $paragraph = $document->content()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertSame('Flattened text', $this->paragraphText($paragraph));
    }

    public function test_missing_document_part_throws_an_invalid_package_exception(): void
    {
        $content = $this->docx(['word/other.xml' => '<root/>']);

        $this->expectException(InvalidPackageException::class);

        (new DocxReader())->read($content);
    }

    #[DataProvider('quoteStyles')]
    public function test_quote_styles_produce_block_quotes(string $styleName): void
    {
        $document = $this->readBody(
            '<w:p>'
            .sprintf('<w:pPr><w:pStyle w:val="%s"/></w:pPr>', $styleName)
            .'<w:r><w:t>Quoted text</w:t></w:r>'
            .'</w:p>',
        );

        $quote = $document->content()[0];
        self::assertInstanceOf(BlockQuote::class, $quote);
        self::assertCount(1, $quote->content());
        $paragraph = $quote->content()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertSame($styleName, $paragraph->styleName());
        self::assertSame('Quoted text', $this->paragraphText($paragraph));
    }

    public function test_empty_bottom_bordered_paragraph_produces_a_horizontal_rule(): void
    {
        $document = $this->readBody(
            '<w:p><w:pPr><w:pBdr><w:bottom w:val="single"/></w:pBdr></w:pPr></w:p>',
        );

        self::assertCount(1, $document->content());
        self::assertInstanceOf(HorizontalRule::class, $document->content()[0]);
    }

    public function test_other_supported_run_formats_produce_their_inline_wrappers(): void
    {
        $document = $this->readBody(
            '<w:p>'
            .'<w:r><w:rPr><w:u w:val="single"/></w:rPr><w:t>underline</w:t></w:r>'
            .'<w:r><w:rPr><w:strike/></w:rPr><w:t>strike</w:t></w:r>'
            .'<w:r><w:rPr><w:vertAlign w:val="superscript"/></w:rPr><w:t>super</w:t></w:r>'
            .'<w:r><w:rPr><w:vertAlign w:val="subscript"/></w:rPr><w:t>sub</w:t></w:r>'
            .'</w:p>',
        );

        $paragraph = $document->content()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertCount(4, $paragraph->inlines());
        self::assertInstanceOf(Underline::class, $paragraph->inlines()[0]);
        self::assertInstanceOf(Strike::class, $paragraph->inlines()[1]);
        self::assertInstanceOf(Superscript::class, $paragraph->inlines()[2]);
        self::assertInstanceOf(Subscript::class, $paragraph->inlines()[3]);

        self::assertSame('underline', $this->wrappedText($paragraph->inlines()[0]));
        self::assertSame('strike', $this->wrappedText($paragraph->inlines()[1]));
        self::assertSame('super', $this->wrappedText($paragraph->inlines()[2]));
        self::assertSame('sub', $this->wrappedText($paragraph->inlines()[3]));
    }

    public function test_a_table_with_a_header_row_and_body_rows_reads_correctly(): void
    {
        $document = $this->readBody(
            $this->paragraphXml('Before')
            .'<w:tbl>'
            .'<w:tr><w:trPr><w:tblHeader/></w:trPr>'
            .'<w:tc><w:tcPr/>'.$this->paragraphXml('Name').'</w:tc>'
            .'<w:tc><w:tcPr/>'.$this->paragraphXml('Age').'</w:tc>'
            .'</w:tr>'
            .'<w:tr>'
            .'<w:tc><w:tcPr/>'.$this->paragraphXml('Alice').'</w:tc>'
            .'<w:tc><w:tcPr/>'.$this->paragraphXml('30').'</w:tc>'
            .'</w:tr>'
            .'<w:tr>'
            .'<w:tc><w:tcPr/>'.$this->paragraphXml('Bob').'</w:tc>'
            .'<w:tc><w:tcPr/>'.$this->paragraphXml('25').'</w:tc>'
            .'</w:tr>'
            .'</w:tbl>'
            .$this->paragraphXml('After'),
        );

        $content = $document->content();
        self::assertCount(3, $content);
        self::assertSame('Before', $content[0]->inlines()[0]->content());

        $table = $content[1];
        self::assertInstanceOf(Table::class, $table);

        $header = $table->header();
        self::assertInstanceOf(TableRow::class, $header);
        self::assertSame('Name', $this->cellText($header->cells()[0]));
        self::assertSame('Age', $this->cellText($header->cells()[1]));

        $rows = $table->rows();
        self::assertCount(2, $rows);
        self::assertSame('Alice', $this->cellText($rows[0]->cells()[0]));
        self::assertSame('30', $this->cellText($rows[0]->cells()[1]));
        self::assertSame('Bob', $this->cellText($rows[1]->cells()[0]));
        self::assertSame('25', $this->cellText($rows[1]->cells()[1]));

        self::assertSame('After', $content[2]->inlines()[0]->content());
    }

    public function test_a_table_with_no_header_row_has_a_null_header(): void
    {
        $document = $this->readBody(
            '<w:tbl>'
            .'<w:tr><w:tc><w:tcPr/>'.$this->paragraphXml('A').'</w:tc></w:tr>'
            .'<w:tr><w:tc><w:tcPr/>'.$this->paragraphXml('B').'</w:tc></w:tr>'
            .'</w:tbl>',
        );

        $table = $document->content()[0];
        self::assertInstanceOf(Table::class, $table);
        self::assertNull($table->header());
        self::assertCount(2, $table->rows());
    }

    public function test_only_the_first_tblheader_marked_row_becomes_the_table_header(): void
    {
        $document = $this->readBody(
            '<w:tbl>'
            .'<w:tr><w:trPr><w:tblHeader/></w:trPr><w:tc><w:tcPr/>'.$this->paragraphXml('H1').'</w:tc></w:tr>'
            .'<w:tr><w:trPr><w:tblHeader/></w:trPr><w:tc><w:tcPr/>'.$this->paragraphXml('H2').'</w:tc></w:tr>'
            .'</w:tbl>',
        );

        $table = $document->content()[0];
        self::assertInstanceOf(Table::class, $table);
        self::assertSame('H1', $this->cellText($table->header()->cells()[0]));
        self::assertCount(1, $table->rows());
        self::assertSame('H2', $this->cellText($table->rows()[0]->cells()[0]));
    }

    public function test_a_cells_gridspan_reads_as_colspan(): void
    {
        $document = $this->readBody(
            '<w:tbl><w:tr>'
            .'<w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr>'.$this->paragraphXml('Merged').'</w:tc>'
            .'</w:tr></w:tbl>',
        );

        $cell = $document->content()[0]->rows()[0]->cells()[0];
        self::assertSame(2, $cell->colspan());
        self::assertSame(1, $cell->rowspan());
    }

    public function test_a_cell_without_gridspan_has_colspan_1(): void
    {
        $document = $this->readBody(
            '<w:tbl><w:tr><w:tc><w:tcPr/>'.$this->paragraphXml('Plain').'</w:tc></w:tr></w:tbl>',
        );

        self::assertSame(1, $document->content()[0]->rows()[0]->cells()[0]->colspan());
    }

    public function test_a_numbered_paragraph_inside_a_cell_resolves_its_numbering_ref(): void
    {
        $document = $this->readBody(
            '<w:tbl><w:tr><w:tc><w:tcPr/>'
            .'<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="5"/></w:numPr></w:pPr>'
            .'<w:r><w:t>Item one</w:t></w:r></w:p>'
            .'</w:tc></w:tr></w:tbl>',
        );

        $cellParagraph = $document->content()[0]->rows()[0]->cells()[0]->content()[0];
        self::assertInstanceOf(Paragraph::class, $cellParagraph);
        self::assertSame(5, $cellParagraph->numbering()?->numId());
        self::assertSame(0, $cellParagraph->numbering()?->ilvl());
    }

    public function test_a_cell_with_multiple_paragraphs_reads_them_all(): void
    {
        $document = $this->readBody(
            '<w:tbl><w:tr><w:tc><w:tcPr/>'
            .$this->paragraphXml('First')
            .$this->paragraphXml('Second')
            .'</w:tc></w:tr></w:tbl>',
        );

        $content = $document->content()[0]->rows()[0]->cells()[0]->content();
        self::assertCount(2, $content);
        self::assertSame('First', $content[0]->inlines()[0]->content());
        self::assertSame('Second', $content[1]->inlines()[0]->content());
    }

    public function test_a_nested_table_inside_a_cell_reads_correctly(): void
    {
        // #31 scoped nested tables as "out of scope unless trivially free
        // from the recursive approach" - reusing the same body-children
        // dispatcher for cell content makes it genuinely free, so this
        // asserts real support rather than a documented skip.
        $document = $this->readBody(
            '<w:tbl><w:tr><w:tc><w:tcPr/>'
            .$this->paragraphXml('Outer cell text')
            .'<w:tbl><w:tr><w:tc><w:tcPr/>'.$this->paragraphXml('Nested').'</w:tc></w:tr></w:tbl>'
            .'</w:tc></w:tr></w:tbl>',
        );

        $cellContent = $document->content()[0]->rows()[0]->cells()[0]->content();
        self::assertCount(2, $cellContent);
        self::assertSame('Outer cell text', $cellContent[0]->inlines()[0]->content());

        $nestedTable = $cellContent[1];
        self::assertInstanceOf(Table::class, $nestedTable);
        self::assertSame(
            'Nested',
            $nestedTable->rows()[0]->cells()[0]->content()[0]->inlines()[0]->content(),
        );
    }

    public function test_an_inline_drawing_resolves_to_an_inline_image_with_bytes_and_dimensions(): void
    {
        $document = (new DocxReader())->read($this->docxWithImage(
            '<w:p><w:r>'.$this->drawingXml('rId4', 952500, 476250, 'A photo', 'Picture 1').'</w:r></w:p>',
            ['rId4' => 'media/image1.png'],
            ['word/media/image1.png' => 'fake-png-bytes'],
        ));

        $inlines = $document->content()[0]->inlines();
        self::assertCount(1, $inlines);
        $image = $inlines[0];
        self::assertInstanceOf(InlineImage::class, $image);
        self::assertSame('word/media/image1.png', $image->src());
        self::assertSame('A photo', $image->alt());
        self::assertSame('Picture 1', $image->title());
        self::assertSame('fake-png-bytes', $image->data());
        self::assertSame('image/png', $image->mimeType());
        self::assertSame(100, $image->width());
        self::assertSame(50, $image->height());
    }

    public function test_a_drawing_referencing_a_wmf_image_is_skipped_without_crashing(): void
    {
        $document = (new DocxReader())->read($this->docxWithImage(
            '<w:p><w:r><w:t>Before </w:t></w:r><w:r>'
            .$this->drawingXml('rId5', 952500, 476250)
            .'</w:r><w:r><w:t> After</w:t></w:r></w:p>',
            ['rId5' => 'media/image2.wmf'],
            ['word/media/image2.wmf' => 'fake-wmf-bytes'],
        ));

        $inlines = $document->content()[0]->inlines();
        self::assertContainsOnlyInstancesOf(Text::class, $inlines);
        self::assertSame('Before  After', $this->paragraphText($document->content()[0]));
    }

    public function test_a_drawing_with_no_matching_relationship_is_skipped_without_crashing(): void
    {
        $document = (new DocxReader())->read($this->docxWithImage(
            '<w:p><w:r>'.$this->drawingXml('rIdMissing', 952500, 476250).'</w:r></w:p>',
            [],
            [],
        ));

        self::assertSame([], $document->content()[0]->inlines());
    }

    public function test_a_tab_run_element_reads_as_a_tab_text(): void
    {
        $document = $this->readBody(
            '<w:p><w:r><w:t>a</w:t></w:r><w:r><w:tab/></w:r><w:r><w:t>b</w:t></w:r></w:p>',
        );

        $inlines = $document->content()[0]->inlines();
        self::assertCount(3, $inlines);
        self::assertInstanceOf(Text::class, $inlines[0]);
        self::assertSame('a', $inlines[0]->content());
        self::assertInstanceOf(Text::class, $inlines[1]);
        self::assertSame("\t", $inlines[1]->content());
        self::assertInstanceOf(Text::class, $inlines[2]);
        self::assertSame('b', $inlines[2]->content());
    }

    public function test_a_cr_run_element_reads_as_a_line_break(): void
    {
        $document = $this->readBody(
            '<w:p><w:r><w:t>a</w:t></w:r><w:r><w:cr/></w:r><w:r><w:t>b</w:t></w:r></w:p>',
        );

        $inlines = $document->content()[0]->inlines();
        self::assertCount(3, $inlines);
        self::assertInstanceOf(Text::class, $inlines[0]);
        self::assertInstanceOf(LineBreak::class, $inlines[1]);
        self::assertInstanceOf(Text::class, $inlines[2]);
    }

    public function test_a_no_break_hyphen_run_element_reads_as_a_non_breaking_hyphen(): void
    {
        $document = $this->readBody(
            '<w:p><w:r><w:t>a</w:t></w:r><w:r><w:noBreakHyphen/></w:r><w:r><w:t>b</w:t></w:r></w:p>',
        );

        $inlines = $document->content()[0]->inlines();
        self::assertCount(3, $inlines);
        self::assertInstanceOf(Text::class, $inlines[1]);
        self::assertSame("\u{2011}", $inlines[1]->content());
    }

    public function test_an_sdt_block_reads_its_paragraph_content(): void
    {
        $document = $this->readBody(
            '<w:sdt><w:sdtPr/><w:sdtContent><w:p><w:r><w:t>sdt content</w:t></w:r></w:p></w:sdtContent></w:sdt>',
        );

        self::assertCount(1, $document->content());
        self::assertSame('sdt content', $this->paragraphText($document->content()[0]));
    }

    public function test_a_nested_sdt_inside_an_sdt_reads_recursively(): void
    {
        $document = $this->readBody(
            '<w:sdt><w:sdtContent>'
            .'<w:sdt><w:sdtContent><w:p><w:r><w:t>inner</w:t></w:r></w:p></w:sdtContent></w:sdt>'
            .'</w:sdtContent></w:sdt>',
        );

        self::assertCount(1, $document->content());
        self::assertSame('inner', $this->paragraphText($document->content()[0]));
    }

    public function test_an_sdt_block_containing_a_table_reads_the_table(): void
    {
        $document = $this->readBody(
            '<w:sdt><w:sdtContent>'
            .'<w:tbl><w:tr><w:tc><w:p><w:r><w:t>cell</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
            .'</w:sdtContent></w:sdt>',
        );

        self::assertCount(1, $document->content());
        self::assertInstanceOf(Table::class, $document->content()[0]);
    }

    public function test_an_sdt_without_sdt_content_reads_as_nothing(): void
    {
        $document = $this->readBody('<w:sdt><w:sdtPr/></w:sdt>');

        self::assertSame([], $document->content());
    }

    public function test_an_sdt_wrapping_a_table_row_reads_the_row(): void
    {
        $document = $this->readBody(
            '<w:tbl>'
            .'<w:sdt><w:sdtContent><w:tr><w:tc><w:p><w:r><w:t>controlled</w:t></w:r></w:p></w:tc></w:tr></w:sdtContent></w:sdt>'
            .'<w:tr><w:tc><w:p><w:r><w:t>plain</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>',
        );

        $table = $document->content()[0];
        self::assertInstanceOf(Table::class, $table);
        self::assertCount(2, $table->rows());
        self::assertSame('controlled', $this->cellText($table->rows()[0]->cells()[0]));
        self::assertSame('plain', $this->cellText($table->rows()[1]->cells()[0]));
    }

    public function test_an_sdt_wrapping_a_table_cell_reads_the_cell(): void
    {
        $document = $this->readBody(
            '<w:tbl><w:tr>'
            .'<w:sdt><w:sdtContent><w:tc><w:p><w:r><w:t>controlled</w:t></w:r></w:p></w:tc></w:sdtContent></w:sdt>'
            .'<w:tc><w:p><w:r><w:t>plain</w:t></w:r></w:p></w:tc>'
            .'</w:tr></w:tbl>',
        );

        $table = $document->content()[0];
        self::assertInstanceOf(Table::class, $table);
        self::assertCount(1, $table->rows());
        self::assertCount(2, $table->rows()[0]->cells());
        self::assertSame('controlled', $this->cellText($table->rows()[0]->cells()[0]));
        self::assertSame('plain', $this->cellText($table->rows()[0]->cells()[1]));
    }

    public function test_a_header_row_inside_an_sdt_still_becomes_the_table_header(): void
    {
        $document = $this->readBody(
            '<w:tbl>'
            .'<w:sdt><w:sdtContent><w:tr><w:trPr><w:tblHeader/></w:trPr>'
            .'<w:tc><w:p><w:r><w:t>Head</w:t></w:r></w:p></w:tc></w:tr></w:sdtContent></w:sdt>'
            .'<w:tr><w:tc><w:p><w:r><w:t>Body</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>',
        );

        $table = $document->content()[0];
        self::assertInstanceOf(Table::class, $table);
        self::assertNotNull($table->header());
        self::assertSame('Head', $this->cellText($table->header()->cells()[0]));
        self::assertCount(1, $table->rows());
        self::assertSame('Body', $this->cellText($table->rows()[0]->cells()[0]));
    }


    /**
     * @param array<string, string> $relationships rId => Target (relative to word/)
     * @param array<string, string> $mediaParts    full package part path => raw bytes
     */
    private function docxWithImage(string $body, array $relationships, array $mediaParts): string
    {
        $relationshipXml = '';
        foreach ($relationships as $id => $target) {
            $relationshipXml .= sprintf(
                '<Relationship Id="%s" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="%s"/>',
                $id,
                $target,
            );
        }

        $relsXml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationshipXml
            .'</Relationships>';

        return $this->docx([
            'word/document.xml' => $this->documentXml($body),
            'word/_rels/document.xml.rels' => $relsXml,
            ...$mediaParts,
        ]);
    }

    private function drawingXml(string $relationshipId, int $cx, int $cy, string $alt = '', string $name = ''): string
    {
        return sprintf(
            '<w:drawing>'
            .'<wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            .'<wp:extent cx="%d" cy="%d"/>'
            .'<wp:docPr id="1" name="%s" descr="%s"/>'
            .'<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:blipFill>'
            .'<a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="%s"/>'
            .'</pic:blipFill>'
            .'</pic:pic>'
            .'</a:graphicData>'
            .'</a:graphic>'
            .'</wp:inline>'
            .'</w:drawing>',
            $cx,
            $cy,
            htmlspecialchars($name, ENT_XML1),
            htmlspecialchars($alt, ENT_XML1),
            $relationshipId,
        );
    }

    private function paragraphXml(string $text): string
    {
        return '<w:p><w:r><w:t>'.$text.'</w:t></w:r></w:p>';
    }

    private function cellText(TableCell $cell): string
    {
        return $cell->content()[0]->inlines()[0]->content();
    }

    /**
     * @return iterable<string, array{string, array<string, array{int, int}>}>
     */
    public static function numberingFixtures(): iterable
    {
        yield 'legal outline' => ['legal-outline', [
            'Definitions' => [2000, 0],
            'Term of Agreement' => [2000, 0],
            'Initial Term' => [2000, 1],
            'Renewal' => [2000, 1],
            'Automatic renewal' => [2000, 2],
            'Written notice' => [2000, 3],
            'Termination' => [2000, 0],
        ]];

        yield 'simple nested lists' => ['simple-nested-lists', [
            'Term of Agreement' => [1001, 0],
            'Initial Term' => [1002, 1],
            'Renewal' => [1002, 1],
            'Automatic renewal' => [1003, 2],
            'Notice of non-renewal' => [1003, 2],
            'Written notice' => [1004, 3],
            'Delivery method' => [1004, 3],
            'Termination' => [1001, 0],
        ]];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function quoteStyles(): iterable
    {
        yield 'quote' => ['Quote'];
        yield 'intense quote' => ['IntenseQuote'];
    }

    private function readBody(string $body): Document
    {
        return (new DocxReader())->read($this->docxWithDocumentXml($this->documentXml($body)));
    }

    private function documentXml(string $body): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:document xmlns:w="%s"><w:body>%s</w:body></w:document>',
            self::WORD_NAMESPACE,
            $body,
        );
    }

    private function docxWithDocumentXml(string $documentXml): string
    {
        return $this->docx(['word/document.xml' => $documentXml]);
    }

    /**
     * @param array<string, string> $parts
     */
    private function docx(array $parts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'transmark-docx-test-');
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

    private function paragraphText(Paragraph $paragraph): string
    {
        $text = '';
        foreach ($paragraph->inlines() as $inline) {
            if ($inline instanceof Text) {
                $text .= $inline->content();
            }
        }

        return $text;
    }

    private function wrappedText(object $wrapper): string
    {
        self::assertTrue(method_exists($wrapper, 'children'));
        $children = $wrapper->children();
        self::assertCount(1, $children);
        self::assertInstanceOf(Text::class, $children[0]);

        return $children[0]->content();
    }
}
