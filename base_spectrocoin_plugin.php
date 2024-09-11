<?php

declare(strict_types=1);

use SpectroCoin\SCMerchantClient\Config;

/**
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */
defined('JPATH_BASE') or die();
defined('_JEXEC') or die('Restricted access');

define('SPECTROCOIN_VIRTUEMART_EXTENSION_VERSION', '1.0.0');

if (!class_exists('vmPSPlugin')) {
    require implode(DS, [JPATH_VM_PLUGINS, 'vmpsplugin.php']);
}
if (!class_exists('VmConfig')) {
    require implode(DS, [JPATH_ADMINISTRATOR, 'components', 'com_virtuemart', 'helpers', 'config.php']);
}
if (!class_exists('ShopFunctions')) {
    require implode(DS, [JPATH_VM_ADMINISTRATOR, 'helpers', 'shopfunctions.php']);
}

abstract class plgVmPaymentBaseSpectrocoin extends vmPSPlugin
{
    public function __construct(&$subject, array $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());

        $this->setConfigParameterable($this->_configTableFieldName, $this->getVarsToPush());

        if ($this->isAdmin()) {
            $shopsLink = JRoute::_('index.php?option=com_virtuemart&view=user&task=editshop');
            $notice = '<b>SpectroCoin:</b> Make sure you select the same currency in your SpectroCoin payment settings as in your ' .
                '<a target="_blank" href="' . $shopsLink . '">shop</a>' . ' settings';
            $this->notice($notice);
        }
    }

    /**
     * Includes a class file if not already loaded.
     * @param string $className
     * @param array $segments
     * @return void
     */
    public static function includeClassFile(string $className, array $segments): void
    {
        if (!class_exists($className)) {
            require_once implode(DS, $segments);
        }
    }

    /**
     * Creates the SQL table for payment method.
     * @return string
     */
    public function getVmPluginCreateTableSQL(): string
    {
        return $this->createTableSQL('Payment SpectroCoin Table');
    }

    /**
     * Defines the SQL fields for the plugin table.
     * @return array
     */
    public function getTableSQLFields(): array
    {
        return [
            'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED',
            'order_number' => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name' => 'varchar(5000)',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'char(3)',
            'logo' => 'varchar(5000)'
        ];
    }

    /**
     * Returns the cost for the payment method.
     * @param VirtueMartCart $cart
     * @param object $method
     * @param array $cart_prices
     * @return float
     */
    public function getCosts(VirtueMartCart $cart, $method, $cart_prices): float
    {
        return 0.0;
    }

    /**
     * Checks if the payment method conditions are met.
     * @param VirtueMartCart $cart
     * @param object $method
     * @param array $cart_prices
     * @return bool
     */
    protected function checkConditions($cart, $method, $cart_prices): bool
    {
        return true;
    }

    /**
     * Handles plugin installation for VirtueMart.
     * @param int $jplugin_id
     * @return bool
     */
    public function plgVmOnStoreInstallPaymentPluginTable(int $jplugin_id): bool
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * Calculates the price for the selected payment method.
     * @param VirtueMartCart $cart
     * @param array $cart_prices
     * @param string $cart_prices_name
     * @return bool
     */
    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, string &$cart_prices_name): bool
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
     * Returns the currency used for the payment method.
     * @param int $virtuemart_paymentmethod_id
     * @param int|null $paymentCurrencyId
     * @return void
     */
    public function plgVmgetPaymentCurrency(int $virtuemart_paymentmethod_id, ?int &$paymentCurrencyId): void
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return;
        }
        $this->getPaymentCurrency($method);
        $paymentCurrencyId = $method->payment_currency;
    }

    /**
     * Automatically selects the payment method based on conditions.
     * @param VirtueMartCart $cart
     * @param array $cart_prices
     * @param int $paymentCounter
     * @return bool
     */
    public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = [], int &$paymentCounter): bool
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    /**
     * Shows the payment method on the order in frontend.
     * @param int $virtuemart_order_id
     * @param int $virtuemart_paymentmethod_id
     * @param string $payment_name
     * @return void
     */
    public function plgVmOnShowOrderFEPayment(int $virtuemart_order_id, int $virtuemart_paymentmethod_id, string &$payment_name): void
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * Prints the payment method on the order in backend.
     * @param string $order_number
     * @param int $method_id
     * @return bool
     */
    public function plgVmonShowOrderPrintPayment(string $order_number, int $method_id): bool
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    /**
     * Declares the plugin parameters for VirtueMart.
     * @param string $name
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function plgVmDeclarePluginParamsPayment(string $name, int $id, array &$data): bool
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    /**
     * Declares the plugin parameters for VirtueMart 3.
     * @param array $data
     * @return bool
     */
    public function plgVmDeclarePluginParamsPaymentVM3(array &$data): bool
    {
        return $this->declarePluginParams('payment', $data);
    }

    /**
     * Sets the plugin parameters on the table.
     * @param string $name
     * @param int $id
     * @param array $table
     * @return bool
     */
    public function plgVmSetOnTablePluginParamsPayment(string $name, int $id, array &$table): bool
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    /**
     * Handles the payment response.
     * @param string $html
     * @return bool
     */
    public function plgVmOnPaymentResponseReceived(string &$html): bool
    {
        self::includeClassFile('VirtueMartCart', [JPATH_VM_SITE, 'helpers', 'cart.php']);
        self::includeClassFile('shopFunctionsF', [JPATH_VM_SITE, 'helpers', 'shopfunctionsf.php']);
        self::includeClassFile('VirtueMartModelOrders', [JPATH_VM_ADMINISTRATOR, 'models', 'orders.php']);

        $virtuemart_paymentmethod_id = JFactory::getApplication()->input->getInt('pm', 0);
        $order_number = JFactory::getApplication()->input->getString('on', 0);
        $vendorId = 0;

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return false;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
            return false;
        }
        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
            return false;
        }

        $payment_name = $this->renderPluginName($method);
        $html = $this->_getPaymentResponseHtml($paymentTable, $payment_name);
        return true;
    }

    /**
     * Displays the payment method on the frontend.
     * @param VirtueMartCart $cart
     * @param int $selected
     * @param string $htmlIn
     * @return string
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, int $selected = 0, string &$htmlIn): string
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
            $errors = [];
        }
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /**
     * Verifies if the cart currency is supported by SpectroCoin.
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
     * Returns the current GMT timestamp.
     * @return string
     */
    public function getGMTTimeStamp(): string
    {
        $tz_minutes = date('Z') / 60;
        $tz_minutes = $tz_minutes >= 0 ? '+' . sprintf("%03d", $tz_minutes) : (string) $tz_minutes;
        return date('YdmHis000000') . $tz_minutes;
    }

    /**
     * Displays a Joomla notice message.
     * @param string $message
     * @return void
     */
    public function notice(string $message): void
    {
        $app = JFactory::getApplication();
        $app->enqueueMessage($message, 'notice');
    }

    /**
     * Checks if the current user has admin access.
     * @return bool
     */
    public function isAdmin(): bool
    {
        $user = JFactory::getUser();
        return $user->authorise('core.admin');
    }
}
