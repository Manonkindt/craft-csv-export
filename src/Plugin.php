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
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\services\Utilities;
use craft\web\View;
use manonkindt\csvexport\exporters\FlatCsvExporter;
use manonkindt\csvexport\exporters\TranslationWorkbookExporter;
use manonkindt\csvexport\models\Settings;
use manonkindt\csvexport\services\Export;
use manonkindt\csvexport\services\Import;
use manonkindt\csvexport\utilities\CsvExportUtility;
use manonkindt\csvexport\utilities\CsvImportUtility;
use manonkindt\csvexport\web\assets\importbutton\ImportButtonAsset;
use yii\base\Event;

/**
 * @property-read Export $export
 * @property-read Import $import
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
                'import' => Import::class,
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
                $event->exporters[] = TranslationWorkbookExporter::class;
            }
        );

        // Register the utility (Utilities → CSV Export)
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = CsvExportUtility::class;
                $event->types[] = CsvImportUtility::class;
            }
        );

        // "Import translations…" button next to "Export…" on the entries index.
        // The JS decides whether the page is an entries index (CP URLs can be
        // rewritten by plugins such as CP Nav), so register on every CP page.
        $request = Craft::$app->getRequest();
        if ($request->getIsCpRequest() && !$request->getIsConsoleRequest() && !$request->getIsActionRequest()) {
            Event::on(
                View::class,
                View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
                static function() {
                    $user = Craft::$app->getUser()->getIdentity();
                    if (!$user || !$user->can('utility:' . CsvImportUtility::id())) {
                        return;
                    }
                    $view = Craft::$app->getView();
                    $view->registerAssetBundle(ImportButtonAsset::class);
                    $view->registerJs('window.csvExportImportButton = ' . Json::encode([
                        'url' => UrlHelper::cpUrl('utilities/' . CsvImportUtility::id()),
                        'label' => Craft::t('csv-export', 'Import translations…'),
                        'title' => Craft::t('csv-export', 'Import a translated Excel or CSV file'),
                    ]) . ';', View::POS_HEAD);
                }
            );
        }
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
