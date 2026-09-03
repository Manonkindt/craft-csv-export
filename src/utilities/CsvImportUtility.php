<?php

namespace manonkindt\csvexport\utilities;

use Craft;
use craft\base\Utility;

class CsvImportUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('csv-export', 'CSV Import');
    }

    public static function id(): string
    {
        return 'csv-import';
    }

    public static function icon(): ?string
    {
        return 'file-import';
    }

    public static function contentHtml(): string
    {
        $sites = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $sites[] = [
                'label' => sprintf('%s (%s)', Craft::t('site', $site->name), $site->language),
                'value' => $site->id,
            ];
        }

        return Craft::$app->getView()->renderTemplate('csv-export/_import/index.twig', [
            'sites' => $sites,
            'primarySiteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'multiSite' => Craft::$app->getIsMultiSite(),
        ]);
    }
}
