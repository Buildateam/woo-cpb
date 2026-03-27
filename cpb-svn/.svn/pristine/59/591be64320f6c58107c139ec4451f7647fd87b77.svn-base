<?php

/**
 * Class CPBWOO_Currency
 *
 * Handles all currency related functionality for the Custom Product Builder WooCommerce integration.
 *
 * @package CPBWOO
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class CPBWOO_Currency
{
    /**
     * Reference to main plugin instance
     */
    private $plugin;

    /**
     * Base currency for exchange rates
     */
    const BASE_CURRENCY = 'USD';

    /**
     * Transient key for exchange rates
     */
    const RATES_TRANSIENT_KEY = 'cpbwoo_exchange_rates';

    /**
     * Cache duration for exchange rates
     */
    const RATES_CACHE_DURATION = HOUR_IN_SECONDS;

    /**
     * Get CPB Backend API endpoint for currency rates
     * Uses domain from Initializer URL setting or falls back to CPB_URL constant
     */
    private function get_cpb_currency_api_url()
    {
        // Use the main plugin's static base URL method
        $base_url = CPBWOO_Main::get_cpb_base_url();

        return $base_url . '/cpb/common/currency/rates';
    }

    /**
     * Fallback exchange rate API endpoint
     */
    const FALLBACK_EXCHANGE_API_URL = 'https://api.exchangerate-api.com/v4/latest/USD';

    /**
     * Constructor
     *
     * @param CPBWOO_Main $plugin Main plugin instance
     */
    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Get current currency from optionally installed multi-currency plugins
     *
     * @return string Current currency code, e.g. 'USD', 'EUR', etc.
     */
    public function get_current_currency()
    {
        // Validate that WooCommerce is available
        if (!function_exists('get_woocommerce_currency')) {
            return 'USD'; // Safe fallback
        }

        // Try getting currency from WPML (https://wordpress.org/plugins/woocommerce-multilingual/)
        // NOTE: 'wcml_price_currency' is a filter hook from WPML plugin, not our code
        if (function_exists('apply_filters')) {
            $currency = apply_filters('wcml_price_currency', null);
            $currency = $this->sanitize_currency_code($currency);
            if ($this->is_valid_currency_code($currency)) {
                return $currency;
            }
        }

        // Try getting currency from WOOCS (https://wordpress.org/plugins/woocommerce-currency-switcher/)
        // NOTE: $WOOCS is a global variable from WOOCS plugin, not our code
        global $WOOCS;
        if (is_object($WOOCS) && isset($WOOCS->current_currency) && !empty($WOOCS->current_currency)) {
            $woocs_currency = $this->sanitize_currency_code($WOOCS->current_currency);
            if ($this->is_valid_currency_code($woocs_currency)) {
                return $woocs_currency;
            }
        }

        // Try getting currency from WooCommerce session
        if (function_exists('WC') && WC() && WC()->session) {
            $session_currency = WC()->session->get('chosen_currency')
                ?: WC()->session->get('client_currency')
                ?: WC()->session->get('woocs_current_currency')
                ?: WC()->session->get('wmc_current_currency');

            $session_currency = $this->sanitize_currency_code($session_currency);
            if ($this->is_valid_currency_code($session_currency)) {
                return $session_currency;
            }
        }

        // Try getting currency from cookies
        $cookie_currency = null;
        if (isset($_COOKIE['woocs_current_currency']) && !empty($_COOKIE['woocs_current_currency'])) {
            $cookie_currency = $this->sanitize_currency_code(sanitize_text_field(wp_unslash($_COOKIE['woocs_current_currency'])));
        } elseif (isset($_COOKIE['wmc_current_currency']) && !empty($_COOKIE['wmc_current_currency'])) {
            $cookie_currency = $this->sanitize_currency_code(sanitize_text_field(wp_unslash($_COOKIE['wmc_current_currency'])));
        }

        if ($this->is_valid_currency_code($cookie_currency)) {
            return $cookie_currency;
        }

        // Try Aelia Currency Switcher (https://aelia.co/wordpress-plugins/woocommerce-multi-currency/)
        // NOTE: 'wc_aelia_cs_selected_currency' is a filter hook from Aelia Currency Switcher plugin, not our code
        if (function_exists('apply_filters')) {
            $aelia_currency = apply_filters('wc_aelia_cs_selected_currency', null);
            $aelia_currency = $this->sanitize_currency_code($aelia_currency);
            $default_currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
            if ($this->is_valid_currency_code($aelia_currency) && $aelia_currency !== $default_currency) {
                return $aelia_currency;
            }
        }

        // Fallback to WooCommerce default currency
        $default_currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null;
        if ($this->is_valid_currency_code($default_currency)) {
            return $default_currency;
        }

        // Ultimate fallback
        return 'USD';
    }

    /**
     * Safely sanitize currency code input
     *
     * @param mixed $currency_code Raw currency code input
     * @return string|null Sanitized currency code or null if invalid
     */
    private function sanitize_currency_code($currency_code)
    {
        if (empty($currency_code) || !is_string($currency_code)) {
            return null;
        }

        // Use WordPress sanitize_text_field if available, otherwise manual sanitization
        if (function_exists('sanitize_text_field')) {
            $sanitized = sanitize_text_field($currency_code);
        } else {
            // Manual sanitization: remove non-alphanumeric characters and convert to uppercase
            $sanitized = preg_replace('/[^A-Za-z0-9]/', '', $currency_code);
        }

        // Convert to uppercase and limit to 3 characters
        $sanitized = strtoupper(substr($sanitized, 0, 3));

        return strlen($sanitized) === 3 ? $sanitized : null;
    }

    /**
     * Validate if a currency code is valid
     *
     * @param string|null $currency_code Currency code to validate
     * @return bool True if valid, false otherwise
     */
    private function is_valid_currency_code($currency_code)
    {
        if (empty($currency_code) || !is_string($currency_code)) {
            return false;
        }

        // Basic validation: 3 uppercase letters
        if (!preg_match('/^[A-Z]{3}$/', $currency_code)) {
            return false;
        }

        // Check against WooCommerce supported currencies if available
        if (function_exists('get_woocommerce_currencies')) {
            $supported_currencies = get_woocommerce_currencies();
            return array_key_exists($currency_code, $supported_currencies);
        }

        // Basic list of common currencies as fallback
        $common_currencies = [
            'USD',
            'EUR',
            'GBP',
            'JPY',
            'AUD',
            'CAD',
            'CHF',
            'CNY',
            'SEK',
            'NZD',
            'MXN',
            'SGD',
            'HKD',
            'NOK',
            'TRY',
            'RUB',
            'INR',
            'BRL',
            'ZAR',
            'KRW'
        ];

        return in_array($currency_code, $common_currencies, true);
    }


    /**
     * Get exchange rates with caching
     * 
     * @return array Exchange rates
     */
    public function get_exchange_rates()
    {
        $rates = get_transient(self::RATES_TRANSIENT_KEY);

        if (false === $rates) {
            $rates = $this->fetch_exchange_rates_from_api();

            if (!$rates) {
                $rates = $this->get_fallback_rates();
            }
        }

        return $rates;
    }


    /**
     * Fetch exchange rates from CPB backend API with fallback to external API
     *
     * @return array|false Exchange rates or false on failure
     */
    private function fetch_exchange_rates_from_api()
    {
        // First try CPB backend API
        $rates = $this->fetch_rates_from_cpb_backend();

        if ($rates) {
            return $rates;
        }

        // Fallback to external API
        $rates = $this->fetch_rates_from_fallback_api();

        if ($rates) {
            return $rates;
        }

        return false;
    }

    /**
     * Fetch exchange rates from CPB backend
     *
     * @return array|false Exchange rates or false on failure
     */
    private function fetch_rates_from_cpb_backend()
    {
        $api_url = $this->get_cpb_currency_api_url();
        $response = wp_remote_get($api_url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'CPB-WooCommerce-Plugin/' . CPBWOO_Main::PLUGIN_VERSION
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['success']) || !$data['success']) {
            return false;
        }

        if (isset($data['data']['rates'])) {
            $rates = $data['data']['rates'];
            // Cache for specified duration
            set_transient(self::RATES_TRANSIENT_KEY, $rates, self::RATES_CACHE_DURATION);
            return $rates;
        }

        return false;
    }

    /**
     * Fetch exchange rates from fallback external API
     *
     * @return array|false Exchange rates or false on failure
     */
    private function fetch_rates_from_fallback_api()
    {
        $response = wp_remote_get(self::FALLBACK_EXCHANGE_API_URL, array(
            'timeout' => 10
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['rates'])) {
            $rates = $data['rates'];
            // Cache for specified duration
            set_transient(self::RATES_TRANSIENT_KEY, $rates, self::RATES_CACHE_DURATION);
            return $rates;
        }

        return false;
    }

    /**
     * Get fallback exchange rates when API fails
     *
     * @return array Fallback exchange rates
     */
    private function get_fallback_rates()
    {
        return array(
            'USD' => 1,
            'EUR' => 0.85,
            'GBP' => 0.73,
            'CAD' => 1.25,
            'AUD' => 1.35,
            'JPY' => 110,
            'CHF' => 0.92,
            'CNY' => 6.45
        );
    }

    /**
     * Get currency data for frontend localization
     * Only includes base currency and current currency rates to reduce data size
     *
     * @return array Currency data array
     */
    public function get_currency_data_for_frontend()
    {
        $all_exchange_rates = $this->get_exchange_rates();
        $current_currency = $this->get_current_currency();
        $base_currency = self::BASE_CURRENCY;

        // Filter to only include base currency and current currency
        $filtered_rates = array();

        // Always include base currency rate
        if (isset($all_exchange_rates[$base_currency])) {
            $filtered_rates[$base_currency] = $all_exchange_rates[$base_currency];
        }

        // Include current currency rate if different from base
        if ($current_currency !== $base_currency && isset($all_exchange_rates[$current_currency])) {
            $filtered_rates[$current_currency] = $all_exchange_rates[$current_currency];
        }

        return array(
            'cpbwoo_currency'       => $current_currency,
            'cpbwoo_exchange_rates' => $filtered_rates,
            'cpbwoo_base_currency'  => $base_currency
        );
    }

    /**
     * Convert price from one currency to another
     * 
     * @param float $amount Amount to convert
     * @param string $from_currency Source currency
     * @param string $to_currency Target currency
     * @return float Converted amount
     */
    public function convert_price($amount, $from_currency, $to_currency)
    {
        if ($from_currency === $to_currency) {
            return $amount;
        }

        $rates = $this->get_exchange_rates();

        $from_rate = $rates[$from_currency] ?? 1;
        $to_rate = $rates[$to_currency] ?? 1;


        $base_amount = $amount / $from_rate;
        $converted_amount = $base_amount * $to_rate;

        return $converted_amount;
    }
}
