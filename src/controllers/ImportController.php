<?php

namespace manonkindt\csvexport\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use manonkindt\csvexport\Plugin;
use manonkindt\csvexport\utilities\CsvImportUtility;
use Throwable;
use yii\web\Response;

/**
 * Two-step translation import: preview (nothing saved) → apply.
 */
class ImportController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        $this->requirePermission('utility:' . CsvImportUtility::id());

        return parent::beforeAction($action);
    }

    /**
     * Reads the uploaded file and shows what would change.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();

        $utilityUrl = UrlHelper::cpUrl('utilities/' . CsvImportUtility::id());
        $file = UploadedFile::getInstanceByName('file');

        if (!$file || $file->getHasError()) {
            $this->setFailFlash(Craft::t('csv-export', 'Please choose an .xlsx or .csv file.'));
            return $this->redirect($utilityUrl);
        }

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            $this->setFailFlash(Craft::t('csv-export', 'Only .xlsx and .csv files are supported.'));
            return $this->redirect($utilityUrl);
        }

        $sourceSiteId = (int)$this->request->getBodyParam('sourceSiteId') ?: Craft::$app->getSites()->getPrimarySite()->id;
        $fallbackSiteId = (int)$this->request->getBodyParam('fallbackSiteId') ?: null;
        $overwrite = (bool)$this->request->getBodyParam('overwrite', false);

        $tmpPath = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'csv-import-' . uniqid('', true) . '.' . $ext;

        try {
            if (!$file->saveAs($tmpPath)) {
                throw new \RuntimeException('Could not store the uploaded file.');
            }
            $import = Plugin::getInstance()->import;
            $plan = $import->plan($tmpPath, $file->name, $sourceSiteId, $fallbackSiteId, $overwrite);
            $import->savePlan($plan);
        } catch (Throwable $e) {
            Craft::error('CSV Export import failed: ' . $e->getMessage(), __METHOD__);
            $this->setFailFlash(Craft::t('csv-export', 'The file could not be read: {error}', ['error' => $e->getMessage()]));
            return $this->redirect($utilityUrl);
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        $totalChanges = array_sum(array_map(static fn(array $s) => count($s['changes']), $plan['sites']));

        return $this->renderTemplate('csv-export/_import/preview.twig', [
            'plan' => $plan,
            'totalChanges' => $totalChanges,
            'sourceSite' => Craft::$app->getSites()->getSiteById($sourceSiteId),
            'utilityUrl' => $utilityUrl,
            'previewLimit' => 150,
        ]);
    }

    /**
     * Applies a previously previewed plan.
     */
    public function actionApply(): Response
    {
        $this->requirePostRequest();

        $utilityUrl = UrlHelper::cpUrl('utilities/' . CsvImportUtility::id());
        $token = (string)$this->request->getRequiredBodyParam('token');
        $import = Plugin::getInstance()->import;

        $plan = $import->loadPlan($token);
        if (!$plan) {
            $this->setFailFlash(Craft::t('csv-export', 'This preview has expired. Please upload the file again.'));
            return $this->redirect($utilityUrl);
        }

        $result = $import->apply($plan);
        $import->deletePlan($token);

        if (!$result['errors']) {
            $this->setSuccessFlash(Craft::t('csv-export', 'Translations imported: {cells} texts in {saved} elements.', [
                'cells' => $result['cells'],
                'saved' => $result['saved'],
            ]));
        }

        return $this->renderTemplate('csv-export/_import/result.twig', [
            'plan' => $plan,
            'result' => $result,
            'utilityUrl' => $utilityUrl,
        ]);
    }
}
