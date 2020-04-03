<?php
/**
 * @category    Unitpay
 * @package     Unitpay_Unitpay
 * @copyright   Copyright (c) 2012 Unitpay
 * @license     BSD
 */
class Unitpay_Unitpay_IndexController extends Mage_Core_Controller_Front_Action
{
    /**
     * Делает перенаправление на сайт Unitpay для осуществления оплаты.
     *
     * @access public
     * @return void
     */
    public function redirectAction()
    {

        $model = Mage::getModel('unitpay/payment');
        echo $model->generateForm();

    }


    public function callbackAction()
    {

        header('Content-type:application/json;  charset=utf-8');

        $method = '';
        $params = [];

        $paymentModel = Mage::getModel('unitpay/payment');

        if ((isset($_GET['params'])) && (isset($_GET['method'])) && (isset($_GET['params']['signature']))){
            $params = $_GET['params'];
            $method = $_GET['method'];
            $signature = $params['signature'];


            if (empty($signature)){
                $status_sign = false;
            }else{
                $secret_key = Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/unitpay/unitpay_secret_key'));
                $status_sign = $paymentModel->verifySignature($params, $method, $secret_key);
            }
        }else{
            $status_sign = false;
        }

//    $status_sign = true;


        if ($status_sign){
            switch ($method) {
                case 'check':
                    $result = $paymentModel->check( $params );
                    break;
                case 'pay':
                    $result = $paymentModel->payment( $params );
                    break;
                case 'error':
                    $result = $paymentModel->error( $params );
                    break;
                default:
                    $result = array('error' =>
                        array('message' => 'неверный метод')
                    );
                    break;
            }
        }else{
            $result = array('error' =>
                array('message' => 'неверная сигнатура')
            );
        }

        echo json_encode($result);
        die();
    }

}
