<?php
class Unitpay_Unitpay_Model_Payment extends Mage_Payment_Model_Method_Abstract
{
    protected $_isGateway               = true;
    protected $_canUseInternal          = false;
    protected $_canUseForMultishipping  = false;

    protected $_code = 'unitpay';

    private $_order;
    private $_ipnData = array();
    private $_ipnValidator;

    /**
     * Возвращает URL для перенаправления покупателя после создания заказа.
     *
     * @access public
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('unitpay/index/redirect', array('_secure' => true));
    }

    /**
     * Generate form
     **/
    public function generateForm()
    {
        $domain = Mage::getStoreConfig('payment/unitpay/unitpay_domain');

		//$quote = Mage::getModel("checkout/session")->getQuote();
		
        $order = $this->getOrder();
		$orderItems = $order->getAllItems();
        $sum = number_format($order->getGrandTotal(), 2, '.', '');
        $account = $order->getIncrementId();
        $desc = 'Оплата по заказу №' . $order->getIncrementId();
		$currency = Mage::app()->getStore()->getCurrentCurrencyCode();
		$tax_info = $order->getFullTaxInfo();

		$signature = hash('sha256', join('{up}', array(
            $account,
            $currency,
            $desc,
            $sum,
            Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/unitpay/unitpay_secret_key'))
        )));
		
		
		//$store = Mage::app()->getStore('default');
		//$taxCalculation = Mage::getModel('tax/calculation');

		$items = array();
		
		foreach ($order->getAllItems() as $item) {
			//$taxPrice = $item->getTaxAmount();
			$product = Mage::getModel('catalog/product')->load($item->getProductId());

			//$taxClass = Mage::getModel('tax/class')->load($product->getTaxClassId())->getClassName();
			
			
			//$store = Mage::app()->getStore('default');
			//$request = Mage::getSingleton('tax/calculation')->getRateRequest(null, null, null, $store);
			//$percent = Mage::getSingleton('tax/calculation')->getRate($request->setProductClassId($product->getTaxClassId()));
			
			$items[] = array(
				'name'          => $item->getName(),
				'price'         => number_format($item->getPrice(), 2, '.', ''),
				//'price' => $item->getPrice(),
				'count'   => round($item->getQtyOrdered()),
				'type' => 'commodity',
				"nds" => $product->getTaxClassId() > 0 ? $this->getTaxRates($item->getData("tax_percent")) : "none",
				'currency' => $currency,
			);
		}
		
		$deliveryPrice = $order->getShippingAmount();
		
		if($deliveryPrice > 0) {
			//$taxRateId = Mage::getStoreConfig('tax/classes/shipping_tax_class', $store);
			//$percent = $taxCalculation->getRate($request->setProductClassId($taxRateId));
			
			$items[] = array(
				'name'          => "Доставка",
				'price'         => number_format($deliveryPrice, 2, '.', ''),
				//'price' => $deliveryPrice,
				'count'   => 1,
				'type' => 'service',
				//'nds' => $this->getTaxRates($percent),
				//'nds' => isset($tax_info[0]["percent"]) ? $this->getTaxRates($tax_info[0]["percent"]) : "none",
				'currency' => $currency,
			);
		}
		
		//var_dump($currency);
		//var_dump($sum);
		//print_r($items);die();
		
		$cashItems = base64_encode(json_encode($items));
		
        $form = '';
        $form .=
            '<form id="unitpay" action="https://' . $domain . '/pay/' . Mage::getStoreConfig('payment/unitpay/unitpay_public_key') . '" method="POST" id="unitpay_form">'.
            '<input type="hidden" name="sum" value="' . $sum . '" />'.
            '<input type="hidden" name="account" value="' . $account . '" />'.
            '<input type="hidden" name="desc" value="' . $desc . '" />'.
			'<input type="hidden" name="currency" value="' . $currency . '" />'.
			'<input type="hidden" name="signature" value="' . $signature . '" />'.
			'<input type="hidden" name="customerPhone" value="' . preg_replace('/\D/', '', $order->getShippingAddress()->getTelephone()) . '" />'.
			'<input type="hidden" name="customerEmail" value="' . $order->getCustomerEmail() . '" />'.
			'<input type="hidden" name="cashItems" value="' . $cashItems . '" />'.
            '<input type="submit" />' .
            '</form>';
        $form .= '<script type="text/javascript"> document.forms.unitpay.submit(); </script>';
        return $form;

    }

