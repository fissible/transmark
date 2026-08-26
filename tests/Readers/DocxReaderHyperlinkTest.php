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
