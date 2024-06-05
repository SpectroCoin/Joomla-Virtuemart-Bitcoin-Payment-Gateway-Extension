<?php

/**
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */
defined('JPATH_BASE') or die();
defined('_JEXEC') or die('Restricted access');
define('SPECTROCOIN_VIRTUEMART_EXTENSION_VERSION', '1.0.0');

// Manually include some classes
if (!class_exists('plgVmPaymentBaseSpectrocoin')) {
    require_once(JPATH_PLUGINS . '/vmpayment/spectrocoin/base_spectrocoin_plugin.php');
}

class plgVmPaymentSpectrocoin extends plgVmPaymentBaseSpectrocoin {

    public function plgVmOnPaymentNotification() {
        self::includeClassFile('VirtueMartModelOrders', [JPATH_VM_ADMINISTRATOR, 'models', 'orders.php']);
        self::includeClassFile('SpectroCoin_ApiError', [self::SCPLUGIN_CLIENT_PATH, 'data', 'SpectroCoin_ApiError.php']);

        try {
            $input = JFactory::getApplication()->input;
            $orderNumber = $input->getString('orderId');

            $orderModel = new VirtueMartModelOrders();
            $orderId = $orderModel->getOrderIdByOrderNumber($orderNumber);
            $order = $orderModel->getOrder($orderId);

            $model_order = VmModel::getModel('orders');

            if (!$method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id)) {
                return null;
            }

            if (!$this->selectedThisElement($method->payment_element)) {
                return false;
            }

            $client = self::getSCClientByMethod($method);

            $expectedKeys = ['userId', 'merchantApiId', 'merchantId', 'apiId', 'orderId', 'payCurrency', 'payAmount', 'receiveCurrency', 'receiveAmount', 'receivedAmount', 'description', 'orderRequestId', 'status', 'sign'];
            $postData = [];
            foreach ($expectedKeys as $key) {
                if ($input->get($key, null, 'string')) {
                    $postData[$key] = $input->get($key, '', 'string');
                }
            }

            $callback = $client->spectrocoinProcessCallback($postData);

            $newStatus = '';
            switch ($callback->getStatus()) {
                case SpectroCoin_OrderStatusEnum::$Test:
                    $newStatus = $method->test_status;
                    break;
                case SpectroCoin_OrderStatusEnum::$New:
                    $newStatus = $method->new_status;
                    break;
                case SpectroCoin_OrderStatusEnum::$Pending:
                    $newStatus = $method->pending_status;
                    break;
                case SpectroCoin_OrderStatusEnum::$Expired:
                    $newStatus = $method->expired_status;
                    break;
                case SpectroCoin_OrderStatusEnum::$Failed:
                    $newStatus = $method->failed_status;
                    break;
                case SpectroCoin_OrderStatusEnum::$Paid:
                    $newStatus = $method->paid_status;
                    break;
                default:
                    echo 'Unknown order status: ' . $callback->getStatus();
                    exit;
            }

            $order['order_status'] = $newStatus;
            $model_order->updateStatusForOneOrder($orderId, $order, true);
            echo '*ok*';
        } catch (Exception $e) {
            echo $e->getMessage();
        }

        JFactory::getApplication()->close();
    }

    const SCPLUGIN_PATH = JPATH_PLUGINS . '/vmpayment/spectrocoin';
    const SCPLUGIN_CLIENT_PATH = self::SCPLUGIN_PATH . '/lib/SCMerchantClient';

    protected static function getSCClientByMethod($method) {
        self::includeClassFile('SCMerchantClient', [self::SCPLUGIN_CLIENT_PATH, 'SCMerchantClient.php']);
        return new SCMerchantClient(
            "https://test.spectrocoin.com/api/public/oauth/token",
            "https://test.spectrocoin.com/api/public",
            $method->project_id,
            $method->client_id,
            $method->client_secret
        );
    }

    public function plgVmConfirmedOrder($cart, $order) {
        if (!$method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id)) return null;
        if (!$this->selectedThisElement($method->payment_element)) return false;

        self::includeClassFile('VirtueMartModelOrders', [JPATH_VM_ADMINISTRATOR, 'models', 'orders.php']);
        self::includeClassFile('VirtueMartModelCurrency', [JPATH_VM_ADMINISTRATOR, 'models', 'currency.php']);
        self::includeClassFile('SpectroCoin_ApiError', [self::SCPLUGIN_CLIENT_PATH, 'data', 'SpectroCoin_ApiError.php']);
        self::includeClassFile('SpectroCoin_CreateOrderRequest', [self::SCPLUGIN_CLIENT_PATH, 'messages', 'SpectroCoin_CreateOrderRequest.php']);
        self::includeClassFile('SpectroCoin_CreateOrderResponse', [self::SCPLUGIN_CLIENT_PATH, 'messages', 'SpectroCoin_CreateOrderResponse.php']);

        VmConfig::loadJLang('com_virtuemart', true);
        VmConfig::loadJLang('com_virtuemart_orders', true);

        $client = self::getSCClientByMethod($method);

        $uriBaseVirtuemart = JURI::root() . 'index.php?option=com_virtuemart';

        $orderId = $order['details']['BT']->virtuemart_order_id . substr(md5(rand(1, pow(2, 16))), 0, 8);
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
            JFactory::getApplication()->enqueueMessage("Error occurred. Code: " . $response->getCode() . " " . $response->getMessage());
            return false;
        } else {
            JFactory::getApplication()->enqueueMessage("Unknown SpectroCoin error.");
            return false;
        }

        return true;
    }
}
