# HtmlReader Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `HtmlReader`, a best-effort arbitrary-HTML → canonical `Document` tree reader for `fissible/transmark`, closing #43.

**Architecture:** `HtmlReader implements ReaderInterface` parses HTML with PHP's built-in `DOMDocument::loadHTML()` (no new dependency — `ext-dom` is already required) and walks the DOM with the same match-based `mapBlock`/`mapInline` dispatch pattern `MarkdownReader` already uses. Structural scaffolding (`script`/`style`/`head`) is stripped; unrecognized structural containers (`div`, `section`, custom wrappers) are unwrapped transparently; only genuinely unmappable *content* elements (forms, embeds, custom elements) throw `HtmlParseException`. This is a best-effort reader with a hard failure mode, not a silent-lossy one and not a narrow round-trip-only one — see Global Constraints.

**Tech Stack:** PHP 8.2+, `ext-dom`'s `DOMDocument` HTML parser (libxml), PHPUnit.

## Global Constraints

- No new Composer dependency. `ext-dom` is already required by `composer.json`.
- **Strip vs. unwrap vs. throw is a fixed, three-way policy — do not blur these:**
  - **Strip silently** (no node emitted, not an error): `script`, `style`, `head`, `meta`, `link`, `title`, `noscript`, and HTML comments (`<!-- -->`). These are not reader-visible content — same category as a browser's reader-mode treatment. HTML comments are NOT mapped to the `Comment` node class (see next point).
  - **Unwrap transparently** (no node emitted for the container itself, but its children are still walked and contribute to the parent's content): any element tag not otherwise recognized — this covers `div`, `span` (only when not resolved as inline), `section`, `article`, `header`, `footer`, `nav`, `main`, `aside`, and any other unknown block-level wrapper, without needing an explicit allow-list. This is the default for "no reasonable node-taxonomy target, but it's not embed/form content either."
  - **Throw `HtmlParseException`**: `form`, `input`, `button`, `select`, `textarea`, `canvas`, `svg`, `iframe`, `video`, `audio`, `object`, `embed`, `map`, `area`, and any custom element (tag name containing a hyphen, per the HTML Custom Elements naming rule). These have no representable content model in the node taxonomy — throwing lets the user find and remove/replace them rather than silently losing content.
  - A bare, non-whitespace text node directly under a block container becomes its own `Paragraph` (best-effort default for markup that skips a wrapping element).
- **`Fissible\Transmark\Nodes\Inline\Comment` is NOT used by this reader.** It represents an editorial/review-comment attached to block content (see `Comment::__construct(array $content, ?string $author)` — it wraps `BlockInterface[]`), not a raw HTML `<!-- -->` markup comment. HTML comments are stripped (see above). Do not conflate the two.
- **Never emit `NumberingRef`.** Like `MarkdownReader`, visually-numbered HTML paragraphs are read as plain `Paragraph`/`ListNode` content, never reconstructed into Word-style numbering. This is a known, deliberate lossy point — legal-outline round-tripping through HTML is out of scope, same category as the analogous PDF limitation in transmark-pdf#4.
- **UTF-8 handling:** `DOMDocument::loadHTML()` mangles UTF-8 without an explicit hint (verified: `café` becomes `cafÃ©`). Fix by prepending `<?xml encoding="utf-8"?>` to the input before parsing — verified to produce correct output with zero stray DOM nodes. Do **not** use `mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')` — verified to emit a PHP deprecation notice on PHP 8.2+ (`Handling HTML entities via mbstring is deprecated`).
- **Empty input:** `DOMDocument::loadHTML('')` throws an uncaught `ValueError` on PHP 8.2+ (verified). Guard explicitly (`trim($content) === ''`) before calling `loadHTML()` and throw `HtmlParseException` instead.
- **`loadHTML()`'s boolean return value and `libxml_get_errors()` are NOT reliable failure signals.** Verified: `loadHTML()` returns `true` even for genuinely non-HTML garbage input (`"<<<>>>not html at all &&&&"`), and produces a non-empty DOM. `libxml_get_errors()` only reports on truly malformed markup (bad tag names, bad entities) — ordinary sloppy-but-recoverable HTML (e.g. unclosed `<p>`/`<div>` tags) parses silently with **zero** errors, which is exactly the "don't throw on sloppy-but-recognizable markup" behavior this reader wants. The real failure signal is: **no `<body>` element, or `<body>` maps to zero blocks** — that's what `HtmlParseException` is keyed on, not parser return codes.
- Follow `MarkdownReader`'s file conventions: single class, `match (true)`/`match ($tag)`-based dispatch, `mapBlockChildren`/`mapInlineChildren` recursion helpers.
- `HtmlWriter`'s round-trip convention for `CodeBlock` (per #45, filed alongside this plan): `<pre><code class="language-{lang}">...</code></pre>` when a language is set, `<pre><code>...</code></pre>` otherwise. `HtmlReader`'s `CodeBlock` parsing must match this exactly so the two sides agree once #45 ships.
- Run `vendor/bin/phpunit` (full suite) and `composer cs` (must report zero violations) before every commit.

---

## File Structure

- Create: `src/Readers/Exception/HtmlParseException.php` — thrown on unmappable content or content-free input. Mirrors `Ooxml\Exception\InvalidPackageException`'s style (plain `\RuntimeException` subclass, caller builds the message).
- Create: `src/Readers/HtmlReader.php` — the reader itself.
- Create: `tests/Readers/HtmlReaderTest.php` — per-feature unit tests, built up task by task.
- Create: `tests/fixtures/html/` — a small corpus of messy real-world-style HTML fixtures for the final best-effort integration test (Task 10).

---

### Task 1: `HtmlParseException`

**Files:**
- Create: `src/Readers/Exception/HtmlParseException.php`

**Interfaces:**
- Produces: `Fissible\Transmark\Readers\Exception\HtmlParseException extends \RuntimeException` — used by every later task.

- [ ] **Step 1: Write the class**

```php
<?php

declare(strict_types=1);

namespace Fissible\Transmark\Readers\Exception;

/**
 * Thrown when HTML content cannot be mapped into the canonical Document
 * tree: no parsable content was found, or an element has no representable
 * node-taxonomy target (forms, embeds, custom elements).
 */
final class HtmlParseException extends \RuntimeException
{
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Readers/Exception/HtmlParseException.php
git commit -m "feat: add HtmlParseException"
```

---

### Task 2: `HtmlReader` skeleton — paragraphs, text, UTF-8, empty-input guard

**Files:**
- Create: `src/Readers/HtmlReader.php`
- Test: `tests/Readers/HtmlReaderTest.php`

**Interfaces:**
- Consumes: `Fissible\Transmark\Contracts\ReaderInterface` (`read(string $content): Document`), `Fissible\Transmark\Readers\Exception\HtmlParseException` (Task 1).
- Produces: `HtmlReader implements ReaderInterface`. Internal method shape used by every later task — **do not change these signatures in later tasks**:
  - `private function mapBlockChildren(\DOMNode $container): array` — returns `BlockInterface[]`
  - `private function mapBlockChild(\DOMNode $node): array` — returns `BlockInterface[]` (0 or 1 elements; array-returning so a later task can make an unwrapped container contribute multiple sibling blocks without changing this signature)
  - `private function mapInlineChildren(\DOMNode $container): array` — returns `InlineInterface[]`
  - `private function mapInline(\DOMNode $node): ?InlineInterface`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Fissible\Transmark\Tests\Readers;

use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Readers\Exception\HtmlParseException;
use Fissible\Transmark\Readers\HtmlReader;
use PHPUnit\Framework\TestCase;

final class HtmlReaderTest extends TestCase
{
    private function read(string $html): Document
    {
        return (new HtmlReader())->read($html);
    }

    public function test_reads_a_single_paragraph(): void
    {
        $document = $this->read('<html><body><p>Hello world</p></body></html>');

        $content = $document->content();
        self::assertCount(1, $content);
        self::assertInstanceOf(Paragraph::class, $content[0]);

        $inlines = $content[0]->inlines();
        self::assertCount(1, $inlines);
        self::assertInstanceOf(Text::class, $inlines[0]);
        self::assertSame('Hello world', $inlines[0]->content());
    }

    public function test_reads_multiple_paragraphs_in_order(): void
    {
        $document = $this->read('<body><p>First</p><p>Second</p></body>');

        $content = $document->content();
        self::assertCount(2, $content);
        self::assertSame('First', $content[0]->inlines()[0]->content());
        self::assertSame('Second', $content[1]->inlines()[0]->content());
    }

    public function test_bare_text_directly_under_body_becomes_a_paragraph(): void
    {
        $document = $this->read('<body>Just some text, no wrapper</body>');

        $content = $document->content();
        self::assertCount(1, $content);
        self::assertInstanceOf(Paragraph::class, $content[0]);
        self::assertSame('Just some text, no wrapper', $content[0]->inlines()[0]->content());
    }

    public function test_throws_on_empty_content(): void
    {
        $this->expectException(HtmlParseException::class);

        $this->read('');
    }

    public function test_throws_on_whitespace_only_content(): void
    {
        $this->expectException(HtmlParseException::class);

        $this->read('   ');
    }

    public function test_throws_when_there_is_no_parsable_content(): void
    {
        $this->expectException(HtmlParseException::class);

        $this->read('<html><body></body></html>');
    }

    public function test_handles_utf8_content_correctly(): void
    {
        $document = $this->read('<p>café — 日本語 🎉</p>');

        self::assertSame('café — 日本語 🎉', $document->content()[0]->inlines()[0]->content());
    }

    public function test_does_not_throw_on_malformed_but_recoverable_markup(): void
    {
        $document = $this->read('<p>Unclosed paragraph<p>Second paragraph');

        self::assertNotEmpty($document->content());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: FAIL — `Class "Fissible\Transmark\Readers\HtmlReader" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Fissible\Transmark\Readers;

use Fissible\Transmark\Contracts\BlockInterface;
use Fissible\Transmark\Contracts\InlineInterface;
use Fissible\Transmark\Contracts\ReaderInterface;
use Fissible\Transmark\Document;
use Fissible\Transmark\Nodes\Block\Paragraph;
use Fissible\Transmark\Nodes\Inline\Text;
use Fissible\Transmark\Readers\Exception\HtmlParseException;

final class HtmlReader implements ReaderInterface
{
    public function read(string $content): Document
    {
        if (trim($content) === '') {
            throw new HtmlParseException('Cannot parse empty HTML content.');
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$content);
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);

        if (!$body instanceof \DOMElement) {
            throw new HtmlParseException('The HTML content has no <body> to read.');
        }

        $blocks = $this->mapBlockChildren($body);

        if ($blocks === []) {
            throw new HtmlParseException('No parsable content was found in the HTML.');
        }

        return new Document($blocks);
    }

    /**
     * @return BlockInterface[]
     */
    private function mapBlockChildren(\DOMNode $container): array
    {
        $blocks = [];

        foreach ($container->childNodes as $child) {
            array_push($blocks, ...$this->mapBlockChild($child));
        }

        return $blocks;
    }

    /**
     * @return BlockInterface[]
     */
    private function mapBlockChild(\DOMNode $node): array
    {
        if ($node instanceof \DOMText) {
            $text = trim($node->textContent);

            return $text === '' ? [] : [new Paragraph([new Text($text)])];
        }

        if (!$node instanceof \DOMElement) {
            return [];
        }

        $tag = strtolower($node->localName);

        $block = match ($tag) {
            'p' => new Paragraph($this->mapInlineChildren($node)),
            default => null,
        };

        return $block !== null ? [$block] : [];
    }

    /**
     * @return InlineInterface[]
     */
    private function mapInlineChildren(\DOMNode $container): array
    {
        $inlines = [];

        foreach ($container->childNodes as $child) {
            $inline = $this->mapInline($child);
            if ($inline !== null) {
                $inlines[] = $inline;
            }
        }

        return $inlines;
    }

    private function mapInline(\DOMNode $node): ?InlineInterface
    {
        if ($node instanceof \DOMText) {
            return $node->textContent === '' ? null : new Text($node->textContent);
        }

        return null;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Readers/HtmlReader.php tests/Readers/HtmlReaderTest.php
git commit -m "feat: add HtmlReader skeleton (paragraphs, text, UTF-8, empty-input guard)"
```

---

### Task 3: Headings

**Files:**
- Modify: `src/Readers/HtmlReader.php`
- Test: `tests/Readers/HtmlReaderTest.php`

- [ ] **Step 1: Write the failing tests**

```php
    public function test_reads_headings_h1_through_h6(): void
    {
        $html = '<body>'.implode('', array_map(
            static fn (int $n) => "<h{$n}>Heading {$n}</h{$n}>",
            range(1, 6),
        )).'</body>';

        $content = $this->read($html)->content();

        self::assertCount(6, $content);
        foreach ($content as $index => $heading) {
            self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Heading::class, $heading);
            self::assertSame($index + 1, $heading->level());
            self::assertSame('Heading '.($index + 1), $heading->inlines()[0]->content());
        }
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php --filter test_reads_headings_h1_through_h6`
Expected: FAIL — headings map to nothing (empty content array / count mismatch).

- [ ] **Step 3: Implement**

Add the `use` import and insert the heading check before the `match ($tag)` block in `mapBlockChild`:

```php
use Fissible\Transmark\Nodes\Block\Heading;
```

```php
        $tag = strtolower($node->localName);

        if (preg_match('/^h([1-6])$/', $tag, $matches) === 1) {
            return [new Heading((int) $matches[1], $this->mapInlineChildren($node))];
        }

        $block = match ($tag) {
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Readers/HtmlReader.php tests/Readers/HtmlReaderTest.php
git commit -m "feat(HtmlReader): map h1-h6 to Heading"
```

---

### Task 4: Inline formatting (recursive, no fixed nesting order needed)

**Files:**
- Modify: `src/Readers/HtmlReader.php`
- Test: `tests/Readers/HtmlReaderTest.php`

Unlike `DocxReader::parseRun` (which must impose a fixed wrap order on flat Word run-property flags), the HTML DOM is already properly nested by the parser, so `mapInline` just recurses — this mirrors `MarkdownReader::mapInline` exactly.

- [ ] **Step 1: Write the failing tests**

```php
    public function test_reads_nested_inline_formatting(): void
    {
        $document = $this->read('<p><strong><em>bold italic</em></strong></p>');

        $inlines = $document->content()[0]->inlines();
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Strong::class, $inlines[0]);

        $children = $inlines[0]->children();
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Emphasis::class, $children[0]);
        self::assertSame('bold italic', $children[0]->children()[0]->content());
    }

    public function test_reads_b_and_i_as_strong_and_emphasis(): void
    {
        $document = $this->read('<p><b>bold</b> <i>italic</i></p>');

        $inlines = $document->content()[0]->inlines();
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Strong::class, $inlines[0]);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Emphasis::class, $inlines[2]);
    }

    public function test_reads_underline_strike_sub_sup_and_inline_code(): void
    {
        $document = $this->read(
            '<p><u>u</u><s>s</s><sub>sub</sub><sup>sup</sup><code>code</code></p>',
        );

        $inlines = $document->content()[0]->inlines();
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Underline::class, $inlines[0]);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Strike::class, $inlines[1]);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Subscript::class, $inlines[2]);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Superscript::class, $inlines[3]);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Code::class, $inlines[4]);
        self::assertSame('code', $inlines[4]->content());
    }

    public function test_reads_links_with_title(): void
    {
        $document = $this->read('<p><a href="https://example.com" title="Example">link text</a></p>');

        $link = $document->content()[0]->inlines()[0];
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Link::class, $link);
        self::assertSame('https://example.com', $link->href());
        self::assertSame('Example', $link->title());
        self::assertSame('link text', $link->children()[0]->content());
    }

    public function test_reads_links_without_a_title(): void
    {
        $link = $this->read('<p><a href="https://example.com">text</a></p>')->content()[0]->inlines()[0];

        self::assertNull($link->title());
    }

    public function test_reads_line_breaks(): void
    {
        $document = $this->read('<p>line one<br>line two</p>');

        $inlines = $document->content()[0]->inlines();
        self::assertSame('line one', $inlines[0]->content());
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\LineBreak::class, $inlines[1]);
        self::assertSame('line two', $inlines[2]->content());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: FAIL on the new tests — `mapInline` currently returns `null` for all elements.

- [ ] **Step 3: Implement**

```php
use Fissible\Transmark\Nodes\Inline\Code;
use Fissible\Transmark\Nodes\Inline\Emphasis;
use Fissible\Transmark\Nodes\Inline\LineBreak;
use Fissible\Transmark\Nodes\Inline\Link;
use Fissible\Transmark\Nodes\Inline\Strike;
use Fissible\Transmark\Nodes\Inline\Strong;
use Fissible\Transmark\Nodes\Inline\Subscript;
use Fissible\Transmark\Nodes\Inline\Superscript;
use Fissible\Transmark\Nodes\Inline\Underline;
```

```php
    private function mapInline(\DOMNode $node): ?InlineInterface
    {
        if ($node instanceof \DOMText) {
            return $node->textContent === '' ? null : new Text($node->textContent);
        }

        if (!$node instanceof \DOMElement) {
            return null;
        }

        $tag = strtolower($node->localName);

        return match ($tag) {
            'strong', 'b' => new Strong($this->mapInlineChildren($node)),
            'em', 'i' => new Emphasis($this->mapInlineChildren($node)),
            'u' => new Underline($this->mapInlineChildren($node)),
            's', 'strike', 'del' => new Strike($this->mapInlineChildren($node)),
            'sub' => new Subscript($this->mapInlineChildren($node)),
            'sup' => new Superscript($this->mapInlineChildren($node)),
            'code' => new Code($node->textContent),
            'a' => new Link(
                $node->getAttribute('href'),
                $this->mapInlineChildren($node),
                $node->hasAttribute('title') ? $node->getAttribute('title') : null,
            ),
            'br' => new LineBreak(),
            default => null,
        };
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: PASS (15 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Readers/HtmlReader.php tests/Readers/HtmlReaderTest.php
git commit -m "feat(HtmlReader): map inline formatting (strong/em/u/s/sub/sup/code/a/br)"
```

---

### Task 5: Lists

**Files:**
- Modify: `src/Readers/HtmlReader.php`
- Test: `tests/Readers/HtmlReaderTest.php`

**Interfaces:**
- Consumes: `ListNode::__construct(string $type, array $items, int $start)`, `ListItem::__construct(array $content)` (both from Node taxonomy, already `final class` — verified via `src/Nodes/Block/ListNode.php` and `ListItem.php`).

- [ ] **Step 1: Write the failing tests**

```php
    public function test_reads_an_unordered_list(): void
    {
        $document = $this->read('<ul><li>One</li><li>Two</li></ul>');

        $list = $document->content()[0];
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\ListNode::class, $list);
        self::assertSame(\Fissible\Transmark\Nodes\Block\ListNode::TYPE_UNORDERED, $list->type());
        self::assertCount(2, $list->items());
        self::assertSame('One', $list->items()[0]->content()[0]->inlines()[0]->content());
    }

    public function test_reads_an_ordered_list_with_a_start_attribute(): void
    {
        $document = $this->read('<ol start="5"><li>Five</li><li>Six</li></ol>');

        $list = $document->content()[0];
        self::assertSame(\Fissible\Transmark\Nodes\Block\ListNode::TYPE_ORDERED, $list->type());
        self::assertSame(5, $list->start());
    }

    public function test_reads_a_nested_list(): void
    {
        $document = $this->read('<ul><li>A<ul><li>Nested</li></ul></li></ul>');

        $outerItem = $document->content()[0]->items()[0];
        $content = $outerItem->content();

        self::assertSame('A', $content[0]->inlines()[0]->content());
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\ListNode::class, $content[1]);
        self::assertSame('Nested', $content[1]->items()[0]->content()[0]->inlines()[0]->content());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: FAIL — `ul`/`ol` currently map to nothing.

- [ ] **Step 3: Implement**

```php
use Fissible\Transmark\Nodes\Block\ListItem;
use Fissible\Transmark\Nodes\Block\ListNode;
```

```php
        $block = match ($tag) {
            'p' => new Paragraph($this->mapInlineChildren($node)),
            'ul' => $this->mapList($node, ListNode::TYPE_UNORDERED),
            'ol' => $this->mapList($node, ListNode::TYPE_ORDERED),
            default => null,
        };
```

```php
    private function mapList(\DOMElement $node, string $type): ListNode
    {
        $items = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->localName) === 'li') {
                $items[] = new ListItem($this->mapBlockChildren($child));
            }
        }

        $start = $node->hasAttribute('start') ? (int) $node->getAttribute('start') : 1;

        return new ListNode($type, $items, $start);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: PASS (18 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Readers/HtmlReader.php tests/Readers/HtmlReaderTest.php
git commit -m "feat(HtmlReader): map ul/ol/li to ListNode/ListItem"
```

---

### Task 6: BlockQuote, HorizontalRule, CodeBlock

**Files:**
- Modify: `src/Readers/HtmlReader.php`
- Test: `tests/Readers/HtmlReaderTest.php`

Matches the `<pre><code class="language-X">` convention #45 establishes on the `HtmlWriter` side (see Global Constraints).

- [ ] **Step 1: Write the failing tests**

```php
    public function test_reads_a_blockquote(): void
    {
        $document = $this->read('<blockquote><p>Quoted text</p></blockquote>');

        $quote = $document->content()[0];
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\BlockQuote::class, $quote);
        self::assertSame('Quoted text', $quote->content()[0]->inlines()[0]->content());
    }

    public function test_reads_a_horizontal_rule(): void
    {
        $document = $this->read('<p>Before</p><hr><p>After</p>');

        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\HorizontalRule::class, $document->content()[1]);
    }

    public function test_reads_a_code_block_with_a_language(): void
    {
        $document = $this->read('<pre><code class="language-php">echo 1;</code></pre>');

        $codeBlock = $document->content()[0];
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\CodeBlock::class, $codeBlock);
        self::assertSame('echo 1;', $codeBlock->content());
        self::assertSame('php', $codeBlock->language());
    }

    public function test_reads_a_code_block_without_a_language(): void
    {
        $document = $this->read('<pre><code>plain</code></pre>');

        $codeBlock = $document->content()[0];
        self::assertSame('plain', $codeBlock->content());
        self::assertNull($codeBlock->language());
    }

    public function test_reads_a_bare_pre_with_no_code_child(): void
    {
        $document = $this->read('<pre>raw preformatted text</pre>');

        self::assertSame('raw preformatted text', $document->content()[0]->content());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: FAIL — `blockquote`/`hr`/`pre` currently map to nothing.

- [ ] **Step 3: Implement**

```php
use Fissible\Transmark\Nodes\Block\BlockQuote;
use Fissible\Transmark\Nodes\Block\CodeBlock;
use Fissible\Transmark\Nodes\Block\HorizontalRule;
```

```php
        $block = match ($tag) {
            'p' => new Paragraph($this->mapInlineChildren($node)),
            'ul' => $this->mapList($node, ListNode::TYPE_UNORDERED),
            'ol' => $this->mapList($node, ListNode::TYPE_ORDERED),
            'blockquote' => new BlockQuote($this->mapBlockChildren($node)),
            'hr' => new HorizontalRule(),
            'pre' => $this->mapCodeBlock($node),
            default => null,
        };
```

```php
    private function mapCodeBlock(\DOMElement $node): CodeBlock
    {
        $codeElement = null;

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->localName) === 'code') {
                $codeElement = $child;
                break;
            }
        }

        $content = $codeElement !== null ? $codeElement->textContent : $node->textContent;
        $language = null;

        if ($codeElement !== null) {
            foreach (explode(' ', $codeElement->getAttribute('class')) as $class) {
                if (str_starts_with($class, 'language-')) {
                    $language = substr($class, strlen('language-'));
                    break;
                }
            }
        }

        return new CodeBlock($content, $language);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: PASS (23 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Readers/HtmlReader.php tests/Readers/HtmlReaderTest.php
git commit -m "feat(HtmlReader): map blockquote, hr, and pre/code to BlockQuote, HorizontalRule, CodeBlock"
```

---

### Task 7: Tables

**Files:**
- Modify: `src/Readers/HtmlReader.php`
- Test: `tests/Readers/HtmlReaderTest.php`

**Interfaces:**
- Consumes: `Table::__construct(array $rows, ?TableRow $header)`, `TableRow::__construct(array $cells)`, `TableCell::__construct(array $content, int $colspan, int $rowspan)` (verified signatures from `src/Nodes/Block/{Table,TableRow,TableCell}.php`).

- [ ] **Step 1: Write the failing tests**

```php
    public function test_reads_a_table_with_thead_and_tbody(): void
    {
        $html = '<table><thead><tr><th>Name</th><th>Age</th></tr></thead>'
            .'<tbody><tr><td>Alice</td><td>30</td></tr></tbody></table>';

        $table = $this->read($html)->content()[0];
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Table::class, $table);

        $header = $table->header();
        self::assertNotNull($header);
        self::assertSame('Name', $header->cells()[0]->content()[0]->inlines()[0]->content());

        $rows = $table->rows();
        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows[0]->cells()[0]->content()[0]->inlines()[0]->content());
    }

    public function test_reads_a_table_with_no_thead(): void
    {
        $table = $this->read('<table><tr><td>A</td></tr><tr><td>B</td></tr></table>')->content()[0];

        self::assertNull($table->header());
        self::assertCount(2, $table->rows());
    }

    public function test_reads_colspan_and_rowspan(): void
    {
        $table = $this->read('<table><tr><td colspan="2" rowspan="3">merged</td></tr></table>')->content()[0];
        $cell = $table->rows()[0]->cells()[0];

        self::assertSame(2, $cell->colspan());
        self::assertSame(3, $cell->rowspan());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: FAIL — `table` currently maps to nothing.

- [ ] **Step 3: Implement**

```php
use Fissible\Transmark\Nodes\Block\Table;
use Fissible\Transmark\Nodes\Block\TableCell;
use Fissible\Transmark\Nodes\Block\TableRow;
```

```php
        $block = match ($tag) {
            'p' => new Paragraph($this->mapInlineChildren($node)),
            'ul' => $this->mapList($node, ListNode::TYPE_UNORDERED),
            'ol' => $this->mapList($node, ListNode::TYPE_ORDERED),
            'blockquote' => new BlockQuote($this->mapBlockChildren($node)),
            'hr' => new HorizontalRule(),
            'pre' => $this->mapCodeBlock($node),
            'table' => $this->mapTable($node),
            default => null,
        };
```

```php
    private function mapTable(\DOMElement $node): Table
    {
        $header = null;
        $rows = [];

        foreach ($node->childNodes as $section) {
            if (!$section instanceof \DOMElement) {
                continue;
            }

            $sectionTag = strtolower($section->localName);

            if ($sectionTag === 'thead') {
                foreach ($section->childNodes as $tr) {
                    if ($tr instanceof \DOMElement && strtolower($tr->localName) === 'tr') {
                        $header ??= $this->mapTableRow($tr);
                    }
                }
            } elseif ($sectionTag === 'tbody' || $sectionTag === 'tfoot') {
                foreach ($section->childNodes as $tr) {
                    if ($tr instanceof \DOMElement && strtolower($tr->localName) === 'tr') {
                        $rows[] = $this->mapTableRow($tr);
                    }
                }
            } elseif ($sectionTag === 'tr') {
                $rows[] = $this->mapTableRow($section);
            }
        }

        return new Table($rows, $header);
    }

    private function mapTableRow(\DOMElement $node): TableRow
    {
        $cells = [];

        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->localName);
            if ($tag !== 'td' && $tag !== 'th') {
                continue;
            }

            $colspan = $child->hasAttribute('colspan') ? (int) $child->getAttribute('colspan') : 1;
            $rowspan = $child->hasAttribute('rowspan') ? (int) $child->getAttribute('rowspan') : 1;

            $cells[] = new TableCell(
                [new Paragraph($this->mapInlineChildren($child))],
                $colspan,
                $rowspan,
            );
        }

        return new TableRow($cells);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: PASS (26 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Readers/HtmlReader.php tests/Readers/HtmlReaderTest.php
git commit -m "feat(HtmlReader): map table/thead/tbody/tr/td/th to Table/TableRow/TableCell"
```

---

### Task 8: Images (block and inline)

**Files:**
- Modify: `src/Readers/HtmlReader.php`
- Test: `tests/Readers/HtmlReaderTest.php`

An `<img>` as a direct child of a block container is `Image` (block); an `<img>` inside inline content (e.g. inside a `<p>`) is `InlineImage`.

- [ ] **Step 1: Write the failing tests**

```php
    public function test_reads_a_block_level_image(): void
    {
        $document = $this->read('<body><img src="photo.jpg" alt="A photo" title="Title"></body>');

        $image = $document->content()[0];
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Image::class, $image);
        self::assertSame('photo.jpg', $image->src());
        self::assertSame('A photo', $image->alt());
        self::assertSame('Title', $image->title());
    }

    public function test_reads_an_inline_image_inside_a_paragraph(): void
    {
        $document = $this->read('<p>See <img src="icon.png" alt="icon"> here</p>');

        $inlines = $document->content()[0]->inlines();
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\InlineImage::class, $inlines[1]);
        self::assertSame('icon.png', $inlines[1]->src());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: FAIL — `img` currently maps to nothing in either context.

- [ ] **Step 3: Implement**

```php
use Fissible\Transmark\Nodes\Block\Image;
use Fissible\Transmark\Nodes\Inline\InlineImage;
```

```php
        $block = match ($tag) {
            'p' => new Paragraph($this->mapInlineChildren($node)),
            'ul' => $this->mapList($node, ListNode::TYPE_UNORDERED),
            'ol' => $this->mapList($node, ListNode::TYPE_ORDERED),
            'blockquote' => new BlockQuote($this->mapBlockChildren($node)),
            'hr' => new HorizontalRule(),
            'pre' => $this->mapCodeBlock($node),
            'table' => $this->mapTable($node),
            'img' => new Image(
                $node->getAttribute('src'),
                $node->getAttribute('alt'),
                $node->hasAttribute('title') ? $node->getAttribute('title') : null,
            ),
            default => null,
        };
```

Add `'img'` to `mapInline`'s `match ($tag)`:

```php
            'br' => new LineBreak(),
            'img' => new InlineImage(
                $node->getAttribute('src'),
                $node->getAttribute('alt'),
                $node->hasAttribute('title') ? $node->getAttribute('title') : null,
            ),
            default => null,
        };
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: PASS (28 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Readers/HtmlReader.php tests/Readers/HtmlReaderTest.php
git commit -m "feat(HtmlReader): map img to Image (block) and InlineImage (inline)"
```

---

### Task 9: Strip / unwrap / throw policy for everything else

**Files:**
- Modify: `src/Readers/HtmlReader.php`
- Test: `tests/Readers/HtmlReaderTest.php`

This is the task that makes the reader genuinely "best-effort over real-world HTML" rather than throwing on the first `<div>` it sees. See Global Constraints for the exact three-way policy.

- [ ] **Step 1: Write the failing tests**

```php
    public function test_strips_script_and_style_without_throwing(): void
    {
        $document = $this->read(
            '<html><head><style>p{color:red}</style></head>'
            .'<body><script>alert(1)</script><p>Real content</p></body></html>',
        );

        $content = $document->content();
        self::assertCount(1, $content);
        self::assertSame('Real content', $content[0]->inlines()[0]->content());
    }

    public function test_strips_html_comments_without_throwing_or_mapping_to_the_comment_node(): void
    {
        $document = $this->read('<body><!-- a comment --><p>Real content</p></body>');

        self::assertCount(1, $document->content());
    }

    public function test_unwraps_div_and_semantic_wrappers_transparently(): void
    {
        $document = $this->read(
            '<body><div><section><article><p>Deeply wrapped</p></article></section></div></body>',
        );

        $content = $document->content();
        self::assertCount(1, $content);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Paragraph::class, $content[0]);
        self::assertSame('Deeply wrapped', $content[0]->inlines()[0]->content());
    }

    public function test_unwrapping_a_container_can_contribute_multiple_sibling_blocks(): void
    {
        $document = $this->read('<div><p>One</p><p>Two</p></div>');

        self::assertCount(2, $document->content());
    }

    public function test_unknown_inline_level_tag_in_block_position_flattens_to_a_paragraph(): void
    {
        $document = $this->read('<body><mark>highlighted text</mark></body>');

        $content = $document->content();
        self::assertCount(1, $content);
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Paragraph::class, $content[0]);
        self::assertSame('highlighted text', $content[0]->inlines()[0]->content());
    }

    /**
     * @return string[]
     */
    public static function unmappableContentProvider(): array
    {
        return [
            'form' => ['<form><input type="text"></form>'],
            'canvas' => ['<canvas width="10" height="10"></canvas>'],
            'svg' => ['<svg><circle r="5"/></svg>'],
            'iframe' => ['<iframe src="https://example.com"></iframe>'],
            'video' => ['<video src="movie.mp4"></video>'],
            'custom element' => ['<my-widget>content</my-widget>'],
        ];
    }

    /**
     * @dataProvider unmappableContentProvider
     */
    public function test_throws_on_unmappable_content_elements(string $html): void
    {
        $this->expectException(HtmlParseException::class);

        $this->read($html);
    }

    public function test_exception_message_names_the_offending_tag(): void
    {
        try {
            $this->read('<form></form><p>fallback so body is not otherwise empty</p>');
            self::fail('Expected HtmlParseException was not thrown.');
        } catch (HtmlParseException $exception) {
            self::assertStringContainsString('form', $exception->getMessage());
        }
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: FAIL — `script`/`style`/comments currently fall into `mapBlockChild`'s `default => null` branch fine (already silently dropped, so those 2 tests may already pass), but `div`/`section`/`mark` are also silently dropped instead of unwrapped/flattened (content lost, count mismatches), and nothing throws yet for `form`/`canvas`/etc.

- [ ] **Step 3: Implement**

Add the three policy constants and extend `mapBlockChild`'s fallthrough:

```php
    private const STRIP_TAGS = ['script', 'style', 'head', 'meta', 'link', 'title', 'noscript'];

    private const DENY_TAGS = [
        'form', 'input', 'button', 'select', 'textarea', 'canvas', 'svg', 'iframe',
        'video', 'audio', 'object', 'embed', 'map', 'area',
    ];

    private const INLINE_TAGS = [
        'a', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'sub', 'sup', 'code',
        'span', 'br', 'img', 'small', 'mark', 'abbr', 'time', 'cite', 'q', 'kbd', 'samp',
        'var', 'dfn',
    ];
```

```php
        $block = match ($tag) {
            'p' => new Paragraph($this->mapInlineChildren($node)),
            'ul' => $this->mapList($node, ListNode::TYPE_UNORDERED),
            'ol' => $this->mapList($node, ListNode::TYPE_ORDERED),
            'blockquote' => new BlockQuote($this->mapBlockChildren($node)),
            'hr' => new HorizontalRule(),
            'pre' => $this->mapCodeBlock($node),
            'table' => $this->mapTable($node),
            'img' => new Image(
                $node->getAttribute('src'),
                $node->getAttribute('alt'),
                $node->hasAttribute('title') ? $node->getAttribute('title') : null,
            ),
            default => null,
        };

        if ($block !== null) {
            return [$block];
        }

        if (in_array($tag, self::STRIP_TAGS, true)) {
            return [];
        }

        if (in_array($tag, self::DENY_TAGS, true) || str_contains($tag, '-')) {
            throw new HtmlParseException(sprintf(
                'Cannot parse <%s>: no representable content model for this element.',
                $tag,
            ));
        }

        if (in_array($tag, self::INLINE_TAGS, true)) {
            $inlines = $this->mapInlineChildren($node);

            return $inlines === [] ? [] : [new Paragraph($inlines)];
        }

        // Unrecognized structural container (div/section/article/...): pass its
        // content through transparently instead of inventing a node for it.
        return $this->mapBlockChildren($node);
    }
```

(Note: this replaces the previous `return $block !== null ? [$block] : [];` line at the end of `mapBlockChild` — the new code above ends the method.)

HTML comments: `\DOMComment` is not a `\DOMText` or `\DOMElement`, so it already falls through both the text and element checks at the top of `mapBlockChild` and `mapInline` and returns nothing automatically — no code change needed for the comment tests to pass, but keep the assertion as regression coverage per Global Constraints (comments must never reach the `Comment` node class).

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: PASS (36 tests, including the 6-case data provider).

- [ ] **Step 5: Commit**

```bash
git add src/Readers/HtmlReader.php tests/Readers/HtmlReaderTest.php
git commit -m "feat(HtmlReader): strip scaffolding, unwrap unknown containers, throw on unmappable content"
```

---

### Task 10: Messy real-world HTML fixture corpus + integration tests

**Files:**
- Create: `tests/fixtures/html/messy-page.html`
- Create: `tests/fixtures/html/mixed-case-tags.html`
- Test: `tests/Readers/HtmlReaderTest.php`

Proves the best-effort behavior holds end-to-end on input shaped like a real page, not just isolated single-tag unit tests.

- [ ] **Step 1: Create the fixtures**

`tests/fixtures/html/messy-page.html`:

```html
<!DOCTYPE html>
<html>
<head>
  <title>Test Page</title>
  <meta charset="utf-8">
  <style>body { font-family: sans-serif; }</style>
  <script>console.log('should be stripped');</script>
</head>
<body>
  <header>
    <nav><a href="/">Home</a></nav>
  </header>
  <main>
    <article>
      <h1>Article Title</h1>
      <p>First paragraph with <strong>bold</strong> and <em>italic</em> text.</p>
      <!-- editorial note, should be stripped -->
      <ul>
        <li>Point one</li>
        <li>Point two with <a href="https://example.com">a link</a></li>
      </ul>
      <blockquote><p>A quoted remark.</p></blockquote>
      <pre><code class="language-php">echo "hi";</code></pre>
    </article>
  </main>
  <footer>
    <p>Copyright notice</p>
  </footer>
</body>
</html>
```

`tests/fixtures/html/mixed-case-tags.html`:

```html
<BODY><P>Upper-case tags <STRONG>should still parse</STRONG>.</P></BODY>
```

- [ ] **Step 2: Write the tests**

Note: `Node` (the base of every node class) is not `JsonSerializable` and its properties
are `private`, so `json_encode($block)` would serialize to `{}` regardless of actual
content — a check built on that would pass unconditionally, catching nothing. Use a real
recursive plain-text extractor instead, from the start (already reflected below).

```php
    public function test_reads_a_realistic_messy_page_end_to_end(): void
    {
        $html = file_get_contents(__DIR__.'/../fixtures/html/messy-page.html');
        $document = (new HtmlReader())->read($html);

        $content = $document->content();
        self::assertNotEmpty($content);

        // header/nav/main/article/footer are all unwrapped, contributing their
        // content directly rather than nesting or being dropped.
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Link::class, $this->firstInline($content[0]));
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Block\Heading::class, $content[1]);
        self::assertSame('Article Title', $content[1]->inlines()[0]->content());

        $rendered = array_map(static fn ($block) => $block::class, $content);
        self::assertContains(\Fissible\Transmark\Nodes\Block\ListNode::class, $rendered);
        self::assertContains(\Fissible\Transmark\Nodes\Block\BlockQuote::class, $rendered);
        self::assertContains(\Fissible\Transmark\Nodes\Block\CodeBlock::class, $rendered);

        // script/style/title/comment content must never appear anywhere in the tree.
        $text = $this->flattenText($content);
        self::assertStringNotContainsString('should be stripped', $text);
        self::assertStringNotContainsString('editorial note', $text);
        self::assertStringNotContainsString('font-family', $text);
    }

    public function test_reads_mixed_case_tags(): void
    {
        $html = file_get_contents(__DIR__.'/../fixtures/html/mixed-case-tags.html');
        $document = (new HtmlReader())->read($html);

        $inlines = $document->content()[0]->inlines();
        self::assertInstanceOf(\Fissible\Transmark\Nodes\Inline\Strong::class, $inlines[1]);
    }

    private function firstInline(\Fissible\Transmark\Contracts\BlockInterface $block): \Fissible\Transmark\Contracts\InlineInterface
    {
        return $block->inlines()[0];
    }

    /**
     * @param \Fissible\Transmark\Contracts\BlockInterface[] $blocks
     */
    private function flattenText(array $blocks): string
    {
        $text = '';

        foreach ($blocks as $block) {
            if (method_exists($block, 'inlines')) {
                foreach ($block->inlines() as $inline) {
                    $text .= method_exists($inline, 'content') ? $inline->content() : '';
                }
            }
            if (method_exists($block, 'content') && is_array($block->content())) {
                $text .= $this->flattenText($block->content());
            }
            if (method_exists($block, 'items')) {
                foreach ($block->items() as $item) {
                    $text .= $this->flattenText($item->content());
                }
            }
        }

        return $text;
    }
```

- [ ] **Step 3: Run the tests**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`

Unlike Tasks 2-9, this task adds no new production code (see Files above — fixtures and
tests only), so this is an integration checkpoint over Tasks 1-9's already-implemented
behavior, not red-green TDD: it should PASS on the first run if those tasks are correct.
Treat any failure here as a real bug surfaced by combining features together for the first
time (e.g. an unwrap-ordering issue, or the thead-row-selection logic) — fix
`src/Readers/HtmlReader.php` to address it, not the fixtures or the test's expectations,
then re-run until green.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Readers/HtmlReaderTest.php`
Expected: PASS (38 tests).

Run the full suite to confirm no regressions elsewhere:

Run: `vendor/bin/phpunit`
Expected: PASS, all tests (existing + new).

Run: `composer cs`
Expected: 0 violations.

- [ ] **Step 5: Commit**

```bash
git add tests/fixtures/html tests/Readers/HtmlReaderTest.php
git commit -m "test(HtmlReader): add messy real-world HTML fixture corpus and end-to-end tests"
```

---

## Session handoff notes

*(fill in at the end of each execution session — see repo CLAUDE.md, "Session handoff notes" convention)*
