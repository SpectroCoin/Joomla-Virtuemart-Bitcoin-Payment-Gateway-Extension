<?php

/**
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */
defined('JPATH_BASE') or die();
defined('_JEXEC') or die('Restricted access');
define('SPECTROCOIN_VIRTUEMART_EXTENSION_VERSION', '1.0.0');

// Manually include some 
if (!class_exists('plgVmPaymentBaseSpectrocoin')) {
    require(implode(DS, [JPATH_PLUGINS, 'vmpayment', 'spectrocoin', 'base_spectrocoin_plugin.php']));
}

class plgVmPaymentSpectrocoin extends plgVmPaymentBaseSpectrocoin {

    public function plgVmOnPaymentNotification() {
        self::includeClassFile('VirtueMartModelOrders', [JPATH_VM_ADMINISTRATOR, 'models', 'orders.php']);
        self::includeClassFile('SpectroCoin_ApiError', [self::SCPLUGIN_CLIENT_PATH, 'data', 'SpectroCoin_ApiError.php']);

        try {
            $order_Id = VirtueMartModelOrders::getOrderIdByOrderNumber($_REQUEST['orderId']);
            $order         = VirtueMartModelOrders::getOrder($order_Id);
            $model_order    = VmModel::getModel('orders');

            if (!$method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id)) return null;
            if (!$this->selectedThisElement($method->payment_element)) return false;

            $client = self::getSCClientByMethod($method);

            $expected_keys = ['userId', 'merchantApiId', 'merchantId', 'apiId', 'orderId', 'payCurrency', 'payAmount', 'receiveCurrency', 'receiveAmount', 'receivedAmount', 'description', 'orderRequestId', 'status', 'sign'];
            $post_data = [];
            foreach ($expected_keys as $key) {
                if (isset($_REQUEST[$key])) {
                    $post_data[$key] = $_REQUEST[$key]; //TODO gali buti kad $_POST
                }
            }
            $callback = $client->spectrocoinProcessCallback($post_data);
            
            $new_status = '';
            switch ($callback->getStatus()) {
                case SpectroCoin_OrderStatusEnum::$Test:
                    $new_status = $method->test_status;
                    break;
                case SpectroCoin_OrderStatusEnum::$New:
                    $new_status = $method->new_status;
                    break;
                case SpectroCoin_OrderStatusEnum::$Pending:
                    $new_status = $method->pending_status;
                    break;
                case SpectroCoin_OrderStatusEnum::$Expired:
                    $new_status = $method->expired_status;
                    break;
                case SpectroCoin_OrderStatusEnum::$Failed:
                    $new_status = $method->failed_status;
                    break;
                case SpectroCoin_OrderStatusEnum::$Paid:
                    $new_status = $method->paid_status;
                    break;
                default:
                    echo 'Unknown order status: ' . $callback->getStatus();
                    exit;
            }

            $order['order_status'] = $new_status;
            $model_order->updateStatusForOneOrder ($order_Id, $order, true);
            echo '*ok*';
        }
        catch (Exception $e) {
            echo $e->getMessage();
        }

        JFactory::getApplication()->close();
    }

    const SCPLUGIN_PATH = JPATH_PLUGINS.'/vmpayment/spectrocoin';
    const SCPLUGIN_CLIENT_PATH = self::SCPLUGIN_PATH.'/lib/SCMerchantClient';
    protected static function getSCClientByMethod($method) {
        self::includeClassFile('SCMerchantClient', [self::SCPLUGIN_CLIENT_PATH, 'SCMerchantClient.php']);
        return new SCMerchantClient(
            "https://test.spectrocoin.com/api/public/oauth/token",
            "https://test.spectrocoin.com/api/public",
            $method->project_id,
            $method->client_id,
            $method->client_secret,
            
        );
    }
    public function plgVmConfirmedOrder($cart, $order) {
        // First validations
        if (!$method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id)) return null;
        if (!$this->selectedThisElement($method->payment_element)) return false;
    
        // Include needed files
        self::includeClassFile('VirtueMartModelOrders', [JPATH_VM_ADMINISTRATOR, 'models', 'orders.php']);
        self::includeClassFile('VirtueMartModelCurrency', [JPATH_VM_ADMINISTRATOR, 'models', 'currency.php']);
        self::includeClassFile('SpectroCoin_ApiError', [self::SCPLUGIN_CLIENT_PATH, 'data', 'SpectroCoin_ApiError.php']);
        self::includeClassFile('SpectroCoin_CreateOrderRequest', [self::SCPLUGIN_CLIENT_PATH, 'messages', 'SpectroCoin_CreateOrderRequest.php']);
        self::includeClassFile('SpectroCoin_CreateOrderResponse', [self::SCPLUGIN_CLIENT_PATH, 'messages', 'SpectroCoin_CreateOrderResponse.php']);
    
        VmConfig::loadJLang('com_virtuemart', true);
        VmConfig::loadJLang('com_virtuemart_orders', true);
    
        $client = self::getSCClientByMethod($method);
    
        // Util data
        $uri_base_virtuemart = JURI::root().'index.php?option=com_virtuemart';
    
        // Prepare data
        $order_id              = $order['details']['BT']->virtuemart_order_id;
        $payment_method_id     = $order['details']['BT']->virtuemart_paymentmethod_id;
        $order_number          = $order['details']['BT']->order_number;
        $receive_currency_code = shopFunctions::getCurrencyByID($method->currency_id, 'currency_code_3');
        $pay_currency_code     = 'BTC';
        $receive_amount        = round($order['details']['BT']->order_total, 2); // @todo - change to utility class method
        $description           = "Order $order_number at " . basename(JUri::base()); // TODO: translation
        $callback_url          = (JROUTE::_($uri_base_virtuemart.'&view=pluginresponse&task=pluginnotification&tmpl=component'));
        $success_url           = (JROUTE::_($uri_base_virtuemart.'&view=pluginresponse&task=pluginresponsereceived&pm='.$payment_method_id));
        $failure_url           = (JROUTE::_($uri_base_virtuemart.'&view=cart'));
        $locale                = explode('-', JFactory::getLanguage()->getTag())[0];
        
        $request = new SpectroCoin_CreateOrderRequest(
            $order_id,
            $description,
            null,
            $receive_currency_code,
            $receive_amount,
            $pay_currency_code,
            $callback_url,
            $success_url,
            $failure_url,
            $locale
        );
    
        $response = $client->spectrocoinCreateOrder($request);
        if($response instanceof SpectroCoin_CreateOrderResponse) {
            $model = VmModel::getModel('orders');
            $order['order_status'] = 'P';
            $model->updateStatusForOneOrder($order_id, $order);
            
            // Clear the cart to ensure the order ID is not reused
            $cart->emptyCart();
            
            JFactory::getApplication()->redirect($response->getRedirectUrl());
            exit;
        }
        elseif($response instanceof SpectroCoin_ApiError) {
            JFactory::getApplication()->enqueueMessage( "Error occurred. Code: " . $response->getCode() . " " . $response->getMessage());
            return false;
        }
        else {
            JFactory::getApplication()->enqueueMessage("Unknown SpectroCoin error.");
            return false;
        }
    
        return true;
    }

}