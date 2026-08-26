<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Readers;

use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Readers\DocxReader;
use PHPUnit\Framework\TestCase;

final class DocxReaderNumberingRefTest extends TestCase
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function test_numpr_without_ilvl_defaults_to_level_zero(): void
    {
        // Per ECMA-376 §17.9.22 an omitted w:ilvl inside w:numPr means level 0.
        $document = (new DocxReader())->read($this->docx(
            '<w:numPr><w:numId w:val="1"/></w:numPr>',
        ));

        $paragraph = $document->content()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertNotNull($paragraph->numbering());
        self::assertSame(0, $paragraph->numbering()->ilvl());
    }

    public function test_numpr_with_explicit_ilvl_is_preserved(): void
    {
        $document = (new DocxReader())->read($this->docx(
            '<w:numPr><w:ilvl w:val="2"/><w:numId w:val="1"/></w:numPr>',
        ));

        $paragraph = $document->content()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertNotNull($paragraph->numbering());
        self::assertSame(2, $paragraph->numbering()->ilvl());
    }

    public function test_numpr_with_numid_zero_cancels_numbering(): void
    {
        // numId "0" means "cancel inherited numbering" (ECMA-376 §17.9.18).
        $document = (new DocxReader())->read($this->docx(
            '<w:numPr><w:numId w:val="0"/></w:numPr>',
        ));

        $paragraph = $document->content()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertNull($paragraph->numbering());
    }

    /**
     * Builds a DOCX whose only paragraph carries the given numPr XML.
     */
    private function docx(string $numPr): string
    {
        $document = '<?xml version="1.0"?><w:document xmlns:w="'.self::NS.'"><w:body>'
            .'<w:p><w:pPr>'.$numPr.'</w:pPr><w:r><w:t>First clause</w:t></w:r></w:p>'
            .'</w:body></w:document>';

        $numbering = '<?xml version="1.0"?><w:numbering xmlns:w="'.self::NS.'">'
            .'<w:abstractNum w:abstractNumId="10"><w:lvl w:ilvl="0">'
            .'<w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1."/>'
            .'</w:lvl></w:abstractNum>'
            .'<w:num w:numId="1"><w:abstractNumId w:val="10"/></w:num></w:numbering>';

        $path = tempnam(sys_get_temp_dir(), 'transmark-numref-test-');
        self::assertIsString($path);

        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
            self::assertTrue($zip->addFromString('word/document.xml', $document));
            self::assertTrue($zip->addFromString('word/numbering.xml', $numbering));
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
