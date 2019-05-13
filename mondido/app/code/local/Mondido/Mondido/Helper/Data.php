<?php

class Mondido_Mondido_Helper_Data extends Mage_Core_Helper_Abstract
{
    /**
     * Return checkout session instance
     *
     * @return Mage_Checkout_Model_Session
     */
    protected function _getCheckoutSession()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Return sales quote instance for specified ID
     *
     * @param int $quoteId Quote identifier
     * @return Mage_Sales_Model_Quote
     */
    protected function _getQuote($quoteId)
    {
        return Mage::getModel('sales/quote')->load($quoteId);
    }

    public function restoreQuote()
    {

        $order = $this->_getCheckoutSession()->getLastRealOrder();
        if ($order->getId()) {
            $quote = $this->_getQuote($order->getQuoteId());
            if ($quote->getId()) {
                $quote->setIsActive(1)
                    ->setReservedOrderId(null)
                    ->save();
                $this->_getCheckoutSession()
                    ->replaceQuote($quote)
                    ->unsLastRealOrderId();
                return true;
            }
        }
        return false;
    }

    /**
     * Get Order Items
     * @param Mage_Sales_Model_Order $order
     * @return array
     */
    public function getOrderItems($order)
    {
        $lines = array();
        $items = $order->getAllVisibleItems();
        foreach ($items as $item) {
            /** @var Mage_Sales_Model_Order_Item $item */
            $itemQty = (int)$item->getQtyOrdered();
            $priceWithTax = $item->getRowTotalInclTax();
            $priceWithoutTax = $item->getRowTotal();
            $taxPercent = $priceWithoutTax > 0 ? (($priceWithTax / $priceWithoutTax) - 1) * 100 : 0;
            $taxPrice = $priceWithTax - $priceWithoutTax;

            $name = $item->getName();
            if (empty($name)) {
                $name = sprintf('Product %s', $item->getSku());
            }

            $lines[] = array(
                'artno' => $item->getSku(),
                'description' => $name,
                'amount' => number_format($priceWithTax, 2, '.', ''),
                'qty' => $itemQty,
                'vat' => number_format($taxPercent, 2, '.', ''),
                'discount' => '0.00'
            );
        }

        // add Shipping
        if (!$order->getIsVirtual()) {
            $shippingExclTax = $order->getShippingAmount();
            $shippingIncTax = $order->getShippingInclTax();
            $shippingTax = $shippingIncTax - $shippingExclTax;
            $shippingTaxRate = $shippingExclTax > 0 ? (($shippingIncTax / $shippingExclTax) - 1) * 100 : 0;

            $lines[] = array(
                'artno' => 'shipping',
                'description' => Mage::helper('sales')->__('Shipping'),
                'amount' => number_format($shippingIncTax, 2, '.', ''),
                'qty' => 1,
                'vat' => number_format($shippingTaxRate, 2, '.', ''),
                'discount' => '0.00'
            );
        }

        // add Discount
        $discount = -1 * ($order->getDiscountAmount() + $order->getShippingDiscountAmount());
        if (abs($discount) > 0) {
            $lines[] = array(
                'artno' => 'discount',
                'description' => Mage::helper('sales')->__('Discount (%s)', $order->getDiscountDescription()),
                'amount' => number_format($discount, 2, '.', ''),
                'qty' => 1,
                'vat' => 0,
                'discount' => '0.00'
            );
        }

        return $lines;
    }
}
