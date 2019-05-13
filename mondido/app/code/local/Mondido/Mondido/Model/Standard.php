<?php
/**
 * Our test CC module adapter
 */
class Mondido_Mondido_Model_Standard extends Mage_Payment_Model_Method_Abstract
{
    protected $_code  = 'mondido';
    protected $_isGateway = true;
    protected $_formBlockType = 'mondido/standard_form';
    protected $_infoBlockType = 'mondido/payment_info';
    protected $_isInitializeNeeded      = true;
    protected $_canUseInternal          = false;
    protected $_canUseForMultishipping  = false;
    protected $paymentAction  = 'Sale';

    /**
     * Config instance
     * @var Mage_Mondido_Model_Config
     */
    protected $_config = null;


    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('mondido/standard/redirect', array('_secure' => true));
    }

    public function initialize($paymentAction, $stateObject)
    {
        $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        $stateObject->setState($state);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
    }


    /**
     * Get mondido session namespace
     *
     * @return Mage_Mondido_Model_Session
     */
    public function getSession()
    {
        return Mage::getSingleton('mondido/session');
    }

    /**
     * Get checkout session namespace
     *
     * @return Mage_Checkout_Model_Session
     */
    public function getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Get current quote
     *
     * @return Mage_Sales_Model_Quote
     */
    public function getQuote()
    {
        return $this->getCheckout()->getQuote();
    }

    /**
     * Create main block for standard form
     *
     */
    public function createFormBlock($name)
    {
        $block = $this->getLayout()->createBlock('mondido/standard_form', $name)
            ->setMethod('mondido')
            ->setPayment($this->getPayment())
            ->setTemplate('mondido/standard/form.phtml');

        return $block;
    }

	public function getCheckoutFields(){
        $orderIncrementId = $this->getCheckout()->getLastRealOrderId();
        $data = $this->generate_mondido_data($orderIncrementId);
        return $data;
	}

    /*
	 * Generate mondido data
	 */
    public function generate_mondido_data($order_id, $callback = false, $status = "") {
        //Get Order Information
        $order = Mage::getModel('sales/order')->loadByIncrementId($order_id);

        $customer_id = $order->getCustomerId();
        $amount = $order->getGrandTotal();
        $amount = number_format($amount, 2, '.', '');
        $secret = trim(Mage::getStoreConfig('payment/mondido/merchant_secret',Mage::app()->getStore()));
        $merchant_id = trim(Mage::getStoreConfig('payment/mondido/merchant_id',Mage::app()->getStore()));
        $test = Mage::getStoreConfig('payment/mondido/test_mode',Mage::app()->getStore());
        $hash_algorithm = "md5";
        $currency = strtolower($order->getOrderCurrencyCode());

        // Generate hash
        $str = $merchant_id 
            . $order_id 
            . $customer_id 
            . $amount 
            . $currency
            . $status
            . (($test==1 and $status=="") ? "test" : "")
            . $secret;

        $hash = $hash_algorithm($str);
        $rh = $merchant_id 
            . $order_id
            . $customer_id
            . $amount 
            . $currency
            . $status
            . $secret;
            
        $redirect_hash = $hash_algorithm($rh);

        // Meta Data
        $metadata = array();

        // Order Data
        $metadata['order'] = $order->getData();

        $baddr = Mage::getModel('sales/order_address')->load($metadata["order"]["billing_address_id"]);
        $country = Mage::getModel('directory/country')->loadByCode($baddr->getCountry());

        $saddr =  Mage::getModel('sales/order_address')->load($metadata["order"]["shipping_address_id"]);
        // Customer Data
        $platform = array();
        $platform["type"] = "wocoomerce";
        $platform["version"] = Mage::getVersion();
        $platform["language_version"] = phpversion();
        $platform["plugin_version"] = '1.5.1';
        $metadata['platform'] = $platform;
 
        $metadata['customer'] = array(
            "id" => $customer_id,
            "guest" => $metadata["order"]["customer_is_guest"],
            "email" => $metadata["order"]["customer_email"],
            "firstname" => $metadata["order"]["customer_firstname"],
            "middlename" => $metadata["order"]["customer_middlename"],
            "country" => $country->getName(),
            "lastname" => $metadata["order"]["customer_lastname"],
            "gender" => $metadata["order"]["customer_gender"],
            "address" => array(
                "shipping" => $saddr->getData(),
                "billing" => $baddr->getData()
            )
        );

        // Products Data
        $vat_amount = 0;
        $prods = array();
        $orderItems = $order->getItemsCollection();
        foreach($orderItems as $sItem) {
            $nProduct = Mage::getModel('catalog/product')->load(
                $sItem->getProductId()
            );

            $store = Mage::app()->getStore('default');
            $taxCalculation = Mage::getModel('tax/calculation');
            $request = $taxCalculation->getRateRequest(null, null, null, $store);
            $taxClassId = $nProduct->getTaxClassId();
            $percent = $taxCalculation->getRate($request->setProductClassId($taxClassId));
            $prc = $percent / 100;

           $prod_arr = $nProduct->getData();
           $prod_arr['product_extra_description'] = '';
           $prod_arr['size_chart'] = '';

           $ws = '/\s+/';
           $ds = '/\\t|\\r|\\n]+|<[^>]*>/';
           $prod_arr['description'] = preg_replace($ds, '', $prod_arr['description']);
           $prod_arr['short_description'] = preg_replace($ds, '', $prod_arr['short_description']);
           $prod_arr['description'] = preg_replace($ws, ' ', $prod_arr['description']);
           $prod_arr['short_description'] = preg_replace($ws, ' ', $prod_arr['short_description']);

           $imageUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA) . 'catalog/product' . $nProduct->getImage();
           $prod_arr['image'] = $imageUrl;

           $_priceTax = Mage::helper('tax')->getPrice( $sItem, $sItem->getPrice());
           $_specialPriceIncTax = $_priceTax *  (1+ $prc);
           $qty = $sItem->getQtyOrdered();
           $tot_amount = $qty * $_specialPriceIncTax;
           $vat_amount = $vat_amount + $tot_amount;

           array_push($prods, $prod_arr);
        }

        $metadata['products'] = $prods;

        //Return Data
        return array(
            'merchant_id' => $merchant_id,
            'payment_ref' => $order_id,
            'customer_ref' => $customer_id,
            'amount' => $amount,
            'currency' => $currency,
            'secret' => $secret,
            'hash' => $hash,
            'test' => (($test == 1) ? "true" : "false"),
            'metadata' => $metadata,
            'redirect_hash' => $redirect_hash,
            'items' => Mage::helper('mondido')->getOrderItems($order),
            'vat_amount' => $vat_amount
        );
    }
}