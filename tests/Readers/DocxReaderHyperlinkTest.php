<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Readers;

use Fissible\Transmark\Nodes\Inline\Link;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Readers\DocxReader;
use Fissible\Transmark\Writers\HtmlWriter;
use PHPUnit\Framework\TestCase;

final class DocxReaderHyperlinkTest extends TestCase
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function test_external_hyperlink_reads_back_as_link_node(): void
    {
        $paragraph = $this->readParagraph(
            '<w:hyperlink r:id="rId1" w:tooltip="Example site"><w:r><w:t>a link</w:t></w:r></w:hyperlink>',
            ['rId1' => 'https://example.com/'],
        );

        self::assertCount(1, $paragraph);
        $link = $paragraph[0];
        self::assertInstanceOf(Link::class, $link);
        self::assertSame('https://example.com/', $link->href());
        self::assertSame('Example site', $link->title());

        $children = $link->children();
        self::assertCount(1, $children);
        self::assertInstanceOf(Text::class, $children[0]);
        self::assertSame('a link', $children[0]->content());
    }

    public function test_hyperlink_without_tooltip_has_no_title(): void
    {
        $paragraph = $this->readParagraph(
            '<w:hyperlink r:id="rId1"><w:r><w:t>a link</w:t></w:r></w:hyperlink>',
            ['rId1' => 'https://example.com/'],
        );

        self::assertInstanceOf(Link::class, $paragraph[0]);
        self::assertNull($paragraph[0]->title());
    }

    public function test_anchor_hyperlink_reads_back_as_fragment_href(): void
    {
        $paragraph = $this->readParagraph(
            '<w:hyperlink w:anchor="section2"><w:r><w:t>jump</w:t></w:r></w:hyperlink>',
            [],
        );

        self::assertInstanceOf(Link::class, $paragraph[0]);
        self::assertSame('#section2', $paragraph[0]->href());
    }

    public function test_unresolvable_relationship_id_degrades_to_plain_text(): void
    {
        $paragraph = $this->readParagraph(
            '<w:hyperlink r:id="rIdMissing"><w:r><w:t>plain now</w:t></w:r></w:hyperlink>',
            [],
        );

        self::assertCount(1, $paragraph);
        self::assertInstanceOf(Text::class, $paragraph[0]);
        self::assertSame('plain now', $paragraph[0]->content());
    }

    public function test_unresolvable_rid_falls_back_to_anchor(): void
    {
        $paragraph = $this->readParagraph(
            '<w:hyperlink r:id="rIdMissing" w:anchor="section3"><w:r><w:t>anchor</w:t></w:r></w:hyperlink>',
            [],
        );

        self::assertInstanceOf(Link::class, $paragraph[0]);
        self::assertSame('#section3', $paragraph[0]->href());
    }

    public function test_hyperlink_inside_table_cell_is_preserved(): void
    {
        $rels = '<?xml version="1.0"?><Relationships '
            .'xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $rels .= '<Relationship Id="rId1" '
            .'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" '
            .'Target="https://example.com/" TargetMode="External"/>';
        $rels .= '</Relationships>';

        $bodyXml = '<w:tbl xmlns:w="'.self::NS.'">'
                    .'<w:tr>'
                    .'<w:tc><w:tcPr/><w:p><w:hyperlink r:id="rId1"><w:r><w:t>cell link</w:t></w:r></w:hyperlink></w:p></w:tc>'
                    .'</w:tr>'
                    .'</w:tbl>';

        $document = (new DocxReader())->read($this->docxWithBodyAndRels($bodyXml, $rels));

        $table = $document->content()[0];
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Table::class, $table);
        $cell = $table->rows()[0]->cells()[0];
        $paragraph = $cell->content()[0];
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Paragraph::class, $paragraph);
        $link = $paragraph->inlines()[0];
        self::assertInstanceOf(Link::class, $link);
        self::assertSame('https://example.com/', $link->href());
    }

    public function test_non_external_target_mode_is_ignored(): void
    {
        $rels = '<?xml version="1.0"?><Relationships '
            .'xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $rels .= '<Relationship Id="rId1" '
            .'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" '
            .'Target="internal-target" TargetMode="Internal"/>';
        $rels .= '</Relationships>';

        $paragraph = (new DocxReader())->read($this->docxWithBodyAndRels(
            '<w:p><w:hyperlink r:id="rId1"><w:r><w:t>ignored</w:t></w:r></w:hyperlink></w:p>',
            $rels,
        ))->content()[0];

        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Text::class, $paragraph->inlines()[0]);
    }

    public function test_field_based_hyperlink_wraps_result_runs_in_a_link(): void
    {
        $paragraph = $this->readParagraph(
            '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText xml:space="preserve"> HYPERLINK "https://example.com/page" </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r><w:t>click</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>',
            [],
        );

        self::assertCount(1, $paragraph);
        $link = $paragraph[0];
        self::assertInstanceOf(Link::class, $link);
        self::assertSame('https://example.com/page', $link->href());
        self::assertCount(1, $link->children());
        self::assertInstanceOf(Text::class, $link->children()[0]);
        self::assertSame('click', $link->children()[0]->content());
    }

    public function test_field_based_hyperlink_with_screentip_switch_keeps_the_real_url(): void
    {
        $paragraph = $this->readParagraph(
            '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText> HYPERLINK \o "My Tooltip" "https://example.com/page" </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r><w:t>click</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>',
            [],
        );

        self::assertInstanceOf(Link::class, $paragraph[0]);
        self::assertSame('https://example.com/page', $paragraph[0]->href());
    }

    public function test_field_based_hyperlink_with_target_frame_switch_keeps_the_real_url(): void
    {
        $paragraph = $this->readParagraph(
            '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText> HYPERLINK \t "_blank" "https://example.com/page" </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r><w:t>click</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>',
            [],
        );

        self::assertInstanceOf(Link::class, $paragraph[0]);
        self::assertSame('https://example.com/page', $paragraph[0]->href());
    }

    public function test_field_based_hyperlink_with_unquoted_bookmark_uses_a_fragment_href(): void
    {
        $paragraph = $this->readParagraph(
            '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText> HYPERLINK \l Bookmark1 </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r><w:t>jump</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>',
            [],
        );

        self::assertInstanceOf(Link::class, $paragraph[0]);
        self::assertSame('#Bookmark1', $paragraph[0]->href());
    }

    public function test_field_based_hyperlink_with_multiple_switches_and_a_bookmark(): void
    {
        $paragraph = $this->readParagraph(
            '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText> HYPERLINK \o "tip" \l "Book" \t "_blank" </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r><w:t>jump</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>',
            [],
        );

        self::assertInstanceOf(Link::class, $paragraph[0]);
        self::assertSame('#Book', $paragraph[0]->href());
    }

    public function test_a_nested_field_inside_a_hyperlink_field_keeps_all_text_in_one_link(): void
    {
        $paragraph = $this->readParagraph(
            '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText> HYPERLINK "https://outer.com" </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r><w:t>page </w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText> PAGE </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r><w:t>7</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>'
            .'<w:r><w:t> tail</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>',
            [],
        );

        self::assertCount(1, $paragraph);
        $link = $paragraph[0];
        self::assertInstanceOf(Link::class, $link);
        self::assertSame('https://outer.com', $link->href());
        self::assertSame('page 7 tail', $this->linkText($link));
    }

    public function test_a_run_mixing_a_field_event_and_content_keeps_the_content(): void
    {
        $paragraph = $this->readParagraph(
            '<w:r><w:fldChar w:fldCharType="begin"/><w:t>lead</w:t></w:r>'
            .'<w:r><w:instrText> HYPERLINK "https://example.com" </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r><w:t>click</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>',
            [],
        );

        self::assertCount(2, $paragraph);
        self::assertInstanceOf(Text::class, $paragraph[0]);
        self::assertSame('lead', $paragraph[0]->content());
        self::assertInstanceOf(Link::class, $paragraph[1]);
        self::assertSame('click', $this->linkText($paragraph[1]));
    }

    public function test_field_based_hyperlink_with_anchor_uses_fragment_href(): void
    {
        $paragraph = $this->readParagraph(
            '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText> HYPERLINK \l "Section 2" </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r><w:t>jump</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>',
            [],
        );

        self::assertInstanceOf(Link::class, $paragraph[0]);
        self::assertSame('#Section 2', $paragraph[0]->href());
    }

    public function test_field_based_hyperlink_wraps_all_result_runs_in_one_link(): void
    {
        $paragraph = $this->readParagraph(
            '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText> HYPERLINK "https://example.com" </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r><w:t>click </w:t></w:r>'
            .'<w:r><w:t>here</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>',
            [],
        );

        self::assertCount(1, $paragraph);
        $link = $paragraph[0];
        self::assertInstanceOf(Link::class, $link);
        self::assertCount(2, $link->children());
    }

    public function test_non_hyperlink_field_keeps_its_cached_result_text(): void
    {
        $paragraph = $this->readParagraph(
            '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText> PAGE </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r><w:t>12</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>',
            [],
        );

        self::assertCount(1, $paragraph);
        self::assertInstanceOf(Text::class, $paragraph[0]);
        self::assertSame('12', $paragraph[0]->content());
    }

    /**
     * Builds a DOCX with custom body XML and relationships XML.
     */
    private function docxWithBodyAndRels(string $bodyXml, string $rels): string
    {
        $document = '<?xml version="1.0"?><w:document xmlns:w="'.self::NS.'" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<w:body>'.$bodyXml.'</w:body></w:document>';

        $path = tempnam(sys_get_temp_dir(), 'transmark-link-test-');
        self::assertIsString($path);

        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
            self::assertTrue($zip->addFromString('word/document.xml', $document));
            self::assertTrue($zip->addFromString('word/_rels/document.xml.rels', $rels));
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

    public function test_link_survives_docx_to_html_conversion(): void
    {
        $document = (new DocxReader())->read($this->docx(
            '<w:hyperlink r:id="rId1"><w:r><w:t>a link</w:t></w:r></w:hyperlink>',
            ['rId1' => 'https://example.com/'],
        ));

        self::assertSame(
            '<p><a href="https://example.com/">a link</a></p>',
            (new HtmlWriter())->write($document),
        );
    }

    /**
     * @param array<string, string> $relationships relationship id => external target
     *
     * @return \Fissible\Transmark\Contracts\InlineInterface[]
     */
    private function readParagraph(string $hyperlinkXml, array $relationships): array
    {
        return (new DocxReader())->read($this->docx($hyperlinkXml, $relationships))
            ->content()[0]
            ->inlines();
    }

    private function linkText(Link $link): string
    {
        $text = '';
        foreach ($link->children() as $child) {
            if ($child instanceof Text) {
                $text .= $child->content();
            }
        }

        return $text;
    }

    /**
     * Builds a DOCX whose only paragraph contains the given hyperlink XML.
     *
     * @param array<string, string> $relationships
     */
    private function docx(string $hyperlinkXml, array $relationships): string
    {
        $document = '<?xml version="1.0"?><w:document xmlns:w="'.self::NS.'" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<w:body><w:p>'.$hyperlinkXml.'</w:p></w:body></w:document>';

        $rels = '<?xml version="1.0"?><Relationships '
                .'xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($relationships as $id => $target) {
            $rels .= '<Relationship Id="'.$id.'" '
                .'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" '
                .'Target="'.$target.'" TargetMode="External"/>';
        }
        $rels .= '</Relationships>';

        $path = tempnam(sys_get_temp_dir(), 'transmark-link-test-');
        self::assertIsString($path);

        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
            self::assertTrue($zip->addFromString('word/document.xml', $document));
            self::assertTrue($zip->addFromString('word/_rels/document.xml.rels', $rels));
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
