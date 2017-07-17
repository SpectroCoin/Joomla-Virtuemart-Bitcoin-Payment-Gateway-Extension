<?php
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
        self::includeClassFile('ApiError', [self::SCPLUGIN_CLIENT_PATH, 'data', 'ApiError.php']);

        try {
            $orderId = VirtueMartModelOrders::getOrderIdByOrderNumber($_REQUEST['orderId']);
            $order         = VirtueMartModelOrders::getOrder($orderId);
            $modelOrder    = VmModel::getModel('orders');

            // First validations
            if (!$method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id)) return null;
            if (!$this->selectedThisElement($method->payment_element)) return false;

            $client = self::getSCClientByMethod($method);
            $client->setPrivateMerchantKey($method->private_key);

            $callback = $client->parseCreateOrderCallback($_REQUEST);
            
            $newStatus = '';
            switch ($callback->getStatus()) {
                case OrderStatusEnum::$Test:
                    $newStatus = $method->test_status;
                    break;
                case OrderStatusEnum::$New:
                    $newStatus = $method->new_status;
                    break;
                case OrderStatusEnum::$Pending:
                    $newStatus = $method->pending_status;
                    break;
                case OrderStatusEnum::$Expired:
                    $newStatus = $method->expired_status;
                    break;
                case OrderStatusEnum::$Failed:
                    $newStatus = $method->failed_status;
                    break;
                case OrderStatusEnum::$Paid:
                    $newStatus = $method->paid_status;
                    break;
                default:
                    echo 'Unknown order status: ' . $callback->getStatus();
                    exit;
            }

            $order['order_status'] = $newStatus;
            $modelOrder->updateStatusForOneOrder ($orderId, $order, true);
            echo '*ok*';
        }
        catch (Exception $e) {
            echo $e->getMessage();
        }

        JFactory::getApplication()->close();
    }

    const SCPLUGIN_PATH = JPATH_PLUGINS.DS.'vmpayment'.DS.'spectrocoin';
    const SCPLUGIN_CLIENT_PATH = self::SCPLUGIN_PATH.DS.'lib'.DS.'SCMerchantClient';

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
        $client->setPrivateMerchantKey($method->private_key);

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
            $request = new CreateOrderRequest(
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
            $request = new CreateOrderRequest(
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

        $response = $client->createOrder($request);

        if($response instanceof CreateOrderResponse) {
			$model = VmModel::getModel('orders');
			$order['order_status'] = 'C';
			$model->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, true);
			$this->emptyCart(null);
			JFactory::getApplication()->redirect($response->getRedirectUrl());
			exit;
		}
		elseif($response instanceof ApiError) {
			// TODO: translation
			JFactory::getApplication()->enqueueMessage("SpectroCoin error: " . $response->getCode() . ": " . $response->getMessage());
		}
		else {
			// TODO: translation
			JFactory::getApplication()->enqueueMessage("Unknown SpectroCoin error.");
		}

		return true;
    }
}