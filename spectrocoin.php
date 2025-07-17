<?php

declare(strict_types=1);

use SpectroCoin\SCMerchantClient\Http\OldOrderCallback;
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
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;

use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\RequestException;

/**
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

defined('JPATH_BASE') or die();
defined('_JEXEC') or die('Restricted access');

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
        $app         = Factory::getApplication();
        $input       = $app->input;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        Log::add('plgVmOnPaymentNotification initialized.', Log::DEBUG, 'plg_vmpayment_spectrocoin');

        try {
            if (stripos($contentType, 'application/json') !== false) {
                $cb = $this->initCallbackFromJson();
                if (! $cb) {
                    throw new InvalidArgumentException('Invalid JSON callback payload');
                }

                $merchantApiId = $cb->getMerchantApiId();

                $db    = Factory::getDbo();
                $query = $db->getQuery(true)
                    ->select('payment_params')
                    ->from($db->quoteName('#__virtuemart_paymentmethods'))
                    ->where($db->quoteName('payment_element') . ' = ' . $db->quote('spectrocoin'))
                    ->where($db->quoteName('published') . ' = 1');
                $db->setQuery($query);
                $rows = $db->loadObjectList();

                $method = null;
                foreach ($rows as $row) {
                    $params = $row->payment_params;

                    preg_match('/project_id="([^"]+)"/',       $params, $m1);
                    preg_match('/client_id="([^"]+)"/',        $params, $m2);
                    preg_match('/client_secret="([^"]+)"/',    $params, $m3);

                    $projectId    = $m1[1] ?? null;
                    $clientId     = $m2[1] ?? null;
                    $clientSecret = $m3[1] ?? null;

                    if ($clientId === $merchantApiId) {
                        $method = (object)[
                            'project_id'    => $projectId,
                            'client_id'     => $clientId,
                            'client_secret' => $clientSecret,
                        ];
                        break;
                    }
                }

                if (! $method) {
                    throw new InvalidArgumentException(
                        "No SpectroCoin method found for merchantApiId {$merchantApiId}"
                    );
                }

                $apiClient  = self::getSCClientByMethod($method);
                $remoteData = $apiClient->getOrderById($cb->getUuid());
                if (empty($remoteData['orderId']) || empty($remoteData['status'])) {
                    throw new InvalidArgumentException('Malformed order data from API');
                }

                $orderId   = (int) explode('-', $remoteData['orderId'], 2)[0];
                $rawStatus = $remoteData['status'];
            } else {
                $cb = $this->initCallbackFromPost();
                if (! $cb) {
                    throw new InvalidArgumentException('Invalid form callback payload');
                }
                $orderId   = $input->getInt('orderId');
                $rawStatus = $cb->getStatus();
                if (! $orderId) {
                    throw new InvalidArgumentException('Missing orderId in POST');
                }
            }

            $orderModel = new VirtueMartModelOrders();
            $order      = $orderModel->getOrder($orderId);
            if (empty($order['details'])) {
                throw new InvalidArgumentException("Order #{$orderId} not found or has no details");
            }

            $method = $this->getVmPluginMethod(
                $order['details']['BT']->virtuemart_paymentmethod_id
            );
            if (! $method) {
                throw new InvalidArgumentException("Payment method not configured for order #{$orderId}");
            }
            if (! $this->selectedThisElement($method->payment_element)) {
                throw new InvalidArgumentException("SpectroCoin plugin not active for this order");
            }

            // --- 3) Map raw status to your VirtueMart order status ---
            $statusEnum = OrderStatus::normalize($rawStatus);
            switch ($statusEnum) {
                case OrderStatus::NEW:
                    $newVmStatus = $method->new_status;
                    break;
                case OrderStatus::PENDING:
                    $newVmStatus = $method->pending_status;
                    break;
                case OrderStatus::PAID:
                    $newVmStatus = $method->paid_status;
                    break;
                case OrderStatus::FAILED:
                    $newVmStatus = $method->failed_status;
                    break;
                case OrderStatus::EXPIRED:
                    $newVmStatus = $method->expired_status;
                    break;
                default:
                    throw new InvalidArgumentException('Unknown order status: ' . $rawStatus);
            }

            // --- 4) Do the update ---
            $order['order_status'] = $newVmStatus;
            VmModel::getModel('orders')
                ->updateStatusForOneOrder($orderId, $order, true);

            http_response_code(200);
            echo '*ok*';
        } catch (InvalidArgumentException $e) {
            Log::add("Error processing callback: {$e->getMessage()}", Log::ERROR, 'plg_vmpayment_spectrocoin');
            http_response_code(400);
            echo "Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        } catch (RequestException $e) {
            Log::add("Callback API error: {$e->getMessage()}", Log::ERROR, 'plg_vmpayment_spectrocoin');
            http_response_code(500);
            echo "API Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        } catch (\Throwable $e) {
            Log::add("Unexpected error: {$e->getMessage()}", Log::ERROR, 'plg_vmpayment_spectrocoin');
            http_response_code(500);
            echo "Internal Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }

        $app->close();
    }

    /**
     * Helper to pull your plugin params into a simple object.
     */
    protected function getPluginParams(): object
    {
        $plugin = PluginHelper::getPlugin('vmpayment', 'spectrocoin');
        $r      = new Registry($plugin->params);
        return (object)[
            'project_id'    => $r->get('project_id'),
            'client_id'     => $r->get('client_id'),
            'client_secret' => $r->get('client_secret'),
        ];
    }


    /**
     * Initializes the callback data from POST (form-encoded) request.
     * 
     * Callback format processed by this method is URL-encoded form data.
     * Example: merchantId=1387551&apiId=105548&userId=…&sign=…
     * Content-Type: application/x-www-form-urlencoded
     * These callbacks are being sent by old merchant projects.
     *
     * Extracts the expected fields from `$_POST`, validates the signature,
     * and returns an `OldOrderCallback` instance wrapping that data.
     *
     * @deprecated since v2.1.0
     *
     * @return OldOrderCallback|null  An `OldOrderCallback` if the POST body
     *                                contained valid data; `null` otherwise.
     */
    private function initCallbackFromPost(): ?OldOrderCallback
    {
        $expected_keys = ['userId', 'merchantApiId', 'merchantId', 'apiId', 'orderId', 'payCurrency', 'payAmount', 'receiveCurrency', 'receiveAmount', 'receivedAmount', 'description', 'orderRequestId', 'status', 'sign'];

        $callback_data = [];
        foreach ($expected_keys as $key) {
            if (isset($_POST[$key])) {
                $callback_data[$key] = $_POST[$key];
            }
        }

        if (empty($callback_data)) {
            Log::add("No data received in callback", Log::ERROR, 'plg_vmpayment_spectrocoin');
            return null;
        }
        return new OldOrderCallback($callback_data);
    }

    /**
     * Initializes the callback data from JSON request body.
     *
     * Reads the raw HTTP request body, decodes it as JSON, and returns
     * an OrderCallback instance if the payload is valid.
     *
     * @return OrderCallback|null  An OrderCallback if the JSON payload
     *                             contained valid data; null if the body
     *                             was empty.
     *
     * @throws \JsonException           If the request body is not valid JSON.
     * @throws \InvalidArgumentException If required fields are missing
     *                                   or validation fails in OrderCallback.
     *
     */
    private function initCallbackFromJson(): ?OrderCallback
    {
        $body = (string) \file_get_contents('php://input');
        if ($body === '') {
            Log::add("Empty JSON callback payload", Log::ERROR, 'plg_vmpayment_spectrocoin');
            return null;
        }

        $data = \json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            Log::add('JSON callback payload is not an object', Log::ERROR, 'plg_vmpayment_spectrocoin');
            return null;
        }

        return new OrderCallback(
            $data['id'] ?? null,
            $data['merchantApiId'] ?? null
        );
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
        $orderId = (int) $order['details']['BT']->virtuemart_order_id;
        $orderNumber = $order['details']['BT']->order_number;
        $uriBaseVirtuemart = Uri::root() . 'index.php?option=com_virtuemart';

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
