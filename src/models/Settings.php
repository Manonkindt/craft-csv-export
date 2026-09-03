<?php

namespace manonkindt\csvexport\models;

use Craft;
use craft\base\Model;

class Settings extends Model
{
    public const MATRIX_MODE_COLUMNS = 'columns';
    public const MATRIX_MODE_TEXT = 'text';
    public const MATRIX_MODE_JSON = 'json';

    public const LABELS_HANDLE = 'handle';
    public const LABELS_NAME = 'name';

    /**
     * @var string CSV field delimiter used by the utility download and the console command.
     *             Use `;` for Excel in most European locales. `\t` for tab.
     */
    public string $delimiter = ';';

    /**
     * @var bool Prepend a UTF-8 byte order mark so Excel recognises the encoding.
     */
    public bool $includeBom = true;

    /**
     * @var string PHP date format for date/time values.
     */
    public string $dateFormat = 'Y-m-d H:i:s';

    /**
     * @var string Separator between multiple related elements / options in one cell.
     */
    public string $multiValueSeparator = ' | ';

    /**
     * @var string How Matrix (and Content Block) fields are flattened:
     *             - columns: one column per nested entry field, e.g. `data[1].answer`
     *             - text: one cell per Matrix field with readable "label: value" lines
     *             - json: one cell per Matrix field containing JSON
     */
    public string $matrixMode = self::MATRIX_MODE_COLUMNS;

    /**
     * @var string Column header style: field handle or field name.
     */
    public string $columnLabels = self::LABELS_HANDLE;

    /**
     * @var string[] Element attributes exported before the custom fields.
     */
    public array $metaColumns = ['id', 'title', 'slug', 'status', 'postDate', 'dateUpdated'];

    /**
     * @var bool Strip HTML tags from rich text (CKEditor, Redactor) values.
     */
    public bool $stripHtml = false;

    public static function availableMetaColumns(): array
    {
        return [
            'id' => 'ID',
            'uid' => 'UID',
            'title' => Craft::t('app', 'Title'),
            'slug' => Craft::t('app', 'Slug'),
            'uri' => Craft::t('app', 'URI'),
            'url' => Craft::t('app', 'URL'),
            'section' => Craft::t('app', 'Section'),
            'type' => Craft::t('app', 'Entry Type'),
            'site' => Craft::t('app', 'Site'),
            'status' => Craft::t('app', 'Status'),
            'author' => Craft::t('app', 'Author'),
            'postDate' => Craft::t('app', 'Post Date'),
            'expiryDate' => Craft::t('app', 'Expiry Date'),
            'dateCreated' => Craft::t('app', 'Date Created'),
            'dateUpdated' => Craft::t('app', 'Date Updated'),
        ];
    }

    public static function delimiterOptions(): array
    {
        return [
            ';' => Craft::t('csv-export', 'Semicolon (;) — Excel in most European locales'),
            ',' => Craft::t('csv-export', 'Comma (,)'),
            '\t' => Craft::t('csv-export', 'Tab'),
        ];
    }

    public static function matrixModeOptions(): array
    {
        return [
            self::MATRIX_MODE_COLUMNS => Craft::t('csv-export', 'One column per nested field (e.g. data[1].answer)'),
            self::MATRIX_MODE_TEXT => Craft::t('csv-export', 'Readable text in one cell'),
            self::MATRIX_MODE_JSON => Craft::t('csv-export', 'JSON in one cell'),
        ];
    }

    public static function columnLabelOptions(): array
    {
        return [
            self::LABELS_HANDLE => Craft::t('csv-export', 'Field handle (best for re-importing)'),
            self::LABELS_NAME => Craft::t('csv-export', 'Field name (best for humans)'),
        ];
    }

    /**
     * Returns the real one-character delimiter (the settings form stores `\t` as a literal string).
     */
    public function getDelimiterChar(): string
    {
        return match ($this->delimiter) {
            '\t', "\t", 'tab' => "\t",
            default => mb_substr($this->delimiter, 0, 1) ?: ',',
        };
    }

    protected function defineRules(): array
    {
        return [
            [['delimiter', 'dateFormat', 'matrixMode', 'columnLabels'], 'required'],
            [['delimiter'], 'in', 'range' => array_keys(self::delimiterOptions())],
            [['matrixMode'], 'in', 'range' => array_keys(self::matrixModeOptions())],
            [['columnLabels'], 'in', 'range' => array_keys(self::columnLabelOptions())],
            [['metaColumns'], 'each', 'rule' => ['in', 'range' => array_keys(self::availableMetaColumns())]],
            [['includeBom', 'stripHtml'], 'boolean'],
            [['multiValueSeparator'], 'string'],
        ];
    }
}
