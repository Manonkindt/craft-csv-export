<?php

namespace manonkindt\csvexport\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\Site;
use manonkindt\csvexport\Plugin;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use Throwable;

/**
 * Imports translations from a workbook (one sheet per language) or a CSV (one language).
 *
 * The import is deliberately narrow:
 * - only translatable, textual fields (and titles) are written
 * - the source language is never touched
 * - empty cells are ignored, so nothing is ever cleared
 * - existing translations are kept unless "overwrite" is enabled
 */
class Import extends Component
{
    public const SKIP_EMPTY = 'empty';
    public const SKIP_UNCHANGED = 'unchanged';
    public const SKIP_ALREADY_TRANSLATED = 'already-translated';
    public const SKIP_NOT_TRANSLATABLE = 'not-translatable';
    public const SKIP_UNSUPPORTED = 'unsupported';
    public const SKIP_UNKNOWN_FIELD = 'unknown-field';
    public const SKIP_MISSING_NESTED = 'missing-nested';

    /**
     * Reads the file and works out what would change, without saving anything.
     */
    public function plan(string $filePath, string $originalName, int $sourceSiteId, ?int $fallbackSiteId, bool $overwrite): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $sourceSite = Craft::$app->getSites()->getSiteById($sourceSiteId);
        if (!$sourceSite) {
            throw new \InvalidArgumentException("Unknown source site $sourceSiteId");
        }

        $plan = [
            'token' => StringHelper::randomString(32),
            'file' => $originalName,
            'sourceSiteId' => $sourceSiteId,
            'overwrite' => $overwrite,
            'sites' => [],
            'skippedSheets' => [],
            'createdAt' => time(),
        ];

        foreach ($this->readSheets($filePath, $ext) as $sheet) {
            if (strcasecmp(trim($sheet['title']), 'READ ME') === 0) {
                continue;
            }
            $site = $this->resolveSite($sheet['title'], $ext === 'csv' ? $fallbackSiteId : null);
            if (!$site) {
                $plan['skippedSheets'][] = ['sheet' => $sheet['title'], 'reason' => 'no-site'];
                continue;
            }
            if ($site->id === $sourceSite->id) {
                $plan['skippedSheets'][] = ['sheet' => $sheet['title'], 'reason' => 'source'];
                continue;
            }
            if (isset($plan['sites'][$site->id])) {
                $plan['skippedSheets'][] = ['sheet' => $sheet['title'], 'reason' => 'duplicate'];
                continue;
            }
            $plan['sites'][$site->id] = $this->planSheet($sheet, $site, $sourceSite, $overwrite);
        }

