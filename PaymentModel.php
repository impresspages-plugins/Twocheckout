<?php
/**
 * @package   ImpressPages
 */


namespace Plugin\Twocheckout;


class PaymentModel
{

    const MODE_PRODUCTION = 'Production';
    const MODE_TEST = 'Test';
    const MODE_SKIP = 'Skip';


    protected static $instance;

    protected function __construct()
    {
    }

    protected function __clone()
    {
    }

    /**
     * Get singleton instance
     * @return PaymentModel
     */
    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new PaymentModel();
        }

        return self::$instance;
    }

    public function processCallback($params)
    {
        require_once('lib/Twocheckout.php');

        if (ipRequest()->isPost()) { //no user. Just the notification
            $params = array();
            foreach (ipRequest()->getPost() as $k => $v) {
                $params[$k] = $v;
            }

            $passback = \Twocheckout_Notification::check($params, $this->secretWord());
        } else { //notification data and user
            $params = array();
            foreach (ipRequest()->getQuery() as $k => $v) {
                $params[$k] = $v;
            }

            if ($this->isTestMode()) {
                $params['order_number'] = 1;
            }

            $passback = \Twocheckout_Return::check($params, $this->secretWord());
        }


        if (!empty($passback['response_code']) && $passback['response_code'] = 'Success') {
            //successful payment

            $customData = json_decode($params['custom'], true);
            $paymentId = isset($customData['paymentId']) ? $customData['paymentId'] : null;
            $currency = isset($params['currency_code']) ? $params['currency_code'] : null;
            $receiver = isset($params['sid']) ? $params['sid'] : null;
            $amount = isset($params['li_0_price']) ? $params['li_0_price'] : null;

            $payment = Model::getPayment($paymentId);

            if (!$payment) {
                ipLog()->error('Twocheckout.ipn: Order not found.', array('paymentId' => $paymentId));
                return;
            }

            if ($payment['currency'] != $currency) {
                ipLog()->error('Twocheckout.ipn: IPN rejected. Currency doesn\'t match', array('notification currency' => $currency, 'expected currency' => $payment['currency']));
                return;
            }

            $orderPrice = $payment['price'];
            $orderPrice = str_pad($orderPrice, 3, "0", STR_PAD_LEFT);
            $orderPrice = substr_replace($orderPrice, '.', -2, 0);

            if ($amount != $orderPrice) {
                ipLog()->error('Twocheckout.ipn: IPN rejected. Price doesn\'t match', array('notification price' => $amount, 'expected price' => '' . $orderPrice));
                return;
            }

            if ($receiver != $this->getSid()) {
                ipLog()->error('Twocheckout.ipn: IPN rejected. Recipient doesn\'t match', array('notification recipient' => $receiver, 'expected recipient' => $this->getSid()));
                return;
            }

            if ($payment['isPaid']) {
                ipLog()->error('Twocheckout.ipn: Order is already paid', $response);
                return;
            }

            $info = array(
                'id' => $payment['orderId'],
                'paymentId' => $payment['id'],
                'paymentMethod' => '2checkout',
                'title' => $payment['title'],
                'userId' => $payment['userId']
            );

            ipLog()->info('Twocheckout.ipn: Successful payment', $info);

            $newData = array();
            $eventData = array();
            if (isset($params['first_name'])) {
                $newData['payer_first_name'] = $params['first_name'];
                $eventData['payer_first_name'] = $params['first_name'];
            }
            if (isset($params['last_name'])) {
                $newData['payer_last_name'] = $params['last_name'];
                $eventData['payer_last_name'] = $params['last_name'];
            }
            if (isset($params['email'])) {
                $newData['payer_email'] = $params['email'];
                $eventData['payer_email'] = $params['email'];
            }
            if (isset($params['country'])) {
                $newData['payer_country'] = $params['country'];
                $eventData['payer_country'] = $params['country'];
            }

            $this->markAsPaid($paymentId, $newData, $eventData);

        } else {
            //fail
            ipLog()->error(
                'Twocheckout.ipn: notification check error',
                $params
            );
            return;

        }

        return;


    }

    public function markAsPaid($paymentId, $dbData = array(), $eventData = array())
    {
        $payment = Model::getPayment($paymentId);

        $dbData['isPaid'] = 1;
        Model::update($paymentId, $dbData);

        $info = array(
            'id' => $payment['orderId'],
            'paymentId' => $payment['id'],
            'paymentMethod' => '2checkout',
            'title' => $payment['title'],
            'userId' => $payment['userId']
        );
        $info = array_merge($info, $eventData);
        ipEvent('ipPaymentReceived', $info);

    }



    public function get2checkoutForm($paymentId)
    {
        require_once('lib/Twocheckout.php');
        if (!$this->getSid()) {
            throw new \Ip\Exception('Please enter configuration values for Twocheckout plugin');
        }


        $payment = Model::getPayment($paymentId);
        if (!$payment) {
            throw new \Ip\Exception("Can't find order id. " . $paymentId);
        }


        $currency = $payment['currency'];
        $privateData = array(
            'paymentId' => $paymentId,
            'userId' => $payment['userId'],
            'securityCode' => $payment['securityCode']
        );





        $params = array(
            'sid' => $this->getSid(),
            'mode' => '2CO',
            'li_0_product_id' => $payment['id'],
            'li_0_name' => $payment['title'],
            'li_0_price' => $payment['price'] / 100,
            'currency_code' => $currency,
            'custom' => json_encode($privateData),
            'demo' => $this->isTestMode() ? 'Y' : 'N',
            'x_receipt_link_url' => ipRouteUrl('Twocheckout_return'),
        );
        $form = \Twocheckout_Charge::form($params, 'auto');


        return $form;
    }

    /**
     *
     *  Returns $data encoded in UTF8. Very useful before json_encode as it fails if some strings are not utf8 encoded
     * @param mixed $dat array or string
     * @return array
     */
    private function checkEncoding($dat)
    {
        if (is_string($dat)) {
            if (mb_check_encoding($dat, 'UTF-8')) {
                return $dat;
            } else {
                return utf8_encode($dat);
            }
        }
        if (is_array($dat)) {
            $answer = array();
            foreach ($dat as $i => $d) {
                $answer[$i] = $this->checkEncoding($d);
            }
            return $answer;
        }
        return $dat;
    }


    public function getSid()
    {
        if ($this->isTestMode()) {
            return ipGetOption('Twocheckout.testSid');
        } else {
            return ipGetOption('Twocheckout.sid');
        }
    }



    public function isTestMode()
    {
        return ipGetOption('Twocheckout.mode') == self::MODE_TEST;
    }


    public function isSkipMode()
    {
        return ipGetOption('Twocheckout.mode') == self::MODE_SKIP;
    }

    public function isProductionMode()
    {
        return ipGetOption('Twocheckout.mode') == self::MODE_PRODUCTION;
    }

    public function secretWord()
    {
        return ipGetOption('Twocheckout.secretWord');
    }

    public function correctConfiguration()
    {
        if ($this->getActive() && $this->getSid()) {
            return true;
        } else {
            return false;
        }
    }

}
