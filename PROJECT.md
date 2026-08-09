# Transmark — Project Roadmap

A pure-PHP document conversion library: a canonical typed document model with
faithful multi-level numbering resolution, and pluggable readers/writers for
DOCX, Markdown, and HTML — no system binaries required.

This file is the source of truth for sequencing. It is stateless: readable in
a fresh session with no prior context. Update it when tasks complete or scope
changes.

## Design decisions locked in (see docs/architecture notes / conversation history)

- Canonical model is Word's flat model, not HTML's: a numbered `Paragraph`
  carries a `NumberingRef{numId, ilvl}` pointer; it does not live inside a
  nested list container.
- Legal outlines (`1.`, `7.1`, `(a)`) are numbered `Paragraph` nodes, never
  `Heading` — `Heading` is reserved for true semantic H1–H6 titles.
- `NumberingDefinitions` (abstractNums + nums) lives on `Document`, mirroring
  `word/numbering.xml`. Paragraphs only ever hold the pointer.
- Rendered labels ("1.1.3", "(a)") are computed by a single shared
  `NumberingEngine`, in one pass, and are never stored on the tree — this is
  what keeps a read → write round-trip convergent.
- `ListNode`/`ListItem` exist alongside `Paragraph`+`NumberingRef` for simple
  structural lists (e.g. Markdown bullets) that don't need Word's numbering
  fidelity — the two mechanisms serve different fidelity needs, not one
  replacing the other.
- Every node carries an `Attributes` bag (id, classes, arbitrary data) as a
  lossless escape hatch for format-specific data with no first-class node.
- Byte-for-byte round-tripping is explicitly not a goal (DOCX and Markdown
  are not canonical formats). The target is semantic idempotence: AST →
  format → AST returns an equivalent tree.
- Laravel Blade adapter and an xlsx converter are planned as separate
  packages sharing this package's OOXML/zip and document-model layers —
  out of scope here.

**Added after the requirements/spike pass (2026-08-04):**

- **`DocxReader` is uniform, never classifies.** Real OOXML numbering comes
  in two structurally distinct shapes — independent `numId` per nesting
  depth with single-placeholder `lvlText` ("simple", what pandoc produces)
  vs. one `numId` spanning all depths with concatenated `lvlText` +/-
  `isLgl` ("legal") — see `tests/fixtures/numbering/README.md` for the
  validated ground truth. `DocxReader` always builds `Paragraph`+
  `NumberingRef` regardless of which shape it sees; it never tries to
  detect "this looks like a simple list" and rebuild a `ListNode` tree.
  Classification is a **writer** concern (see next point).
