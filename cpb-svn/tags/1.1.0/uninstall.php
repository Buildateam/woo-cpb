<?php
/**
 * Uninstall Custom Product Builder for WooCommerce
 *
 * @package CPB
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Send secure uninstall notification to CPB service
 */
function cpbwoo_send_uninstall_notification() {
    // Get site token for security
    $site_token = get_option( 'cpbwoo_site_token' );
    if ( empty( $site_token ) ) {
        return; // No token, skip notification
    }

    // Get shop name using same logic as main plugin
    $shop_name = cpbwoo_get_shop_name_from_origin();
    if ( empty( $shop_name ) ) {
        return; // No shop name, skip notification
    }

    // Determine URL based on environment
    $url = cpbwoo_get_lifecycle_notification_url( 'uninstall' );
    if ( empty( $url ) ) {
        return; // No URL, skip notification
    }

    // Create secure payload
    $timestamp = time();
    $nonce = wp_create_nonce( 'cpbwoo_lifecycle_uninstall_' . $timestamp );

    $notification_data = [
        'shop_name' => $shop_name,
        'site_url' => get_site_url(),
        'admin_email' => get_option( 'admin_email' ),
        'action' => 'uninstall',
        'timestamp' => $timestamp,
        'nonce' => $nonce,
        'site_token' => $site_token
    ];

    // Create signature for additional security
    $json_body = wp_json_encode( $notification_data, JSON_UNESCAPED_SLASHES );
    $signature = hash_hmac( 'sha256', $json_body, $site_token );

    // Send secure notification (non-blocking)
    if ( function_exists( 'wp_remote_post' ) ) {
        wp_remote_post( $url, [
            'body' => $json_body,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'CPB-Plugin/1.0.0',
                'X-CPB-Token' => $site_token,
                'X-CPB-Shop' => $shop_name,
                'X-CPB-Signature' => $signature,
                'X-CPB-Timestamp' => $timestamp
            ],
            'timeout' => 15,
            'blocking' => false,
            'sslverify' => true
        ]);
    }
}

/**
 * Get shop name from origin (simplified version for uninstall)
 */
function cpbwoo_get_shop_name_from_origin() {
    $shop_name = get_option( 'cpbwoo_shop_name' );
    if ( ! empty( $shop_name ) ) {
        return $shop_name;
    }

    // Fallback to site URL
    $site_url = get_site_url();
    $parsed_url = wp_parse_url( $site_url );
    return isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';
}

/**
 * Get lifecycle notification URL (simplified version for uninstall)
 */
function cpbwoo_get_lifecycle_notification_url( $action ) {
    // Use production URL for uninstall notifications
    // Path matches main plugin's get_lifecycle_notification_url()
    return 'https://app.thecustomproductbuilder.com/cpb/platforms/woocommerce/plugin/' . $action;
}

// Send secure uninstall notification before cleanup
cpbwoo_send_uninstall_notification();

// Delete plugin options
delete_option( 'cpbwoo_shop_name' );
delete_option( 'cpbwoo_shop_id' );
delete_option( 'cpbwoo_use_default_initializer' );
delete_option( 'cpbwoo_script_url' );
delete_option( 'cpbwoo_site_token' );
delete_option( 'cpbwoo_previous_subscription_status' );

// Delete transients
delete_transient( 'cpbwoo_subscription_weekly_check' );
delete_transient( 'cpbwoo_exchange_rates' );

// Note: We intentionally do NOT delete product metadata (_cpbwoo_enabled, _cpbwoo_external_product_id)
// This follows WooCommerce ecosystem standards where product-related data is preserved
// for potential plugin reinstallation. Users can manually remove CPB products if needed.

// Clear any cached data
wp_cache_flush();

