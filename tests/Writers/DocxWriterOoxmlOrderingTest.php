<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Writers;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Code;
use Fissible\Transmark\Nodes\Inline\Link;
use Fissible\Transmark\Nodes\Inline\Strong;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Writers\DocxWriter;
use PHPUnit\Framework\TestCase;

final class DocxWriterOoxmlOrderingTest extends TestCase
{
    public function test_run_properties_follow_the_ct_rpr_element_order(): void
    {
        // Code + Strong means the run needs both rFonts and b; CT_RPr
        // (ECMA-376 §17.3.2.28) requires rFonts to precede b.
        $document = new Document([new Paragraph([
            new Strong([new Code('bold code')]),
        ])]);

        $documentXml = $this->documentXmlOf($document);
        $run = $documentXml->getElementsByTagNameNS(DocxWriterOoxmlOrderingTest::W_NS, 'r')->item(0);
        self::assertNotNull($run);

        $runChildren = [];
        foreach ($run->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->namespaceURI === self::W_NS) {
                $runChildren[] = $child->localName;
            }
        }
        self::assertSame(['rPr', 't'], $runChildren);

        self::assertSame(
            ['rFonts', 'b'],
            $this->rPrChildOrder($run),
        );
    }

    public function test_identical_hyperlink_targets_share_one_relationship(): void
    {
        $link = static fn (): Link => new Link('https://example.com', [new Text('x')], null);
        $document = new Document([new Paragraph([$link(), $link(), $link()])]);

        $bytes = (new DocxWriter())->write($document);

        $rels = $this->partOf($bytes, 'word/_rels/document.xml.rels');
        $hyperlinks = [];
        foreach ($rels->getElementsByTagNameNS(self::PKG_NS, 'Relationship') as $relationship) {
            if ($relationship instanceof \DOMElement
                && str_contains($relationship->getAttribute('Type'), '/hyperlink')) {
                $hyperlinks[] = $relationship->getAttribute('Target');
            }
        }

        self::assertSame(['https://example.com'], $hyperlinks);
    }

    private function rPrChildOrder(\DOMNode $run): array
    {
        $order = [];
        foreach ($run->firstChild->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $order[] = $child->localName;
            }
        }

        return $order;
    }

    private function documentXmlOf(Document $document): \DOMDocument
    {
        return $this->partOf((new DocxWriter())->write($document), 'word/document.xml');
    }

    private function partOf(string $docxBytes, string $part): \DOMDocument
    {
        $path = tempnam(sys_get_temp_dir(), 'transmark-ordering-test-');
        self::assertIsString($path);

        try {
            file_put_contents($path, $docxBytes);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $xml = $zip->getFromName($part);
            self::assertIsString($xml);
            $zip->close();

            $dom = new \DOMDocument();
            self::assertTrue($dom->loadXML($xml));

            return $dom;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const PKG_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
}
