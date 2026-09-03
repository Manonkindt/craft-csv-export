<?php

namespace manonkindt\csvexport\utilities;

use Craft;
use craft\base\Utility;
use craft\models\Section;
use manonkindt\csvexport\Plugin;

class CsvExportUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('csv-export', 'CSV Export');
    }

    public static function id(): string
    {
        return 'csv-export';
    }

    public static function icon(): ?string
    {
        return 'file-csv';
    }

    public static function contentHtml(): string
    {
        $sections = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            /** @var Section $section */
            $sections[] = ['label' => Craft::t('site', $section->name), 'value' => $section->handle];
        }

        $sites = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $sites[] = ['label' => Craft::t('site', $site->name), 'value' => $site->handle];
        }

        $settings = Plugin::getInstance()->getSettings();

        return Craft::$app->getView()->renderTemplate('csv-export/_utility.twig', [
            'sections' => $sections,
            'sites' => $sites,
            'settings' => $settings,
        ]);
    }
}
