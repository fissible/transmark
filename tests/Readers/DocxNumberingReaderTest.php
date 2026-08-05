<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Readers;

use Fissible\Transmark\Document;
use Fissible\Transmark\Numbering\RestartRule;
use Fissible\Transmark\Readers\DocxReader;
use PHPUnit\Framework\TestCase;

final class DocxNumberingReaderTest extends TestCase
{
    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function test_legal_outline_fixture_parses_islgl_and_concatenated_lvltext(): void
    {
        $definitions = $this->readFixture('legal-outline')->numbering();
        $level = $definitions->levelFor(2000, 2);
        $abstractNum = $definitions->abstractNum(0);

        self::assertNotNull($level);
        self::assertTrue($level->isLegal());
        self::assertSame('%1.%2.%3.', $level->lvlText());
        self::assertNotNull($abstractNum);
        self::assertSame('multilevel', $abstractNum->multiLevelType());
    }

    public function test_simple_nested_lists_fixture_parses_all_four_independent_abstract_nums(): void
    {
        $definitions = $this->readFixture('simple-nested-lists')->numbering();

        self::assertSame([990, 99411, 99731, 99531], array_keys($definitions->abstractNums()));
        self::assertSame([1000, 1001, 1002, 1003, 1004], array_keys($definitions->nums()));
        self::assertSame(990, $definitions->num(1000)?->abstractNumId());
        self::assertSame(99411, $definitions->num(1001)?->abstractNumId());
        self::assertSame(99411, $definitions->num(1002)?->abstractNumId());
        self::assertSame(99731, $definitions->num(1003)?->abstractNumId());
        self::assertSame(99531, $definitions->num(1004)?->abstractNumId());
    }

    public function test_num_without_lvloverride_has_empty_level_overrides(): void
    {
        $num = $this->readFixture('simple-nested-lists')->numbering()->num(1000);

        self::assertNotNull($num);
        self::assertSame([], $num->levelOverrides());
    }

    public function test_num_with_start_override_captures_the_overridden_start_value(): void
    {
        $num = $this->readFixture('simple-nested-lists')->numbering()->num(1001);

        self::assertNotNull($num);
        self::assertSame(array_fill(0, 9, 1), $num->levelOverrides());
    }

    public function test_missing_numbering_xml_produces_an_empty_definitions_table_not_an_exception(): void
    {
        $document = (new DocxReader())->read($this->docx([
            'word/document.xml' => $this->documentXml(),
        ]));

        self::assertSame([], $document->numbering()->abstractNums());
        self::assertSame([], $document->numbering()->nums());
    }

    public function test_lvlrestart_maps_all_three_restart_states(): void
    {
        $numberingXml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:numbering xmlns:w="%1$s">'
            .'<w:abstractNum w:abstractNumId="7">'
            .'<w:lvl w:ilvl="0"><w:numFmt w:val="decimal"/><w:lvlText w:val="%%1"/></w:lvl>'
            .'<w:lvl w:ilvl="1"><w:numFmt w:val="decimal"/><w:lvlText w:val="%%2"/><w:lvlRestart w:val="0"/></w:lvl>'
            .'<w:lvl w:ilvl="2"><w:numFmt w:val="decimal"/><w:lvlText w:val="%%3"/><w:lvlRestart w:val="1"/></w:lvl>'
            .'</w:abstractNum>'
            .'<w:num w:numId="70"><w:abstractNumId w:val="7"/></w:num>'
            .'</w:numbering>',
            self::WORD_NAMESPACE,
        );
        $document = (new DocxReader())->read($this->docx([
            'word/document.xml' => $this->documentXml(),
            'word/numbering.xml' => $numberingXml,
        ]));
        $definitions = $document->numbering();
        $default = $definitions->levelFor(70, 0);
        $never = $definitions->levelFor(70, 1);
        $explicit = $definitions->levelFor(70, 2);

        self::assertNotNull($default);
        self::assertNotNull($never);
        self::assertNotNull($explicit);
        self::assertSame(RestartRule::DefaultImmediateParent, $default->restartRule());
        self::assertSame(RestartRule::Never, $never->restartRule());
        self::assertSame(RestartRule::AfterIlvl, $explicit->restartRule());
        self::assertSame(0, $explicit->restartAfterIlvl());
    }

    private function readFixture(string $name): Document
    {
        $fixturePath = dirname(__DIR__).'/fixtures/numbering/'.$name;
        $documentXml = file_get_contents($fixturePath.'/document.xml');
        $numberingXml = file_get_contents($fixturePath.'/numbering.xml');
        self::assertIsString($documentXml);
        self::assertIsString($numberingXml);

        return (new DocxReader())->read($this->docx([
            'word/document.xml' => $documentXml,
            'word/numbering.xml' => $numberingXml,
        ]));
    }

    private function documentXml(): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:document xmlns:w="%s"><w:body/></w:document>',
            self::WORD_NAMESPACE,
        );
    }

    /**
     * @param array<string, string> $parts
     */
    private function docx(array $parts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'transmark-docx-numbering-test-');
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
