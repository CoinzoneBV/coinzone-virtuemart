<?php
defined('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin'))
{
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentCoinzone extends vmPSPlugin
{
    function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush        = $this->getVarsToPush();
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Coinzone Payment Table');
    }

    function getTableSQLFields()
    {
        $SQLfields = array(
            'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'         => 'int(1) UNSIGNED',
            'order_number'                => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name'                => 'varchar(5000)',
            'payment_order_total'         => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency'            => 'char(3)',
            'buyer_email'                 => 'varchar(255)'
        );

        return $SQLfields;
    }

    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
    {
        if (!$this->selectedThisByMethodId($virtuemart_payment_id))
        {
            return NULL;
        }

        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id)))
        {
            return NULL;
        }

        $html = '<table class="adminlist">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('COINZONE_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE('COINZONE_PAYMENT_TOTAL', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
        $html .= '</table>' . "\n";

        return $html;
    }

    function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        if (preg_match('/%$/', $method->cost_percent_total))
        {
            $cost_percent_total = substr($method->cost_percent_total, 0, -1);
        }
        else
        {
            $cost_percent_total = $method->cost_percent_total;
        }

        return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
    }

    protected function checkConditions($cart, $method, $cart_prices)
    {
        return true;
    }

    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id)))
        {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element))
        {
            return false;
        }
        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;
        return $paymentCurrencyId;
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    function plgVmOnPaymentNotification ()
    {
        if (!class_exists ('VirtueMartModelOrders'))
        {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }

        $content = file_get_contents("php://input");
        $input = json_decode($content);
        $headers = getallheaders();
        $nHeaders = array();
        foreach($headers as $key => $value) {
            $nHeaders[strtolower($key)] = $value;
        }

        $schema = isset($_SERVER['HTTPS']) ? "https://" : "http://";
        $currentUrl = $schema . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $order_number = $input->merchantReference;
        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber ($order_number)))
        {
            header("HTTP/1.0 400 Bad Request");
            exit("Invalid reference");
        }

        $modelOrder = VmModel::getModel ('orders');
        $order      = $modelOrder->getOrder($virtuemart_order_id);
        if (!$order)
        {
            header("HTTP/1.0 400 Bad Request");
            exit("Invalid order");
        }

        $method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id);
        $stringToSign = $content . $currentUrl . $headers['timestamp'];
        $signature = hash_hmac('sha256', $stringToSign, $method->api_key);
        if ($signature !== $headers['signature']) {
            header("HTTP/1.0 400 Bad Request");
            exit("Invalid callback");
        }

        switch($input->status) {
            case 'PAID':
            case 'COMPLETE':
                $order['order_status'] = 'C';
                $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, TRUE);
                exit('OK');
                break;
            default:
                header("HTTP/1.0 400 Bad Request");
                exit("NO_ACTION");
                break;
        }
    }

    function plgVmDisplayListFEPayment (VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        $session = JFactory::getSession ();
        $errors  = $session->get ('errorMessages', 0, 'vm');

        if($errors != "")
        {
            $errors = unserialize($errors);
            $session->set ('errorMessages', "", 'vm');
        }
        else
        {
            $errors = array();
        }

        return $this->displayListFE ($cart, $selected, $htmlIn);
    }

    public function getGMTTimeStamp()
    {
        $tz_minutes = date('Z') / 60;

        if ($tz_minutes >= 0)
        {
            $tz_minutes = '+' . sprintf("%03d",$tz_minutes);
        }

        $stamp = date('YdmHis000000') . $tz_minutes;

        return $stamp;
    }

    function plgVmConfirmedOrder($cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id)))
        {
            return NULL;
        }
        if (!$this->selectedThisElement($method->payment_element))
        {
            return false;
        }
        $lang     = JFactory::getLanguage();
        $filename = 'com_virtuemart';
        $lang->load($filename, JPATH_ADMINISTRATOR);
        $vendorId = 0;
        $html     = "";

        if (!class_exists('VirtueMartModelOrders'))
        {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }
        $this->getPaymentCurrency($method, true);

        $q  = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
        $db = JFactory::getDBO();
        $db->setQuery($q);
        $currency_code_3        = $db->loadResult();
        $address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);

        if (!class_exists('Coinzone')) {
            require('lib' . DS . 'coinzone_lib.php');
        }

        /* create payload array */
        $payload = array(
            'amount' => $order['details']['BT']->order_total,
            'currency' => $currency_code_3,
            'merchantReference' => $order['details']['BT']->order_number,
            'email' => $address->email,
            'notificationUrl' => (JROUTE::_ (JURI::root () . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component'))
        );

        $coinzone = new Coinzone($method->client_code, $method->api_key);
        $response = $coinzone->callApi('transaction', $payload);
        if ($response->status->code == 201) {
            header('Location: ' . $response->response->url);
        } else {
            return null;
        }
    }

    function _handlePaymentCancel ($virtuemart_order_id, $html)
    {
        if (!class_exists ('VirtueMartModelOrders'))
        {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }
        $modelOrder = VmModel::getModel ('orders');
        $modelOrder->remove (array('virtuemart_order_id' => $virtuemart_order_id));
        // error while processing the payment
        $mainframe = JFactory::getApplication ();
        $mainframe->redirect (JRoute::_ ('index.php?option=com_virtuemart&view=cart&task=editpayment'), $html);
    }
}
