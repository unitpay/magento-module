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

        $order = $this->getOrder();
        $sum = number_format($order->getGrandTotal(), 2, '.', '');
        $account = $order->getIncrementId();
        $desc = 'Оплата по заказу №' . $order->getIncrementId();

        $form = '';
        $form .=
            '<form id="unitpay" action="https://unitpay.ru/pay/' . Mage::getStoreConfig('payment/unitpay/unitpay_public_key') . '" method="POST" id="unitpay_form">'.
            '<input type="hidden" name="sum" value="' . $sum . '" />'.
            '<input type="hidden" name="account" value="' . $account . '" />'.
            '<input type="hidden" name="desc" value="' . $desc . '" />'.
            '<input type="submit" />' .
            '</form>';
        $form .= '<script type="text/javascript"> document.forms.unitpay.submit(); </script>';
        return $form;

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

            if ((float)$sum != (float)$params['orderSum']) {
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

            if ((float)$sum != (float)$params['orderSum']) {
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
