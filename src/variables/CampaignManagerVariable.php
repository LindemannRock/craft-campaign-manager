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
use craft\ckeditor\web\assets\ckeditor\CkeditorAsset;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\i18n\Locale;
use craft\web\View;
use Illuminate\Support\Collection;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\services\AnalyticsService;
use lindemannrock\campaignmanager\services\CampaignsService;
use lindemannrock\campaignmanager\services\RecipientsService;
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
     * Get the recipients service
     */
    public function getRecipients(): RecipientsService
    {
        return CampaignManager::$plugin->recipients;
    }

    /**
     * Get the SMS service
     */
    public function getSms(): SmsService
    {
        return CampaignManager::$plugin->sms;
    }

    /**
     * Get the analytics service
     *
     * @since 5.1.0
     */
    public function getAnalytics(): AnalyticsService
    {
        return CampaignManager::$plugin->analytics;
    }

    /**
     * Render CKEditor HTML for message editing
     */
    public function ckeEditorHtml(mixed $value, string $name, string $handle, ?ElementInterface $element = null): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(CkeditorAsset::class);

        /** @var object|null $ckeConfig */
        $ckeConfig = null;
        if (class_exists(\craft\ckeditor\CkeConfig::class)) {
            /** @var object $ckeConfig */
            $ckeConfig = new \craft\ckeditor\CkeConfig();
        }

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
                        ], $this->getCkeConfigHeadingLevels($ckeConfig)),
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

        if ($this->getCkeConfigOptions($ckeConfig) !== null) {
            // translate the placeholder text
            $configOptions = $this->getCkeConfigOptions($ckeConfig);
            if (isset($configOptions['placeholder']) && is_string($configOptions['placeholder'])) {
                $configOptions['placeholder'] = Craft::t('site', $configOptions['placeholder']);
            }

            $configOptionsJs = Json::encode($configOptions);
        } elseif (($configJs = $this->getCkeConfigJs($ckeConfig)) !== null) {
            $configOptionsJs = <<<JS
(() => {
  $configJs
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

        if (($configCss = $this->getCkeConfigCss($ckeConfig)) !== null && $configCss !== '') {
            $view->registerCss($configCss);
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

    /**
     * @param object|null $ckeConfig
     * @return list<int>
     */
    private function getCkeConfigHeadingLevels(?object $ckeConfig): array
    {
        if ($ckeConfig === null || !property_exists($ckeConfig, 'headingLevels') || !is_array($ckeConfig->headingLevels)) {
            return [];
        }

        return array_values(array_filter($ckeConfig->headingLevels, static fn(mixed $level): bool => is_int($level)));
    }

    /**
     * @param object|null $ckeConfig
     * @return array<string, mixed>|null
     */
    private function getCkeConfigOptions(?object $ckeConfig): ?array
    {
        if ($ckeConfig === null || !property_exists($ckeConfig, 'options') || !is_array($ckeConfig->options)) {
            return null;
        }

        /** @var array<string, mixed> $options */
        $options = $ckeConfig->options;

        return $options;
    }

    private function getCkeConfigJs(?object $ckeConfig): ?string
    {
        if ($ckeConfig === null || !property_exists($ckeConfig, 'js') || !is_string($ckeConfig->js) || $ckeConfig->js === '') {
            return null;
        }

        return $ckeConfig->js;
    }

    private function getCkeConfigCss(?object $ckeConfig): ?string
    {
        if ($ckeConfig === null || !property_exists($ckeConfig, 'css') || !is_string($ckeConfig->css) || $ckeConfig->css === '') {
            return null;
        }

        return $ckeConfig->css;
    }
}
