<?php
/**
 * EnupalPaypal plugin for Craft CMS 3.x
 *
 * @link      https://enupal.com/
 * @copyright Copyright (c) 2018 Enupal
 */

namespace enupal\paypal\services;

use Craft;
use yii\base\Component;
use enupal\paypal\Paypal;
use enupal\paypal\elements\PaypalButton as ButtonElement;
use enupal\paypal\records\PaypalButton as PaypalButtonRecord;
use enupal\paypal\enums\PaypalType;

use yii\base\Exception;
use craft\models\MailSettings;
use craft\helpers\MailerHelper;

class Buttons extends Component
{
    protected $buttonRecord;

    /**
     * Constructor
     *
     * @param object $buttonRecord
     */
    public function __construct($buttonRecord = null)
    {
        $this->buttonRecord = $buttonRecord;

        if (is_null($this->buttonRecord)) {
            $this->buttonRecord = new PaypalButtonRecord();
        }
    }

    /**
     * Returns a PaypalButton model if one is found in the database by id
     *
     * @param int $id
     * @param int $siteId
     *
     * @return null|\craft\base\ElementInterface
     */
    public function getButtonById(int $id, int $siteId = null)
    {
        $query = ButtonElement::find();
        $query->id($id);
        $query->siteId($siteId);

        return $query->one();
    }

    /**
     * Returns a PaypalButton model if one is found in the database by handle
     *
     * @param string $handle
     * @param int $siteId
     *
     * @return null|\craft\base\ElementInterface
     */
    public function getButtonByHandle($handle, int $siteId = null)
    {
        $query = ButtonElement::find();
        $query->handle($handle);
        $query->siteId($siteId);

        return $query->one();
    }

    /**
     * Returns all Buttons
     *
     * @return null|ButtonElement[]
     */
    public function getAllButtons()
    {
        $query = ButtonElement::find();

        return $query->all();
    }

    /**
     * @param $button ButtonElement
     *
     * @throws \Exception
     * @return bool
     * @throws \Throwable
     */
    public function saveBackup(ButtonElement $button)
    {
        if ($button->id) {
            $buttonRecord = PaypalButtonRecord::findOne($button->id);

            if (!$buttonRecord) {
                throw new Exception(Paypal::t('No PaypalButton exists with the ID “{id}”', ['id' => $button->id]));
            }
        }

        if (!$button->validate()) {
            return false;
        }

        $transaction = Craft::$app->db->beginTransaction();

        try {
            if (Craft::$app->elements->saveElement($button)) {
                $transaction->commit();
            }
        } catch (\Exception $e) {
            $transaction->rollback();

            throw $e;
        }

        return true;
    }

    /**
     * Enupal PaypalButton send notification service
     *
     * @param $button ButtonElement
     */
    public function sendNotification(ButtonElement $button)
    {
        $settings = new MailSettings();
        $buttonSettings = Paypal::$app->settings->getSettings();
        $templatePath = 'enupal-paypal/_notification/email';
        $emailSettings = Craft::$app->getSystemSettings()->getSettings('email');

        $settings->fromEmail = $buttonSettings->notificationSenderEmail;
        $settings->fromName = $buttonSettings->notificationSenderName;
        $settings->template = $templatePath;
        $settings->transportType = $emailSettings['transportType'];
        $settings->transportSettings = $emailSettings['transportSettings'];

        $mailer = MailerHelper::createMailer($settings);

        $emails = explode(",", $buttonSettings->notificationRecipients);

        try {
            $emailSent = $mailer
                ->composeFromKey('enupal_paypal_notification', ['button' => $button])
                ->setTo($emails)
                ->send();
        } catch (\Throwable $e) {
            Craft::$app->getErrorHandler()->logException($e);
            $emailSent = false;
        }

        if ($emailSent) {
            Paypal::info('Notification Email sent successfully!');
        } else {
            Paypal::error('There was an error sending the Notification email');
        }

        return $emailSent;
    }

    /**
     * Removes a Paypal Button
     *
     * @param ButtonElement $button
     *
     * @throws \CDbException
     * @throws \Exception
     * @return boolean
     * @throws \Throwable
     */
    public function deleteBackup(ButtonElement $button)
    {
        $transaction = Craft::$app->db->beginTransaction();

        try {
            // Delete the Element and PaypalButton
            $success = Craft::$app->elements->deleteElementById($button->id);

            if (!$success) {
                $transaction->rollback();
                Paypal::error("Couldn’t delete Paypal Button");

                return false;
            }

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollback();

            throw $e;
        }

        return true;
    }