- **Writers classify simple-vs-legal per `numId`, not the reader.** A
  `numId` is "simple" if every level it uses has non-`isLgl`,
  single-placeholder `lvlText` (browsers/Markdown can auto-count it
  natively); otherwise it's "legal" and must be rendered as flat
  paragraphs with a literal, fully-computed label string, since neither
  HTML nor Markdown has native support for concatenated cross-level
  counters. `HtmlWriter` is split into two issues along exactly this line
  (#9 simple, #10 legal); `MarkdownWriter` (#11) reuses the same
  classification and should share the logic rather than duplicate it.
- **`league/commonmark` (^2.4) is a real runtime dependency**, adopted as
  `MarkdownReader`'s underlying parser rather than hand-rolling a
  CommonMark-subset parser. Validated via spike
  (`docs/spikes/commonmark-ast-mapping.php`): its parsed AST
  (`Document > Heading/ListBlock/Paragraph > ListItem > ... > Text/Strong/Emphasis`)
  maps cleanly onto this project's own `Block`/`Inline` taxonomy with zero
  numbering-model involvement, confirming Markdown lists are exactly the
  `ListNode`/`ListItem` case above. BSD-3-Clause, lightweight transitive
  deps (`league/config`, `psr/event-dispatcher`, symfony polyfill/
  deprecation shims), no system-binary requirement.
- **`phpoffice/phpword` is explicitly rejected** as a `DocxReader`
  foundation. Confirmed by reading its `Reader\Word2007\Numbering::readLevel()`
  source directly: it captures `start`/`numFmt`/`lvlRestart`/`suffix`/
  `lvlText`/etc. but **never reads `w:isLgl` at all** — it would silently
  drop the exact fidelity feature this project exists to preserve.
  `DocxReader` (#6, #7) is hand-rolled against `DOMDocument`, reusing only
  the zip-extraction technique (not the package) via a small
  format-agnostic `Ooxml\OoxmlPackage` (#5) shared with the future
  `transmark-xlsx` package.

**Added after the PHP-ecosystem competitive landscape review (2026-08-06):**

- **RTF, DOCX templating/mail-merge, and WMF/EMF image conversion are
  explicitly out of scope**, not tracked as backlog issues:
  - RTF has no actively-maintained pure-PHP parser to build on, and its
    real-world relevance is declining — a hand-rolled control-word
    tokenizer is real, uncertain-payoff effort. Revisit only if a
    concrete need appears.
  - DOCX templating/mail-merge is orthogonal to this project's
    "converter" positioning — PHPWord/phpdocx do this because they're
    document *authoring* tools; this project isn't one.
  - WMF/EMF vector-image conversion needs real image-processing tooling
    (`ext-gd`/`ext-imagick`), which this project has chosen not to
    depend on (see #32's non-goal). Treated as a documented,
    explicit lossy/unsupported case, same as other "expected lossy"
    conversions already in this document.
- **PDF export is a first-class API in a satellite package, not a docs
  recipe** (#39, supersedes #36): `fissible/transmark-pdf` will provide
  `PdfWriter`, composing `HtmlWriter` output with `dompdf/dompdf`
  (pure-PHP, LGPL-2.1, non-competing single-direction HTML→PDF renderer)
  rather than building a PDF renderer or shelling out to a binary. `mpdf`
  was the initial pick but was reversed after checking licenses: `mpdf`
  is GPL-2.0-only (a real concern for a dependency library meant to be
  composed into other people's — possibly proprietary — apps) and
  requires `ext-gd`, reintroducing the image-processing-extension
  dependency class already declined elsewhere (#32's WMF/EMF non-goal).
  `dompdf` (LGPL-2.1, `ext-mbstring` only) avoids both. This
  follows the same satellite-package precedent as #13/#14
  (`transmark-blade`/`transmark-xlsx`): core `transmark` stays
  dependency-free, but a consumer wanting DOCX→HTML *and* HTML→PDF gets
  one real requirement (`fissible/transmark-pdf`), with `fissible/transmark`
  resolved transitively — not two separate integrations to wire up
  themselves.
- **`ext-zip` is the one place the "no system binaries" pitch is
  narrower than it sounds** — it's an optional PHP extension, not
  universal. #35 tracks evaluating a pure-PHP alternative
  (`nelexa/zip`) as a spike/decision, not a committed migration.

## Status: DOCX, HTML, and Markdown semantic I/O core complete

The canonical model, full numbering engine, OOXML package layer,
`DocxReader`, `MarkdownReader`, `DocxWriter`, `HtmlWriter`, and
`MarkdownWriter` are implemented. Ground-truth fixtures verify simple nested
lists and genuine legal outlines end to end, while Markdown support maps
CommonMark/GFM ASTs without introducing Word numbering references.
`DocxWriter` serializes the canonical tree into a native OOXML package without
another dependency and has been independently opened in LibreOffice. The
semantic-idempotence harness covers both Markdown and DOCX reader/writer pairs,
requiring exact tree equivalence for supported semantics and explicit,
format-specific result assertions for every documented lossy conversion.

```
src/
├── Attributes.php                   # lossless escape hatch on every node
├── Document.php                     # root: content[] + NumberingDefinitions + metadata
├── Contracts/
│   ├── NodeInterface.php
│   ├── BlockInterface.php
│   ├── InlineInterface.php
│   ├── ReaderInterface.php          # read(string $content): Document
│   ├── WriterInterface.php          # write(Document $document): string
│   └── NumberingEngineInterface.php # resolve(Document $document): NumberingLabelMap
├── Nodes/
│   ├── Node.php / AbstractBlock.php / AbstractInline.php
│   ├── Block/       Paragraph, Heading, ListNode, ListItem, Table,
│   │                TableRow, TableCell, BlockQuote, CodeBlock,
│   │                HorizontalRule, Image
│   └── Inline/      Text, Emphasis, Strong, Underline, Strike,
│                     Superscript, Subscript, Link, InlineImage,
│                     LineBreak, Code, Footnote, Comment
├── Numbering/        # definitions, formats, restart rules, engine + label map
├── Ooxml/            # shared zip + DOM package layer
├── Readers/          # DocxReader, MarkdownReader
└── Writers/          # DocxWriter, HtmlWriter, MarkdownWriter

tests/fixtures/numbering/
├── README.md                  # provenance + expected labels for both fixtures
├── simple-nested-lists/       # pandoc-generated: independent numId per depth
└── legal-outline/             # hand-crafted: concatenated lvlText + isLgl

docs/spikes/
├── legal-outline-resolve.php  # validated prototype of the resolution algorithm
└── commonmark-ast-mapping.php # validated league/commonmark AST walk
```

## Dependency-ordered task list

Leaves first, most-widely-depended-on before its siblings. Each task should
get its own TDD implementation plan (`superpowers:writing-plans` +
`superpowers:subagent-driven-development` or `executing-plans`) when started
— this roadmap only sequences them. All 14 issues below were rewritten
(2026-08-04) into fully fleshed-out, pickup-ready tickets after a
requirements/spike pass — see the design decisions above and each issue's
own body for full context, acceptance criteria, and test suites.

The DOCX, HTML, and Markdown reader/writer chains and semantic-idempotence
harness are complete. The adapter-package stubs (#13/#14) can now be re-scoped
against the implemented HTML and OOXML conventions.

| # | Task | Effort | Depends on | Issue | Status |
|---|------|--------|------------|-------|--------|
| 1a | `NumberingEngine` core counter/restart resolution loop | M | Numbering data model (done) | [#1](https://github.com/fissible/transmark/issues/1) | Done |
| 1b | `lvlText` templating, `NumberFormat` rendering, `isLgl` | M | #1 | [#2](https://github.com/fissible/transmark/issues/2) | Done |
| 1c | Explicit `lvlRestart` (3-state) and per-`numId` start overrides | S | #1, #2 | [#3](https://github.com/fissible/transmark/issues/3) | Done |
| 1d | End-to-end engine test suite against committed ground-truth fixtures | S | #1–#3 | [#4](https://github.com/fissible/transmark/issues/4) | Done |
| 2a | Shared OOXML package layer (`Ooxml\OoxmlPackage`: zip + DOM, docx/xlsx-agnostic) | S | none | [#5](https://github.com/fissible/transmark/issues/5) | Done |
| 2b | `DocxReader`: `word/document.xml` → `Block`/`Inline` tree (uniform `Paragraph`+`NumberingRef`, no classification) | XL | #5 | [#6](https://github.com/fissible/transmark/issues/6) | Done |
| 2c | `DocxReader`: `word/numbering.xml` → `NumberingDefinitions` | L | #5 | [#7](https://github.com/fissible/transmark/issues/7) | Done |
| 2d | `DocxWriter`: canonical tree → native OOXML package | XL | Canonical model; #6/#7 for round-trip validation | [#27](https://github.com/fissible/transmark/issues/27) | Done |
| 3 | `MarkdownReader`: `league/commonmark` AST → tree (`ListNode`/`ListItem`, never `NumberingRef`) | L | Node taxonomy (done); `league/commonmark` dependency already added | [#8](https://github.com/fissible/transmark/issues/8) | Done |
| 4a | `HtmlWriter`: semantic `<ol>`/`<ul>` for `ListNode` trees + "simple" `numId` runs | M | none for `ListNode`; #6/#7 for the numbered-paragraph case | [#9](https://github.com/fissible/transmark/issues/9) | Done |
| 4b | `HtmlWriter`: flat + literal-label strategy for "legal" `numId` runs | M | #1–#4, #9 (classification logic) | [#10](https://github.com/fissible/transmark/issues/10) | Done |
| 5 | `MarkdownWriter`: tree → Markdown, reusing #9/#10's simple-vs-legal classification | M | #8 (node coverage); #1–#4 and #9/#10 (classification) for numbered-paragraph fallback | [#11](https://github.com/fissible/transmark/issues/11) | Done |
| 6 | Semantic-idempotence test harness: hand-write ASTs, round-trip through each reader/writer pair, assert tree equality (with explicit "expected lossy" markers, e.g. legal outlines through Markdown) | M | #8, #11 (minimum, Markdown ⇄ Markdown); #27 for DOCX ⇄ DOCX | [#12](https://github.com/fissible/transmark/issues/12) | Done |
| 7 | `fissible/transmark-blade` (separate package, stub only — re-scope once #9/#10's `HtmlWriter` output conventions settle) | M (re-scope pending) | #9, #10 | [#13](https://github.com/fissible/transmark/issues/13) | Stub — needs re-scoping |
| 8 | `fissible/transmark-xlsx` (separate package, stub only — re-scope once `OoxmlPackage` is validated by real usage) | XL (re-scope pending) | #5 (and validation from #6/#7) | [#14](https://github.com/fissible/transmark/issues/14) | Stub — needs re-scoping |
| 9 | `DocxReader`: table support (`w:tbl` → `Table`/`TableRow`/`TableCell`) | M | #6, #7 | [#31](https://github.com/fissible/transmark/issues/31) | Not started |
| 10 | `DocxReader`: image pass-through (`w:drawing` media extraction, no image-processing deps; WMF/EMF explicitly out of scope) | M | #5, #6 | [#32](https://github.com/fissible/transmark/issues/32) | Not started |
| 11 | `HtmlWriter`: table support | S | `Table` node taxonomy (done); not blocked by #31 | [#33](https://github.com/fissible/transmark/issues/33) | Not started |
| 12 | `HtmlWriter`: image embedding (base64 data-URI, no image-processing deps) | S | `Image` node taxonomy (done); not blocked by #32 | [#34](https://github.com/fissible/transmark/issues/34) | Not started |
| 13 | `Ooxml` zip-backend evaluation: `ext-zip` vs pure-PHP `nelexa/zip` (spike/decision, not a migration) | S | none | [#35](https://github.com/fissible/transmark/issues/35) | Not started |
| 14 | `fissible/transmark-pdf` (separate package): `PdfWriter` composing `HtmlWriter` output with `dompdf/dompdf`, mirroring #13/#14's satellite-package pattern | L | `HtmlWriter` (done) | [#39](https://github.com/fissible/transmark/issues/39) | Done — released [v0.1.0](https://github.com/fissible/transmark-pdf/releases/tag/v0.1.0) |
| 15 | CLI wrapper (`bin/transmark convert`) for reader/writer conversions | S | at least one reader/writer pair (done) | [#37](https://github.com/fissible/transmark/issues/37) | Not started |
| 16 | Content-based format detection (DOCX zip+part signature) + extension as a non-authoritative secondary signal; typed mismatch exception when content and extension disagree (spoofing/rename detector) | S | none | [#42](https://github.com/fissible/transmark/issues/42) | Done |
| 17 | `HtmlReader`: best-effort arbitrary-HTML → canonical tree, throws a dedicated parsing exception on unmappable/ambiguous markup rather than guessing | L | Node taxonomy (done) | [#43](https://github.com/fissible/transmark/issues/43) | Done — implemented via subagent-driven-development (10 tasks + final whole-branch review + 1 consolidated fix wave for 2 Critical/4 Important findings), all clean |
| 18 | `fissible/transmark-pdf` (separate package): `PdfReader` — best-effort layout-heuristic PDF → canonical tree (font-size/whitespace heuristics), throws on ambiguous extraction; library decision: `smalot/pdfparser` (LGPLv3) | L-XL | `PdfWriter` package scaffold (done) | [transmark-pdf#4](https://github.com/fissible/transmark-pdf/issues/4) | In progress (separate agent) |
| 19 | `HtmlWriter`: render `BlockQuote`/`CodeBlock`/`HorizontalRule` (currently silently dropped as `''`); establishes the `<pre><code class="language-X">` convention #43's `HtmlReader` parses back | S | none | [#45](https://github.com/fissible/transmark/issues/45) | Done |
| 20 | `HtmlWriter`: `renderInline()` also silently drops unsupported inline types (`InlineImage`/`Footnote`/`Comment`); same fix pattern as #45, one level down | XS-S | #45 (done) | [#47](https://github.com/fissible/transmark/issues/47) | Not started |
| 21 | `HtmlReader`: block-position inline-run coalescing limited to the static `INLINE_TAGS` list (`<div>a <ins>b</ins> c</div>` fragments into 3 paragraphs instead of 1; no content lost) | S | #43 (done) | [#49](https://github.com/fissible/transmark/issues/49) | Not started |
| 22 | `HtmlReader`: `<p>`/heading content not edge-trimmed, leaks literal newlines from pretty-printed HTML (`<p>\n  Hello\n</p>`) into downstream writer output | XS-S | #43 (done) | [#50](https://github.com/fissible/transmark/issues/50) | Not started |

## Session handoff notes

**Completed (2026-08-04):** Package skeleton — `composer.json` (PSR-4
`Fissible\Transmark\` → `src/`, PHP ^8.2), full directory layout, and every
node/numbering data class listed above.

**Completed (2026-08-05):** Repo created at
[github.com/fissible/transmark](https://github.com/fissible/transmark)
(initially **private**), PHPUnit + php-cs-fixer wired up with real passing
smoke tests, full public-release scaffolding (README, LICENSE, CONTRIBUTING,
CODE_OF_CONDUCT, PR/issue templates, PHP CI workflow), and the fissible
org-standard release pipeline (`release.sh`, `.cliff.toml`, `VERSION`,
reusable release workflow). Tagged and released **v0.1.0** — CI and the
release workflow both ran green. All 14 roadmap tasks below filed as GitHub
issues (#1–#14).

**Completed (2026-08-04, requirements/spike pass):** Ran real spike scripts
(not just discussion) to close every open design gap before finalizing the
roadmap:
- Hand-crafted a genuine legal-outline OOXML fixture (concatenated
  `lvlText` + `isLgl`) since pandoc can't produce this pattern from
  Markdown, and validated the existing `Level`/`AbstractNum` model
  resolves it correctly end-to-end via a working prototype engine
  (`docs/spikes/legal-outline-resolve.php`).
- Confirmed via spec research (ECMA-376 §17.9.4, §17.9.11) the precise
  semantics of `w:isLgl` and the 3-state `w:lvlRestart` (absent = restart
  on immediate parent; `val="N"` = restart on a specific ancestor;
  `val="0"` = never restart) — the latter is a real gap in the current
  `Level` model, flagged explicitly in issue #3.
- Ran a build-vs-buy spike for `MarkdownReader`: adopted
  `league/commonmark` (already added to `composer.json`) after confirming
  its AST maps cleanly onto this project's own node taxonomy
  (`docs/spikes/commonmark-ast-mapping.php`).
- Ran a build-vs-buy spike for `DocxReader`: rejected `phpoffice/phpword`
  after reading its numbering reader source directly and confirming it
  silently drops `w:isLgl` — the exact fidelity feature this project
  exists for.
- Committed both fixture sets + provenance docs at
  `tests/fixtures/numbering/` so every relevant issue can reference real
  ground truth instead of asking each contributor to regenerate it.
- Rewrote all 14 GitHub issues into fully fleshed-out, pickup-ready
  tickets with concrete acceptance criteria, test suites, and explicit
  cross-issue dependency notes (including corrections to the original
  dependency graph — e.g. #6 and #7 turned out to be independent of each
  other and of #1–#4, and #9's `ListNode` half needs no numbering
  dependency at all). #13 and #14 were deliberately left as re-scope-later
  stubs since their target API surface depends on decisions #9/#10 and
  #5–#7 haven't made yet.

**Completed (2026-08-05, via PR #15, merged to `main`):** #1 (`NumberingEngine`
core counter/restart resolution loop — all 6 acceptance criteria verified
by `tests/Numbering/NumberingEngineTest.php`, naive decimal-join label
rendering, #2's scope) and #5 (`Ooxml\OoxmlPackage` — `open()`/`part()`/
`rawPart()`/`close()`, built via subagent-driven development with a final
whole-branch review fix wave, then manually verified against a real
Word-generated `.docx` in addition to the fixture-based test suite).
Full suite: 24/24 passing on `main`.

**Completed (2026-08-05, issues #2–#7, #9–#10):** Implemented and fixture-
validated the full numbering semantics, DOCX package/reader pipeline, and
HTML writer's native-simple-list and literal-legal-outline paths. The core
DOCX-to-HTML value proposition is ready for the v0.2.0 developer preview.

**Completed (2026-08-07, issue #27, via PR #28):** Implemented native
`DocxWriter` output without another dependency. PHP 8.2–8.4 CI passed, and the
acceptance package opened without warning or repair in LibreOffice 26.2.5.2
(AARCH64) on macOS Sonoma 14.5.

**Completed (2026-08-07, issue #12):** Recovered the Markdown round-trip
harness from its incorrectly stacked PR and extended it across the DOCX pair.
The shared helper compares the full canonical tree, and every expected-loss
case must assert its precise resulting shape. Focused coverage documents DOCX
list flattening, link/code presentation loss, styled code-block conversion,
the current table-reader gap, and omitted metadata/attributes. Full suite:
236 tests / 869 assertions on PHP 8.3 and 8.4.

**Completed (2026-08-06/07):** Ran a PHP-ecosystem competitive landscape
review against `phpoffice/phpword`, `phpdocx`, and `pandoc` (validated via
direct source/composer.json reads and live GitHub issue searches, not
assumption — see conversation history). Confirmed `transmark`'s one clear
differentiator (`w:isLgl` legal-outline numbering fidelity: PHPWord's
reader never touches it, pandoc has open restart/continuation bugs on it)
and identified genuine gaps against every incumbent: tables, images,
PDF export, and a CLI. Filed #31–#37 for the achievable subset (table and
image support for `DocxReader`/`HtmlWriter`, a zip-backend evaluation
spike, a PDF composition-recipe doc, a CLI wrapper) and explicitly
declined RTF/templating/WMF-EMF (see design decisions above).

**Completed (2026-08-07):** Re-scoped PDF export from a docs-only recipe
(#36, closed as superseded) to a first-class `PdfWriter` API in a new
satellite package, `fissible/transmark-pdf` (#39), mirroring #13/#14's
pattern — driven by a real consumer need (a legal-acknowledgement
DOCX→HTML→PDF workflow) wanting one Composer requirement, not two, even
though `fissible/transmark` is transitively implied either way. Initially
recommended `mpdf` for its stronger CSS/pagination/header-footer support,
but reversed after checking licenses: `mpdf` is GPL-2.0-only and requires
`ext-gd`; `dompdf` (LGPL-2.1, `ext-mbstring` only) is the safer default
for a dependency library meant to be composed into other apps.

**Next task:** #31 and #32 (`DocxReader` table + image support) close the
gap the semantic-idempotence harness already documents as lossy; #33/#34
are their `HtmlWriter`-side counterparts and can proceed in parallel.
#35 (zip-backend evaluation) and #39 (`transmark-pdf` scaffold) are
independent, pick-up-anytime tasks. #37 (CLI) benefits from at least
tables/images landing first but isn't blocked on them.

**Release decision:** v0.3.0 shipped (native DOCX output + cross-format
semantic-idempotence contract). No release currently pending.

**Blockers:** none.
