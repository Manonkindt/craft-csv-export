<?php

namespace manonkindt\csvexport\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use manonkindt\csvexport\Plugin;
use manonkindt\csvexport\utilities\CsvExportUtility;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class ExportController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        $this->requirePermission('utility:' . CsvExportUtility::id());

        return parent::beforeAction($action);
    }

    /**
     * Returns the custom fields of a section as JSON (used by the utility form).
     */
    public function actionFields(): Response
    {
        $this->requireAcceptsJson();
        $sectionHandle = $this->request->getRequiredParam('section');

        $fields = Plugin::getInstance()->export->fieldsForSection($sectionHandle);

        return $this->asJson([
            'fields' => array_map(
                static fn(string $handle, string $name) => ['handle' => $handle, 'name' => $name],
                array_keys($fields),
                $fields
            ),
        ]);
    }

    /**
     * Streams a CSV download for the requested section/site/status.
     */
    public function actionDownload(): Response
    {
        $this->requirePostRequest();

        $sectionHandle = $this->request->getRequiredBodyParam('section');
        $siteHandle = $this->request->getBodyParam('site') ?: Craft::$app->getSites()->getPrimarySite()->handle;
        $status = $this->request->getBodyParam('status') ?: null;
        $limit = (int)$this->request->getBodyParam('limit', 0);
        $fields = $this->request->getBodyParam('fields');
        $delimiter = $this->request->getBodyParam('delimiter');

        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        if (!$section) {
            throw new BadRequestHttpException("Unknown section: $sectionHandle");
        }

        $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        if (!$site) {
            throw new BadRequestHttpException("Unknown site: $siteHandle");
        }

        $query = Entry::find()
            ->section($section->handle)
            ->siteId($site->id)
            ->orderBy(['postDate' => SORT_DESC, 'id' => SORT_DESC]);

        // "all" = every status (incl. disabled/expired); otherwise the given status
        if ($status === 'all') {
            $query->status(null);
        } elseif ($status) {
            $query->status($status);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $fieldHandles = is_array($fields) && $fields ? array_values(array_filter($fields, 'is_string')) : null;

        $export = Plugin::getInstance()->export;
        [$columns, $rows] = $export->buildTable($query->all(), $fieldHandles);

        $settings = Plugin::getInstance()->getSettings();
        $delimiterChar = $delimiter !== null && $delimiter !== ''
            ? (in_array($delimiter, ['\t', 'tab'], true) ? "\t" : mb_substr($delimiter, 0, 1))
            : $settings->getDelimiterChar();

        $csv = $export->toCsv($columns, $rows, $delimiterChar);

        $filename = sprintf('%s_%s_%s.csv', $section->handle, $site->handle, date('Ymd_His'));

        return $this->response->sendContentAsFile($csv, $filename, [
            'mimeType' => 'text/csv',
        ]);
    }
}
