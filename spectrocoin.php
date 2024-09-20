<?php

declare(strict_types=1);

use SpectroCoin\SCMerchantClient\Http\OrderCallback;
use SpectroCoin\SCMerchantClient\Exception\ApiError;
use SpectroCoin\SCMerchantClient\Exception\GenericError;
use SpectroCoin\SCMerchantClient\Enum\OrderStatus;
use SpectroCoin\SCMerchantClient\SCMerchantClient;
use SpectroCoin\SCMerchantClient\Http\CreateOrderResponse;

use VirtueMartModelOrders;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Log\Log;

use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\RequestException;

/**
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

defined('JPATH_BASE') or die();
defined('_JEXEC') or die('Restricted access');
define('SPECTROCOIN_VIRTUEMART_EXTENSION_VERSION', '2.0.0');

Log::addLogger(
    [
        'text_file' => 'plg_vmpayment_spectrocoin.log.php',
        'text_entry_format' => '{DATE} {TIME} {PRIORITY} {MESSAGE}',
        'text_file_path' => JPATH_ROOT . '/administrator/logs',
    ],
    Log::ALL,
    ['plg_vmpayment_spectrocoin']
);

if (!class_exists('plgVmPaymentBaseSpectrocoin')) {
    require_once(JPATH_PLUGINS . '/vmpayment/spectrocoin/base_spectrocoin_plugin.php');
}

class plgVmPaymentSpectrocoin extends plgVmPaymentBaseSpectrocoin
{
    /**
     * Handles payment notification callback from SpectroCoin with enhanced error handling.
     *
     * @return void
     */
    public function plgVmOnPaymentNotification(): void
    {
        Log::add('plgVmOnPaymentNotification initialized.', Log::INFO, 'plg_vmpayment_spectrocoin');

        try {
            $app = Factory::getApplication();
            $input = $app->input;
            $orderId = $input->getInt('orderId');

            if (!$orderId) {
                throw new InvalidArgumentException("Invalid order ID in callback.");
            }

            $orderModel = new VirtueMartModelOrders();
            $order = $orderModel->getOrder($orderId);

            if (empty($order['details'])) {
                throw new InvalidArgumentException("Order details are empty for order ID $orderId.");
            }

            $method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id);
            if (!$method) {
                throw new InvalidArgumentException("No payment method found for Order ID: $orderId.");
            }

            if (!$this->selectedThisElement($method->payment_element)) {
                throw new InvalidArgumentException("This payment element is not selected: {$method->payment_element}");
            }

            $order_callback = $this->initCallbackFromPost();

            if (!$order_callback) {
                throw new InvalidArgumentException("Invalid callback received.");
            }

            $order_status = match ($order_callback->getStatus()) {
                OrderStatus::New ->value => $method->new_status,
                OrderStatus::Pending->value => $method->pending_status,
                OrderStatus::Expired->value => $method->expired_status,
                OrderStatus::Failed->value => $method->failed_status,
                OrderStatus::Paid->value => $method->paid_status,
                default => throw new InvalidArgumentException('Unknown order status: ' . $order_callback->getStatus()),
            };

            $order['order_status'] = $order_status;
            VmModel::getModel('orders')->updateStatusForOneOrder($orderId, $order, true);

            // Response for success
            http_response_code(200); // OK
            echo '*ok*';
        } catch (InvalidArgumentException $e) {
            Log::add("Error processing callback: {$e->getMessage()}", Log::ERROR, 'plg_vmpayment_spectrocoin');
            http_response_code(400); // Bad Request
            echo "Error processing callback: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        } catch (RequestException $e) {
            Log::add("Callback API error: {$e->getMessage()}", Log::ERROR, 'plg_vmpayment_spectrocoin');
            http_response_code(500); // Internal Server Error
            echo "Callback API error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        } catch (Exception $e) {
            Log::add("General error processing callback: {$e->getMessage()}", Log::ERROR, 'plg_vmpayment_spectrocoin');
            http_response_code(500); // Internal Server Error
            echo "General error processing callback: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }

        Factory::getApplication()->close();
    }

    /**
     * Initializes the callback data from POST request.
     * 
     * @return OrderCallback|null Returns an OrderCallback object if data is valid, null otherwise.
     */
    private function initCallbackFromPost(): ?OrderCallback
    {
        $expected_keys = ['userId', 'merchantApiId', 'merchantId', 'apiId', 'orderId', 'payCurrency', 'payAmount', 'receiveCurrency', 'receiveAmount', 'receivedAmount', 'description', 'orderRequestId', 'status', 'sign'];

        $callback_data = [];
        foreach ($expected_keys as $key) {
            if (isset($_POST[$key])) {
                $callback_data[$key] = $_POST[$key];
            }
        }

        if (empty($callback_data)) {
            $this->wc_logger->log('error', "No data received in callback");
            return null;
        }
        return new OrderCallback($callback_data);
    }

    /**
     * Creates and returns an SCMerchantClient instance using the payment method data.
     *
     * @param object $method Payment method object
     * @return SCMerchantClient
     */
    protected static function getSCClientByMethod(object $method): SCMerchantClient
    {
        return new SCMerchantClient(
            $method->project_id,
            $method->client_id,
            $method->client_secret
        );
    }

    /**
     * Processes the confirmed order and initiates the payment request to SpectroCoin.
     *
     * @param VirtueMartCart $cart
     * @param array $order
     * @return bool|null
     */
    public function plgVmConfirmedOrder($cart, array $order): ?bool
    {
        $method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id);
        if (!$method || !$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        VmConfig::loadJLang('com_virtuemart', true);
        VmConfig::loadJLang('com_virtuemart_orders', true);
        $sc_merchant_client = self::getSCClientByMethod($method);
        $uriBaseVirtuemart = Uri::root() . 'index.php?option=com_virtuemart';

        $orderId = (int) $order['details']['BT']->virtuemart_order_id;
        $orderNumber = $order['details']['BT']->order_number;

        $response = $sc_merchant_client->createOrder([
            'orderId' => $orderId,
            'description' => "Order $orderNumber at " . basename(Uri::base()),
            'receiveAmount' => round((float) $order['details']['BT']->order_total, 2),
            'receiveCurrencyCode' => shopFunctions::getCurrencyByID($method->currency_id, 'currency_code_3'),
            'callbackUrl' => Route::_($uriBaseVirtuemart . '&view=pluginresponse&task=pluginnotification&tmpl=component'),
            'successUrl' => Route::_($uriBaseVirtuemart . '&view=pluginresponse&task=pluginresponsereceived&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id),
            'failureUrl' => Route::_($uriBaseVirtuemart . '&view=cart')
        ]);

        if ($response instanceof CreateOrderResponse) {
            VmModel::getModel('orders')->updateStatusForOneOrder($orderId, ['order_status' => 'P']);
            $cart->emptyCart();
            Factory::getApplication()->redirect($response->getRedirectUrl());
            return true;
        } else if ($response instanceof ApiError || $response instanceof GenericError) {
            Log::add('Error occurred. Code: ' . $response->getCode() . ' ' . $response->getMessage(), Log::ERROR, 'plg_vmpayment_spectrocoin');
            Factory::getApplication()->enqueueMessage('Error occurred. Code: ' . $response->getCode() . ' ' . $response->getMessage());
        } else {
            Log::add('Unknown SpectroCoin error.', Log::ERROR, 'plg_vmpayment_spectrocoin');
            Factory::getApplication()->enqueueMessage('Unknown SpectroCoin error.');
        }

        return false;
    }
}
