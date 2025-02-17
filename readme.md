# SpectroCoin Joomla! VirtueMart Crypto Payment Extension

![Joomla Version](https://img.shields.io/badge/Joomla-v3.x-blue)
![Joomla Version](https://img.shields.io/badge/Joomla-v4.x-blue)
![Joomla Version](https://img.shields.io/badge/Joomla-v5.x-blue)

Integrate cryptocurrency payments seamlessly into your VirtueMart store with the [SpectroCoin VirtueMart Payment Extension](https://spectrocoin.com/plugins/accept-bitcoin-virtuemart.html). This extension facilitates the acceptance of a variety of cryptocurrencies, enhancing payment options for your customers. Easily configure and implement secure transactions for a streamlined payment process on your Joomla! website.

## Installation

Virtuemart extension has to be installed and enabled. To install Virtuemart to you Joomla! site, follow [this link](https://virtuemart.net/downloads).
1. Download latest release from github.
2. In Joomla! dashboard navigate to **System** tab -> **Extensions**.
3. **Upload Package File** -> Upload extension zip file.
4. In Joomla! dashboard go to **Components** -> **VirtueMart** -> **Payment Methods**.
5. If SpectroCoin payment method is not visible click **New** -> Enter "Payment Name", in "Payment Method" select **VM Payment - Spectrocoin**.
6. Move to [Setting up](#setting-up) section.

## Setting up

1. **[Sign up](https://auth.spectrocoin.com/signup)** for a SpectroCoin Account.
2. **[Log in](https://auth.spectrocoin.com/login)** to your SpectroCoin account.
3. On the dashboard, locate the **[Business](https://spectrocoin.com/en/merchants/projects)** tab and click on it.
4. Click on **[New project](https://spectrocoin.com/en/merchants/projects/new)**.
5. Fill in the project details and select desired settings (settings can be changed).
6. Click **"Submit"**.
7. Copy and paste the "Project id".
8. Click on the user icon in the top right and navigate to **[Settings](https://test.spectrocoin.com/en/settings/)**. Then click on **[API](https://test.spectrocoin.com/en/settings/api)** and choose **[Create New API](https://test.spectrocoin.com/en/settings/api/create)**.
9. Add "API name", in scope groups select **"View merchant preorders"**, **"Create merchant preorders"**, **"View merchant orders"**, **"Create merchant orders"**, **"Cancel merchant orders"** and click **"Create API"**.
10. Copy and store "Client id" and "Client secret". Save the settings.

**Note:** Keep in mind that if you want to use the business services of SpectroCoin, your account has to be verified.

## Test order creation on localhost

We gently suggest trying out the plugin in a server environment, as it will not be capable of receiving callbacks from SpectroCoin if it will be hosted on localhost. To successfully create an order on localhost for testing purposes, __change these 3 lines in <em>CreateOrderRequest.php</em>__:

```
$this->callbackUrl = isset($data['callbackUrl']) ? Utils::sanitizeUrl($data['callbackUrl']) : null;,
$this->successUrl = isset($data['successUrl']) ? Utils::sanitizeUrl($data['successUrl']) : null;,
$this->failureUrl = isset($data['failureUrl']) ? Utils::sanitizeUrl($data['failureUrl']) : null;
```
__To__

```
$this->callbackUrl = "https://localhost.com/";,
$this->successUrl = "https://localhost.com/";,
$this->failureUrl = "https://localhost.com/";
```
Don't forget to change it back when migrating website to public. 

## Testing Callbacks

Order callbacks in the SpectroCoin plugin allow your WordPress site to automatically process order status changes sent from SpectroCoin. These callbacks notify your server when an order’s status transitions to PAID, EXPIRED, or FAILED. Understanding and testing this functionality ensures your store handles payments accurately and updates order statuses accordingly.
 
1. Go to your SpectroCoin project settings and enable **Test Mode**.
2. Simulate a payment status:
   - **PAID**: Sends a callback to mark the order as **Completed** in WordPress.
   - **EXPIRED**: Sends a callback to mark the order as **Failed** in WordPress.
3. Ensure your `callbackUrl` is publicly accessible (local servers like `localhost` will not work).
4. Check the **Order History** in SpectroCoin for callback details. If a callback fails, use the **Retry** button to resend it.
5. Verify that:
   - The **order status** in WordPress has been updated accordingly.
   - The **callback status** in the SpectroCoin dashboard is `200 OK`.

## Changelog

### 2.0.0 MAJOR (02/17/2025):

This major update introduces several improvements, including enhanced security, updated coding standards, and a streamlined integration process. **Important:** Users must generate new API credentials (Client ID and Client Secret) in their SpectroCoin account settings to continue using the plugin. The previous private key and merchant ID functionality have been deprecated.

_Updated_ Order creation API endpoint has been updated for enhanced performance and security.

_Removed_ Private key functionality and merchant ID requirement have been removed to streamline integration.

_Added_ OAuth functionality introduced for authentication, requiring Client ID and Client Secret for secure API access.

_Added_ API error logging and message displaying in order creation process.

_Migrated_ Since HTTPful is no longer maintained, we migrated to GuzzleHttp. In this case /vendor directory was added which contains GuzzleHttp dependencies.

_Reworked_ SpectroCoin callback handling was reworked. Added appropriate callback routing for success, fail and callback.

_Added_ plg_vmpayment_spectrocoin.log.php file for logging errors from spectrocoin.php

_Updated_ Class and some method names have been updated based on PSR-12 standards.

_Updated_ Composer class autoloading has been implemented.

_Added_ _Config.php_ file has been added to store plugin configuration.

_Added_ _Utils.php_ file has been added to store utility functions.

_Added_ _GenericError.php_ file has been added to handle generic errors.

_Added_ Strict types have been added to all classes.

### 1.0.0 MAJOR (09/28/2023):

_Removed_ "API URL" field in extension configuration, since is always the same.

_Added_ Function documentation.

_Added_ FIAT currency checking, if selected shop currency is not accepted, Spectrocoin payment method will not be visible

_Maintaining_ `openssl_free_key()` deprecated function for older php versions < 8.0.

## Information

This client has been developed by SpectroCoin.com If you need any further support regarding our services you can contact us via:

E-mail: merchant@spectrocoin.com </br>
Skype: spectrocoin_merchant </br>
[Web](https://spectrocoin.com) </br>
[X (formerly Twitter)](https://twitter.com/spectrocoin) </br>
[Facebook](https://www.facebook.com/spectrocoin/)
