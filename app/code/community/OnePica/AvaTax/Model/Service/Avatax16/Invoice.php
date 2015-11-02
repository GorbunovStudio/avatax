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

/**
 * The AvaTax16 Invoice model
 *
 * @category   OnePica
 * @package    OnePica_AvaTax
 * @author     OnePica Codemaster <codemaster@onepica.com>
 */
class OnePica_AvaTax_Model_Service_Avatax16_Invoice extends OnePica_AvaTax_Model_Service_Avatax16_Tax
{
    /**
     * An array of line numbers to product ids
     *
     * @var array
     */
    protected $_lineToItemId = array();

    /**
     * Save order in AvaTax system
     *
     * @see OnePica_AvaTax_Model_Observer::salesOrderPlaceAfter()
     * @param Mage_Sales_Model_Order_Invoice $invoice
     * @param OnePica_AvaTax_Model_Records_Queue $queue
     * @return bool
     * @throws OnePica_AvaTax_Exception
     * @throws OnePica_AvaTax_Model_Service_Exception_Commitfailure
     * @throws OnePica_AvaTax_Model_Service_Exception_Unbalanced
     */
    public function invoice($invoice, $queue)
    {
        $order = $invoice->getOrder();
        $storeId = $order->getStoreId();
        $invoiceDate = $this->_convertGmtDate($invoice->getCreatedAt(), $storeId);

        $shippingAddress = ($order->getShippingAddress()) ? $order->getShippingAddress() : $order->getBillingAddress();
        if (!$shippingAddress) {
            throw new OnePica_AvaTax_Exception($this->__('There is no address attached to this order'));
        }

        // Set up document for request
        $this->_request = new OnePica_AvaTax16_Document_Request();

        // set up header
        $header = $this->_getRequestHeaderWithMainValues($storeId);
        $header->setDocumentCode($invoice->getIncrementId());
        $header->setTransactionDate($invoiceDate);
        $header->setTaxCalculationDate($this->_getDateModel()->date('Y-m-d'));
        $header->setDefaultLocations($this->_getHeaderDefaultLocations($shippingAddress));

        $this->_request->setHeader($header);

        $this->_addShipping($invoice);
        $items = $invoice->getItemsCollection();
        $this->_initProductCollection($items);
        $this->_initTaxClassCollection($invoice);
        //Added code for calculating tax for giftwrap items
        $this->_addGwOrderAmount($invoice);
        $this->_addGwItemsAmount($invoice);
        $this->_addGwPrintedCardAmount($invoice);

        foreach ($items as $item) {
            /** @var Mage_Sales_Model_Order_Invoice_Item $item */
            $this->_newLine($item);
        }
        $this->_setLinesToRequest();

        //send to AvaTax
        $result = $this->_send($order->getStoreId());

        //if successful
        if (!$result->getHasError()) {
            $message = $this->_getHelper()->__('Invoice #%s was saved to AvaTax', $result->getHeader()->getDocumentCode());
            $this->_addStatusHistoryComment($order, $message);

            $totalTax = $result->getCalculatedTaxSummary()->getTotalTax();
            if ($totalTax != $invoice->getBaseTaxAmount()) {
                throw new OnePica_AvaTax_Model_Service_Exception_Unbalanced(
                    'Collected: '. $invoice->getBaseTaxAmount() . ', Actual: ' . $totalTax
                );
            }

            //if not successful
        } else {
            $messages = print_r($result->getErrors(), true);
            throw new OnePica_AvaTax_Model_Service_Exception_Commitfailure($messages);
        }

        return true;
    }

    /**
     * Adds shipping cost to request as item
     *
     * @param Mage_Sales_Model_Order_Invoice|Mage_Sales_Model_Order_Creditmemo $object
     * @param bool $credit
     * @return int|bool
     */
    protected function _addShipping($object, $credit = false)
    {
        if ($object->getBaseShippingAmount() == 0) {
            return false;
        }

        $lineNumber = $this->_getNewLineCode();;
        $storeId = $object->getStore()->getId();
        $taxClass = Mage::helper('tax')->getShippingTaxClass($storeId);

        $amount = $object->getBaseShippingAmount();
        $amount = $credit ? (-1 * $amount) : $amount;

        $line = new OnePica_AvaTax16_Document_Request_Line();
        $line->setLineCode($lineNumber);
        $shippingSku = $this->_getConfigHelper()->getShippingSku($storeId);
        $line->setItemCode($shippingSku ? $shippingSku : self::DEFAULT_SHIPPING_ITEMS_SKU);
        $line->setItemDescription(self::DEFAULT_SHIPPING_ITEMS_DESCRIPTION);
        $line->setTaxCode($taxClass);
        $line->setNumberOfItems(1);
        $line->setlineAmount($amount);
        $line->setDiscounted('false');

        $this->_lineToItemId[$lineNumber] = $shippingSku;
        $this->_lines[$lineNumber] = $line;
        $this->_setLinesToRequest();
        return $lineNumber;
    }

