<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Format;

use Fissible\Transmark\Format\Exception\FormatMismatchException;
use Fissible\Transmark\Format\Format;
use Fissible\Transmark\Format\FormatDetector;
use PHPUnit\Framework\TestCase;

final class FormatDetectorTest extends TestCase
{
    /** @var string[] temp file paths created during a test, cleaned up in tearDown */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    /**
     * @param array<string, string> $entries relative-in-zip path => file contents
     */
    private function zipBytes(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'transmark-format-');
        $this->tempFiles[] = $path;

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($entries as $entryPath => $contents) {
            $zip->addFromString($entryPath, $contents);
        }
        $zip->close();

        $bytes = file_get_contents($path);
        unlink($path);
        $this->tempFiles = array_diff($this->tempFiles, [$path]);

        return $bytes;
    }

    public function test_detect_returns_docx_for_a_zip_containing_word_document_xml(): void
    {
        $bytes = $this->zipBytes(['word/document.xml' => '<w:document/>']);

        self::assertSame(Format::Docx, FormatDetector::detect($bytes));
    }

    public function test_detect_returns_unknown_for_plain_text(): void
    {
        self::assertSame(Format::Unknown, FormatDetector::detect('# Just some markdown'));
    }

    public function test_detect_returns_unknown_for_a_zip_without_a_word_document_xml_part(): void
    {
        $bytes = $this->zipBytes(['readme.txt' => 'not a docx']);

        self::assertSame(Format::Unknown, FormatDetector::detect($bytes));
    }

    public function test_detect_returns_unknown_for_empty_content(): void
    {
        self::assertSame(Format::Unknown, FormatDetector::detect(''));
    }

    public function test_validate_returns_the_detected_format_when_no_filename_is_given(): void
    {
        $bytes = $this->zipBytes(['word/document.xml' => '<w:document/>']);

        self::assertSame(Format::Docx, FormatDetector::validate($bytes));
    }

    public function test_validate_returns_the_detected_format_when_the_extension_matches(): void
    {
        $bytes = $this->zipBytes(['word/document.xml' => '<w:document/>']);

        self::assertSame(Format::Docx, FormatDetector::validate($bytes, 'contract.docx'));
    }

    public function test_validate_is_case_insensitive_about_the_extension(): void
    {
        $bytes = $this->zipBytes(['word/document.xml' => '<w:document/>']);

        self::assertSame(Format::Docx, FormatDetector::validate($bytes, 'CONTRACT.DOCX'));
    }

    public function test_validate_throws_when_a_docx_extension_is_claimed_but_content_is_not_docx(): void
    {
        $this->expectException(FormatMismatchException::class);

        FormatDetector::validate('# Just some markdown', 'contract.docx');
    }

    public function test_validate_exception_carries_both_signals(): void
    {
        try {
            FormatDetector::validate('# Just some markdown', 'contract.docx');
            self::fail('Expected FormatMismatchException was not thrown.');
        } catch (FormatMismatchException $exception) {
            self::assertSame(Format::Unknown, $exception->detected);
            self::assertSame(Format::Docx, $exception->claimedByExtension);
            self::assertSame('docx', $exception->extension);
            self::assertStringContainsString('docx', $exception->getMessage());
        }
    }

    public function test_validate_does_not_throw_for_an_extension_with_no_known_format_mapping(): void
    {
        $bytes = $this->zipBytes(['word/document.xml' => '<w:document/>']);

        self::assertSame(Format::Docx, FormatDetector::validate($bytes, 'contract.txt'));
    }

    public function test_validate_does_not_throw_for_a_filename_with_no_extension(): void
    {
        $bytes = $this->zipBytes(['word/document.xml' => '<w:document/>']);

        self::assertSame(Format::Docx, FormatDetector::validate($bytes, 'contract'));
    }
}
