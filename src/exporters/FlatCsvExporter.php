<?php

namespace manonkindt\csvexport\exporters;

use Craft;
use craft\base\ElementExporter;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use manonkindt\csvexport\Plugin;

/**
 * Export type for the entries index "Export…" dialog.
 *
 * Produces one row per entry and one column per field. Craft takes care of
 * serialising the rows to CSV, JSON or XML depending on the chosen format.
 */
class FlatCsvExporter extends ElementExporter
{
    public static function displayName(): string
    {
        return Craft::t('csv-export', 'Flat (one column per field)');
    }

    public function export(ElementQueryInterface $query): array
    {
        /** @var Entry[] $entries */
        $entries = $query->all();

        [, $rows] = Plugin::getInstance()->export->buildTable($entries);

        return $rows;
    }

    public function getFilename(): string
    {
        return sprintf('%s-flat-%s', parent::getFilename(), date('Ymd-His'));
    }
}
