<?php
/**
 *
 * @category   Mage
 * @package    Liftmode_NetCents
 * @copyright  Copyright (c)  Dmitry Bashlov, contributors
 */

class Liftmode_NetCents_Model_Method_NetCents extends Mage_Payment_Model_Method_Cc
{
    const PAYMENT_METHOD_ECOREPAY_CODE = 'netcents';

    protected $_code = self::PAYMENT_METHOD_ECOREPAY_CODE;

    protected $_isGateway                   = true;
    protected $_canOrder                    = true;
    protected $_canAuthorize                = true;
    protected $_canCapture                  = true;
    protected $_canRefund                   = true;


    /**
     * Authorize payment abstract method
     *
     * @param Varien_Object $payment
     * @param float $amount
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        if ($amount <= 0) {
            Mage::throwException(Mage::helper($this->_code)->__('Invalid amount for authorization.'));
        }

        $data = $this->_doSale($payment);

        $payment->setTransactionId($data['confirmation'])
                ->setAdditionalInformation(serialize($data))
                ->setIsTransactionClosed(false);

        return $this;
    }


    /**
     * Capture payment abstract method
     *
     * @param Varien_Object $payment
     * @param float $amount
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function capture(Varien_Object $payment, $amount)
    {
        if ($amount <= 0) {
            Mage::throwException(Mage::helper($this->_code)->__('Invalid amount for capture.'));
        }


        $payment->setAmount($amount);

        $data = $this->_doSale($payment);

        $payment->setTransactionId($data['confirmation'])
                ->setAdditionalInformation($data)
                ->setIsTransactionClosed(true);

        return $this;
    }


    /**
     * Refund specified amount for payment
     *
     * @param Varien_Object $payment
     * @param float $amount
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function refund(Varien_Object $payment, $amount)
    {
        if (!$this->canRefund()) {
            Mage::throwException(Mage::helper($this->_code)->__('Refund action is not available.'));
        }

        $data = $payment->getAdditionalInformation();

        if (empty($data['confirmation'])) {
            Mage::throwException(Mage::helper($this->_code)->__('Refund action is not available.'));
        }

        $this->_doValidate(...$this->_doRequest($this->getURL('/payment/' . $data['confirmation'] . '/refund'), array(), array()));

        return $this;
    }

    /**
     * Parent transaction id getter
     *
     * @param Varien_Object $payment
     * @return string
     */
    private function _getParentTransactionId(Varien_Object $payment)
    {
        return $payment->getParentTransactionId() ? $payment->getParentTransactionId() : $payment->getLastTransId();
    }


    /**
     * Return url of payment method
     *
     * @return string
     */
    private function getUrl($uri)
    {
        return $this->getConfigData('gatewayurl') . $uri;
    }


    private function getAccountId() {
        return ($this->getConfigData('apimode')) ? $this->getConfigData('apikey_test') : $this->getConfigData('apikey_live');
    }


    private function getAuthSecret() {
        return ($this->getConfigData('apimode')) ? $this->getConfigData('apisecret_test') : $this->getConfigData('apisecret_live');
    }


    private function _doSale(Varien_Object $payment)
    {
        $order = $payment->getOrder();
        $billingAddress = $order->getBillingAddress();


        $data = array(
            "first_name"    => strval($billingAddress->getFirstname()), // Yes, The customer's first name. Characters allowed: a-z A-Z . ' and -
            "last_name"     => strval($billingAddress->getLastname()), // Yes, The customer's last name. Characters allowed: a-z A-Z . ' and -
            "email"         => strval($order->getCustomerEmail()), // Yes, String Customer's email address. Must be a valid address. Upon processing of the draft an email will be sent to this address.
            "address"       => substr(strval($billingAddress->getStreet(1)), 0, 50), // Yes String The street portion of the mailing address associated with the customer's checking account. Include any apartment number or mail codes here. Any line breaks will be stripped out.
            "city"          => strval($billingAddress->getCity()), // Yes String The city portion of the mailing address associated with the customer's checking
            "state"         => strval($billingAddress->getRegionCode()),// Yes String The state portion of the mailing address associated with the customer's checking account. It must be a valid US state or territory
            "zip"           => strval($billingAddress->getPostcode()), // Yes String The zip code portion of the mailing address associated with the customer's checking account. Accepted formats: XXXXX,  XXXXX-XXXX
            "country"       => strval($billingAddress->getCountry()),
            "phone"         => substr(str_replace(array(' ', '(', ')', '+', '-'), '', strval($billingAddress->getTelephone())), -10), // Yes, The customer's phone number. Characters allowed: 0-9 + - ( and )
            "ip"            => $this->getIpAddress(), // Yes, The customer's IP address.
            "currency" => $order->getOrderCurrencyCode(),
            "card" => array (
                "number"       => strval($payment->getCcNumber()), // Yes, The credit card number, numeric only (no spaces, no non-numeric).
                "expiry_month" => strval($payment->getCcExpMonth()), //Yes, The credit card expiry month, numeric only (leading zero okay).
                "expiry_year"  => strval($payment->getCcExpYear()), // Yes, The credit card expiry year, numeric only (full 4 digit).
                "ccv"          => strval($payment->getCcCid()), // Yes, The 3 or 4 digit credit card CVV code.
            ),
            "invoicenumber" => $order->getIncrementId(),
            "amount"        => (float) $payment->getAmount(), // Yes Decimal Total dollar amount with up to 2 decimal places.
        );

        // prepare to request
        $jsonData = json_encode($data);

        return $this->_doValidate(...$this->_doPost($jsonData, '/payment'), ...[$this->_sanitizeData($jsonData)]);
    }


