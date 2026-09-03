<?php
/**
 * CSV Export plugin for Craft CMS 5.x
 *
 * Export entries to a flat, Excel-friendly CSV file.
 *
 * @link      https://github.com/Manonkindt/craft-csv-export
 * @copyright Copyright (c) 2026 Manon Kindt
 * @license   https://craftcms.github.io/license/ Craft License
 */

namespace manonkindt\csvexport;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementExportersEvent;
use craft\services\Utilities;
use manonkindt\csvexport\exporters\FlatCsvExporter;
use manonkindt\csvexport\models\Settings;
use manonkindt\csvexport\services\Export;
use manonkindt\csvexport\utilities\CsvExportUtility;
use yii\base\Event;

/**
 * @property-read Export $export
 * @property-read Settings $settings
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = false;

    public static function config(): array
    {
        return [
            'components' => [
                'export' => Export::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'manonkindt\\csvexport\\console\\controllers';
        } else {
            $this->controllerNamespace = 'manonkindt\\csvexport\\controllers';
        }

        // Add our exporter to the "Export…" dialog on the entries index
        Event::on(
            Entry::class,
            Element::EVENT_REGISTER_EXPORTERS,
            static function(RegisterElementExportersEvent $event) {
                $event->exporters[] = FlatCsvExporter::class;
            }
        );

        // Register the utility (Utilities → CSV Export)
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = CsvExportUtility::class;
            }
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('csv-export/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }
}
