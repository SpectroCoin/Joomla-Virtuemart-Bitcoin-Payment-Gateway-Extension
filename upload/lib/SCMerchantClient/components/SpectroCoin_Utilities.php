<?php
defined('_JEXEC') or die('Restricted access');

class SpectroCoin_Utilities
{
	/**
	 * Formats currency amount with '0.0#######' format
	 * @param $amount
	 * @return string
	 */
	public static function spectrocoinFormatCurrency($amount)
	{
		$decimals = strlen(substr(strrchr(rtrim(sprintf('%.8f', $amount), '0'), "."), 1));
		$decimals = $decimals < 1 ? 1 : $decimals;
		return number_format($amount, $decimals, '.', '');
	}

	/**
	 * Encrypts the given data using the given encryption key.
	 * @param string $data The data to encrypt.
	 * @param string $encryption_key The encryption key to use.
	 * @return string The encrypted data.
	 */
	public static function spectrocoinEncryptAuthData($data, $encryption_key) {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypte_data = openssl_encrypt($data, 'aes-256-cbc', $encryption_key, 0, $iv);
        return base64_encode($encrypte_data . '::' . $iv); // Store $iv with encrypted data
    }

	/**
	 * Decrypts the given encrypted data using the given encryption key.
	 * @param string $encrypte_dataWithIv The encrypted data to decrypt.
	 * @param string $encryption_key The encryption key to use.
	 * @return string The decrypted data.
	 */
	public static function spectrocoinDecryptAuthData($encrypte_dataWithIv, $encryption_key) {
        list($encrypte_data, $iv) = explode('::', base64_decode($encrypte_dataWithIv), 2);
        return openssl_decrypt($encrypte_data, 'aes-256-cbc', $encryption_key, 0, $iv);
    }

	/**
	 * Generates a random 128-bit secret key for AES-128-CBC encryption.
	 * @return string The generated secret key encoded in base64.
	 */
	public static function spectrocoinGenerateEncryptionKey() {
		$key = openssl_random_pseudo_bytes(32); // 256 bits
		return base64_encode($key); // Encode to base64 for easy storage
	}	
}