    public function _doGetStatus(Varien_Object $payment)
    {
        $data = $payment->getAdditionalInformation();

        // Nothing to check
        if (empty($data['order_id'])) {
            return array();
        }

        list (, $resData) = $this->_doRequest($this->getURL('/transactions/' . $data['order_id']));

        return $resData;
    }


    private function _sanitizeData($data) {
        if (is_string($data)) {
            return preg_replace('/"number":\s*"[^"]*([^"]{4})"/i', '"number":"***$1"', preg_replace('/"ccv":\s*"([^"]*)"/i', '"ccv":"***"', $data));
        }

        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (is_array($v)) {
                    return $this->_sanitizeData($v);
                } else {
                    if (in_array($k, array('ccnumber', 'number', 'CardNumber')) {
                        $data[$k] = "***" . substr($data[$k], -4);
                    }

                    if (in_array($k, array('cvv', 'CardCVV')) {
                        $data[$k] = "***";
                    }
                }
            }
        }

        return $data;
    }


    public function log($data)
    {
        Mage::log($data, null, 'NetCents.log');
    }


    private function _doValidate($code, $data = [], $sent)
    {
        $this->log(array(
            'try to doValidate - before',
            'httpStatusCode' => $code,
            'RespJson' => $data,
            'ReqJson' => $sent,
            'PassValidation' =>  (!(empty($data) === false && empty($data["status"]) === false && (int) substr($data['status'], 0, 1) === 2))
        ));

        if (!(empty($data) === false && empty($data["status"]) === false && (int) substr($data['status'], 0, 1) === 2)) {
            $this->log(array('error on doValidate', 'httpStatusCode' => $code, 'RespJson' => $data, 'ReqJson' => $sent));

            if (Mage::getStoreConfig('slack/general/enable_notification')) {
                $notificationModel   = Mage::getSingleton('mhauri_slack/notification');
                $notificationModel->setMessage(
                    Mage::helper($this->_code)->__("*Netcents payment failed with data:*\nNetcents response ```%s```\n\nData sent ```%s```", json_encode($data), $sent)
                )->send(array('icon_emoji' => ':cop:'));
            }

            Mage::throwException(Mage::helper($this->_code)->__("Error during payment processing: response code: %s %s\nThis credit card processor cannot accept your card; please select a different payment method.", $data['status'], $data['message'] . "\r\n" ));
        }

        return $data;
    }


    private function _doRequest($url, $extReqHeaders = array(), $extReqOpts = array())
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, Mage::helper('core')->decrypt($this->getAccountId()) . ':'. Mage::helper('core')->decrypt($this->getAuthSecret()));

        $reqHeaders = array(
          'Cache-Control: no-cache',
        );

        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($reqHeaders, $extReqHeaders));

        foreach ($extReqOpts as $key => $value) {
            curl_setopt($ch, $key, $value);
        }

        $resp = curl_exec($ch);

        list ($respHeaders, $body) = explode("\r\n\r\n", $resp, 2);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $errCode = curl_errno($ch);
        $errMessage = curl_error($ch);

        curl_close($ch);

        if (!empty($body)) {
            $body = json_decode($body, true);
        }

        if ($errCode || $errMessage || (int) substr($httpCode, 0, 1) !== 2) {
            $this->log(array('doRequest', 'url' => $url, 'httpRespCode' => $httpCode, 'httpRespHeaders' => $respHeaders, 'httpRespBody' => $body, 'httpReqHeaders' => array_merge($reqHeaders, $extReqHeaders), 'httpReqExtraOptions' => $extReqOpts, 'errCode' => $errCode, 'errMessage' => $errMessage));

            if (Mage::getStoreConfig('slack/general/enable_notification')) {
                $notificationModel   = Mage::getSingleton('mhauri_slack/notification');
                $notificationModel->setMessage(
                    Mage::helper($this->_code)->__("*Netcents payment failed with data:*\nNetcents response ```%s %s```\n\nData sent ```%s```", $httpCode, $errMessage, $this->_sanitizeData(!empty($extReqOpts[CURLOPT_POSTFIELDS]) ? $extReqOpts[CURLOPT_POSTFIELDS] : ''))
                )->send(array('icon_emoji' => ':warning:'));
            }

            Mage::throwException(Mage::helper($this->_code)->__("Error during payment processing: response code: %s %s. This credit card processor cannot accept your card; please select a different payment method.", $httpCode, $errMessage . "\r\n" ));
        }


        return array($httpCode, $body);
    }


    private function _doPost($query, $uri)
    {
        return $this->_doRequest($this->getURL($uri), array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($query),
        ), array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $query
        ));
    }


    private function getIpAddress() {
        $ipaddress = '127.0.0.1';

        if (isset($_SERVER['HTTP_CLIENT_IP']))
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_X_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        else if(isset($_SERVER['REMOTE_ADDR']))
            $ipaddress = $_SERVER['REMOTE_ADDR'];

        return $ipaddress;
    }
}
