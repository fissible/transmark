# fissible/transmark-pdf Scaffold Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up `fissible/transmark-pdf`, a new satellite Composer package providing `PdfWriter` — a `WriterInterface` implementation that composes `fissible/transmark`'s `HtmlWriter` output with `dompdf/dompdf` to produce PDF bytes — closing [fissible/transmark#39](https://github.com/fissible/transmark/issues/39).

**Architecture:** `PdfWriter::write(Document $document): string` internally runs `HtmlWriter::write()` to get HTML, feeds that HTML into a `Dompdf` instance (`loadHtml()` → `setPaper()` → `render()` → `output()`), and returns the raw PDF bytes `output()` produces. No new document-model concepts, no PDF rendering logic of our own — this package is purely a composition/adapter layer, mirroring the `fissible/transmark-blade` / `fissible/transmark-xlsx` satellite-package precedent (#13/#14).

**Tech Stack:** PHP 8.2+, `dompdf/dompdf` ^3.1 (LGPL-2.1, pure-PHP, requires only `ext-dom` + `ext-mbstring`), `fissible/transmark` (path/VCS dependency, not yet on Packagist), PHPUnit ^11.0, php-cs-fixer (PSR-12).

## Global Constraints

- New GitHub repo: `fissible/transmark-pdf`, created **private** (org default — flip to public later if desired, not part of this plan).
- PHP version floor: `^8.2` (matches `fissible/transmark`).
- `fissible/transmark` is **not on Packagist** (though it is a public repo) — `composer.json` needs a `repositories` entry of type `vcs` pointing at `https://github.com/fissible/transmark.git`. CI runners have no SSH agent, so the URL must be HTTPS; a `composer config --global github-oauth.github.com` auth step using `secrets.FISSIBLE_PAT` is required in CI regardless, to avoid unauthenticated GitHub API rate limits during resolution. Version constraint: `^0.3` (current tag: `v0.3.0`).
- `dompdf/dompdf` version constraint: `^3.1` (latest stable on Packagist is `v3.1.6` as of this plan).
- PSR-4 namespace: `Fissible\Transmark\Pdf\` → `src/`; tests: `Fissible\Transmark\Pdf\Tests\` → `tests/`.
- `PdfWriter` implements `Fissible\Transmark\Contracts\WriterInterface` (`write(Document $document): string`) — returns raw PDF bytes directly from `Dompdf::output()`, no file path, no side-effect writes.
- License: MIT, copyright "Fissible" — copy `fissible/transmark`'s `LICENSE` verbatim except the repo doesn't change (MIT text has no repo name in it).
- Code style: PSR-12 + `declare(strict_types=1)` everywhere, enforced by `php-cs-fixer` — copy `fissible/transmark`'s `.php-cs-fixer.dist.php` verbatim.
- Test runner: PHPUnit `^11.0`, config copied from `fissible/transmark`'s `phpunit.xml.dist` with the `testsuite name` changed to `TransmarkPdf`.
- CI: mirror `fissible/transmark`'s own `.github/workflows/ci.yml` pattern exactly (PHP 8.2/8.3/8.4 matrix, `composer install`, lint, `vendor/bin/phpunit`, `php-cs-fixer` dry-run on 8.4 only) — **not** the org's generic bash-matrix reusable workflow (`fissible/.github`'s `test-bash.yml`), which is bash-project-specific and doesn't apply here.
- Release wiring: reuse the org's generic `release.yml` reusable workflow, `.cliff.toml`, and `release.sh` verbatim (these are already PHP-agnostic — `fissible/transmark` uses them unmodified).
- Initial `VERSION`: `0.1.0`.
- Never commit `vendor/`, `composer.lock`, PHPUnit/php-cs-fixer caches, or `.claude/`/`.serena/` — same `.gitignore` as `fissible/transmark`.

---

### Task 1: Repository scaffold — org-standard files, Composer wiring, CI/release workflows

**Files:**
- Create (new repo root `~/lib/fissible/transmark-pdf/`): `composer.json`, `README.md`, `LICENSE`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, `.gitignore`, `phpunit.xml.dist`, `.php-cs-fixer.dist.php`, `.cliff.toml`, `release.sh`, `VERSION`, `CHANGELOG.md`
- Create: `.github/workflows/ci.yml`, `.github/workflows/release.yml`, `.github/PULL_REQUEST_TEMPLATE.md`, `.github/ISSUE_TEMPLATE/bug_report.md`, `.github/ISSUE_TEMPLATE/feature_request.md`

**Interfaces:**
- Produces: a working `composer install` that resolves `fissible/transmark` (`^0.3`) via the `vcs` repository entry and `dompdf/dompdf` (`^3.1`) from Packagist, with PSR-4 autoloading `Fissible\Transmark\Pdf\` → `src/` (source doesn't exist yet — Task 2 creates it) and `Fissible\Transmark\Pdf\Tests\` → `tests/`.

- [ ] **Step 1: Create the GitHub repository and clone it locally**

```bash
gh repo create fissible/transmark-pdf --private \
  --description "PDF export for fissible/transmark: PdfWriter composes HtmlWriter output with dompdf/dompdf" \
  --clone
cd ~/lib/fissible/transmark-pdf  # gh clones into the current directory by repo name
```

Confirm the clone landed at `~/lib/fissible/transmark-pdf` (sibling to `~/lib/fissible/transmark`) before continuing — every step below assumes that path as the working directory.

- [ ] **Step 2: Copy org-standard docs from `fissible/transmark`, adjusting repo-name references**

```bash
cd ~/lib/fissible/transmark-pdf
cp ~/lib/fissible/transmark/LICENSE .
cp ~/lib/fissible/transmark/.cliff.toml .
cp ~/lib/fissible/transmark/release.sh . && chmod +x release.sh
cp ~/lib/fissible/transmark/.gitignore .
cp ~/lib/fissible/transmark/CONTRIBUTING.md .
cp ~/lib/fissible/transmark/CODE_OF_CONDUCT.md .
cp ~/lib/fissible/transmark/SECURITY.md .
mkdir -p .github/ISSUE_TEMPLATE
cp ~/lib/fissible/transmark/.github/PULL_REQUEST_TEMPLATE.md .github/
cp ~/lib/fissible/transmark/.github/ISSUE_TEMPLATE/*.md .github/ISSUE_TEMPLATE/
# Replace any literal "fissible/transmark" repo references with "fissible/transmark-pdf"
# in the copied docs (CONTRIBUTING.md / SECURITY.md commonly link back to the repo).
grep -rl 'fissible/transmark\b' CONTRIBUTING.md CODE_OF_CONDUCT.md SECURITY.md .github/ 2>/dev/null \
  | xargs -I{} sed -i '' 's#fissible/transmark\b#fissible/transmark-pdf#g' {}
```

- [ ] **Step 3: Write `composer.json`**

```json
{
    "name": "fissible/transmark-pdf",
    "description": "PDF export for fissible/transmark: PdfWriter composes HtmlWriter output with dompdf/dompdf.",
    "type": "library",
    "license": "MIT",
    "keywords": ["docx", "pdf", "converter", "transmark", "dompdf"],
    "homepage": "https://github.com/fissible/transmark-pdf",
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/fissible/transmark.git"
        }
    ],
    "require": {
        "php": "^8.2",
        "fissible/transmark": "^0.3",
        "dompdf/dompdf": "^3.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "friendsofphp/php-cs-fixer": "^3.0"
    },
    "autoload": {
        "psr-4": {
            "Fissible\\Transmark\\Pdf\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Fissible\\Transmark\\Pdf\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true,
    "scripts": {
        "test": "phpunit",
        "cs": "php-cs-fixer fix --dry-run --diff"
    }
}
```

- [ ] **Step 4: Write `phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.0/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="TransmarkPdf">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 5: Write `.php-cs-fixer.dist.php`**

```php
<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => true,
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
```

- [ ] **Step 6: Write `VERSION` and an initial `CHANGELOG.md`**

```bash
echo "0.1.0" > VERSION
```

```markdown
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/).

## [Unreleased]
```

Save the above as `CHANGELOG.md`.

- [ ] **Step 7: Write `.github/workflows/ci.yml`**

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.2', '8.3', '8.4']
    steps:
      - uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4

      - uses: shivammathur/setup-php@b604ade2a87db23f8871b7182e69ec5e75effb45 # v2
        with:
          php-version: ${{ matrix.php }}
          extensions: dom, mbstring
          coverage: none

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress

      - name: Lint
        run: find src tests -name "*.php" -exec php -l {} \;

      - name: Test
        run: vendor/bin/phpunit

      - name: Check code style
        if: matrix.php == '8.4'
        run: vendor/bin/php-cs-fixer fix --dry-run --diff
```

Note: CI resolution of the `fissible/transmark` VCS dependency needs an authenticated GitHub API call to avoid unauthenticated rate limits (the repo itself is public, but the runner has no SSH agent, so the `vcs` URL is HTTPS). Add a `composer config` step using `secrets.FISSIBLE_PAT` (a per-repo Actions secret — `fissible` is a personal account, not an org, so this must be set on `fissible/transmark-pdf` specifically, not shared org-wide) before `Install dependencies`:

```yaml
      - name: Configure Composer GitHub auth
        run: composer config --global github-oauth.github.com ${{ secrets.FISSIBLE_PAT }}
```

Insert this step unconditionally between `setup-php` and `Install dependencies` — CI runners have no SSH agent and no cached GitHub credentials, so the auth step is required every run, not just as a fallback for a failing `composer install`.

- [ ] **Step 8: Write `.github/workflows/release.yml`**

```yaml
name: Release

on:
  push:
    tags: ['v*']

permissions:
  contents: write

jobs:
  release:
    name: Create GitHub Release
    runs-on: ubuntu-latest

    steps:
      - name: Checkout
        uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4
        with:
          fetch-depth: 0

      - name: Verify tag is on main
        run: |
          git fetch origin main
          git merge-base --is-ancestor "$GITHUB_SHA" origin/main \
            || { echo "Tag is not on main — release aborted."; exit 1; }

      - name: Extract release notes from CHANGELOG.md
        run: |
          VERSION="${GITHUB_REF_NAME#v}"
          awk "/^## \\[${VERSION}\\]/{found=1; next} found && /^## \\[/{exit} found{print}" CHANGELOG.md \
            | sed '/^[[:space:]]*$/d' > release_notes.txt
          if [[ ! -s release_notes.txt ]]; then
            echo "No CHANGELOG entry found for ${VERSION}" > release_notes.txt
          fi

      - name: Create GitHub Release
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          gh release create "$GITHUB_REF_NAME" \
            --title "$GITHUB_REF_NAME" \
            --notes-file release_notes.txt
```

- [ ] **Step 9: Run `composer install` and verify the dependency graph resolves**

```bash
composer install
```

Expected: Composer clones `fissible/transmark` via the `vcs` repository entry (HTTPS URL, using a `composer config --global github-oauth.github.com` auth step wired to `secrets.FISSIBLE_PAT` in CI to avoid unauthenticated GitHub API rate limits) and installs `dompdf/dompdf` from Packagist. `vendor/autoload.php` should exist afterward. If Composer reports it cannot resolve `fissible/transmark`, confirm the `FISSIBLE_PAT` repository-secret is present on `fissible/transmark-pdf` (not just the local machine) before investigating further.

- [ ] **Step 10: Write a minimal placeholder `README.md`**

```markdown
# fissible/transmark-pdf

PDF export for [fissible/transmark](https://github.com/fissible/transmark): `PdfWriter` composes `HtmlWriter` output with [dompdf/dompdf](https://github.com/dompdf/dompdf) to produce PDF bytes.

> Full usage documentation lands in a follow-up task once `PdfWriter` exists.

## Requirements

- PHP ^8.2
- ext-dom, ext-mbstring

## License

MIT
```

(Task 4 replaces this with the full usage doc once `PdfWriter` is implemented and tested.)

- [ ] **Step 11: Commit and push the scaffold**

```bash
git add composer.json README.md LICENSE CONTRIBUTING.md CODE_OF_CONDUCT.md SECURITY.md \
  .gitignore phpunit.xml.dist .php-cs-fixer.dist.php .cliff.toml release.sh VERSION CHANGELOG.md \
  .github/
git commit -m "chore: scaffold fissible/transmark-pdf package"
git push -u origin main
```

**Note:** `composer.lock` and `vendor/` must NOT be committed (already covered by the copied `.gitignore` — verify with `git status` before the `git add` above that neither appears).

---

### Task 2: `PdfWriter` core implementation + unit test

**Files:**
- Create: `src/PdfWriter.php`
- Test: `tests/PdfWriterTest.php`

**Interfaces:**
- Consumes: `Fissible\Transmark\Contracts\WriterInterface` (`write(Document $document): string`), `Fissible\Transmark\Document`, `Fissible\Transmark\Writers\HtmlWriter` (`write(Document $document): string`) — all from `fissible/transmark`, wired in Task 1. `Dompdf\Dompdf` and `Dompdf\Options` from `dompdf/dompdf`.
- Produces: `Fissible\Transmark\Pdf\PdfWriter implements WriterInterface`, constructor `__construct(HtmlWriter $htmlWriter = new HtmlWriter(), string $paperSize = 'letter', string $paperOrientation = 'portrait')`, method `write(Document $document): string` returning raw PDF bytes. Task 3's integration test and Task 4's README example both call `new PdfWriter()` with no arguments.

- [ ] **Step 1: Write the failing unit test**

Create `tests/PdfWriterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fissible\Transmark\Pdf\Tests;

use Fissible\Transmark\Contracts\WriterInterface;
use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Pdf\PdfWriter;
use PHPUnit\Framework\TestCase;

final class PdfWriterTest extends TestCase
{
    public function test_write_implements_writer_interface_contract(): void
    {
        self::assertInstanceOf(WriterInterface::class, new PdfWriter());
    }

    public function test_write_returns_bytes_with_a_valid_pdf_header_and_trailer(): void
    {
        $document = new Document([
            new Paragraph([new Text('Hello World')]),
        ]);

        $pdf = (new PdfWriter())->write($document);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('%%EOF', $pdf);
        self::assertGreaterThan(1000, strlen($pdf));
    }

    public function test_write_accepts_an_empty_document_without_throwing(): void
    {
        $pdf = (new PdfWriter())->write(new Document([]));

        self::assertStringStartsWith('%PDF-', $pdf);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/PdfWriterTest.php`
Expected: FAIL — `Class "Fissible\Transmark\Pdf\PdfWriter" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/PdfWriter.php`:

```php
<?php

declare(strict_types=1);

namespace Fissible\Transmark\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Fissible\Transmark\Contracts\WriterInterface;
use Fissible\Transmark\Document;
use Fissible\Transmark\Writers\HtmlWriter;

final class PdfWriter implements WriterInterface
{
    public function __construct(
        private readonly HtmlWriter $htmlWriter = new HtmlWriter(),
        private readonly string $paperSize = 'letter',
        private readonly string $paperOrientation = 'portrait',
    ) {
    }

    public function write(Document $document): string
    {
        $html = $this->htmlWriter->write($document);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        // Disallow remote resource fetches (SSRF hardening): PdfWriter's
        // input is a converted Document, not trusted arbitrary HTML.
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper($this->paperSize, $this->paperOrientation);
        $dompdf->render();

        return $dompdf->output();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/PdfWriterTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Run full test suite and code style check**

```bash
vendor/bin/phpunit
vendor/bin/php-cs-fixer fix --dry-run --diff
```

Expected: all tests pass, no style violations.

- [ ] **Step 6: Commit**

```bash
git add src/PdfWriter.php tests/PdfWriterTest.php
git commit -m "feat: add PdfWriter composing HtmlWriter output with dompdf"
```

---

### Task 3: Integration test — real DOCX → HTML → PDF pipeline via the legal-outline fixture

**Files:**
- Create: `tests/fixtures/legal-outline/document.xml`, `tests/fixtures/legal-outline/numbering.xml` (copied verbatim from `fissible/transmark`'s `tests/fixtures/numbering/legal-outline/`)
- Test: `tests/PdfWriterIntegrationTest.php`

**Interfaces:**
- Consumes: `Fissible\Transmark\Readers\DocxReader::read(string $content): Document`, `Fissible\Transmark\Writers\HtmlWriter::write(Document $document): string`, `Fissible\Transmark\Pdf\PdfWriter::write(Document $document): string` (Task 2).
- Produces: nothing new consumed by later tasks — this task's deliverable is proof the full composition works end-to-end against a real, non-synthetic DOCX numbering fixture (the legal-outline pattern is `transmark`'s hardest structural case: one `numId` spanning four nesting depths with concatenated `lvlText` labels).

- [ ] **Step 1: Copy the fixture files**

```bash
mkdir -p tests/fixtures/legal-outline
cp ~/lib/fissible/transmark/tests/fixtures/numbering/legal-outline/document.xml tests/fixtures/legal-outline/
cp ~/lib/fissible/transmark/tests/fixtures/numbering/legal-outline/numbering.xml tests/fixtures/legal-outline/
```

- [ ] **Step 2: Write the failing integration test**

Create `tests/PdfWriterIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fissible\Transmark\Pdf\Tests;

use Fissible\Transmark\Pdf\PdfWriter;
use Fissible\Transmark\Readers\DocxReader;
use Fissible\Transmark\Writers\HtmlWriter;
use PHPUnit\Framework\TestCase;

final class PdfWriterIntegrationTest extends TestCase
{
    public function test_legal_outline_docx_fixture_converts_to_pdf_through_the_full_pipeline(): void
    {
        $document = (new DocxReader())->read($this->fixtureDocx('legal-outline'));
        $html = (new HtmlWriter())->write($document);

        $pdf = (new PdfWriter())->write($document);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('%%EOF', $pdf);
        self::assertGreaterThan(1000, strlen($pdf));
        // Sanity check that the HTML this PDF was built from actually
        // carries the legal-outline flat-paragraph rendering strategy.
        self::assertSame(7, substr_count($html, '<p class="numbered-paragraph legal-level-'));
    }

    private function fixtureDocx(string $name): string
    {
        $fixturePath = __DIR__.'/fixtures/'.$name;
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
        $path = tempnam(sys_get_temp_dir(), 'transmark-pdf-integration-test-');
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
            @unlink($path);
        }
    }
}
```

- [ ] **Step 3: Run the test to verify it fails for the right reason**

Run: `vendor/bin/phpunit tests/PdfWriterIntegrationTest.php`
Expected: FAIL — fixture files not found (`file_get_contents()` returns `false`) if Step 1 was skipped, or PASS immediately if Step 1 already ran (no new production code is needed here — `PdfWriter` from Task 2 is generic). Either way, confirm the fixture copy landed correctly before moving on.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/PdfWriterIntegrationTest.php`
Expected: PASS (1 test). If it fails on the `substr_count` assertion, re-check the fixture files copied byte-for-byte from `fissible/transmark` (do not hand-edit them).

- [ ] **Step 5: Run full test suite**

```bash
vendor/bin/phpunit
vendor/bin/php-cs-fixer fix --dry-run --diff
```

Expected: all tests pass (4 total across both test files), no style violations.

- [ ] **Step 6: Commit**

```bash
git add tests/fixtures/legal-outline/ tests/PdfWriterIntegrationTest.php
git commit -m "test: add end-to-end DOCX-to-PDF integration test using legal-outline fixture"
```

---

### Task 4: README usage documentation

**Files:**
- Modify: `README.md` (replaces Task 1's placeholder)

**Interfaces:**
- Consumes: `Fissible\Transmark\Pdf\PdfWriter` (Task 2), `Fissible\Transmark\Readers\DocxReader` (from `fissible/transmark`, documented as the typical upstream reader).
- Produces: nothing consumed by other tasks — this is the final deliverable making the package's "one require" value proposition (the reason #39 chose a satellite package over a docs-only recipe) legible to a new consumer.

- [ ] **Step 1: Replace `README.md` with full usage documentation**

```markdown
# fissible/transmark-pdf

PDF export for [fissible/transmark](https://github.com/fissible/transmark): `PdfWriter` composes `HtmlWriter` output with [dompdf/dompdf](https://github.com/dompdf/dompdf) (pure-PHP, LGPL-2.1) to produce PDF bytes — no system binaries, no `ext-gd`.

## Why a separate package?

`fissible/transmark` stays dependency-free at its core. A consumer who only needs DOCX → HTML never pays for a PDF rendering engine. A consumer who wants DOCX → HTML → PDF requires **this one package** — `fissible/transmark` and `dompdf/dompdf` both come along transitively via Composer, so there's one `composer require`, not two separate integrations to wire up by hand.

## Requirements

- PHP ^8.2
- ext-dom, ext-mbstring

## Installation

```bash
composer require fissible/transmark-pdf
```

## Usage

```php
use Fissible\Transmark\Pdf\PdfWriter;
use Fissible\Transmark\Readers\DocxReader;

$docxBytes = file_get_contents('agreement.docx');
$document = (new DocxReader())->read($docxBytes);

$pdfBytes = (new PdfWriter())->write($document);

file_put_contents('agreement.pdf', $pdfBytes);
```

`PdfWriter` implements `Fissible\Transmark\Contracts\WriterInterface`, the same contract `HtmlWriter`, `DocxWriter`, and `MarkdownWriter` implement — it's a drop-in alongside any other `transmark` writer.

### Paper size and orientation

```php
$writer = new PdfWriter(paperSize: 'A4', paperOrientation: 'landscape');
```

Accepts any paper size/orientation string [dompdf's `setPaper()`](https://github.com/dompdf/dompdf/wiki/Usage) supports.

## License

MIT
```

- [ ] **Step 2: Verify the README's code example actually runs**

Run a scratch script (not committed) to confirm the documented usage snippet works against the legal-outline fixture built the same way `tests/PdfWriterIntegrationTest.php` does:

```bash
php -r '
require "vendor/autoload.php";
use Fissible\Transmark\Pdf\PdfWriter;
use Fissible\Transmark\Readers\DocxReader;

$documentXml = file_get_contents("tests/fixtures/legal-outline/document.xml");
$numberingXml = file_get_contents("tests/fixtures/legal-outline/numbering.xml");

$path = tempnam(sys_get_temp_dir(), "readme-check-");
$zip = new ZipArchive();
$zip->open($path, ZipArchive::OVERWRITE);
$zip->addFromString("word/document.xml", $documentXml);
$zip->addFromString("word/numbering.xml", $numberingXml);
$zip->close();

$document = (new DocxReader())->read(file_get_contents($path));
$pdf = (new PdfWriter(paperSize: "A4", paperOrientation: "landscape"))->write($document);

echo str_starts_with($pdf, "%PDF-") ? "OK: valid PDF, ".strlen($pdf)." bytes\n" : "FAIL\n";
unlink($path);
'
```

Expected output: `OK: valid PDF, <N> bytes` where N is a plausible PDF size (a few thousand bytes).

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: add PdfWriter usage documentation"
```

- [ ] **Step 4: Push**

```bash
git push
```

---

## Self-Review Notes

- **Spec coverage:** #39's ask — `PdfWriter` in a new satellite package composing `HtmlWriter` + `dompdf/dompdf`, mirroring #13/#14's pattern, driven by a "one require" consumer need — is covered by Task 1 (package/repo exists, one require resolves the whole graph), Task 2 (the class itself), Task 3 (proves the composition against transmark's hardest real fixture, not just synthetic input), Task 4 (documents the "one require" story explicitly, since that was the stated rationale for choosing a package over a docs recipe).
- **License/dependency constraint from PROJECT.md:** `dompdf/dompdf` chosen specifically over `mpdf` for LGPL-2.1 (vs GPL-2.0-only) and `ext-mbstring`-only (vs `ext-gd`) — reflected in Global Constraints and Task 1's composer.json.
- **Type/interface consistency:** `PdfWriter::write(Document $document): string` matches `WriterInterface` exactly in every task; `new PdfWriter()` with no args (defaults) is used identically in Task 2's test, Task 3's test, and Task 4's README/verification script.
