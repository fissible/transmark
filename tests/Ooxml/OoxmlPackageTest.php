<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Ooxml;

use Fissible\Transmark\Ooxml\Exception\InvalidPackageException;
use Fissible\Transmark\Ooxml\OoxmlPackage;
use PHPUnit\Framework\TestCase;

final class OoxmlPackageTest extends TestCase
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
    private function writeZipFixture(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'transmark-ooxml-');
        $this->tempFiles[] = $path;

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($entries as $entryPath => $contents) {
            $zip->addFromString($entryPath, $contents);
        }
        $zip->close();

        return $path;
    }

    public function test_open_throws_for_a_nonexistent_path(): void
    {
        $this->expectException(InvalidPackageException::class);

        OoxmlPackage::open(sys_get_temp_dir().'/does-not-exist-'.uniqid().'.docx');
    }

    public function test_open_throws_for_a_file_that_is_not_a_valid_zip(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'transmark-not-a-zip-');
        $this->tempFiles[] = $path;
        file_put_contents($path, 'this is plain text, not a zip archive');

        $this->expectException(InvalidPackageException::class);

        OoxmlPackage::open($path);
    }

    public function test_open_succeeds_for_a_valid_zip(): void
    {
        $path = $this->writeZipFixture(['word/document.xml' => '<w:document/>']);

        $package = OoxmlPackage::open($path);

        self::assertInstanceOf(OoxmlPackage::class, $package);
    }
}
