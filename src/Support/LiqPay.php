<?php

namespace DenizTezcan\LiqPay\Support;

/**
 * Liqpay Payment Module
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category        LiqPay
 * @package         liqpay/liqpay
 * @version         3.0
 * @author          Liqpay
 * @copyright       Copyright (c) 2014 Liqpay
 * @license         http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 *
 * EXTENSION INFORMATION
 *
 * LIQPAY API       https://www.liqpay.ua/documentation/en
 *
 */

/**
 * Payment method liqpay process
 *
 * @author      Liqpay <support@liqpay.ua>
 */
class LiqPay
{
    const CURRENCY_EUR = 'EUR';
    const CURRENCY_USD = 'USD';
    const CURRENCY_UAH = 'UAH';
    const CURRENCY_RUB = 'RUB';
    const CURRENCY_RUR = 'RUR';

    private array $_supportedCurrencies = [
        self::CURRENCY_EUR,
        self::CURRENCY_USD,
        self::CURRENCY_UAH,
        self::CURRENCY_RUB,
        self::CURRENCY_RUR,
    ];

    /**
     * Constructor.
     *
     * @param  string  $_checkout_url
     * @param  string|null  $_server_response_code
     * @param  string  $public_key
     * @param  string  $private_key
     * @param  string|NULL  $api_url  (optional)
     */
    public function __construct(
        private string $_checkout_url = 'https://www.liqpay.ua/api/3/checkout',
        private ?string $_server_response_code = null,
        string $public_key = '',
        string $private_key = '',
        string|null $api_url = 'https://www.liqpay.ua/ru/checkout/card/sandbox_i35444360364'
    ) {
        if (null === $public_key) {
            die('public_key is empty');
        }

        if (null === $private_key) {
            die('private_key is empty');
        }

        $this->_public_key = $public_key;
        $this->_private_key = $private_key;

        if (null !== $api_url) {
            $this->_api_url = $api_url;
        }
    }

    /**
     * Call API
     *
     * @param  string  $path
     * @param  array  $params
     * @param  int  $timeout
     *
     * @return string
     */
    public function api(
        string $path,
        array $params = array(),
        int $timeout = 5,
    ): string {
        if (!isset($params['version'])) {
            throw new InvalidArgumentException('version is null');
        }
        $url = $this->_api_url.$path;
        $public_key = $this->_public_key;
        $private_key = $this->_private_key;
        $data = $this->encode_params(array_merge(compact('public_key'), $params));
        $signature = $this->str_to_sign($private_key.$data.$private_key);
        $postfields = http_build_query(array(
            'data' => $data,
            'signature' => $signature
        ));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt(
            $ch,
            CURLOPT_SSL_VERIFYPEER,
            true
        ); // Avoid MITM vulnerability http://phpsecurity.readthedocs.io/en/latest/Input-Validation.html#validation-of-input-sources
        curl_setopt(
            $ch,
            CURLOPT_SSL_VERIFYHOST,
            2
        );    // Check the existence of a common name and also verify that it matches the hostname provided
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);   // The number of seconds to wait while trying to connect
        curl_setopt(
            $ch,
            CURLOPT_TIMEOUT,
            $timeout
        );          // The maximum number of seconds to allow cURL functions to execute
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $server_output = curl_exec($ch);
        $this->_server_response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return json_decode($server_output);
    }

    /**
     * encode_params
     *
     * @param  array  $params
     * @return string
     */
    private function encode_params(array $params): string
    {
        return base64_encode(json_encode($params));
    }

    /**
     * str_to_sign
     *
     * @param  string  $str
     *
     * @return string
     */
    public function str_to_sign(string $str): string
    {
        $signature = base64_encode(sha1($str, 1));

        return $signature;
    }

    /**
     * Return last api response http code
     *
     * @return string|null
     */
    public function get_response_code(): string|null
    {
        return $this->_server_response_code;
    }

    /**
     * cnb_form
     *
     * @param  array  $params
     *
     * @return string
     *
     * @throws InvalidArgumentException
     */
    public function cnb_form(string $params): string
    {
        $language = 'ru';
        if (isset($params['language']) && $params['language'] === 'en') {
            $language = 'en';
        }

        $params = $this->cnb_params($params);
        $data = $this->encode_params($params);
        $signature = $this->cnb_signature($params);

        return sprintf(
            '
            <form method="POST" action="%s" accept-charset="utf-8">
                %s
                %s
                <input type="image" src="//static.liqpay.ua/buttons/p1%s.radius.png" name="btn_text" />
            </form>
            ',
            $this->_checkout_url,
            sprintf('<input type="hidden" name="%s" value="%s" />', 'data', $data),
            sprintf('<input type="hidden" name="%s" value="%s" />', 'signature', $signature),
            $language
        );
    }

    /**
     * cnb_params
     *
     * @param  array  $params
     *
     * @return array $params
     */
    private function cnb_params(array $params): array
    {
        $params['public_key'] = $this->_public_key;

        if (!isset($params['version'])) {
            throw new InvalidArgumentException('version is null');
        }
        if (!isset($params['amount'])) {
            throw new InvalidArgumentException('amount is null');
        }
        if (!isset($params['currency'])) {
            throw new InvalidArgumentException('currency is null');
        }
        if (!in_array($params['currency'], $this->_supportedCurrencies)) {
            throw new InvalidArgumentException('currency is not supported');
        }
        if ($params['currency'] === self::CURRENCY_RUR) {
            $params['currency'] = self::CURRENCY_RUB;
        }
        if (!isset($params['description'])) {
            throw new InvalidArgumentException('description is null');
        }

        return $params;
    }

    /**
     * cnb_signature
     *
     * @param  array  $params
     *
     * @return string
     */
    public function cnb_signature(array $params): string
    {
        $params = $this->cnb_params($params);
        $private_key = $this->_private_key;

        $json = $this->encode_params($params);
        $signature = $this->str_to_sign($private_key.$json.$private_key);

        return $signature;
    }

    /**
     * cnb_form raw data for custom form
     *
     * @param $params
     * @return array
     */
    public function cnb_form_raw($params): array
    {
        $params = $this->cnb_params($params);

        return array(
            'url' => $this->_checkout_url,
            'data' => $this->encode_params($params),
            'signature' => $this->cnb_signature($params)
        );
    }

    /**
     * decode_params
     *
     * @param  string  $params
     * @return array
     */
    public function decode_params(string $params): array
    {
        return json_decode(base64_decode($params), true);
    }
}
