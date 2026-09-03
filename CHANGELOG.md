# Release Notes for Translation CSV Export

## 1.0.0 - 2026-09-03

### Added
- Initial release.
- **Flat (one column per field)** export type in the entries index **Export…** dialog.
- **Translations (Excel, one sheet per language)** export type: an .xlsx workbook for translation agencies.
- **CSV Export** utility to download a CSV for a section, site and status, with field selection.
- **CSV Import** utility (and an **Import translations…** button on the entries index) that imports translated workbooks or CSVs with a preview. Only translatable text fields are written, the source language is never touched, empty cells are ignored and existing translations are kept unless overwriting is enabled.
- `php craft csv-export/export` and `php craft csv-export/import` console commands.
- Matrix, Neo and Content Block fields flattened to columns; SEO Fields, SEOmatic and Ether SEO split into title/description/social columns.
- Settings for delimiter, UTF‑8 BOM, date format, multi-value separator, Matrix flattening mode and column labels.
- Control panel translations in English, Dutch, French and German.
