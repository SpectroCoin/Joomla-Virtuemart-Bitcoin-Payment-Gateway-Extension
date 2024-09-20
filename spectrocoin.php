<?php

declare(strict_types=1);

use SpectroCoin\SCMerchantClient\Config;
use SpectroCoin\SCMerchantClient\Exception\ApiError;
use SpectroCoin\SCMerchantClient\Enum\OrderStatus

/**
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

defined('JPATH_BASE') or die();
defined('_JEXEC') or die('Restricted access');
define('SPECTROCOIN_VIRTUEMART_EXTENSION_VERSION', '1.0.0');

jimport('joomla.log.log');

if (!class_exists('plgVmPaymentBaseSpectrocoin')) {
    require_once(JPATH_PLUGINS . '/vmpayment/spectrocoin/base_spectrocoin_plugin.php');
}

JLog::addLogger(
    array(
        'text_file' => 'plg_vmpayment_spectrocoin.log.php',
        'text_entry_format' => '{DATE} {TIME} {PRIORITY} {MESSAGE}',
        'text_file_path' => JPATH_ROOT . '/administrator/logs'
    ),
    JLog::ALL,
    array('plg_vmpayment_spectrocoin')
);

class plgVmPaymentSpectrocoin extends plgVmPaymentBaseSpectrocoin
{

    public function plgVmOnPaymentNotification()
    {
        JLog::add('plgVmOnPaymentNotification initialized.', JLog::INFO, 'plg_vmpayment_spectrocoin');

        // self::includeClassFile('VirtueMartModelOrders', [JPATH_VM_ADMINISTRATOR, 'models', 'orders.php']);
        // self::includeClassFile('SpectroCoin_ApiError', [Config::SCPLUGIN_CLIENT_PATH, 'data', 'SpectroCoin_ApiError.php']);

        try {
            $input = JFactory::getApplication()->input;
            $orderId = $input->getInt('orderId');

            $orderModel = new VirtueMartModelOrders();
            $order = $orderModel->getOrder($orderId);

            if (empty($order['details'])) {
                JLog::add('Order details are empty for order ID ' . $orderId, JLog::ERROR, 'plg_vmpayment_spectrocoin');
                return;
            }

            $model_order = VmModel::getModel('orders');

            $method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id);
            if (!$method) {
                JLog::add('No payment method found for Order ID: ' . $orderId, JLog::ERROR, 'plg_vmpayment_spectrocoin');
                return null;
            }

            if (!$this->selectedThisElement($method->payment_element)) {
                JLog::add('This payment element is not selected: ' . $method->payment_element, JLog::DEBUG, 'plg_vmpayment_spectrocoin');
                return false;
            }

            $client = self::getSCClientByMethod($method);

            $expectedKeys = ['userId', 'merchantApiId', 'merchantId', 'apiId', 'orderId', 'payCurrency', 'payAmount', 'receiveCurrency', 'receiveAmount', 'receivedAmount', 'description', 'orderRequestId', 'status', 'sign'];
            $postData = [];
            foreach ($expectedKeys as $key) {
                $value = $input->get($key, '', 'string');
                $postData[$key] = $value;
            }

            $callback = $client->spectrocoinProcessCallback($postData);

            switch ($callback->getStatus()) {
                case OrderStatus::New->value:
                    $order_status = $method->new_status;
                    break;
                case OrderStatus::Pending->value:
                    $order_status = $method->pending_status;
                    break;
                case OrderStatus::Expired->value:
                    $order_status = $method->expired_status;
                    break;
                case OrderStatus::Failed->value:
                    $order_status = $method->failed_status;
                    break;
                case OrderStatus::Paid->value:
                    $order_status = $method->paid_status;
                    break;
                default:
                    JLog::add('Unknown order status: ' . $callback->getStatus(), JLog::ERROR, 'plg_vmpayment_spectrocoin');
                    exit;
            }

            $order['order_status'] = $newStatus;
            $model_order->updateStatusForOneOrder($orderId, $order, true);
            echo '*ok*';
        } catch (Exception $e) {
            JLog::add($e->getMessage(), JLog::ERROR, 'plg_vmpayment_spectrocoin');
            echo $e->getMessage();
        }

        JFactory::getApplication()->close();
    }

    protected static function getSCClientByMethod($method)
    {
        self::includeClassFile('SCMerchantClient', [self::SCPLUGIN_CLIENT_PATH, 'SCMerchantClient.php']);
        return new SCMerchantClient(
            "https://test.spectrocoin.com/api/public/oauth/token",
            "https://test.spectrocoin.com/api/public",
            $method->project_id,
            $method->client_id,
            $method->client_secret
        );
    }

    public function plgVmConfirmedOrder($cart, $order)
    {
        $method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id);
        if (!$method)
            return null;
        if (!$this->selectedThisElement($method->payment_element))
            return false;

        self::includeClassFile('VirtueMartModelOrders', [JPATH_VM_ADMINISTRATOR, 'models', 'orders.php']);
        self::includeClassFile('VirtueMartModelCurrency', [JPATH_VM_ADMINISTRATOR, 'models', 'currency.php']);
        self::includeClassFile('SpectroCoin_ApiError', [self::SCPLUGIN_CLIENT_PATH, 'data', 'SpectroCoin_ApiError.php']);
        self::includeClassFile('SpectroCoin_CreateOrderRequest', [self::SCPLUGIN_CLIENT_PATH, 'messages', 'SpectroCoin_CreateOrderRequest.php']);
        self::includeClassFile('SpectroCoin_CreateOrderResponse', [self::SCPLUGIN_CLIENT_PATH, 'messages', 'SpectroCoin_CreateOrderResponse.php']);

        VmConfig::loadJLang('com_virtuemart', true);
        VmConfig::loadJLang('com_virtuemart_orders', true);

        $client = self::getSCClientByMethod($method);

        $uriBaseVirtuemart = JURI::root() . 'index.php?option=com_virtuemart';

        $orderId = $order['details']['BT']->virtuemart_order_id;
        $paymentMethodId = $order['details']['BT']->virtuemart_paymentmethod_id;
        $orderNumber = $order['details']['BT']->order_number;
        $receiveCurrencyCode = shopFunctions::getCurrencyByID($method->currency_id, 'currency_code_3');
        $payCurrencyCode = 'BTC';
        $receiveAmount = round($order['details']['BT']->order_total, 2);
        $description = "Order $orderNumber at " . basename(JUri::base());
        $callbackUrl = (JROUTE::_($uriBaseVirtuemart . '&view=pluginresponse&task=pluginnotification&tmpl=component'));
        $successUrl = (JROUTE::_($uriBaseVirtuemart . '&view=pluginresponse&task=pluginresponsereceived&pm=' . $paymentMethodId));
        $failureUrl = (JROUTE::_($uriBaseVirtuemart . '&view=cart'));
        $locale = explode('-', JFactory::getLanguage()->getTag())[0];

        $request = new SpectroCoin_CreateOrderRequest(
            $orderId,
            $description,
            null,
            $receiveCurrencyCode,
            $receiveAmount,
            $payCurrencyCode,
            $callbackUrl,
            $successUrl,
            $failureUrl,
            $locale
        );

        $response = $client->spectrocoinCreateOrder($request);
        if ($response instanceof SpectroCoin_CreateOrderResponse) {
            $model = VmModel::getModel('orders');
            $order['order_status'] = 'P';
            $model->updateStatusForOneOrder($orderId, $order);

            $cart->emptyCart();

            JFactory::getApplication()->redirect($response->getRedirectUrl());
            exit;
        } elseif ($response instanceof SpectroCoin_ApiError) {
            JLog::add('Error occurred. Code: ' . $response->getCode() . ' ' . $response->getMessage(), JLog::ERROR, 'plg_vmpayment_spectrocoin');
            JFactory::getApplication()->enqueueMessage("Error occurred. Code: " . $response->getCode() . " " . $response->getMessage());
            return false;
        } else {
            JLog::add('Unknown SpectroCoin error.', JLog::ERROR, 'plg_vmpayment_spectrocoin');
            JFactory::getApplication()->enqueueMessage("Unknown SpectroCoin error.");
            return false;
        }

        return true;
    }

}
