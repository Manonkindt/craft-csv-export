<?php

namespace manonkindt\csvexport\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use manonkindt\csvexport\Plugin;
use yii\console\ExitCode;

/**
 * Exports entries to CSV from the command line.
 *
 * Example:
 *   php craft csv-export/export --section=news --site=default --status=live > news.csv
 *   php craft csv-export/export --section=requests --fields=firstName,lastName,email --output=requests.csv
 */
class ExportController extends Controller
{
    public $defaultAction = 'index';

    /** @var string|null Section handle (required) */
    public ?string $section = null;

    /** @var string|null Site handle (defaults to the primary site) */
    public ?string $site = null;

    /** @var string Entry status: live, pending, expired, disabled or all */
    public string $status = 'live';

    /** @var string|null Comma-separated field handles (defaults to all fields) */
    public ?string $fields = null;

    /** @var int Maximum number of entries (0 = no limit) */
    public int $limit = 0;

    /** @var string|null File to write to (defaults to stdout) */
    public ?string $output = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['section', 'site', 'status', 'fields', 'limit', 'output']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['s' => 'section', 'o' => 'output']);
    }

    /**
     * Exports entries of a section to a flat CSV.
     */
    public function actionIndex(): int
    {
        if (!$this->section) {
            $this->stderr("The --section option is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $section = Craft::$app->getEntries()->getSectionByHandle($this->section);
        if (!$section) {
            $this->stderr("Unknown section: {$this->section}\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $site = $this->site
            ? Craft::$app->getSites()->getSiteByHandle($this->site)
            : Craft::$app->getSites()->getPrimarySite();
        if (!$site) {
            $this->stderr("Unknown site: {$this->site}\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $query = Entry::find()
            ->section($section->handle)
            ->siteId($site->id)
            ->orderBy(['postDate' => SORT_DESC, 'id' => SORT_DESC]);

        if ($this->status === 'all') {
            $query->status(null);
        } else {
            $query->status($this->status);
        }

        if ($this->limit > 0) {
            $query->limit($this->limit);
        }

        $fieldHandles = $this->fields ? array_filter(array_map('trim', explode(',', $this->fields))) : null;

        $export = Plugin::getInstance()->export;
        [$columns, $rows] = $export->buildTable($query->all(), $fieldHandles ?: null);
        $csv = $export->toCsv($columns, $rows);

        if ($this->output) {
            file_put_contents($this->output, $csv);
            $this->stdout(sprintf("Exported %d entries to %s\n", count($rows), $this->output), Console::FG_GREEN);
        } else {
            $this->stdout($csv);
        }

        return ExitCode::OK;
    }
}
