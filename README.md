# Transmark

A pure-PHP document conversion library built around a canonical typed
document model, with faithful multi-level numbering resolution and
pluggable readers/writers for DOCX, Markdown, and HTML — no system
binaries required.

> **Status: pre-alpha.** The document model and numbering data structures
> are in place; readers, writers, and the numbering engine itself are not
> yet implemented. See [PROJECT.md](PROJECT.md) for the roadmap. Not yet
> published to Packagist.

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

## Planned packages

- `fissible/transmark` (this repo) — document model, numbering engine,
  DOCX/Markdown readers, HTML/Markdown writers.
- `fissible/transmark-blade` — Laravel Blade adapter (separate package).
- `fissible/transmark-xlsx` — XLSX reader/writer sharing this package's
  OOXML/zip layer.

## Requirements

- PHP 8.2+
- `ext-dom`, `ext-zip`

## Installation

Not yet published. Once tagged and released:

```bash
composer require fissible/transmark
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for commit conventions, branching,
and the TDD workflow, and [PROJECT.md](PROJECT.md) for the current roadmap
and open issues.

## License

[MIT](LICENSE)
