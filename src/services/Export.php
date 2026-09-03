<?php

namespace manonkindt\csvexport\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\base\NestedElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\ContentBlock as ContentBlockField;
use craft\fields\data\ColorData;
use craft\fields\data\LinkData;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\OptionData;
use craft\fields\Matrix as MatrixField;
use craft\fields\PlainText;
use craft\helpers\Json;
use craft\helpers\MoneyHelper;
use DateTimeInterface;
use manonkindt\csvexport\models\Settings;
use manonkindt\csvexport\Plugin;
use Money\Money;
use Traversable;
use yii\base\Arrayable;

/**
 * Builds flat rows (one per entry, one column per field) and writes CSV.
 *
 * Two modes:
 * - default: every field, formatted according to the plugin settings
 * - translation: only translatable, textual fields, nested elements with their ids,
 *   so the table can be handed to a translator and imported again
 */
class Export extends Component
{
    /**
     * Builds a normalized table for the given entries.
     *
     * @param iterable<Entry> $entries
     * @param string[]|null $fieldHandles Custom field handles to include (null = all fields in the layout)
     * @param array{translation?: bool} $options
     * @return array{0: string[], 1: array<int, array<string, string>>} [$columns, $rows]
     */
    public function buildTable(iterable $entries, ?array $fieldHandles = null, array $options = []): array
    {
        $settings = $this->settings();
        $columns = [];
        $rows = [];

        foreach ($entries as $entry) {
            $row = $this->buildRow($entry, $fieldHandles, $settings, $options);
            $this->mergeColumns($columns, array_keys($row));
            $rows[] = $row;
        }

        // Make every row uniform and in column order
        foreach ($rows as &$row) {
            $normalized = [];
            foreach ($columns as $column) {
                $normalized[$column] = $row[$column] ?? '';
            }
            $row = $normalized;
        }
        unset($row);

        return [$columns, $rows];
    }

    /**
     * Builds the translation table for a set of entries in a given site.
     * Entries that don't exist in that site are left out.
     *
     * @param Entry[] $entries Entries (from any site)
     * @return array{0: string[], 1: array<int, array<string, string>>}
     */
    public function buildTranslationTable(array $entries, int $siteId): array
    {
        $ids = array_values(array_unique(array_map(fn(Entry $e) => $e->id, $entries)));
        if (!$ids) {
            return [['id'], []];
        }

        $siteEntries = Entry::find()
            ->id($ids)
            ->siteId($siteId)
            ->status(null)
            ->fixedOrder()
            ->all();

        return $this->buildTable($siteEntries, null, ['translation' => true]);
    }

    /**
     * Builds a single flat row for an entry.
     *
     * @param array{translation?: bool} $options
     * @return array<string, string>
     */
    public function buildRow(Entry $entry, ?array $fieldHandles, ?Settings $settings = null, array $options = []): array
    {
        $settings ??= $this->settings();
        $translation = !empty($options['translation']);
        $row = [];

        if ($translation) {
            $row['id'] = (string)$entry->id;
            if ($entry->getIsTitleTranslatable()) {
                $row['title'] = (string)$entry->title;
            }
        } else {
            foreach ($settings->metaColumns as $attribute) {
                $row[$attribute] = $this->metaValue($entry, $attribute, $settings);
            }
        }

        foreach ($this->customFields($entry) as $field) {
            if ($fieldHandles !== null && !in_array($field->handle, $fieldHandles, true)) {
                continue;
            }
            $label = $translation ? $field->handle : $this->columnLabel($field, $settings);
            $value = $entry->getFieldValue($field->handle);
            $columnsMode = $translation || $settings->matrixMode === Settings::MATRIX_MODE_COLUMNS;

            // In translation mode, nested elements (Matrix, Neo, Content Block) are always
            // descended into: their own fields decide what is translatable. Other fields
            // must be translatable themselves.
            $isNestedValue = $value instanceof ElementInterface
                || (($value instanceof ElementQueryInterface || $value instanceof ElementCollection) && $this->isNestedList($this->nestedElements($value)));
            if ($translation && !$isNestedValue && !$field->getIsTranslatable($entry)) {
                continue;
            }

            // Content Block: a single nested element
            if ($columnsMode && $field instanceof ContentBlockField && $value instanceof ElementInterface) {
                $row += $this->nestedColumns($value, $label, $settings, false, $options);
                continue;
            }

            // SEO plugins (SEO Fields, SEOmatic, Ether SEO): split into title/description/social columns
            $seoColumns = $this->seoColumns($value, $label);
            if ($seoColumns !== null) {
                $row += $translation ? $this->translatableSeoColumns($seoColumns) : $seoColumns;
                continue;
            }

            // Matrix, Neo, … (nested elements) or relations (entries, assets, …)
            if ($value instanceof ElementQueryInterface || $value instanceof ElementCollection) {
                $elements = $this->nestedElements($value);
                if ($columnsMode && $this->isNestedList($elements)) {
                    foreach ($elements as $i => $nested) {
                        $prefix = sprintf('%s[%d]', $label, $i + 1);
                        if ($translation) {
                            $row["$prefix.id"] = (string)$nested->id;
                        }
                        $row += $this->nestedColumns($nested, $prefix, $settings, !$translation, $options);
                    }
                    continue;
                }
                if ($translation) {
                    continue; // relations can't be translated
                }
                $row[$label] = $this->formatElements($elements, $field, $settings);
                continue;
            }

            if ($translation && !$this->isTextual($field, $value)) {
                continue;
            }

            $row[$label] = $this->formatValue($value, $field, $settings);
        }

        return $row;
    }