    /**
     * Adds giftwraporder cost to request as item
     *
     * @param Mage_Sales_Model_Order_Invoice|Mage_Sales_Model_Order_Creditmemo $object
     * @param bool $credit
     * @return int|bool
     */
    protected function _addGwOrderAmount($object, $credit = false)
    {
        if ($object->getGwPrice() == 0) {
            return false;
        }

        $lineNumber = $this->_getNewLineCode();
        $storeId = $object->getStore()->getId();
        $amount = $object->getGwBasePrice();
        $amount = $credit ? (-1 * $amount) : $amount;

        $line = new OnePica_AvaTax16_Document_Request_Line();
        $line->setLineCode($lineNumber);
        $gwOrderSku = $this->_getConfigHelper()->getGwOrderSku($storeId);
        $line->setItemCode($gwOrderSku ? $gwOrderSku : self::DEFAULT_GW_ORDER_SKU);
        $line->setItemDescription(self::DEFAULT_GW_ORDER_DESCRIPTION);
        $line->setTaxCode($this->_getGiftTaxClassCode($storeId));
        $line->setNumberOfItems(1);
        $line->setlineAmount($amount);
        $line->setDiscounted('false');

        $this->_lineToItemId[$lineNumber] = $gwOrderSku;
        $this->_lines[$lineNumber] = $line;
        $this->_setLinesToRequest();
        return $lineNumber;
    }

    /**
     * Adds giftwrapitems cost to request as item
     *
     * @param Mage_Sales_Model_Order_Invoice|Mage_Sales_Model_Order_Creditmemo $object
     * @param bool $credit
     * @return int|bool
     */
    protected function _addGwItemsAmount($object, $credit = false)
    {
        if ($object->getGwItemsPrice() == 0) {
            return false;
        }

        $lineNumber = $this->_getNewLineCode();
        $storeId = $object->getStore()->getId();

        $amount = $object->getGwItemsBasePrice();
        $amount = $credit ? (-1 * $amount) : $amount;

        $line = new OnePica_AvaTax16_Document_Request_Line();
        $line->setLineCode($lineNumber);
        $gwItemsSku = $this->_getConfigHelper()->getGwItemsSku($storeId);
        $line->setItemCode($gwItemsSku ? $gwItemsSku : self::DEFAULT_GW_ITEMS_SKU);
        $line->setItemDescription(self::DEFAULT_GW_ITEMS_DESCRIPTION);
        $line->setTaxCode($this->_getGiftTaxClassCode($storeId));
        $line->setNumberOfItems(1);
        $line->setlineAmount($amount);
        $line->setDiscounted('false');

        $this->_lineToItemId[$lineNumber] = $gwItemsSku;
        $this->_lines[$lineNumber] = $line;
        $this->_setLinesToRequest();
        return $lineNumber;
    }

    /**
     * Adds giftwrap printed card cost to request as item
     *
     * @param Mage_Sales_Model_Order_Invoice|Mage_Sales_Model_Order_Creditmemo $object
     * @param bool $credit
     * @return int|bool
     */
    protected function _addGwPrintedCardAmount($object, $credit = false)
    {
        if (!$object->getGwPrintedCardBasePrice()) {
            return false;
        }

        $lineNumber = $this->_getNewLineCode();
        $storeId = $object->getStore()->getId();

        $amount = $object->getGwPrintedCardBasePrice();
        $amount = $credit ? (-1 * $amount) : $amount;

        $line = new OnePica_AvaTax16_Document_Request_Line();
        $line->setLineCode($lineNumber);
        $gwPrintedCardSku = $this->_getConfigHelper()->getGwPrintedCardSku($storeId);
        $line->setItemCode($gwPrintedCardSku ? $gwPrintedCardSku : self::DEFAULT_GW_PRINTED_CARD_SKU);
        $line->setItemDescription(self::DEFAULT_GW_PRINTED_CARD_DESCRIPTION);
        $line->setTaxCode($this->_getGiftTaxClassCode($storeId));
        $line->setNumberOfItems(1);
        $line->setlineAmount($amount);
        $line->setDiscounted('false');

        $this->_lineToItemId[$lineNumber] = $gwPrintedCardSku;
        $this->_lines[$lineNumber] = $line;
        $this->_setLinesToRequest();
        return $lineNumber;
    }

