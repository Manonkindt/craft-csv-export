# CSV Export for Craft CMS

Export Craft CMS entries to a **flat, Excel‑friendly CSV file**: one row per entry, one column per field, Matrix blocks flattened into columns.

Handy for pulling form submissions into Excel, or for sending content to a translation agency.

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later

## Installation

From the Plugin Store, search for **CSV Export** and click *Install*. Or with Composer:

```bash
composer require manonkindt/craft-csv-export
php craft plugin/install csv-export
```

## Usage

### 1. From the entries index

Open **Entries**, filter and search like you normally would, then choose **Export…** at the bottom of the list. Pick the export type **Flat (one column per field)** and a format (CSV, JSON or XML).

The selection, section, site and status filters of the element index are respected, so you export exactly what you see.

### 2. From the utility

Go to **Utilities → CSV Export**. Choose a section, site and status, tick the fields you need (or none for all fields) and click **Download CSV**. This download honours the delimiter and UTF‑8 BOM settings, so the file opens correctly in Excel.

Users need the *Utilities → CSV Export* permission.

### 3. From the command line

```bash
php craft csv-export/export --section=requests --site=default --status=all --output=requests.csv
```

Options: `--section` (required), `--site`, `--status` (`live`, `pending`, `expired`, `disabled`, `all`), `--fields=firstName,lastName,email`, `--limit`, `--output` (defaults to stdout).

## How values are flattened

| Field type | Output |
| --- | --- |
| Plain text, number, email, URL, dropdown, radio, lightswitch | The value (`1`/`0` for lightswitches) |
| Rich text (CKEditor, Redactor) | The HTML, or plain text when *Strip HTML* is on |
| Date/Time | Formatted with the configured date format |
| Checkboxes, multi-select | Option values joined with the multi-value separator |
| Entries, categories, tags, users | Titles (users: e‑mail) joined with the separator |
| Assets | URLs joined with the separator |
| Link, color, money | URL, hex code, decimal amount |
| Table, JSON | JSON |
| Matrix / Content Block | Depends on the *Matrix fields* setting, see below |

### Matrix fields

- **One column per nested field** (default): `data[1].type`, `data[1].title`, `data[1].answer`, `data[2].type`, … Columns are added for as many blocks as the longest entry has.
- **Readable text**: one cell per Matrix field with `label: value` lines per block.
- **JSON**: one cell per Matrix field containing a JSON array.

## Settings

Under **Settings → Plugins → CSV Export**:

- **Delimiter**: semicolon (default, opens correctly in Excel in most European locales), comma or tab.
- **Include UTF‑8 BOM**: recommended for Excel.
- **Column labels**: field handle (stable, best for re‑importing) or field name.
- **Entry attributes**: which element attributes (id, title, slug, status, dates, …) are exported first.
- **Matrix fields**: flattening mode, see above.
- **Date format**, **multi-value separator**, **strip HTML**.

> The **Export…** dialog on the entries index is rendered by Craft itself and therefore always uses a comma delimiter. Use the utility or the console command when you need semicolons or a BOM.

## Roadmap

- Import: update existing entries from an (edited or translated) CSV, matched on entry ID and field handle.

## License

MIT © Manon Kindt