    /**
     * Writes a table to a CSV string, honouring the plugin settings.
     *
     * @param string[] $columns
     * @param array<int, array<string, string>> $rows
     */
    public function toCsv(array $columns, array $rows, ?string $delimiter = null, ?bool $bom = null): string
    {
        $settings = $this->settings();
        $delimiter ??= $settings->getDelimiterChar();
        $bom ??= $settings->includeBom;

        $fh = fopen('php://temp', 'w+');
        if ($bom) {
            fwrite($fh, "\xEF\xBB\xBF");
        }
        fputcsv($fh, array_map([$this, 'guardCell'], $columns), $delimiter, '"', '');
        foreach ($rows as $row) {
            fputcsv($fh, array_map([$this, 'guardCell'], array_values($row)), $delimiter, '"', '');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }

    /**
     * Returns the custom fields available for a section (union of all its entry types' layouts).
     *
     * @return array<string, string> handle => name
     */
    public function fieldsForSection(string $sectionHandle): array
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        if (!$section) {
            return [];
        }
        $fields = [];
        foreach ($section->getEntryTypes() as $entryType) {
            foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
                $fields[$field->handle] = $field->name;
            }
        }
        return $fields;
    }

    /**
     * Converts any field value to a single string cell.
     */
    public function formatValue(mixed $value, ?FieldInterface $field, ?Settings $settings = null): string
    {
        $settings ??= $this->settings();

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof DateTimeInterface) {
            return $this->formatDate($value, $settings);
        }

        if ($value instanceof Money) {
            return (string)MoneyHelper::toDecimal($value);
        }

        if ($value instanceof LinkData) {
            return $value->getUrl();
        }

        if ($value instanceof ColorData) {
            return $value->getHex();
        }

        if ($value instanceof MultiOptionsFieldData) {
            $selected = [];
            foreach ($value as $option) {
                /** @var OptionData $option */
                if ($option->selected) {
                    $selected[] = (string)$option->value;
                }
            }
            return implode($settings->multiValueSeparator, $selected);
        }

        if ($value instanceof OptionData) {
            return (string)$value->value;
        }

        // Matrix / Neo / Content Block (when not flattened to columns) and relation fields
        if ($value instanceof ElementQueryInterface || $value instanceof ElementCollection) {
            return $this->formatElements($this->nestedElements($value), $field, $settings);
        }

        if ($value instanceof ElementInterface) {
            if ($field instanceof ContentBlockField || $value instanceof NestedElementInterface) {
                return $this->formatNestedList([$value], $settings);
            }
            return $this->elementLabel($value);
        }

        $seoColumns = $this->seoColumns($value, 'seo');
        if ($seoColumns !== null) {
            return Json::encode($seoColumns);
        }

        // Rich text (CKEditor, Redactor, Vizy…) and other stringable objects
        if (is_object($value) && method_exists($value, '__toString')) {
            $value = (string)$value;
        } elseif (is_object($value) && method_exists($value, 'renderHtml')) {
            $value = (string)$value->renderHtml();
        } elseif ($value instanceof Arrayable) {
            return Json::encode($value->toArray());
        } elseif (is_array($value) || $value instanceof Traversable) {
            return Json::encode($value instanceof Traversable ? iterator_to_array($value) : $value);
        } elseif (is_object($value)) {
            return Json::encode($value);
        }

        $string = (string)$value;

        if ($settings->stripHtml) {
            $string = trim(html_entity_decode(strip_tags($string), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return $string;
    }

    /**
     * Whether a field value is plain or rich text that a translator can edit.
     */
    public function isTextual(?FieldInterface $field, mixed $value): bool
    {
        if (is_string($value)) {
            return true;
        }
        if ($value === null && $field !== null) {
            // Empty text fields still deserve a column
            $class = strtolower(get_class($field));
            return $field instanceof PlainText
                || str_contains($class, 'ckeditor')
                || str_contains($class, 'redactor')
                || str_contains($class, 'vizy');
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return !($value instanceof ColorData
                || $value instanceof LinkData
                || $value instanceof OptionData
                || $value instanceof Money
                || $value instanceof DateTimeInterface);
        }
        return false;
    }

    // -------------------------------------------------------------------------

    protected function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    /**
     * @return FieldInterface[]
     */
    protected function customFields(ElementInterface $element): array
    {
        $layout = $element->getFieldLayout();
        return $layout ? $layout->getCustomFields() : [];
    }

    protected function columnLabel(FieldInterface $field, Settings $settings): string
    {
        return $settings->columnLabels === Settings::LABELS_NAME ? $field->name : $field->handle;
    }

    /**
     * Merges a row's keys into the ordered column list, inserting new keys right
     * after the key that preceded them in the row (keeps Matrix columns grouped).
     *
     * @param string[] $columns
     * @param string[] $rowKeys
     */
    protected function mergeColumns(array &$columns, array $rowKeys): void
    {
        $previous = null;
        foreach ($rowKeys as $key) {
            if (!in_array($key, $columns, true)) {
                $position = $previous !== null ? array_search($previous, $columns, true) : false;
                if ($position === false) {
                    $columns[] = $key;
                } else {
                    array_splice($columns, $position + 1, 0, [$key]);
                }
            }
            $previous = $key;
        }
    }

    protected function metaValue(Entry $entry, string $attribute, Settings $settings): string
    {
        return match ($attribute) {
            'id' => (string)$entry->id,
            'uid' => (string)$entry->uid,
            'title' => (string)$entry->title,
            'slug' => (string)$entry->slug,
            'uri' => (string)$entry->uri,
            'url' => (string)$entry->getUrl(),
            'section' => (string)($entry->getSection()?->handle ?? ''),
            'type' => $entry->getType()->handle,
            'site' => $entry->getSite()->handle,
            'status' => (string)$entry->getStatus(),
            'author' => (string)($entry->getAuthor()?->email ?? ''),
            'postDate' => $this->formatDate($entry->postDate, $settings),
            'expiryDate' => $this->formatDate($entry->expiryDate, $settings),
            'dateCreated' => $this->formatDate($entry->dateCreated, $settings),
            'dateUpdated' => $this->formatDate($entry->dateUpdated, $settings),
            default => '',
        };
    }

    /**
     * Flattens one nested element (Matrix entry / Neo block / Content Block) into prefixed columns.
     *
     * @param array{translation?: bool} $options
     * @return array<string, string>
     */
    protected function nestedColumns(ElementInterface $nested, string $prefix, Settings $settings, bool $withType, array $options = []): array
    {
        $translation = !empty($options['translation']);
        $columns = [];

        if ($withType) {
            $type = $this->nestedTypeHandle($nested);
            if ($type !== null) {
                $columns["$prefix.type"] = $type;
            }
        }

        if ($this->nestedHasTitle($nested) && (!$translation || $nested->getIsTitleTranslatable())) {
            $columns["$prefix.title"] = (string)$nested->title;
        }

        foreach ($this->customFields($nested) as $field) {
            if ($translation && !$field->getIsTranslatable($nested)) {
                continue;
            }
            $label = $translation ? $field->handle : $this->columnLabel($field, $settings);
            $value = $nested->getFieldValue($field->handle);

            $seoColumns = $this->seoColumns($value, "$prefix.$label");
            if ($seoColumns !== null) {
                $columns += $translation ? $this->translatableSeoColumns($seoColumns) : $seoColumns;
                continue;
            }

            if ($translation) {
                if ($value instanceof ElementQueryInterface || $value instanceof ElementCollection || $value instanceof ElementInterface) {
                    continue; // nested-in-nested and relations are not part of the translation table
                }
                if (!$this->isTextual($field, $value)) {
                    continue;
                }
            }

            // Nested Matrix inside Matrix: fall back to readable text to keep the table sane
            $columns["$prefix.$label"] = $this->formatValue($value, $field, $settings);
        }

        return $columns;
    }

    /**
     * @return ElementInterface[]
     */
    protected function nestedElements(mixed $value): array
    {
        if ($value instanceof ElementQueryInterface) {
            return $value->all();
        }
        if ($value instanceof ElementCollection) {
            return $value->all();
        }
        if ($value instanceof ElementInterface) {
            return [$value];
        }
        return [];
    }

    /**
     * True when the elements are nested (owned) elements rather than plain relations.
     *
     * @param ElementInterface[] $elements
     */
    protected function isNestedList(array $elements): bool
    {
        $first = $elements[0] ?? null;
        return $first instanceof NestedElementInterface && $first->getOwnerId() !== null;
    }

    /**
     * Formats a list of elements: nested elements as blocks, relations as labels.
     *
     * @param ElementInterface[] $elements
     */
    protected function formatElements(array $elements, ?FieldInterface $field, Settings $settings): string
    {
        if ($field instanceof MatrixField || $this->isNestedList($elements)) {
            return $this->formatNestedList($elements, $settings);
        }
        return implode($settings->multiValueSeparator, array_map(fn($el) => $this->elementLabel($el), $elements));
    }

    /**
     * Splits the value of a known SEO plugin field into separate columns.
     * Supports SEO Fields (studioespresso), SEOmatic (nystudio107) and SEO (ether).
     *
     * @return array<string, string>|null null when the value is not a known SEO value
     */
    public function seoColumns(mixed $value, string $prefix): ?array
    {
        if (!is_object($value)) {
            return null;
        }

        if (is_a($value, 'studioespresso\\seofields\\models\\SeoFieldModel')) {
            return [
                "$prefix.metaTitle" => (string)($value->metaTitle ?? ''),
                "$prefix.metaDescription" => (string)($value->metaDescription ?? ''),
                "$prefix.socialTitle" => (string)($value->facebookTitle ?? ''),
                "$prefix.socialDescription" => (string)($value->facebookDescription ?? ''),
                "$prefix.socialImage" => $this->assetUrlFromId($value->facebookImage ?? null),
            ];
        }

        if (is_a($value, 'nystudio107\\seomatic\\models\\MetaBundle')) {
            $vars = $value->metaGlobalVars ?? null;
            $bundleSettings = $value->metaBundleSettings ?? null;
            return [
                "$prefix.metaTitle" => (string)($vars->seoTitle ?? ''),
                "$prefix.metaDescription" => (string)($vars->seoDescription ?? ''),
                "$prefix.metaImage" => $this->assetUrlFromId($bundleSettings->seoImageIds ?? null) ?: (string)($vars->seoImage ?? ''),
                "$prefix.socialTitle" => (string)($vars->ogTitle ?? ''),
                "$prefix.socialDescription" => (string)($vars->ogDescription ?? ''),
                "$prefix.socialImage" => $this->assetUrlFromId($bundleSettings->ogImageIds ?? null) ?: (string)($vars->ogImage ?? ''),
                "$prefix.twitterTitle" => (string)($vars->twitterTitle ?? ''),
                "$prefix.twitterDescription" => (string)($vars->twitterDescription ?? ''),
                "$prefix.twitterImage" => $this->assetUrlFromId($bundleSettings->twitterImageIds ?? null) ?: (string)($vars->twitterImage ?? ''),
            ];
        }

        if (is_a($value, 'ether\\seo\\models\\data\\SeoData')) {
            $title = $value->titleRaw ?? '';
            if (is_array($title)) {
                $title = implode(' ', array_filter(array_map('strval', $title)));
            }
            $facebook = $this->etherSocial($value->social['facebook'] ?? null);
            $twitter = $this->etherSocial($value->social['twitter'] ?? null);
            return [
                "$prefix.metaTitle" => (string)$title,
                "$prefix.metaDescription" => (string)($value->descriptionRaw ?? ''),
                "$prefix.socialTitle" => $facebook['title'],
                "$prefix.socialDescription" => $facebook['description'],
                "$prefix.socialImage" => $facebook['image'],
                "$prefix.twitterTitle" => $twitter['title'],
                "$prefix.twitterDescription" => $twitter['description'],
                "$prefix.twitterImage" => $twitter['image'],
            ];
        }

        return null;
    }

    /**
     * Keeps only the SEO columns a translator can edit (no images).
     *
     * @param array<string, string> $seoColumns
     * @return array<string, string>
     */
    protected function translatableSeoColumns(array $seoColumns): array
    {
        return array_filter($seoColumns, fn(string $key) => !str_ends_with($key, 'Image'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Normalises an Ether SEO social entry (SocialData object or raw array).
     *
     * @return array{title: string, description: string, image: string}
     */
    protected function etherSocial(mixed $social): array
    {
        $get = static fn(string $key) => is_object($social) ? ($social->$key ?? null) : (is_array($social) ? ($social[$key] ?? null) : null);
        $image = $get('imageId') ?? $get('image');
        if (is_array($image) && isset($image[0]['id'])) {
            $image = $image[0]['id'];
        }
        return [
            'title' => (string)($get('title') ?? ''),
            'description' => (string)($get('description') ?? ''),
            'image' => $this->assetUrlFromId($image),
        ];
    }

    /**
     * Resolves an asset id (or an array of ids, or an Asset) to its URL.
     */
    protected function assetUrlFromId(mixed $id): string
    {
        if ($id instanceof Asset) {
            return $this->elementLabel($id);
        }
        if (is_array($id)) {
            $id = $id[0] ?? null;
            if ($id instanceof Asset) {
                return $this->elementLabel($id);
            }
        }
        if (!is_numeric($id) || (int)$id <= 0) {
            return '';
        }
        $asset = Craft::$app->getAssets()->getAssetById((int)$id);
        return $asset ? $this->elementLabel($asset) : '';
    }

    protected function nestedTypeHandle(ElementInterface $nested): ?string
    {
        if (!method_exists($nested, 'getType')) {
            return null;
        }
        try {
            $type = $nested->getType();
        } catch (\Throwable) {
            return null;
        }
        return is_object($type) && isset($type->handle) ? (string)$type->handle : null;
    }

    protected function nestedHasTitle(ElementInterface $nested): bool
    {
        if ($nested instanceof Entry) {
            return $nested->getType()->hasTitleField;
        }
        return isset($nested->title) && $nested->title !== '';
    }

    /**
     * @param ElementInterface[] $elements
     */
    protected function formatNestedList(array $elements, Settings $settings): string
    {
        if ($settings->matrixMode === Settings::MATRIX_MODE_JSON) {
            $data = [];
            foreach ($elements as $el) {
                $item = [];
                $type = $this->nestedTypeHandle($el);
                if ($type !== null) {
                    $item['type'] = $type;
                }
                if ($this->nestedHasTitle($el)) {
                    $item['title'] = (string)$el->title;
                }
                foreach ($this->customFields($el) as $field) {
                    $item[$field->handle] = $this->formatValue($el->getFieldValue($field->handle), $field, $settings);
                }
                $data[] = $item;
            }
            return Json::encode($data);
        }

        // Readable text (also used as fallback for nested Matrix inside Matrix in columns mode)
        $blocks = [];
        foreach ($elements as $el) {
            $lines = [];
            if ($this->nestedHasTitle($el) && $el->title !== null && $el->title !== '') {
                $lines[] = (string)$el->title;
            }
            foreach ($this->customFields($el) as $field) {
                $formatted = $this->formatValue($el->getFieldValue($field->handle), $field, $settings);
                if ($formatted === '') {
                    continue;
                }
                $lines[] = sprintf('%s: %s', $this->columnLabel($field, $settings), $formatted);
            }
            if ($lines) {
                $blocks[] = implode("\n", $lines);
            }
        }
        return implode("\n\n", $blocks);
    }

    protected function elementLabel(ElementInterface $element): string
    {
        if ($element instanceof Asset) {
            return (string)($element->getUrl() ?: $element->getFilename());
        }
        if ($element instanceof User) {
            return (string)$element->email;
        }
        $title = $element->title ?? null;
        if ($title !== null && $title !== '') {
            return (string)$title;
        }
        try {
            $string = (string)$element;
        } catch (\Throwable) {
            $string = '';
        }
        return $string !== '' ? $string : (string)$element->id;
    }

    protected function formatDate(?DateTimeInterface $date, Settings $settings): string
    {
        return $date ? $date->format($settings->dateFormat) : '';
    }

    /**
     * Guards against CSV/formula injection when the file is opened in a spreadsheet.
     */
    protected function guardCell(mixed $cell): string
    {
        $cell = (string)$cell;
        if ($cell !== '' && in_array($cell[0], ['=', '-', '+', '@'], true)) {
            return "\t" . $cell;
        }
        return $cell;
    }
}
