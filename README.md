# Transmark

A pure-PHP document conversion library built around a canonical typed
document model, with faithful multi-level numbering resolution and
pluggable readers/writers for DOCX, Markdown, and HTML — no system
binaries required.

> **Status: pre-alpha.** The document model, numbering engine, DOCX,
> Markdown, and HTML readers, and DOCX, HTML, and Markdown writers are
> implemented.
> Semantic round-trip tests cover the supported Markdown and DOCX semantics;
> broader node coverage remains on the roadmap.

## Why

Every existing open-source PHP option for DOCX → HTML conversion fails at
faithfully rendering multi-level numbering (`1.`, `7.1`, `(a)`, `(i)`) —
including legal-outline numbering, where the numbering scheme is itself
the content. [PHPWord](https://github.com/PHPOffice/PHPWord)'s HTML writer
flattens `word/numbering.xml`. The tools that render numbering correctly
(LibreOffice headless, Pandoc) are system binaries, which complicates
Forge/Vapor/serverless deploys. Even [mammoth.js](https://github.com/mwilliamson/python-mammoth),
the best open-source converter available in any language, has long-standing
nested-numbering bugs and deliberately drops complex formatting by design.

Transmark's bet: model the document the way Word actually does — a flat
paragraph carrying a pointer into a numbering definitions table, with
labels computed by a single shared engine — rather than forcing Word's
numbering model into HTML's `<ol>`/`<li>` nesting. See
[PROJECT.md](PROJECT.md) for the full design rationale.

## Design at a glance

- **Canonical model, not a serialization.** Every reader parses into a
  typed tree (`Document` → `Block`/`Inline` nodes); every writer
  serializes that same tree. Adding a format means writing one reader or
  writer, not an N×N converter.
- **Numbering is data, not markup.** A numbered `Paragraph` holds only
  `NumberingRef{numId, ilvl}`. The rendered label ("1.1.3") is computed
  by `NumberingEngine` in a single pass and is never stored on the tree —
  this is what keeps a read → write round-trip convergent.
- **Legal outlines are paragraphs, not headings.** `Heading` is reserved
  for true semantic section titles.
- **Semantic idempotence, not byte-for-byte.** `AST → format → AST`
  should return an equivalent tree. Byte-for-byte round-tripping through
  DOCX or Markdown is not a goal — neither format is canonical.

The test harness enforces exact tree equivalence where a format pair can
represent the canonical semantics. Known format or reader limitations must be
declared as expected losses with both a written reason and an assertion for the
specific resulting tree shape; simply observing that two trees differ does not
count as a passing lossy conversion.

## HTML output conventions

Link hrefs and image sources are scheme-allowlisted so untrusted documents
cannot smuggle script URIs into generated HTML: only `http`, `https`, and
`mailto` links render as anchors (anything else renders its link text
without the `<a>` wrapper), and images accept `http`, `https`, relative
paths, and embedded `data:image/` URIs. Scheme checks ignore control
characters and whitespace the way browsers do when resolving a URI, so
obfuscations like `java\tscript:` cannot slip through.

Simple lists render as native nested `<ol>`/`<ul>` elements. Legal-outline
numbering renders as flat paragraphs because HTML cannot express labels that
concatenate counters across levels. Those paragraphs use
`class="numbered-paragraph legal-level-N"`, where `N` is the zero-based OOXML
numbering level, so consumers can style each indentation depth. A numbered
paragraph nested inside a table cell resolves its label exactly as it would
outside one — the same shared counter, the same simple-vs-legal rendering
choice.

`Table` renders as semantic `<table>`/`<thead>`/`<tbody>`/`<tr>`/`<th>`/`<td>`
— a `header()` row becomes `<thead>` with `<th>` cells (omitted entirely when
there is no header), `rows()` become `<tbody>` with `<td>` cells, and cell
content renders through the same block/inline pipeline as everything else
(including numbered paragraphs and nested lists).

`Image`/`InlineImage` render as `<img>`. An embedded image (one carrying raw
bytes — e.g. from a future DOCX image reader) is base64-encoded into a
self-contained `data:{mime};base64,...` URI; an image with only a `src`
reference (e.g. from HTML input) renders that reference directly. Declared
`width`/`height` become attributes when present.

## Reading HTML

`HtmlReader` accepts arbitrary, real-world HTML — not only HTML this library
wrote:

```php
use Fissible\Transmark\Readers\HtmlReader;

$document = (new HtmlReader())->read(file_get_contents('/path/to/page.html'));
```

It is best-effort with a hard failure mode rather than a silently lossy one.
Scaffolding (`script`, `style`, `head`, `meta`, `title`, `noscript`, HTML
comments) is stripped silently; unrecognized containers and wrappers (`div`,
`section`, `ins`, `font`, ...) are unwrapped transparently so their content
still lands in the tree; and genuinely unmappable content — forms, embeds and
media (`form`, `button`, `iframe`, `svg`, `video`, ...) plus any custom element
— throws `HtmlParseException` naming the offending tag, so you can find and
replace it instead of losing it.

## Satellite packages

All released and available on Packagist:

- [`fissible/transmark-pdf`](https://github.com/fissible/transmark-pdf) —
  PDF export/import (`PdfWriter`, `PdfReader`), composing this package's
  `HtmlWriter` output with `dompdf/dompdf`.
- [`fissible/transmark-blade`](https://github.com/fissible/transmark-blade) —
  Laravel Blade adapter: a `@transmark` directive and `<x-transmark>`
  component that render `Document` objects (or DOCX/Markdown file paths)
  as HTML via `HtmlWriter`.
- [`fissible/transmark-xlsx`](https://github.com/fissible/transmark-xlsx) —
  XLSX import/export (`XlsxReader`, `XlsxWriter`) reading and writing a
  `Workbook`/`Sheet`/`Cell` model, sharing this package's OOXML/zip layer.

## Requirements

- PHP 8.2+
- `ext-dom`, `ext-zip`

## Installation

```bash
composer require fissible/transmark
```

## DOCX to HTML

Readers accept document bytes and return the canonical tree; writers serialize
that tree into the target format:

```php
use Fissible\Transmark\Readers\DocxReader;
use Fissible\Transmark\Writers\HtmlWriter;

$docx = file_get_contents('/path/to/document.docx');
$document = (new DocxReader())->read($docx);
$html = (new HtmlWriter())->write($document);
```

`DocxReader` currently covers paragraphs, headings, core inline formatting,
hyperlinks (external relationship targets and internal bookmark anchors),
and Word numbering definitions. Numbering formats outside the supported set
(`decimal`, `lowerLetter`/`upperLetter`, `lowerRoman`/`upperRoman`, `bullet`,
`none`) — e.g. `ordinal` or `chicago` — degrade to decimal rendering of the
level's literal text rather than aborting the read. See the pre-alpha status
above and roadmap for unsupported document features.

## Writing DOCX

`DocxWriter` creates a complete native OOXML package using only the existing
`ext-dom` and `ext-zip` requirements:

```php
use Fissible\Transmark\Writers\DocxWriter;

$bytes = (new DocxWriter())->write($document);
file_put_contents('/path/to/document.docx', $bytes);
```

Paragraphs, headings, quotes, rules, tables, core inline formatting, links,
code, and numbering definitions are supported. Structural `ListNode` trees
are deliberately converted into Word's flat numbered-paragraph model; reading
the result back therefore preserves list numbering and visible content, not
the original tree nesting. Code blocks preserve their visual style and line
breaks, but the current `DocxReader` reads them back as styled paragraphs.

Images, inline images, footnotes, and comments require an asset/part API and
currently throw an unsupported-node exception instead of being silently
dropped. DOCX template editing, arbitrary layout fidelity, and media embedding
are outside the first writer's scope.

## Markdown

Markdown uses `league/commonmark` for standards-compliant parsing, including
GFM strikethrough and tables:

```php
use Fissible\Transmark\Readers\MarkdownReader;
use Fissible\Transmark\Writers\MarkdownWriter;

$document = (new MarkdownReader())->read($markdown);
$markdown = (new MarkdownWriter())->write($document);
```

Structural lists serialize as native Markdown lists. Word legal outlines have
no native Markdown equivalent, so `MarkdownWriter` emits their computed labels
as literal text. Underline, superscript, and subscript use raw inline HTML.
Those conversions are deliberately lossy: reading the Markdown back preserves
the visible text, but cannot reconstruct the original OOXML numbering or
formatting metadata.

## Command-line usage

`bin/transmark convert` picks a reader/writer pair by file extension and runs
the same `$writer->write($reader->read($contents))` pipeline shown above:

```bash
vendor/bin/transmark convert agreement.docx agreement.html
vendor/bin/transmark convert notes.md notes.html
```

Supported formats: `docx`, `html`/`htm`, `md`/`markdown`. When a path's
extension doesn't match its real format (or has none), override detection
explicitly:

```bash
vendor/bin/transmark convert --from=md --to=html notes.txt notes.out
```

An unsupported format, a missing input file, or a reader/writer failure all
print a clear one-line error to stderr and exit non-zero — never a raw stack
trace.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for commit conventions, branching,
and the TDD workflow, and [PROJECT.md](PROJECT.md) for the current roadmap
and open issues.

Security vulnerabilities should be reported privately according to
[SECURITY.md](SECURITY.md), not opened as public issues.

## License

[MIT](LICENSE)
