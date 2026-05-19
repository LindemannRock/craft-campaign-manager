<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\records;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\DateTimeHelper;
use craft\models\Site;
use DateInterval;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\helpers\PhoneHelper;
use lindemannrock\campaignmanager\helpers\TimeHelper;
use verbb\formie\elements\Submission;

/**
 * Recipient Record
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 *
 * @property int $id
 * @property int $campaignId
 * @property int $siteId
 * @property string|null $name
 * @property string|null $email
 * @property string|null $emailInvitationCode
 * @property \DateTime|null $emailSendDate
 * @property \DateTime|null $emailOpenDate
 * @property string|null $sms
 * @property string|null $smsInvitationCode
 * @property \DateTime|null $smsSendDate
 * @property \DateTime|null $smsOpenDate
 * @property int|null $submissionId
 * @property \DateTime|null $invitationExpiryDate
 * @property \DateTime|null $dateCreated
 * @property \DateTime|null $dateUpdated
 */
class RecipientRecord extends BaseRecord
{
    /**
     * @var Submission|null The loaded Formie submission (not persisted to DB)
     * @since 5.1.0
     */
    public ?Submission $submission = null;

    public const TABLE_NAME = 'campaignmanager_recipients';

    private ?ElementInterface $_campaign = null;

    /**
     * @var string[]
     */
    protected array $dateTimeAttributes = [
        'emailSendDate',
        'emailOpenDate',
        'smsSendDate',
        'smsOpenDate',
        'invitationExpiryDate',
    ];

    /**
     * @inheritdoc
     * @return array<array<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            [['campaignId', 'siteId'], 'required'],
            [['campaignId', 'siteId', 'submissionId'], 'integer'],
            [['name', 'email', 'sms'], 'string', 'max' => 255],
            ['email', 'email', 'skipOnEmpty' => true],
            ['sms', 'validatePhone', 'skipOnEmpty' => true],
        ]);
    }

    /**
     * Validate the phone number using libphonenumber
     */
    public function validatePhone(string $attribute): void
    {
        $value = $this->$attribute;
        if ($value === null || $value === '') {
            return;
        }

        $result = PhoneHelper::validate($value);
        if (!$result['valid']) {
            $this->addError($attribute, $result['error'] ?? 'Invalid phone number');
        }
    }

    /**
     * @inheritdoc
     */
    public function beforeSave($insert): bool
    {
        // Format phone number to E.164 before saving
        if ($this->sms !== null && $this->sms !== '') {
            $e164 = PhoneHelper::toE164($this->sms);
            if ($e164 !== null) {
                $this->sms = $e164;
            }
        }
        $invitationCode = CampaignManager::$plugin->recipients->getUniqueInvitationCode();

        if (empty($this->emailInvitationCode)) {
            $this->emailInvitationCode = $invitationCode;
        }

        if (empty($this->smsInvitationCode)) {
            $this->smsInvitationCode = $invitationCode;
        }

        if ($this->getIsNewRecord() && empty($this->invitationExpiryDate)) {
            $campaign = $this->getCampaign();
            // Direct property access — the getter lives on CampaignBehavior, which
            // `method_exists()` doesn't see (behavior methods are dispatched via
            // __call). The Campaign element exposes `invitationExpiryPeriod` as a
            // public property hydrated by CampaignQuery.
            $expiryPeriod = $campaign instanceof Campaign ? $campaign->invitationExpiryPeriod : null;
            if ($expiryPeriod) {
                try {
                    $expiryDate = new DateInterval($expiryPeriod);
                    $this->invitationExpiryDate = TimeHelper::fromNow($expiryDate);
                } catch (\Exception $e) {
                    // Malformed period (legacy data, direct DB write, etc.) would
                    // otherwise crash recipient creation. Save without expiry and
                    // log so the bad campaign value can be corrected.
                    Craft::warning(sprintf(
                        'Invalid invitation expiry period; recipient saved with no expiry: campaignId=%s, recipientId=%s, period=%s, error=%s',
                        $this->campaignId,
                        $this->id ?? 'new',
                        $expiryPeriod,
                        $e->getMessage(),
                    ), CampaignManager::$plugin->id);
                }
            }
        }

        return parent::beforeSave($insert);
    }

    /**
     * Get the campaign element
     */
    public function getCampaign(): ?ElementInterface
    {
        if (!isset($this->_campaign)) {
            $this->_campaign = Craft::$app->elements->getElementById($this->campaignId, null, $this->siteId);
        }

        return $this->_campaign;
    }

    /**
     * Get the site
     */
    public function getSite(): ?Site
    {
        return Craft::$app->sites->getSiteById($this->siteId);
    }

    /**
     * Check if this recipient has a submission
     */
    public function hasSubmission(): bool
    {
        return (bool)$this->submissionId;
    }

    /**
     * Check if the invitation has expired
     */
    public function invitationIsExpired(): bool
    {
        if (!$this->invitationExpiryDate) {
            return false;
        }

        return $this->invitationExpiryDate < DateTimeHelper::now();
    }

    /**
     * Find all recipients with outstanding email invitations
     *
     * @return array<static>
     */
    public static function findAllWithOutstandingEmailInvitation(): array
    {
        /** @var array<static> $results */
        $results = static::find()
            ->where(['emailSendDate' => null])
            ->all();

        return $results;
    }

    /**
     * Find all recipients with outstanding SMS invitations
     *
     * @return array<static>
     */
    public static function findAllWithOutstandingSmsInvitation(): array
    {
        /** @var array<static> $results */
        $results = static::find()
            ->where(['smsSendDate' => null])
            ->all();

        return $results;
    }
}
