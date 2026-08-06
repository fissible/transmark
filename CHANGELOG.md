# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/).
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

