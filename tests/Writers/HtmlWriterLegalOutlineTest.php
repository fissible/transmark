<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Writers;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
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

final class HtmlWriterLegalOutlineTest extends TestCase
{
    public function test_legal_outline_fixture_renders_flat_paragraphs_with_literal_labels(): void
    {
        $html = $this->writeFixture('legal-outline');

        self::assertSame(7, substr_count($html, '<p class="numbered-paragraph legal-level-'));
        self::assertStringContainsString('>1. Definitions</p>', $html);
        self::assertStringContainsString('>2.2.1. Automatic renewal</p>', $html);
        self::assertStringNotContainsString('<ol', $html);
        self::assertStringNotContainsString('<li', $html);
    }

    public function test_legal_outline_labels_match_documented_expected_values_exactly(): void
    {
        self::assertSame([
            '1. Definitions',
            '2. Term of Agreement',
            '2.1. Initial Term',
            '2.2. Renewal',
            '2.2.1. Automatic renewal',
            '2.2.1.1. Written notice',
            '3. Termination',
        ], $this->paragraphTexts($this->writeFixture('legal-outline')));
    }

    public function test_ilvl_is_reflected_in_output_indentation_or_class(): void
    {
        self::assertSame([
            'numbered-paragraph legal-level-0',
            'numbered-paragraph legal-level-0',
            'numbered-paragraph legal-level-1',
            'numbered-paragraph legal-level-1',
            'numbered-paragraph legal-level-2',
            'numbered-paragraph legal-level-3',
            'numbered-paragraph legal-level-0',
        ], $this->paragraphClasses($this->writeFixture('legal-outline')));
    }

    public function test_mixed_simple_and_legal_numids_in_one_document_each_use_the_correct_branch(): void
    {
        $document = new Document(
            content: [
                $this->numberedParagraph('Simple one', 10, 0),
                $this->numberedParagraph('Simple two', 10, 0),
                $this->numberedParagraph('Legal parent', 20, 0),
                $this->numberedParagraph('Legal child', 20, 1),
            ],
            numbering: new NumberingDefinitions(
                abstractNums: [
                    1 => new AbstractNum(1, [
                        0 => new Level(0, NumberFormat::Decimal, '%1.'),
                    ]),
                    2 => new AbstractNum(2, [
                        0 => new Level(0, NumberFormat::Decimal, '%1.'),
                        1 => new Level(1, NumberFormat::Decimal, '%1.%2.'),
                    ]),
                ],
                nums: [
                    10 => new Num(10, 1),
                    20 => new Num(20, 2),
                ],
            ),
        );

        self::assertSame(
            '<ol><li>Simple one</li><li>Simple two</li></ol>'
            .'<p class="numbered-paragraph legal-level-0">1. Legal parent</p>'
            .'<p class="numbered-paragraph legal-level-1">1.1. Legal child</p>',
            (new HtmlWriter())->write($document),
        );
    }

    private function writeFixture(string $name): string
    {
        return (new HtmlWriter())->write((new DocxReader())->read($this->fixtureDocx($name)));
    }

    private function numberedParagraph(string $text, int $numId, int $ilvl): Paragraph
    {
        return new Paragraph([new Text($text)], numbering: new NumberingRef($numId, $ilvl));
    }

    /**
     * @return string[]
     */
    private function paragraphTexts(string $html): array
    {
        $paragraphs = [];

        foreach ($this->htmlDocument($html)->getElementsByTagName('p') as $paragraph) {
            $paragraphs[] = $paragraph->textContent;
        }

        return $paragraphs;
    }

    /**
     * @return string[]
     */
    private function paragraphClasses(string $html): array
    {
        $classes = [];

        foreach ($this->htmlDocument($html)->getElementsByTagName('p') as $paragraph) {
            $classes[] = $paragraph->getAttribute('class');
        }

        return $classes;
    }

    private function htmlDocument(string $html): \DOMDocument
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<!doctype html><html><body>'.$html.'</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::assertTrue($loaded);

        return $document;
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
        $path = tempnam(sys_get_temp_dir(), 'transmark-html-legal-test-');
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