        return $plan;
    }

    /**
     * Applies a plan created by [[plan()]].
     *
     * @return array{saved: int, cells: int, errors: array<int, array{entryId: int, title: string, site: string, errors: string[]}>}
     */
    public function apply(array $plan): array
    {
        $result = ['saved' => 0, 'cells' => 0, 'errors' => []];
        $elements = Craft::$app->getElements();

        foreach ($plan['sites'] as $siteId => $sheet) {
            $siteId = (int)$siteId;
            $byElement = [];
            foreach ($sheet['changes'] as $change) {
                $byElement[$change['elementId']][] = $change;
            }

            foreach ($byElement as $elementId => $changes) {
                $element = $elements->getElementById((int)$elementId, null, $siteId);
                if (!$element) {
                    $result['errors'][] = [
                        'entryId' => $changes[0]['entryId'],
                        'title' => $changes[0]['entryTitle'],
                        'site' => $sheet['siteName'],
                        'errors' => [Craft::t('csv-export', 'Element no longer exists.')],
                    ];
                    continue;
                }

                foreach ($changes as $change) {
                    $this->setValue($element, $change);
                }

                try {
                    $saved = $elements->saveElement($element, true, true, false);
                } catch (Throwable $e) {
                    $saved = false;
                    $element->addError('general', $e->getMessage());
                }

                if ($saved) {
                    $result['saved']++;
                    $result['cells'] += count($changes);
                } else {
                    $result['errors'][] = [
                        'entryId' => $changes[0]['entryId'],
                        'title' => $changes[0]['entryTitle'],
                        'site' => $sheet['siteName'],
                        'errors' => $element->getErrorSummary(true),
                    ];
                }
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Plan storage (between preview and confirmation)
    // -------------------------------------------------------------------------

    public function savePlan(array $plan): string
    {
        $dir = $this->planDir();
        FileHelper::createDirectory($dir);
        // Housekeeping: drop plans older than a day
        foreach (glob($dir . '/*.json') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
        FileHelper::writeToFile($dir . '/' . $plan['token'] . '.json', Json::encode($plan));
        return $plan['token'];
    }

    public function loadPlan(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9]{32}$/', $token)) {
            return null;
        }
        $path = $this->planDir() . '/' . $token . '.json';
        if (!is_file($path)) {
            return null;
        }
        return Json::decode(file_get_contents($path));
    }

    public function deletePlan(string $token): void
    {
        if (preg_match('/^[A-Za-z0-9]{32}$/', $token)) {
            @unlink($this->planDir() . '/' . $token . '.json');
        }
    }

    protected function planDir(): string
    {
        return Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'csv-export';
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array{title: string, rows: array<int, array<int, mixed>>}>
     */
    protected function readSheets(string $filePath, string $ext): array
    {
        if ($ext === 'csv') {
            $reader = new Csv();
            $reader->setInputEncoding(Csv::GUESS_ENCODING);
            $reader->setDelimiter($this->guessDelimiter($filePath));
        } else {
            $reader = IOFactory::createReader('Xlsx');
        }
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        $sheets = [];
        foreach ($spreadsheet->getAllSheets() as $worksheet) {
            $sheets[] = [
                'title' => $worksheet->getTitle(),
                'rows' => $worksheet->toArray(null, false, false, false),
            ];
        }
        $spreadsheet->disconnectWorksheets();

        return $sheets;
    }

    protected function guessDelimiter(string $filePath): string
    {
        $fh = fopen($filePath, 'r');
        $line = (string)fgets($fh);
        fclose($fh);
        $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($counts);
        $best = array_key_first($counts);
        return $counts[$best] > 0 ? $best : ',';
    }

    protected function resolveSite(string $sheetTitle, ?int $fallbackSiteId): ?Site
    {
        $sites = Craft::$app->getSites();
        $title = trim($sheetTitle);
        foreach ($sites->getAllSites() as $site) {
            if (
                strcasecmp($site->handle, $title) === 0
                || strcasecmp((string)$site->name, $title) === 0
                || strcasecmp((string)$site->language, $title) === 0
                || strcasecmp(mb_substr($site->handle, 0, 31), $title) === 0
            ) {
                return $site;
            }
        }
        return $fallbackSiteId ? $sites->getSiteById($fallbackSiteId) : null;
    }

    // -------------------------------------------------------------------------
    // Planning
    // -------------------------------------------------------------------------

    protected function planSheet(array $sheet, Site $site, Site $sourceSite, bool $overwrite): array
    {
        $result = [
            'siteId' => $site->id,
            'siteName' => $site->name,
            'siteHandle' => $site->handle,
            'sheet' => $sheet['title'],
            'rows' => 0,
            'changes' => [],
            'skipped' => [],
            'missing' => [],
            'error' => null,
        ];

        $rows = $sheet['rows'];
        $header = array_map(static fn($h) => trim((string)$h), (array)array_shift($rows));
        $idIndex = null;
        foreach ($header as $i => $column) {
            if (strcasecmp($column, 'id') === 0) {
                $idIndex = $i;
                break;
            }
        }
        if ($idIndex === null) {
            $result['error'] = 'no-id-column';
            return $result;
        }

        foreach ($rows as $row) {
            $row = (array)$row;
            $entryId = (int)($row[$idIndex] ?? 0);
            if ($entryId <= 0) {
                continue;
            }
            $result['rows']++;

            $target = Entry::find()->id($entryId)->siteId($site->id)->status(null)->one();
            if (!$target) {
                $result['missing'][] = $entryId;
                continue;
            }
            $source = Entry::find()->id($entryId)->siteId($sourceSite->id)->status(null)->one();

            // Nested element ids present in the row (e.g. "data[2].id" => 123)
            $nestedIds = [];
            foreach ($header as $i => $column) {
                if (preg_match('/^(.+\[\d+\])\.id$/', $column, $m) && !empty($row[$i])) {
                    $nestedIds[$m[1]] = (int)$row[$i];
                }
            }

            foreach ($header as $i => $column) {
                if ($i === $idIndex || $column === '' || str_ends_with($column, '].id')) {
                    continue;
                }
                $new = trim((string)($row[$i] ?? ''));
                if ($new === '') {
                    $this->skip($result, self::SKIP_EMPTY);
                    continue;
                }

                $descriptor = $this->resolve($target, $column, $nestedIds);
                if (is_string($descriptor)) {
                    $this->skip($result, $descriptor);
                    continue;
                }
                if ($this->normalize($descriptor['current']) === $this->normalize($new)) {
                    $this->skip($result, self::SKIP_UNCHANGED);
                    continue;
                }
                if (!$overwrite && $descriptor['current'] !== '') {
                    $sourceDescriptor = $source ? $this->resolve($source, $column, $nestedIds) : null;
                    $sourceCurrent = is_array($sourceDescriptor) ? $sourceDescriptor['current'] : null;
                    // Non-empty and different from the source text = already translated: keep it
                    if ($sourceCurrent === null || $this->normalize($descriptor['current']) !== $this->normalize($sourceCurrent)) {
                        $this->skip($result, self::SKIP_ALREADY_TRANSLATED);
                        continue;
                    }
                }

                $result['changes'][] = [
                    'elementId' => $descriptor['element']->id,
                    'entryId' => $target->id,
                    'entryTitle' => (string)$target->title,
                    'column' => $column,
                    'kind' => $descriptor['kind'],
                    'handle' => $descriptor['handle'] ?? null,
                    'seoKey' => $descriptor['seoKey'] ?? null,
                    'old' => $descriptor['current'],
                    'new' => $new,
                ];
            }
        }

        return $result;
    }

    protected function skip(array &$result, string $reason): void
    {
        $result['skipped'][$reason] = ($result['skipped'][$reason] ?? 0) + 1;
    }

    protected function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Works out which element/attribute a column points to.
     *
     * @param array<string, int> $nestedIds
     * @return array{element: ElementInterface, kind: string, handle?: string, seoKey?: string, current: string}|string A descriptor, or a skip reason
     */
    protected function resolve(ElementInterface $element, string $column, array $nestedIds): array|string
    {
        $export = Plugin::getInstance()->export;

        // Nested element column: handle[n].rest
        if (preg_match('/^([^\[.]+)\[(\d+)\]\.(.+)$/', $column, $m)) {
            [, $handle, $n, $rest] = $m;
            $nested = $this->nestedElement($element, $handle, (int)$n, $nestedIds["{$handle}[$n]"] ?? null);
            if (!$nested) {
                return self::SKIP_MISSING_NESTED;
            }
            return $this->resolve($nested, $rest, []);
        }

        if ($column === 'title') {
            if (!$element->getIsTitleTranslatable()) {
                return self::SKIP_NOT_TRANSLATABLE;
            }
            return ['element' => $element, 'kind' => 'title', 'current' => (string)$element->title];
        }

        $handle = $column;
        $sub = null;
        if (str_contains($column, '.')) {
            [$handle, $sub] = explode('.', $column, 2);
        }

        $field = $this->fieldByHandle($element, $handle);
        if (!$field) {
            return self::SKIP_UNKNOWN_FIELD;
        }
        if (!$field->getIsTranslatable($element)) {
            return self::SKIP_NOT_TRANSLATABLE;
        }
        $value = $element->getFieldValue($handle);

        if ($sub !== null) {
            // Content Block: single nested element
            if ($value instanceof ElementInterface) {
                return $this->resolve($value, $sub, []);
            }
            $seo = $export->seoColumns($value, $handle);
            if ($seo === null || !array_key_exists($column, $seo)) {
                return self::SKIP_UNKNOWN_FIELD;
            }
            if (str_ends_with($sub, 'Image')) {
                return self::SKIP_UNSUPPORTED;
            }
            return ['element' => $element, 'kind' => 'seo', 'handle' => $handle, 'seoKey' => $sub, 'current' => $seo[$column]];
        }

        if ($value instanceof ElementQueryInterface || $value instanceof ElementCollection || $value instanceof ElementInterface) {
            return self::SKIP_UNSUPPORTED;
        }
        if (!$export->isTextual($field, $value)) {
            return self::SKIP_UNSUPPORTED;
        }

        return ['element' => $element, 'kind' => 'field', 'handle' => $handle, 'current' => $value === null ? '' : (string)$value];
    }

    protected function fieldByHandle(ElementInterface $element, string $handle): ?FieldInterface
    {
        $layout = $element->getFieldLayout();
        if (!$layout) {
            return null;
        }
        foreach ($layout->getCustomFields() as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }
        return null;
    }

    protected function nestedElement(ElementInterface $owner, string $handle, int $n, ?int $nestedId): ?ElementInterface
    {
        if (!$this->fieldByHandle($owner, $handle)) {
            return null;
        }
        $value = $owner->getFieldValue($handle);
        $elements = [];
        if ($value instanceof ElementQueryInterface || $value instanceof ElementCollection) {
            $elements = $value->all();
        }

        if ($nestedId) {
            foreach ($elements as $el) {
                if ((int)$el->id === $nestedId) {
                    return $el;
                }
            }
            return Craft::$app->getElements()->getElementById($nestedId, null, $owner->siteId);
        }

        return $elements[$n - 1] ?? null;
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    protected function setValue(ElementInterface $element, array $change): void
    {
        $new = (string)$change['new'];

        switch ($change['kind']) {
            case 'title':
                $element->title = $new;
                return;

            case 'field':
                $element->setFieldValue($change['handle'], $new);
                return;

            case 'seo':
                $model = $element->getFieldValue($change['handle']);
                $this->setSeoValue($model, $change['seoKey'], $new);
                $element->setFieldValue($change['handle'], $model);
                return;
        }
    }

    protected function setSeoValue(mixed $model, string $key, string $value): void
    {
        if (!is_object($model)) {
            return;
        }

        if (is_a($model, 'studioespresso\\seofields\\models\\SeoFieldModel')) {
            $map = ['metaTitle' => 'metaTitle', 'metaDescription' => 'metaDescription', 'socialTitle' => 'facebookTitle', 'socialDescription' => 'facebookDescription'];
            if (isset($map[$key])) {
                $model->{$map[$key]} = $value;
            }
            return;
        }

        if (is_a($model, 'nystudio107\\seomatic\\models\\MetaBundle')) {
            $map = ['metaTitle' => 'seoTitle', 'metaDescription' => 'seoDescription', 'socialTitle' => 'ogTitle', 'socialDescription' => 'ogDescription', 'twitterTitle' => 'twitterTitle', 'twitterDescription' => 'twitterDescription'];
            if (!isset($map[$key]) || !isset($model->metaGlobalVars)) {
                return;
            }
            $prop = $map[$key];
            $model->metaGlobalVars->$prop = $value;
            // Make sure SEOmatic uses the custom text rather than pulling it from a field
            if (isset($model->metaBundleSettings) && property_exists($model->metaBundleSettings, $prop . 'Source')) {
                $model->metaBundleSettings->{$prop . 'Source'} = 'fromCustom';
            }
            return;
        }

        if (is_a($model, 'ether\\seo\\models\\data\\SeoData')) {
            switch ($key) {
                case 'metaTitle':
                    $raw = $model->titleRaw;
                    if (is_array($raw) && count($raw) === 1) {
                        $model->titleRaw = [array_key_first($raw) => $value];
                    } else {
                        $model->titleRaw = [$value];
                    }
                    return;
                case 'metaDescription':
                    $model->descriptionRaw = $value;
                    return;
                default:
                    $network = str_starts_with($key, 'twitter') ? 'twitter' : 'facebook';
                    $prop = str_ends_with($key, 'Title') ? 'title' : 'description';
                    $social = $model->social[$network] ?? [];
                    if (is_object($social)) {
                        $social->$prop = $value;
                    } else {
                        $social = (array)$social;
                        $social[$prop] = $value;
                    }
                    $model->social[$network] = $social;
                    return;
            }
        }
    }
}
