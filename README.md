# CSV Export for Craft CMS

**A simple translation workflow for Craft CMS.** Export your content to a clean, Excel-friendly file, send it to your translator or edit it yourself, and safely import the completed translations back into Craft. On the side, it also gives you a flat, one-column-per-field CSV export of any entries, handy for pulling form submissions into Excel.

The plugin is built for **translating existing content**. It only exports what can actually be translated and leaves your site's structure, relations, assets and other non-translatable data untouched.

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later
- For the translation workflow: a multi-site installation (one site per language)

## Installation

From the Plugin Store, search for **CSV Export** and click *Install*. Or with Composer:

```bash
composer require manonkindt/craft-csv-export
php craft plugin/install csv-export
```

## The translation workflow

### 1. Export

Go to **Entries**, filter the entries you want translated and click **Export…** at the bottom of the list. Choose the export type **Translations (Excel, one sheet per language)**.

You get an `.xlsx` workbook with:

- a **READ ME** sheet with instructions for the translator, in the language of your control panel and in English;
- **one sheet per site**, named after the site handle (`nl`, `fr`, `en`, …). The first sheet is the source language. Every sheet has the same rows (one per entry) and the same columns.

Only translatable content is included:

| Content | Columns |
| --- | --- |
| Entry title (when the entry type's title is translatable) | `title` |
| Plain Text, CKEditor, Redactor, Vizy fields set to a translatable translation method | `fieldHandle` |
| Matrix, Neo and Content Block blocks | `blocks[1].id`, `blocks[1].title`, `blocks[1].text`, `blocks[2].id`, … |
| SEO Fields, SEOmatic, Ether SEO | `seo.metaTitle`, `seo.metaDescription`, `seo.socialTitle`, `seo.socialDescription` (SEOmatic and Ether SEO also `seo.twitterTitle`, `seo.twitterDescription`) |

Relations, assets, dates, numbers, options, lightswitches, images and non-translatable fields are left out on purpose. The `id` columns identify the entries and blocks and must stay untouched.

### 2. Translate

The translator opens the sheet of the target language and replaces the texts with the translation. No Craft account, no field-setup knowledge, no control panel. HTML tags in rich text stay in place; only the text between them is translated. Empty cells are fine, they are ignored on import.

### 3. Import

Go to **Utilities → CSV Import**, or click **Import translations…** next to **Export…** on the entries index. Upload the workbook, choose the source language, check the preview and confirm.

The preview lists, per language, every text that will be written with its current and new value, plus what is skipped and why. Nothing is saved until you confirm.

A single-language `.csv` (for example from the flat export below) can be imported too; pick its language in the form.

### Safe by default

- The **source language is never modified**.
- Only **existing entries and blocks** in the **corresponding site** are written. The plugin never creates or deletes entries or blocks.
- Only translatable, textual fields and titles are written. Non-translatable and unsupported fields are skipped and reported.
- **Empty cells are ignored**, so nothing is ever cleared.
- **Existing translations are preserved**: a cell is only written when the target field is still empty or still identical to the source text. Enable **Overwrite existing translations** to write every non-empty cell.
- Every element is saved through Craft's normal validation. Elements that fail validation remain unchanged and their errors are shown.
- Every successful save creates a Craft revision, so changes can be reverted through the entry's revision history.

Users need the **Utilities → CSV Import** permission to import.

## Flat CSV export

Besides the translation workbook, the plugin exports any entries as a flat CSV: one row per entry, one column per field, nested content flattened into columns. Useful for form submissions, reports or a quick look at your content in Excel.

- **Entries index → Export… → Flat (one column per field)**: respects the filters, search and selection of the index. Craft writes the file (CSV, JSON or XML).
- **Utilities → CSV Export**: pick a section, site, status and fields, and download a CSV that honours the delimiter and UTF‑8 BOM settings so Excel opens it correctly.

How values are flattened:

| Field type | Output |
| --- | --- |
| Plain text, number, email, URL, dropdown, radio, lightswitch | The value (`1`/`0` for lightswitches) |
| Rich text (CKEditor, Redactor, Vizy) | The HTML, or plain text when *Strip HTML* is on |
| Date/Time | Formatted with the configured date format |
| Checkboxes, multi-select | Option values joined with the multi-value separator |
| Entries, categories, tags, users | Titles (users: e‑mail) joined with the separator |
| Assets | URLs joined with the separator |
| Link, color, money | URL, hex code, decimal amount |
| Table, JSON | JSON |
| Matrix, Neo, Content Block | One column per nested field (`data[1].type`, `data[1].answer`, …), readable text or JSON, depending on the *Matrix fields* setting |
| SEO Fields, SEOmatic, Ether SEO | Split into `seo.metaTitle`, `seo.metaDescription`, `seo.socialTitle`, `seo.socialDescription`, `seo.socialImage` (and Twitter/meta image columns where the plugin has them) |

## Command line

```bash
# Flat CSV
php craft csv-export/export --section=requests --site=default --status=all --output=requests.csv

# Translation workbook
php craft csv-export/export --section=news --translations --output=news-translations.xlsx

# Import: preview, then save
php craft csv-export/import --file=news-translations.xlsx --source=nl
php craft csv-export/import --file=news-translations.xlsx --source=nl --apply
```

Export options: `--section` (required), `--site`, `--status` (`live`, `pending`, `expired`, `disabled`, `all`), `--fields=firstName,lastName,email`, `--limit`, `--output`, `--translations`.
Import options: `--file` (required), `--source` (source site handle), `--site` (language for `.csv` files), `--overwrite`, `--apply`.

## Settings

Under **Settings → Plugins → CSV Export**:

- **Delimiter**: semicolon (default, opens correctly in Excel in most European locales), comma or tab. Applies to the utility download and the console command; the entries index export is written by Craft itself (comma).
- **Include UTF‑8 BOM**: recommended for Excel.
- **Column labels**: field handle (stable, best for re‑importing) or field name.
- **Entry attributes**: which element attributes (id, title, slug, status, dates, …) come first in the flat export.
- **Matrix fields**: how nested content is flattened in the flat export.
- **Date format**, **multi-value separator**, **strip HTML**.

The translation workbook always uses field handles, ids and one column per nested field, so it can be imported again.

## Languages

The control panel texts are available in English, Dutch, French and German. Add another language by copying `src/translations/nl/csv-export.php`.

## License

This plugin is licensed for sale in the Craft Plugin Store under the [Craft License](https://craftcms.github.io/license/). Copyright © 2026 Manon Kindt.
