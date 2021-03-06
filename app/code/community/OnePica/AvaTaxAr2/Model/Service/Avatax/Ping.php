<?php
/**
 * OnePica_AvaTax
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0), a
 * copy of which is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   OnePica
 * @package    OnePica_AvaTax
 * @author     OnePica Codemaster <codemaster@onepica.com>
 * @copyright  Copyright (c) 2015 One Pica, Inc.
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

use Avalara\AvaTaxRestV2\SeverityLevel;

/**
 * The AvaTax Ping model
 *
 * @category   OnePica
 * @package    OnePica_AvaTax
 * @author     OnePica Codemaster <codemaster@onepica.com>
 */
class OnePica_AvaTaxAr2_Model_Service_Avatax_Ping extends OnePica_AvaTaxAr2_Model_Service_Avatax_Abstract
{
    /**
     * Tries to ping AvaTax service with provided credentials
     *
     * @param int $storeId
     * @return bool|array
     * @throws \Varien_Exception
     */
    public function ping($storeId = null)
    {
        /** @var OnePica_AvaTaxAr2_Model_Service_AvaTax_Config $config */
        $config = Mage::getModel('avataxar2/service_avatax_config');
        $client = $config->getClient();

        $pingResult = null;
        $message = null;
        $result = new Varien_Object();

        try {
            $pingResult = $client->ping();
            $result->setActualResult($pingResult);

            if (is_string($pingResult)) {
                Mage::throwException($this->_getExceptionMessage($pingResult));
            }

            if (!isset($pingResult) || !is_object($pingResult)) {
                Mage::throwException($this->_getExceptionMessage('Ping result is not set'));
            }

            if (!$pingResult->authenticated) {
                Mage::throwException($this->_getExceptionMessage('Not Authorized'));
            }

            $result->setResultCode(SeverityLevel::C_SUCCESS);
        } catch (Exception $exception) {
            $message = $exception->getMessage();
            $result->setMessage($message);
            $result->setResultCode(SeverityLevel::C_EXCEPTION);
        }

        return ($result->getResultCode() == SeverityLevel::C_SUCCESS) ? true : $result->getMessage();
    }

    /**
     * @param $message
     * @return string
     */
    protected function _getExceptionMessage($message)
    {
        return Mage::helper('avataxar2')->__('AvaTax Rest V2 Error: "%s"', $message);
    }
}