	public function getTaxRates($rate){
        switch (intval($rate)){
            case 10:
                $vat = 'vat10';
                break;
            case 20:
                $vat = 'vat20';
                break;
            case 0:
                $vat = 'vat0';
                break;
            default:
                $vat = 'none';
        }

        return $vat;
    }
	
    /**
     * Возвращает текущий заказ.
     *
     * @access public
     * @return mixed
     */
    public function getOrder()
    {
        if (!$this->_order) {
            $session = Mage::getSingleton('checkout/session');
            $this->_order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
        }
        return $this->_order;
    }

    /**
     * Проверяет сигнатуру
     * @param $params - параметры запроса
     * @param $method - метод запроса
     * @param $secret - секретный ключ
     * @return bool
     */
    function verifySignature($params, $method, $secret)
    {
        return $params['signature'] == $this->getSignature($method, $params, $secret);
    }

    /**
     * Вычисляет сигнатуру по методу параметрам и секретному ключу
     * @param $method - метод запроса
     * @param array $params - параметры запроса
     * @param $secretKey - секретный ключ
     * @return string
     */
    function getSignature($method, array $params, $secretKey)
    {
        ksort($params);
        unset($params['sign']);
        unset($params['signature']);
        array_push($params, $secretKey);
        array_unshift($params, $method);
        return hash('sha256', join('{up}', $params));
    }

    /**
     * Функция обработки запроса check
     * @param $params - параметры запроса
     * @return array
     */
    function check( $params )
    {

        $order =  Mage::getModel('sales/order')->loadByIncrementId( $params['account'] );

        if (!$order->getId()){
            $result = array('error' =>
                array('message' => 'заказа не существует')
            );
        }else{

            $sum = number_format($order->getGrandTotal(), 2, '.','');
            $currency = $order->getOrderCurrencyCode();

            if ((float) $sum != (float) number_format($params['orderSum'], 2, '.','')) {
                $result = array('error' =>
                    array('message' => 'не совпадает сумма заказа')
                );
            }elseif ($currency != $params['orderCurrency']) {
                $result = array('error' =>
                    array('message' => 'не совпадает валюта заказа')
                );
            }
            else{
                $result = array('result' =>
                    array('message' => 'Запрос успешно обработан')
                );
            }
        }

        return $result;
    }

    /**
     * Функция обработки запроса pay
     * @param $params - параметры запроса
     * @return array
     */
    function payment( $params )
    {

        $order =  Mage::getModel('sales/order')->loadByIncrementId( $params['account'] );

        if (!$order->getId()){
            $result = array('error' =>
                array('message' => 'заказа не существует')
            );
        }else{

            $sum = number_format($order->getGrandTotal(), 2, '.','');
            $currency = $order->getOrderCurrencyCode();

            if ((float) $sum != (float) number_format($params['orderSum'], 2, '.','')) {
                $result = array('error' =>
                    array('message' => 'не совпадает сумма заказа')
                );
            }elseif ($currency != $params['orderCurrency']) {
                $result = array('error' =>
                    array('message' => 'не совпадает валюта заказа')
                );
            }
            else{

                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, Mage_Sales_Model_Order::STATE_PROCESSING, 'Unitpay payment complete');
                $order->save();

                $result = array('result' =>
                    array('message' => 'Запрос успешно обработан')
                );
            }
        }

        return $result;
    }

    /**
     * Функция обработки запроса error
     * @param $params - параметры запроса
     * @return array
     */
    function error( $params )
    {
        $order =  Mage::getModel('sales/order')->loadByIncrementId( $params['account'] );

        if (!$order->getId()){
            $result = array('error' =>
                array('message' => 'заказа не существует')
            );
        }else{
            $order->cancel()->save();
            $result = array('result' =>
                array('message' => 'Запрос успешно обработан')
            );
        }
        return $result;
    }

}
