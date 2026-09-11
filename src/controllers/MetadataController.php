<?php

namespace carlcs\assetmetadata\controllers;

use carlcs\assetmetadata\fields\AssetMetadata;
use carlcs\assetmetadata\Plugin;
use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Control panel actions for the Asset Metadata field.
 *
 * All actions require a logged-in control panel user (the default for `craft\web\Controller`), a POST
 * request with a valid CSRF token, and permission to view the asset in question.
 */
class MetadataController extends Controller
{
    /**
     * Extracts the metadata of an asset and returns the rendered subfield values of a field, keyed by
     * subfield ID. Used by the field’s “Refresh” button.
     */
    public function actionGetFieldValue(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $fieldId = (int)$request->getRequiredBodyParam('fieldId');
        $siteId = $request->getBodyParam('siteId');

        $asset = Craft::$app->getElements()->getElementById($elementId, Asset::class, $siteId ? (int)$siteId : null);

        if (!$asset instanceof Asset) {
            throw new BadRequestHttpException("No asset exists with the ID $elementId.");
        }

        if (!$this->canViewAsset($asset)) {
            throw new ForbiddenHttpException('User is not permitted to view this asset.');
        }

        $field = Craft::$app->getFields()->getFieldById($fieldId);

        if (!$field instanceof AssetMetadata) {
            throw new BadRequestHttpException("No Asset Metadata field exists with the ID $fieldId.");
        }

        $value = Plugin::getInstance()->getMetadata()->getFieldValue($field, $asset);

        if ($value === null) {
            return $this->asFailure(Craft::t('asset-metadata', 'The asset’s file could not be analyzed.'));
        }

        return $this->asJson([
            'value' => $value,
        ]);
    }

    /**
     * Returns whether the current user may view the asset.
     *
     * `Elements::canView()` (which also fires the authorization events plugins can hook into) exists since
     * Craft 4.3; older Craft 4 releases only offer the element-level check.
     */
    private function canViewAsset(Asset $asset): bool
    {
        $elements = Craft::$app->getElements();

        if (method_exists($elements, 'canView')) {
            return $elements->canView($asset);
        }

        $user = Craft::$app->getUser()->getIdentity();

        return $user !== null && $asset->canView($user);
    }
}
