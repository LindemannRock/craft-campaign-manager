<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use craft\web\Response;
use lindemannrock\campaignmanager\controllers\ActivityLogsController;
use yii\web\Response as YiiResponse;

/**
 * Activity logs controller that hands back the template and variables of a
 * page instead of rendering the full control panel layout.
 *
 * @since 5.16.0
 */
final class CapturingActivityLogsController extends ActivityLogsController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): YiiResponse
    {
        $response = new Response();
        $response->data = compact('template', 'variables');

        return $response;
    }
}
