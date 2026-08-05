# OOXML Package Layer (`Ooxml\OoxmlPackage`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement `Fissible\Transmark\Ooxml\OoxmlPackage`, a small,
format-agnostic zip + DOM extraction layer for OOXML packages (DOCX now,
XLSX later), per [GitHub issue #5](https://github.com/fissible/transmark/issues/5).

**Architecture:** One `final class OoxmlPackage` wrapping `ZipArchive`. A
private constructor takes an already-`open()`ed `ZipArchive` plus the
source path; a static `open(string $path): self` factory validates the
file exists and is a readable zip before constructing. `part(string): ?DOMDocument`
and `rawPart(string): ?string` read entries out of the zip on demand (no
eager extraction of the whole archive). A dedicated
`Exception\InvalidPackageException` (extends `\RuntimeException`) is
thrown for both "file doesn't exist" and "not a valid zip" — callers
catch one exception type for "this package is unusable," and get `null`
(not an exception) for "this specific part is absent," since a missing
`word/numbering.xml` is a normal, valid state (a docx with no numbered
content), not corruption.

**Tech Stack:** PHP 8.2+, `ext-zip` (`ZipArchive`), `ext-dom`
(`DOMDocument`) — both already required in `composer.json`, no new
dependencies.

## Global Constraints

- PHP `^8.2` — no PHP 8.3+-only syntax (e.g. no typed class constants).
- PSR-4 autoload root `Fissible\Transmark\` → `src/`,
  `Fissible\Transmark\Tests\` → `tests/` (already configured in
  `composer.json`, no changes needed).
- `declare(strict_types=1);` at the top of every new file (php-cs-fixer's
  `declare_strict_types` rule is enforced with `setRiskyAllowed(true)`, so
  this is checked in CI).
- PSR-12 formatting, enforced by `vendor/bin/php-cs-fixer fix --dry-run --diff`
  (must run clean before each commit in this plan).
- No new Composer dependencies — this ticket is pure infrastructure on
  top of `ext-zip`/`ext-dom`.
- Every step's test must actually run (`vendor/bin/phpunit`) before
  moving on — no step is "done" on code inspection alone.

---

### Task 1: `OoxmlPackage::open()` — file and zip validation

**Files:**
- Create: `src/Ooxml/Exception/InvalidPackageException.php`
- Create: `src/Ooxml/OoxmlPackage.php`
- Test: `tests/Ooxml/OoxmlPackageTest.php`

**Interfaces:**
- Produces: `Fissible\Transmark\Ooxml\Exception\InvalidPackageException extends \RuntimeException`.
- Produces: `Fissible\Transmark\Ooxml\OoxmlPackage::open(string $path): self`
  — throws `InvalidPackageException` if `$path` doesn't exist or isn't a
  valid zip archive.
- Later tasks in this plan add `part()`/`rawPart()`/`close()` to this same
  class — don't mark the class `final` in a way that blocks that (it's
  fine as `final`, just don't seal the constructor signature in a way
  that can't accept the `ZipArchive` instance later tasks need — see Task
  2's interface note).

- [ ] **Step 1: Write the failing tests**

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Ooxml/OoxmlPackageTest.php`
Expected: FAIL (or a fatal error) — `Fissible\Transmark\Ooxml\OoxmlPackage`
and `Fissible\Transmark\Ooxml\Exception\InvalidPackageException` don't
exist yet.

- [ ] **Step 3: Write the minimal implementation**

```php
<?php
// src/Ooxml/Exception/InvalidPackageException.php

declare(strict_types=1);

namespace Fissible\Transmark\Ooxml\Exception;

final class InvalidPackageException extends \RuntimeException
{
}
```

```php
<?php
// src/Ooxml/OoxmlPackage.php

declare(strict_types=1);

namespace Fissible\Transmark\Ooxml;

use Fissible\Transmark\Ooxml\Exception\InvalidPackageException;

/**
 * A thin zip + DOM reader for OOXML packages (DOCX, and eventually
 * XLSX) — format-agnostic on purpose, since both are a zip of XML
 * parts plus a relationships/content-types manifest.
 */
final class OoxmlPackage
{
    private function __construct(
        private readonly \ZipArchive $zip,
        private readonly string $path,
    ) {
    }

    public static function open(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidPackageException(sprintf('No such file: "%s".', $path));
        }

        $zip = new \ZipArchive();
        $status = $zip->open($path);

        if ($status !== true) {
            throw new InvalidPackageException(sprintf(
                '"%s" is not a valid zip archive (ZipArchive error code %d).',
                $path,
                $status,
            ));
        }

        return new self($zip, $path);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Ooxml/OoxmlPackageTest.php`
Expected: PASS (3 tests, 3 assertions)

- [ ] **Step 5: Lint and format check**

Run: `php -l src/Ooxml/Exception/InvalidPackageException.php src/Ooxml/OoxmlPackage.php tests/Ooxml/OoxmlPackageTest.php`
Run: `vendor/bin/php-cs-fixer fix --dry-run --diff`
Expected: no syntax errors, no formatting diffs (fix in place if there are
any, then re-run both commands).

- [ ] **Step 6: Commit**

```bash
git add src/Ooxml/Exception/InvalidPackageException.php src/Ooxml/OoxmlPackage.php tests/Ooxml/OoxmlPackageTest.php
git commit -m "feat: add OoxmlPackage::open() with zip/file validation"
```

---

### Task 2: `part()` and `rawPart()` — reading entries out of the zip

**Files:**
- Modify: `src/Ooxml/OoxmlPackage.php`
- Modify: `tests/Ooxml/OoxmlPackageTest.php`

**Interfaces:**
- Consumes: `OoxmlPackage::open()` from Task 1 (private `$zip`/`$path`
  properties already exist on the class).
- Produces: `OoxmlPackage::rawPart(string $partPath): ?string` — raw
  bytes of a zip entry, or `null` if the entry doesn't exist.
- Produces: `OoxmlPackage::part(string $partPath): ?\DOMDocument` — parses
  a part's bytes as XML, or `null` if the entry doesn't exist. Throws
  `InvalidPackageException` if the entry exists but fails to parse as
  XML (a corrupt part is a package-level problem, not a "this part is
  simply absent" case — callers must be able to tell those apart, per
  issue #5's stated distinction between "no numbering.xml" (valid, `null`)
  and "malformed package" (exception)).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Ooxml/OoxmlPackageTest.php` (inside the existing class):

```php
    public function test_raw_part_returns_null_for_a_missing_part_path(): void
    {
        $path = $this->writeZipFixture(['word/document.xml' => '<w:document/>']);
        $package = OoxmlPackage::open($path);

        self::assertNull($package->rawPart('word/numbering.xml'));
    }

    public function test_raw_part_returns_the_exact_bytes_of_an_existing_part(): void
    {
        $path = $this->writeZipFixture(['word/document.xml' => '<w:document>hello</w:document>']);
        $package = OoxmlPackage::open($path);

        self::assertSame('<w:document>hello</w:document>', $package->rawPart('word/document.xml'));
    }

    public function test_part_returns_null_for_a_missing_part_path(): void
    {
        $path = $this->writeZipFixture(['word/document.xml' => '<w:document/>']);
        $package = OoxmlPackage::open($path);

        self::assertNull($package->part('word/numbering.xml'));
    }

    public function test_part_throws_for_a_malformed_xml_part(): void
    {
        $path = $this->writeZipFixture(['word/document.xml' => '<w:document>not closed']);
        $package = OoxmlPackage::open($path);

        $this->expectException(InvalidPackageException::class);

        $package->part('word/document.xml');
    }

    public function test_part_returns_a_dom_document_with_working_namespace_queries(): void
    {
        $numberingXml = file_get_contents(
            __DIR__.'/../fixtures/numbering/legal-outline/numbering.xml',
        );
        $path = $this->writeZipFixture(['word/numbering.xml' => $numberingXml]);
        $package = OoxmlPackage::open($path);

        $dom = $package->part('word/numbering.xml');
        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

        self::assertNotNull($dom);
        $abstractNums = $dom->getElementsByTagNameNS($ns, 'abstractNum');
        self::assertSame(1, $abstractNums->length);
        self::assertSame('0', $abstractNums->item(0)->getAttributeNS($ns, 'abstractNumId'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Ooxml/OoxmlPackageTest.php`
Expected: FAIL — `rawPart()`/`part()` don't exist yet (fatal error calling
undefined method).

- [ ] **Step 3: Write the minimal implementation**

Add to `src/Ooxml/OoxmlPackage.php`, inside the `OoxmlPackage` class:

```php
    public function rawPart(string $partPath): ?string
    {
        $contents = $this->zip->getFromName($partPath);

        return $contents === false ? null : $contents;
    }

    public function part(string $partPath): ?\DOMDocument
    {
        $xml = $this->rawPart($partPath);

        if ($xml === null) {
            return null;
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            throw new InvalidPackageException(sprintf(
                'Part "%s" in "%s" is not well-formed XML.',
                $partPath,
                $this->path,
            ));
        }

        return $dom;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Ooxml/OoxmlPackageTest.php`
Expected: PASS (8 tests total so far)

- [ ] **Step 5: Lint and format check**

Run: `php -l src/Ooxml/OoxmlPackage.php`
Run: `vendor/bin/php-cs-fixer fix --dry-run --diff`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Ooxml/OoxmlPackage.php tests/Ooxml/OoxmlPackageTest.php
git commit -m "feat: add OoxmlPackage::part()/rawPart() zip-entry access"
```

---

### Task 3: `close()` and repeated open/close resource-cycle test

**Files:**
- Modify: `src/Ooxml/OoxmlPackage.php`
- Modify: `tests/Ooxml/OoxmlPackageTest.php`

**Interfaces:**
- Consumes: `OoxmlPackage::open()`/`$this->zip` from Task 1.
- Produces: `OoxmlPackage::close(): void` — closes the underlying
  `ZipArchive` handle. This is the last public method issue #5 specifies;
  after this task the class's public API matches the issue's proposed
  shape exactly.

- [ ] **Step 1: Write the failing test**

Add to `tests/Ooxml/OoxmlPackageTest.php`:

```php
    public function test_repeated_open_close_cycles_do_not_leak_resources(): void
    {
        $path = $this->writeZipFixture(['word/document.xml' => '<w:document/>']);

        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        });

        for ($i = 0; $i < 100; $i++) {
            $package = OoxmlPackage::open($path);
            $package->part('word/document.xml');
            $package->close();
        }

        restore_error_handler();

        self::assertSame([], $warnings, 'Expected no PHP warnings/errors across 100 open/close cycles.');
    }

    public function test_close_does_not_throw(): void
    {
        $path = $this->writeZipFixture(['word/document.xml' => '<w:document/>']);
        $package = OoxmlPackage::open($path);

        $package->close();

        $this->addToAssertionCount(1); // reaching this line without a thrown error is the assertion
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Ooxml/OoxmlPackageTest.php`
Expected: FAIL — `close()` doesn't exist yet (fatal error calling
undefined method).

- [ ] **Step 3: Write the minimal implementation**

Add to `src/Ooxml/OoxmlPackage.php`, inside the `OoxmlPackage` class:

```php
    public function close(): void
    {
        $this->zip->close();
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Ooxml/OoxmlPackageTest.php`
Expected: PASS (10 tests total)

- [ ] **Step 5: Run the full project test suite**

Run: `composer test` (equivalent to `vendor/bin/phpunit`)
Expected: PASS — this suite plus the pre-existing
`tests/DocumentModelTest.php` (3 tests) both pass, 13 tests total, no
regressions.

- [ ] **Step 6: Lint and format check**

Run: `php -l src/Ooxml/OoxmlPackage.php`
Run: `vendor/bin/php-cs-fixer fix --dry-run --diff`
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Ooxml/OoxmlPackage.php tests/Ooxml/OoxmlPackageTest.php
git commit -m "feat: add OoxmlPackage::close(), complete issue #5's API surface"
```

---

## Self-Review

**Spec coverage against issue #5:**
- `open()` throws a clear exception for missing file / invalid zip → Task 1. ✓
- `part()` returns `null` for a missing part, not an exception → Task 2. ✓
- `part()` returns a working `DOMDocument` verified against a real
  committed fixture (`legal-outline/numbering.xml`), not a toy string →
  Task 2, last test. ✓
- Repeated open/close cycles don't leak/warn → Task 3. ✓
- `rawPart()` for non-XML parts → Task 2. ✓ (issue's acceptance criteria
  focus on `part()`, but `rawPart()` is in the issue's proposed shape and
  is trivial to cover alongside it.)
- In-memory zip fixtures built via `ZipArchive` in test setup, not a
  committed binary `.docx` → `writeZipFixture()` helper, used throughout. ✓

**Placeholder scan:** No TBD/TODO markers; every step has complete,
runnable code. No task references a method or type not defined in an
earlier task.

**Type consistency:** `OoxmlPackage::open(): self`,
`part(string): ?\DOMDocument`, `rawPart(string): ?string`,
`close(): void` are declared once (Task 1/2/3) and referenced
consistently with no renaming across tasks.

**Deliberately out of scope** (per issue #5's own "keep this minimal" note
— do not add in this plan): `_rels/*.rels` relationship resolution,
`[Content_Types].xml` parsing. If `DocxReader` (#6/#7) later needs either,
that's new scope for those tickets, not a retroactive change to this
plan.
