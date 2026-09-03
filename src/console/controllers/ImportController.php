<?php

namespace manonkindt\csvexport\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use manonkindt\csvexport\Plugin;
use yii\console\ExitCode;

/**
 * Imports translations from a workbook (.xlsx, one sheet per language) or a CSV.
 *
 * Without --apply only a preview is printed; nothing is saved.
 *
 * Example:
 *   php craft csv-export/import --file=news-translations.xlsx --source=nl
 *   php craft csv-export/import --file=news-fr.csv --source=nl --site=fr --apply
 */
class ImportController extends Controller
{
    public $defaultAction = 'index';

    /** @var string|null Path to the .xlsx or .csv file (required) */
    public ?string $file = null;

    /** @var string|null Handle of the source language site (defaults to the primary site) */
    public ?string $source = null;

    /** @var string|null Site handle for .csv files or sheets that don't match a site */
    public ?string $site = null;

    /** @var bool Overwrite existing translations */
    public bool $overwrite = false;

    /** @var bool Save the changes (without this flag only a preview is printed) */
    public bool $apply = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['file', 'source', 'site', 'overwrite', 'apply']);
    }

    /**
     * Previews or applies a translation import.
     */
    public function actionIndex(): int
    {
        if (!$this->file || !is_file($this->file)) {
            $this->stderr("The --file option must point to an existing .xlsx or .csv file.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $sites = Craft::$app->getSites();
        $sourceSite = $this->source ? $sites->getSiteByHandle($this->source) : $sites->getPrimarySite();
        if (!$sourceSite) {
            $this->stderr("Unknown source site: {$this->source}\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $fallbackSite = $this->site ? $sites->getSiteByHandle($this->site) : null;
        if ($this->site && !$fallbackSite) {
            $this->stderr("Unknown site: {$this->site}\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $import = Plugin::getInstance()->import;
        $plan = $import->plan($this->file, basename($this->file), $sourceSite->id, $fallbackSite?->id, $this->overwrite);

        foreach ($plan['skippedSheets'] as $skipped) {
            $this->stdout(sprintf("Sheet \"%s\" skipped (%s)\n", $skipped['sheet'], $skipped['reason']), Console::FG_GREY);
        }

        $total = 0;
        foreach ($plan['sites'] as $sheet) {
            $this->stdout(sprintf("\n%s (%s)\n", $sheet['siteName'], $sheet['sheet']), Console::FG_CYAN);
            if ($sheet['error']) {
                $this->stdout("  error: {$sheet['error']}\n", Console::FG_RED);
                continue;
            }
            $this->stdout(sprintf("  %d rows, %d texts to import\n", $sheet['rows'], count($sheet['changes'])));
            if ($sheet['missing']) {
                $this->stdout(sprintf("  %d entries missing in this site: %s\n", count($sheet['missing']), implode(', ', array_slice($sheet['missing'], 0, 20))), Console::FG_YELLOW);
            }
            foreach ($sheet['skipped'] as $reason => $count) {
                $this->stdout(sprintf("  skipped %s: %d\n", $reason, $count), Console::FG_GREY);
            }
            foreach (array_slice($sheet['changes'], 0, 20) as $change) {
                $this->stdout(sprintf("  #%d %s → %s\n", $change['entryId'], $change['column'], mb_substr(strip_tags($change['new']), 0, 80)));
            }
            if (count($sheet['changes']) > 20) {
                $this->stdout(sprintf("  … and %d more\n", count($sheet['changes']) - 20));
            }
            $total += count($sheet['changes']);
        }

        if (!$this->apply) {
            $this->stdout(sprintf("\nPreview only. %d texts would be imported. Add --apply to save.\n", $total), Console::FG_GREEN);
            return ExitCode::OK;
        }

        $result = $import->apply($plan);
        $this->stdout(sprintf("\nImported %d texts in %d elements.\n", $result['cells'], $result['saved']), Console::FG_GREEN);
        foreach ($result['errors'] as $error) {
            $this->stdout(sprintf("  #%d %s (%s): %s\n", $error['entryId'], $error['title'], $error['site'], implode(' ', $error['errors'])), Console::FG_RED);
        }

        return $result['errors'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
