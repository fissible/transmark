# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/).
## [0.5.0] - 2026-08-27

### Added
- Populate and emit id/class attributes in the HTML leg
- Pass raw HTML embedded in Markdown through as a RawHtml node

### Fixed
- Render missing numbering counters as their start values
- Preserve table cell alignment through the HTML leg
- Keep edge-whitespace inline formatting parseable in MarkdownWriter
- Escape leading list markers in MarkdownWriter paragraph text
- Map w:tab, w:cr, and w:noBreakHyphen run elements in DocxReader
- Read w:sdt content controls and field-code hyperlinks in DocxReader
- Correct audit defects in field hyperlinks and markdown block-start guards
- Gate raw HTML passthrough behind an opt-in in MarkdownReader
- Process field events and run content in document order
- Read w:sdt content controls wrapping table rows and cells
- Read w:fldSimple HYPERLINK fields as links
- Read a monospaced run back as inline code in DocxReader
## [0.4.2] - 2026-08-26

### Changed
- Dedupe relationships with an O(n) foreach; correct rPr order comment

### Fixed
- Emit rPr children in CT_RPr schema order and dedupe relationships
- UriScheme strips control chars before truncation + XSS hardening (DocxWriter)
- Strike before u in rPr order; add tests for strike/u order and relationship dedupe
- Continue simple-list counters across non-list interruptions
- UriScheme strips control chars before truncation + XSS hardening (MarkdownWriter)
- Map markdown soft line breaks to a space instead of a hard break
- Emit legal outlines flat in Markdown to avoid indented-code-block parsing
- Scheme-allowlist link hrefs and image sources in HtmlWriter
- UriScheme strips control chars before truncation (MarkdownWriter)
- Preserve hyperlinks when reading DOCX
- Thread hyperlinks through table parsing; fix anchor fallback, TargetMode, table-cell hyperlinks; add tests
- Default an omitted w:ilvl in numPr to level 0
- NumId=0 cancels numbering (ECMA-376 §17.9.18) + test
- Degrade unsupported w:numFmt values to decimal instead of aborting the read
## [0.4.1] - 2026-08-10

### Fixed
- MarkdownWriter mis-indents a simple list starting below ilvl 0
## [0.4.0] - 2026-08-09

### Added
- Add content-based format detection with extension mismatch check
- Add HtmlParseException
- Add HtmlReader skeleton (paragraphs, text, UTF-8, empty-input guard)
- Map h1-h6 to Heading
- Map inline formatting (strong/em/u/s/sub/sup/code/a/br)
- Map ul/ol/li to ListNode/ListItem
- Map blockquote, hr, and pre/code to BlockQuote, HorizontalRule, CodeBlock
- Map table/thead/tbody/tr/td/th to Table/TableRow/TableCell
- Map img to Image (block) and InlineImage (inline)
- Strip scaffolding, unwrap unknown containers, throw on unmappable content
- Add bin/transmark convert CLI wrapper
- Extend Image/InlineImage with embedded-data fields
- HtmlWriter table and image rendering
- DocxReader table support
- DocxReader image pass-through

### Fixed
- Render BlockQuote/CodeBlock/HorizontalRule in HtmlWriter
- Restore libxml global error state in HtmlReader::read()
- Clamp colspan/rowspan to minimum 1 to handle malformed attributes
- Preserve span content at inline level by unwrapping transparently
- Preserve content for all transparent inline wrappers (mark, abbr, small, etc.)
- Migrate @dataProvider to PHPUnit 11 attribute syntax
- Apply the strip/unwrap/throw policy in inline position and coalesce inline runs
- HtmlWriter/HtmlReader follow-up fixes from #43/#45 fix waves
- NumberingShapeClassifier ignores paragraphs nested in containers
- TreeEquivalence ignores Image/InlineImage's embedded-data fields
## [0.3.0] - 2026-08-07

### Added
- Add Markdown reader and writer
- Add native DOCX writer
## [0.2.0] - 2026-08-06

### Added
- Adopt league/commonmark, add ground-truth numbering fixtures
- Resolve numbering counter state
- Add OoxmlPackage::open() with zip/file validation
- Add OoxmlPackage::part()/rawPart() zip-entry access
- Add OoxmlPackage::close(), complete issue #5's API surface
- Render numbering level formats
- Honor numbering restart rules
- Read docx document content
- Parse docx numbering definitions
- Render simple lists as html
- Render legal outlines as html

### Fixed
- Address final review findings — exception contract, error diagnostics, binary coverage
## [0.1.0] - 2026-08-05

### Added
- Add canonical document model and numbering data model