    /**
     * Makes a Line object from a product item object
     *
     * @param Mage_Sales_Model_Order_Invoice_Item|Mage_Sales_Model_Order_Creditmemo_Item $item
     * @param bool $credit
     * @return null|bool
     */
    protected function _newLine($item, $credit = false)
    {
        if ($this->isProductCalculated($item->getOrderItem())) {
            return false;
        }
        if ($item->getQty() == 0) {
            return false;
        }

        $lineNumber = $this->_getNewLineCode();
        $storeId = $this->_retrieveStoreIdFromItem($item);
        $product = $this->_getProductByProductId($item->getProductId());
        $taxClass = $this->_getTaxClassCodeByProduct($product);
        $price = $item->getBaseRowTotal() - $item->getBaseDiscountAmount();
        $price = $credit ? (-1 * $price) : $price;

        $line = new OnePica_AvaTax16_Document_Request_Line();
        $line->setLineCode($lineNumber);
        $line->setItemCode(substr($item->getSku(), 0, 50));
        $line->setItemDescription($item->getName());
        $line->setNumberOfItems($item->getQty());
        $line->setlineAmount($price);
        $line->setDiscounted($item->getBaseDiscountAmount() ? 'true' : 'false');

        if ($taxClass) {
            $line->setTaxCode($taxClass);
        }
        $ref1Value = $this->_getRefValueByProductAndNumber($product, 1, $storeId);
        if ($ref1Value) {
            $line->setRef1($ref1Value);
        }
        $ref2Value = $this->_getRefValueByProductAndNumber($product, 2, $storeId);
        if ($ref2Value) {
            $line->setRef2($ref2Value);
        }

        $this->_lineToItemId[$lineNumber] = $item->getOrderItemId();
        $this->_lines[$lineNumber] = $line;
    }

    /**
     * Retrieve store id from item
     *
     * @param Mage_Sales_Model_Order_Invoice_Item|Mage_Sales_Model_Order_Creditmemo_Item $item
     * @return int
     */
    protected function _retrieveStoreIdFromItem($item)
    {
        $storeId = null;
        if ($item instanceof Mage_Sales_Model_Order_Invoice_Item) {
            $storeId = $item->getInvoice()->getStoreId();
        } else {
            $storeId = $item->getCreditmemo()->getStoreId();
        }

        return $storeId;
    }

    /**
     * Retrieve converted date taking into account the current time zone and store.
     *
     * @param string $gmt
     * @param int    $storeId
     * @return string
     */
    protected function _convertGmtDate($gmt, $storeId)
    {
        $date = date('m-d-Y H:i:s', strtotime($gmt));
        return Mage::app()->getLocale()->storeDate($storeId, $date)->toString(self::SERVICE_DATE_FORMAT);
    }

    /**
     * Sends a request to the Avatax16 server
     *
     * @param int $storeId
     * @return mixed
     */
    protected function _send($storeId)
    {
        /** @var OnePica_AvaTax_Model_Service_Avatax16_Config $configModel */
        $configModel = $this->getService()->getServiceConfig()->init($storeId);
        $config = $configModel->getLibConfig();
        $connection = $configModel->getTaxConnection();
        $result = null;
        $message = null;

        try {
            $result = $connection->createTransaction($this->_request);
        } catch (Exception $exception) {
            $message = new Message();
            $message->setSummary($exception->getMessage());
        }

        if (!isset($result) || !is_object($result)) {
            $actualResult = $result;
            $result = new Varien_Object();
            $result->setHasError(true)
                ->setResultCode(self::RESPONSE_RESULT_CODE_EXCEPTION)
                ->setActualResult($actualResult)
                ->setMessages(array($message));
        }

        $this->_log(
            OnePica_AvaTax_Model_Source_Avatax16_Logtype::TRANSACTION,
            $this->_request,
            $result,
            $storeId,
            $config
        );

        if ($result->getHasError()) {
            if ($this->_getConfigHelper()->fullStopOnError($storeId)) {
                $this->_getErrorsHelper()->addErrorMessage($storeId);
            }
        } else {
            $this->_getErrorsHelper()->removeErrorMessage();
        }

        return $result;
    }

    /**
     * Adds a comment to order history. Method choosen based on Magento version.
     *
     * @param Mage_Sales_Model_Order $order
     * @param string $comment
     * @return self
     */
    protected function _addStatusHistoryComment($order, $comment)
    {
        if (method_exists($order, 'addStatusHistoryComment')) {
            $order->addStatusHistoryComment($comment)->save();
        } elseif (method_exists($order, 'addStatusToHistory')) {
            $order->addStatusToHistory($order->getStatus(), $comment, false)->save();;
        }
        return $this;
    }
}
