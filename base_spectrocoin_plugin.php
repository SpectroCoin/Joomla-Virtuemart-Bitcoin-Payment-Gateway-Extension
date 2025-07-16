<?php

use SpectroCoin\SCMerchantClient\Config;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */
defined('JPATH_BASE') or die();
defined('_JEXEC') or die('Restricted access');
define('SPECTROCOIN_VIRTUEMART_EXTENSION_VERSION', '2.1.0');

if (!class_exists('vmPSPlugin')) {
    require(implode(DS, [JPATH_VM_PLUGINS, 'vmpsplugin.php']));
}
if (!class_exists('VmConfig')) {
    require(implode(DS, [JPATH_ADMINISTRATOR, 'components', 'com_virtuemart', 'helpers', 'config.php']));
}
if (!class_exists('ShopFunctions')) {
    require(implode(DS, [JPATH_VM_ADMINISTRATOR, 'helpers', 'shopfunctions.php']));
}

abstract class plgVmPaymentBaseSpectrocoin extends vmPSPlugin
{

    function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());

        $this->setConfigParameterable($this->_configTableFieldName, $this->getVarsToPush());

        if ($this->isAdmin()) {
            $shopsLink = JRoute::_('index.php?option=com_virtuemart&view=user&task=editshop');
            $notice = '<b>SpectroCoin:</b> Make sure you select the same currency in your SpectroCoin payment settings as in your ' . '<a target="_blank" href="' . $shopsLink . '">shop</a>' . ' settings';
            $this->notice($notice);
        }
    }

    public static function includeClassFile($className, array $segments)
    {
        if (!class_exists($className)) {
            require_once implode(DS, $segments);
        }
    }
    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment SpectroCoin Table');
    }
    public function getTableSQLFields()
    {
        return array(
            'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED',
            'order_number' => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name' => 'varchar(5000)',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'char(3)',
            'logo' => 'varchar(5000)'
        );
    }

    public function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        return 0;
    }

    protected function checkConditions($cart, $method, $cart_prices)
    {
        return true;
    }

    public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    public function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);
        $paymentCurrencyId = $method->payment_currency;
        return;
    }

    public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    public function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    public function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    public function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    public function plgVmOnPaymentResponseReceived(&$html)
    {
        // Include some needed classes
        self::includeClassFile('VirtueMartCart', [JPATH_VM_SITE, 'helpers', 'cart.php']);
        self::includeClassFile('shopFunctionsF', [JPATH_VM_SITE, 'helpers', 'shopfunctionsf.php']);
        self::includeClassFile('shopFunctionsF', [JPATH_VM_ADMINISTRATOR, 'models', 'orders.php']);

        $virtuemart_paymentmethod_id = JFactory::getApplication()->input->get->get('pm', 0);
        $order_number = JFactory::getApplication()->input->get->get('on', 0);
        $vendorId = 0;

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return null;
        }
        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
            return null;
        }
        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
            return '';
        }

        $payment_name = $this->renderPluginName($method);
        $html = $this->_getPaymentResponseHtml($paymentTable, $payment_name);
        return true;
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        if (!$this->checkCartCurrency()) {
            return '';
        }

        $session = JFactory::getSession();
        $errors = $session->get('errorMessages', 0, 'vm');
        if ($errors != "") {
            $errors = unserialize($errors);
            $session->set('errorMessages', "", 'vm');
        } else {
            $errors = array();
        }
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /**
     * Function which checks if the currency of the cart is supported by SpectroCoin
     * @return bool
     */
    private function checkCartCurrency(): bool
    {
        if (!class_exists('VmConfig')) {
            require(JPATH_ADMINISTRATOR . DS . 'components' . DS . 'com_virtuemart' . DS . 'helpers' . DS . 'config.php');
        }
        $currency_model = VmModel::getModel('currency');
        $displayCurrency = $currency_model->getCurrency($this->product->product_currency);
        $currentCurrencyIsoCode = $displayCurrency->currency_code_3;

        return in_array($currentCurrencyIsoCode, Config::ACCEPTED_FIAT_CURRENCIES);
    }
    /**
     * Function which GMT timestamp
     * @return string
     */
    public function getGMTTimeStamp()
    {
        $tz_minutes = date('Z') / 60;
        if ($tz_minutes >= 0) {
            $tz_minutes = '+' . sprintf("%03d", $tz_minutes);
        }
        $stamp = date('YdmHis000000') . $tz_minutes;
        return $stamp;
    }
    /**
     * Function which shows Joomla notice
     */
    public function notice($message)
    {
        $app = JFactory::getApplication();
        $app->enqueueMessage($message, 'notice');
    }
    /**
     * Function which checks if user is logged in as admin
     * @return bool
     */
    public function isAdmin()
    {
        $user = JFactory::getUser();
        $authorize = $user->authorise('core.admin');
        if ($authorize) {
            return true;
        } else {
            return false;
        }
    }

}