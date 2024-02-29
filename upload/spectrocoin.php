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
            $callback = $client->spectrocoin_process_callback($post_data);
            
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
            $method->api_url, 
            $method->merchant_id,
            $method->project_id,
            false
        );
    }
    public function plgVmConfirmedOrder($cart, $order) {
        // First validations
        if (!$method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id)) return null;
        if (!$this->selectedThisElement($method->payment_element)) return false;

        // Include needed files
        self::includeClassFile('VirtueMartModelOrders', [JPATH_VM_ADMINISTRATOR, 'models', 'orders.php']);
        self::includeClassFile('VirtueMartModelCurrency', [JPATH_VM_ADMINISTRATOR, 'models', 'currency.php']);
        self::includeClassFile('ApiError', [self::SCPLUGIN_CLIENT_PATH, 'data', 'ApiError.php']);
        self::includeClassFile('CreateOrderRequest', [self::SCPLUGIN_CLIENT_PATH, 'messages', 'CreateOrderRequest.php']);
        self::includeClassFile('CreateOrderResponse', [self::SCPLUGIN_CLIENT_PATH, 'messages', 'CreateOrderResponse.php']);

        VmConfig::loadJLang('com_virtuemart', true);
        VmConfig::loadJLang('com_virtuemart_orders', true);

        $client = self::getSCClientByMethod($method);

        // Util data
        $uriBaseVirtuemart = JURI::root().'index.php?option=com_virtuemart';

        // Prepare data
        $orderID         = $order['details']['BT']->virtuemart_order_id;
        $paymentMethodID = $order['details']['BT']->virtuemart_paymentmethod_id;
        $orderNumber     = $order['details']['BT']->order_number;
        $currencyCode    = shopFunctions::getCurrencyByID($method->currency_id, 'currency_code_3');
        $total           = round($order['details']['BT']->order_total, 2); // @todo - change to utility class method
        $description     = "Order $orderNumber at " . basename(JUri::base()); // TODO: translation
        $culture         = explode('-', JFactory::getLanguage()->getTag())[0];
        $uriCallback     = (JROUTE::_($uriBaseVirtuemart.'&view=pluginresponse&task=pluginnotification&tmpl=component'));
        $uriSuccess      = (JROUTE::_($uriBaseVirtuemart.'&view=pluginresponse&task=pluginresponsereceived&pm='.$paymentMethodID));
        $uriFailure      = (JROUTE::_($uriBaseVirtuemart.'&view=cart'));

        if ($method->payment_method == 'pay') {
            // Create request
            $request = new SpectroCoin_CreateOrderRequest(
                $orderNumber,
                $currencyCode,
                $total,
                $currencyCode,
                null,
                $description,
                $culture,
                $uriCallback,
                $uriSuccess,
                $uriFailure
            );
        }
        else {
            // Create request
            $request = new SpectroCoin_CreateOrderRequest(
                $orderNumber,
                $currencyCode,
                null,
                $currencyCode,
                $total,
                $description,
                $culture,
                $uriCallback,
                $uriSuccess,
                $uriFailure
            );
        }

        $response = $client->spectrocoin_create_order($request);

        if($response instanceof SpectroCoin_CreateOrderResponse) {
			$model = VmModel::getModel('orders');
			$order['order_status'] = 'C';
			$model->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, true);
			$this->emptyCart(null);
			JFactory::getApplication()->redirect($response->getRedirectUrl());
			exit;
		}
		elseif($response instanceof SpectroCoin_ApiError) {
            JFactory::getApplication()->enqueueMessage( "Error occured. Code: " . $response->getCode() . " " . $response->getMessage());
            return false;
		}
		else {
			JFactory::getApplication()->enqueueMessage("Unknown SpectroCoin error.");
            return false;
		}

		return true;
    }

}