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
function send_cpb_uninstall_notification() {
    // Get site token for security
    $site_token = get_option( 'cpb_site_token' );
    if ( empty( $site_token ) ) {
        return; // No token, skip notification
    }

    // Get shop name using same logic as main plugin
    $shop_name = get_cpb_shop_name_from_origin();
    if ( empty( $shop_name ) ) {
        return; // No shop name, skip notification
    }

    // Determine URL based on environment
    $url = get_cpb_lifecycle_notification_url( 'uninstall' );
    if ( empty( $url ) ) {
        return; // No URL, skip notification
    }

    // Create secure payload
    $timestamp = time();
    $nonce = wp_create_nonce( 'cpb_lifecycle_uninstall_' . $timestamp );

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
function get_cpb_shop_name_from_origin() {
    $shop_name = get_option( 'cpb_shop_name' );
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
function get_cpb_lifecycle_notification_url( $action ) {
    // Use production URL for uninstall notifications
    return 'https://app.thecustomproductbuilder.com/api/integrations/woocommerce/lifecycle/' . $action;
}

// Send secure uninstall notification before cleanup
send_cpb_uninstall_notification();

// Delete plugin options
delete_option( 'cpb_shop_name' );
delete_option( 'cpb_shop_id' );
delete_option( 'cpb_use_default_initializer' );
delete_option( 'cpb_script_url' );

// Note: We intentionally do NOT delete product metadata (_is_cpb_product, _cpb_external_product_id)
// This follows WooCommerce ecosystem standards where product-related data is preserved
// for potential plugin reinstallation. Users can manually remove CPB products if needed.

// Clear any cached data
wp_cache_flush();

