<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Readers;

use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Readers\DocxReader;
use Fissible\Transmark\Writers\HtmlWriter;
use PHPUnit\Framework\TestCase;

final class DocxReaderNumberingFormatTest extends TestCase
{
    public function test_unsupported_numfmt_value_does_not_abort_the_read(): void
    {
        $document = (new DocxReader())->read($this->docxWithNumFmt('ordinal'));

        $paragraph = $document->content()[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertTrue($paragraph->isNumbered());
    }

    public function test_unsupported_numfmt_value_degrades_to_decimal_labels(): void
    {
        $document = (new DocxReader())->read($this->docxWithNumFmt('ordinal'));

        self::assertSame(
            '<ol><li>First clause</li></ol>',
            (new HtmlWriter())->write($document),
        );
    }

    public function test_legal_shape_with_unsupported_numfmt_still_renders_computed_labels(): void
    {
        // Ordinal style ("1st", "2nd") has no supported renderer; the
        // degraded level substitutes decimal counters into the literal
        // lvlText instead of aborting the read.
        $numbering = '<?xml version="1.0"?><w:numbering xmlns:w="'.self::NS.'">'
            .$this->abstractNum('ordinal', '%1%', true)
            .'<w:num w:numId="1"><w:abstractNumId w:val="10"/></w:num></w:numbering>';

        $document = (new DocxReader())->read($this->docx($this->body(), $numbering));

        self::assertSame(
            '<p class="numbered-paragraph legal-level-0">1% First clause</p>',
            (new HtmlWriter())->write($document),
        );
    }

    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private function docxWithNumFmt(string $numFmt): string
    {
        $numbering = '<?xml version="1.0"?><w:numbering xmlns:w="'.self::NS.'">'
            .$this->abstractNum($numFmt, '%1.', false)
            .'<w:num w:numId="1"><w:abstractNumId w:val="10"/></w:num></w:numbering>';

        return $this->docx($this->body(), $numbering);
    }

    private function abstractNum(string $numFmt, string $lvlText, bool $isLgl): string
    {
        return '<w:abstractNum w:abstractNumId="10"><w:multiLevelType w:val="multilevel"/>'
            .'<w:lvl w:ilvl="0">'
            .'<w:start w:val="1"/><w:numFmt w:val="'.$numFmt.'"/><w:lvlText w:val="'.$lvlText.'"/>'
            .($isLgl ? '<w:isLgl/>' : '')
            .'</w:lvl>'
            .'</w:abstractNum>';
    }

    private function body(): string
    {
        return '<w:body><w:p><w:pPr><w:numPr>'
            .'<w:ilvl w:val="0"/><w:numId w:val="1"/>'
            .'</w:numPr></w:pPr><w:r><w:t>First clause</w:t></w:r></w:p></w:body>';
    }

    private function docx(string $body, string $numbering): string
    {
        $document = '<?xml version="1.0"?><w:document xmlns:w="'.self::NS.'">'.$body.'</w:document>';

        $path = tempnam(sys_get_temp_dir(), 'transmark-numfmt-test-');
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
