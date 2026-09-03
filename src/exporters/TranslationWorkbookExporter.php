<?php

namespace manonkindt\csvexport\exporters;

use Craft;
use craft\base\ElementExporter;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\models\Site;
use manonkindt\csvexport\Plugin;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Export type for the entries index "Export…" dialog that produces an Excel
 * workbook with one sheet per site (language), meant for translators.
 *
 * The workbook can be imported again via Utilities → CSV Import.
 */
class TranslationWorkbookExporter extends ElementExporter
{
    public static function displayName(): string
    {
        return Craft::t('csv-export', 'Translations (Excel, one sheet per language)');
    }

    public static function isFormattable(): bool
    {
        return false;
    }

    public function export(ElementQueryInterface $query): string
    {
        /** @var Entry[] $entries */
        $entries = $query->all();

        $sourceSite = $entries ? $entries[0]->getSite() : Craft::$app->getSites()->getCurrentSite();
        $sites = $this->sitesFor($entries, $sourceSite);

        $export = Plugin::getInstance()->export;
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('CSV Export for Craft CMS')
            ->setTitle(Craft::t('csv-export', 'Translations'));

        $this->writeReadMe($spreadsheet->getActiveSheet(), $sourceSite, $sites);

        foreach ($sites as $site) {
            [$columns, $rows] = $export->buildTranslationTable($entries, $site->id);
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->sheetTitle($site));
            $this->writeTable($sheet, $columns, $rows);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $fh = fopen('php://temp', 'w+');
        $writer->save($fh);
        rewind($fh);
        $content = stream_get_contents($fh);
        fclose($fh);
        $spreadsheet->disconnectWorksheets();

        return $content;
    }

    public function getFilename(): string
    {
        return sprintf('%s-translations-%s.xlsx', parent::getFilename(), date('Ymd-His'));
    }

    /**
     * Returns the sites the entries live in, source site first.
     *
     * @param Entry[] $entries
     * @return Site[]
     */
    protected function sitesFor(array $entries, Site $sourceSite): array
    {
        $siteIds = [$sourceSite->id => true];
        foreach ($entries as $entry) {
            $section = $entry->getSection();
            if (!$section) {
                continue;
            }
            foreach ($section->getSiteSettings() as $siteSettings) {
                $siteIds[$siteSettings->siteId] = true;
            }
        }

        $sites = [];
        foreach (array_keys($siteIds) as $siteId) {
            $site = Craft::$app->getSites()->getSiteById($siteId);
            if ($site) {
                $sites[] = $site;
            }
        }
        return $sites;
    }

    /**
     * Excel sheet titles: max 31 characters, no []:*?/\
     */
    public static function sheetTitle(Site $site): string
    {
        $title = preg_replace('/[\[\]:*?\/\\\\]/', '-', $site->handle);
        return mb_substr($title, 0, 31);
    }

    /**
     * @param string[] $columns
     * @param array<int, array<string, string>> $rows
     */
    protected function writeTable(Worksheet $sheet, array $columns, array $rows): void
    {
        foreach ($columns as $c => $column) {
            $sheet->setCellValueExplicit([$c + 1, 1], $column, DataType::TYPE_STRING);
        }
        foreach ($rows as $r => $row) {
            $c = 0;
            foreach ($row as $value) {
                $sheet->setCellValueExplicit([++$c, $r + 2], (string)$value, DataType::TYPE_STRING);
            }
        }

        $lastColumn = max(1, count($columns));
        $sheet->getStyle([1, 1, $lastColumn, 1])->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2937']],
        ]);
        // The id column must not be edited: grey it out
        $sheet->getStyle([1, 2, 1, max(2, count($rows) + 1)])->applyFromArray([
            'font' => ['color' => ['rgb' => '9CA3AF']],
        ]);
        $sheet->freezePane('B2');
        foreach (range(1, $lastColumn) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setWidth(40);
        }
        $sheet->getColumnDimensionByColumn(1)->setWidth(10);
        $sheet->getStyle([1, 1, $lastColumn, count($rows) + 1])->getAlignment()->setWrapText(true)->setVertical('top');
    }

    /**
     * @param Site[] $sites
     */
    protected function writeReadMe(Worksheet $sheet, Site $sourceSite, array $sites): void
    {
        $sheet->setTitle('READ ME');

        $lines = [
            Craft::t('csv-export', 'Translation workbook'),
            '',
            Craft::t('csv-export', 'This file contains one sheet per language. The sheet “{sheet}” holds the source texts ({site}). The other sheets hold the texts as they currently are in that language.', [
                'sheet' => self::sheetTitle($sourceSite),
                'site' => $sourceSite->name,
            ]),
            '',
            Craft::t('csv-export', 'How to translate:'),
            Craft::t('csv-export', '1. Open the sheet of the language you are translating into.'),
            Craft::t('csv-export', '2. Replace the texts in that sheet with your translation. Leave the “id” column and the header row untouched.'),
            Craft::t('csv-export', '3. Do not add, remove or reorder columns or rows. Empty cells are ignored on import.'),
            Craft::t('csv-export', '4. HTML tags (like <p> or <strong>) must stay in place. Translate only the text between them.'),
            Craft::t('csv-export', '5. Send the file back. It will be imported with Utilities → CSV Import, which only fills in translations and never overwrites the source language.'),
            '',
            Craft::t('csv-export', 'Sheets in this file:'),
        ];
        foreach ($sites as $site) {
            $lines[] = sprintf('%s — %s (%s)%s', self::sheetTitle($site), $site->name, $site->language, $site->id === $sourceSite->id ? ' — ' . Craft::t('csv-export', 'source language') : '');
        }

        // Also add the English version when the control panel isn't in English, for the translator
        if (!str_starts_with(Craft::$app->language, 'en')) {
            $lines[] = '';
            $lines[] = 'English:';
            $lines[] = 'This file contains one sheet per language. The sheet "' . self::sheetTitle($sourceSite) . '" holds the source texts. Open the sheet of the language you are translating into and replace the texts with your translation. Leave the "id" column and the header row untouched. Do not add, remove or reorder columns or rows. Empty cells are ignored on import. HTML tags must stay in place; translate only the text between them.';
        }

        foreach ($lines as $i => $line) {
            $sheet->setCellValueExplicit([1, $i + 1], $line, DataType::TYPE_STRING);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getColumnDimension('A')->setWidth(120);
        $sheet->getStyle([1, 1, 1, count($lines)])->getAlignment()->setWrapText(true);
    }
}