    /**
     * Generate a random string, using a cryptographically secure
     * pseudorandom number generator (random_int)
     *
     * For PHP 7, random_int is a PHP core function
     * For PHP 5.x, depends on https://github.com/paragonie/random_compat
     *
     * @param int    $length   How many characters do we want?
     * @param string $keyspace A string of all possible characters
     *                         to select from
     *
     * @return string
     * @throws \Exception
     */
    public function getRandomStr($length = 10, $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ')
    {
        $str = '';
        $max = mb_strlen($keyspace, '8bit') - 1;

        for ($i = 0; $i < $length; ++$i) {
            $str .= $keyspace[random_int(0, $max)];
        }
        return $str;
    }

    /**
     * @return array
     */
    public function getColorStatuses()
    {
        $colors = [
            PaypalType::PAY => 'green',
            PaypalType::DONATION => 'blue',
        ];

        return $colors;
    }

    /**
     * @return bool|string
     */
    public function getEnupalPaypalPath()
    {
        $defaultTemplate = Craft::getAlias('@enupal/paypal/templates/_frontend/');

        return $defaultTemplate;
    }

    /**
     * @param ButtonElement $button
     *
     * @return ButtonElement
     */
    public function populateButtonFromPost(ButtonElement $button)
    {
        $request = Craft::$app->getRequest();

        $postFields = $request->getBodyParam('fields');

        $button->setAttributes($postFields, false);

        return $button;
    }

    /**
     * @return array
     */
    public function getCurrencies()
    {
        $currencies = [];
        $currencies['AUD'] = 'Australian Dollar - AUD';
        $currencies['BRL'] = 'Brazilian Real - BRL';
        $currencies['CAD'] = 'Canadian Dollar - CAD';
        $currencies['CZK'] = 'Czech Koruna - CZK';
        $currencies['DKK'] = 'Danish Krone - DKK';
        $currencies['EUR'] = 'Euro - EUR';
        $currencies['HKD'] = 'Hong Kong Dollar - HKD';
        $currencies['HUF'] = 'Hungarian Forint - HUF';
        $currencies['ILS'] = 'Israeli New Sheqel - ILS';
        $currencies['JPY'] = 'Japanese Yen - JPY';
        $currencies['MYR'] = 'Malaysian Ringgit - MYR';
        $currencies['MXN'] = 'Mexican Peso - MXN';
        $currencies['NOK'] = 'Norwegian Krone - NOK';
        $currencies['NZD'] = 'New Zealand Dollar - NZD';
        $currencies['PHP'] = 'Philippine Peso - PHP';
        $currencies['PLN'] = 'Polish Zloty - PLN';
        $currencies['GBP'] = 'Pound Sterling - GBP';
        $currencies['RUB'] = 'Russian Ruble - RUB';
        $currencies['SGD'] = 'Singapore Dollar - SGD';
        $currencies['SEK'] = 'Swedish Krona - SEK';
        $currencies['CHF'] = 'Swiss Franc - CHF';
        $currencies['TWD'] = 'Taiwan New Dollar - TWD';
        $currencies['THB'] = 'Thai Baht - THB';
        $currencies['TRY'] = 'Turkish Lira - TRY';
        $currencies['USD'] = 'U.S. Dollar - USD';

        return $currencies;
    }

    /**
     * @param null $name
     * @param null $handle
     *
     * @return ButtonElement
     */
    public function createNewButton($name = null, $handle = null): ButtonElement
    {
        $button = new ButtonElement();
        $name = empty($name) ? 'Button' : $name;
        $handle = empty($handle) ? 'button' : $handle;

        $button->name = $this->getFieldAsNew('name', $name);
        $button->handle = $this->getFieldAsNew('handle', $handle);
        $button->slides = [];

        if ($this->saveSlider($button)) {
            $settings = $this->getSettings();
            $sources = null;

            if (isset($settings['volumeId'])) {
                $folder = (new Query())
                    ->select('*')
                    ->from(['{{%volumefolders}}'])
                    ->where(['volumeId' => $settings['volumeId']])
                    ->one();

                $defaultSubFolder = new VolumeFolderRecord();
                $defaultSubFolder->parentId = $folder['id'];
                $defaultSubFolder->volumeId = $settings['volumeId'];
                $defaultSubFolder->name = $button->handle;
                $defaultSubFolder->path = $button->handle."/";
                $defaultSubFolder->save();
            }
        }

        return $button;
    }

    /**
     * Create a secuencial string for the "name" and "handle" fields if they are already taken
     *
     * @param string
     * @param string
     *
     * @return null|string
     */
    public function getFieldAsNew($field, $value)
    {
        $newField = null;
        $i = 1;
        $band = true;
        do {
            $newField = $field == "handle" ? $value.$i : $value." ".$i;
            $slider = $this->getFieldValue($field, $newField);
            if (is_null($slider)) {
                $band = false;
            }

            $i++;
        } while ($band);

        return $newField;
    }

    /**
     * Returns the value of a given field
     *
     * @param string $field
     * @param string $value
     *
     * @return PaypalButtonRecord
     */
    public function getFieldValue($field, $value)
    {
        $result = PaypalButtonRecord::findOne([$field => $value]);

        return $result;
    }
}
