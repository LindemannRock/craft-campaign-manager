<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use lindemannrock\campaignmanager\services\RecipientsService;

/**
 * Recipients service whose invitation codes come from a scripted list, so a
 * test can decide which codes a recipient is offered.
 *
 * @since 5.16.0
 */
final class ScriptedCodeRecipientsService extends RecipientsService
{
    /**
     * @param list<string> $codes Codes to hand out in order; the real generator takes over once they are used up
     */
    public function __construct(
        private array $codes,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    public function getUniqueInvitationCode(): string
    {
        $code = array_shift($this->codes);

        return $code ?? parent::getUniqueInvitationCode();
    }

    /**
     * @return list<string>
     */
    public function remainingCodes(): array
    {
        return $this->codes;
    }
}
