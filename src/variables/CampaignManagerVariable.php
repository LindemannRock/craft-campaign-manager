<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\variables;

use Craft;
use craft\base\ElementInterface;
use craft\ckeditor\CkeConfig;
use craft\ckeditor\web\assets\ckeditor\CkeditorAsset;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\i18n\Locale;
use craft\web\View;
use Illuminate\Support\Collection;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\services\CampaignsService;
use lindemannrock\campaignmanager\services\CustomersService;
use lindemannrock\campaignmanager\services\SmsService;
use yii\base\Behavior;

/**
 * Campaign Manager Variable
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 */
class CampaignManagerVariable extends Behavior
{
    /**
     * Get the campaigns service
     */
    public function getCampaigns(): CampaignsService
    {
        return CampaignManager::$plugin->campaigns;
    }

    /**
     * Get the customers service
     */
    public function getCustomers(): CustomersService
    {
        return CampaignManager::$plugin->customers;
    }

    /**
     * Get the SMS service
     */
    public function getSms(): SmsService
    {
        return CampaignManager::$plugin->sms;
    }

    /**
     * Render CKEditor HTML for message editing
     */
    public function ckeEditorHtml(mixed $value, string $name, string $handle, ?ElementInterface $element = null): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(CkeditorAsset::class);

        $ckeConfig = new CkeConfig();

        // Toolbar cleanup
        $toolbar = ['bold'];
        $toolbar = array_values($toolbar);

        $id = Html::id($handle);
        $idJs = Json::encode($view->namespaceInputId($id));

        $baseConfig = [
            'defaultTransform' => null,
            'language' => $element?->getSite()->language ?? 'en',
            'elementSiteId' => $element?->siteId,
            'accessibleFieldName' => $name,
            'describedBy' => '',
            'findAndReplace' => [
                'uiType' => 'dropdown',
            ],
            'heading' => [
                'options' => [
                    ...array_map(fn(int $level) => [
                        'model' => "heading$level",
                        'view' => "h$level",
                        'title' => "Heading $level",
                        'class' => "ck-heading_heading$level",
                    ], $ckeConfig->headingLevels ?: []),
                ],
            ],
            'image' => [
                'toolbar' => [
                    'toggleImageCaption',
                    'imageTextAlternative',
                ],
            ],
            'assetSources' => [],
            'assetSelectionCriteria' => [],
            'linkOptions' => [],
            'table' => [
                'contentToolbar' => [
                    'tableRow',
                    'tableColumn',
                    'mergeTableCells',
                ],
            ],
            'transforms' => [],
            'ui' => [
                'viewportOffset' => ['top' => 50],
                'poweredBy' => [
                    'position' => 'inside',
                    'label' => '',
                ],
            ],
        ];

        if (isset($ckeConfig->options)) {
            // translate the placeholder text
            if (isset($ckeConfig->options['placeholder']) && is_string($ckeConfig->options['placeholder'])) {
                $ckeConfig->options['placeholder'] = Craft::t('site', $ckeConfig->options['placeholder']);
            }

            $configOptionsJs = Json::encode($ckeConfig->options);
        } elseif (isset($ckeConfig->js)) {
            $configOptionsJs = <<<JS
(() => {
  $ckeConfig->js
})()
JS;
        } else {
            $configOptionsJs = '{}';
        }

        $baseConfigJs = Json::encode($baseConfig);
        $toolbarJs = Json::encode($toolbar);
        $languageJs = Json::encode([
            'ui' => $element?->getSite()->getLocale()->getLanguageID(),
            'content' => $element?->getSite()->getLocale()->getLanguageID(),
            'textPartLanguage' => static::textPartLanguage(),
        ]);
        $showWordCountJs = Json::encode(false);
        $wordLimitJs = 0;

        $view->registerJs(<<<JS
((\$) => {
  const config = Object.assign($baseConfigJs, $configOptionsJs);
  if (!jQuery.isPlainObject(config.toolbar)) {
    config.toolbar = {};
  }
  config.toolbar.items = $toolbarJs;
  if (!jQuery.isPlainObject(config.language)) {
    config.language = {};
  }
  config.language = Object.assign($languageJs, config.language);
  const extraRemovePlugins = [];
  if ($showWordCountJs) {
    if (typeof config.wordCount === 'undefined') {
      config.wordCount = {};
    }
    const onUpdate = config.wordCount.onUpdate || (() => {});
    config.wordCount.onUpdate = (stats) => {
      const statText = [];
      if (config.wordCount.displayWords || typeof config.wordCount.displayWords === 'undefined') {
        statText.push(Craft.t('ckeditor', '{num, number} {num, plural, =1{word} other{words}}', {
          num: stats.words,
        }));
      }
      if (config.wordCount.displayCharacters) {
        statText.push(Craft.t('ckeditor', '{num, number} {num, plural, =1{character} other{characters}}', {
          num: stats.characters,
        }));
      }
      const container = \$('#' + $wordLimitJs);
      container.html(Craft.escapeHtml(statText.join(', ')) || '&nbsp;');
      if ($wordLimitJs) {
        if (stats.words > $wordLimitJs) {
          container.addClass('error');
        } else if (stats.words >= Math.floor($wordLimitJs * .9)) {
          container.addClass('warning');
        } else {
          container.removeClass('error warning');
        }
      }
      onUpdate(stats);
    }
  } else {
    extraRemovePlugins.push('WordCount');
  }
  if (extraRemovePlugins.length) {
    if (typeof config.removePlugins === 'undefined') {
      config.removePlugins = [];
    }
    config.removePlugins.push(...extraRemovePlugins);
  }
  CKEditor5.craftcms.create($idJs, config);
})(jQuery)
JS,
            View::POS_END,
        );

        if ($ckeConfig->css) {
            $view->registerCss($ckeConfig->css);
        }

        $html = Html::textarea($handle, $value, [
            'id' => $id,
            'class' => 'hidden',
        ]);

        return Html::tag('div', $html, [
            'class' => array_filter([
            ]),
        ]);
    }

    /**
     * Get text part language options
     *
     * @return array<int, array<string, mixed>>
     */
    public static function textPartLanguage(): array
    {
        return Collection::make(Craft::$app->getI18n()->getSiteLocales())
            ->map(fn(Locale $locale) => array_filter([
                'title' => $locale->getDisplayName(Craft::$app->language),
                'languageCode' => $locale->id,
                'textDirection' => $locale->getOrientation() === 'rtl' ? 'rtl' : null,
            ]))
            ->sortBy('title')
            ->values()
            ->all();
    }
}
