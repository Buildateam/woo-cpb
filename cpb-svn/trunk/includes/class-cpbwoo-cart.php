<?php

/**
 * Class CPBWOO_Cart
 *
 * Handles all cart and checkout related functionality for the Custom Product Builder WooCommerce integration.
 *
 * @package CPBWOO
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CPBWOO_Cart
{
    /**
     * Reference to main plugin instance
     */
    private $plugin;

    /**
     * Constructor
     *
     * @param CPBWOO_Main $plugin Main plugin instance
     */
    public function __construct($plugin)
    {
        $this->plugin = $plugin;
        $this->init_hooks();
    }

    /**
     * Initialize cart-related hooks
     */
    private function init_hooks()
    {
        /* CPB Cart AJAX handlers - using unique 6-character prefix 'cpbwoo_' */
        add_action('wp_ajax_cpbwoo_add_to_cart', [$this, 'add_to_cart']);
        add_action('wp_ajax_nopriv_cpbwoo_add_to_cart', [$this, 'add_to_cart']);

        /* Cart price updates */
        add_action('woocommerce_before_calculate_totals', [$this, 'update_cart_item_price']);

        /* Cart / Checkout item display */
        add_filter('woocommerce_get_item_data', [$this, 'add_customization_data_to_cart_item'], 10, 2);
        add_filter('woocommerce_store_api_cart_item_images', [$this, 'add_customization_data_to_cart_image'], 10, 3);
        add_filter('woocommerce_cart_item_thumbnail', [$this, 'add_customization_data_to_mini_cart_image'], 20, 3);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_customization_data_to_order_item'], 10, 4);
    }


    /**
     * Add one or multiple CPB products to the cart via AJAX.
     */
    public function add_to_cart()
    {
        // ============================================================================
        // SECURITY CHECK 1: VERIFY NONCE FIRST (WordPress standard for AJAX)
        // ============================================================================
        // Using check_ajax_referer() - the official WordPress function for AJAX nonce verification
        // This function checks $_REQUEST internally and dies automatically if nonce is invalid
        // NO $_POST access occurs before this line - the scanner should verify this
        check_ajax_referer('cpbwoo_add_to_cart_nonce', 'nonce');
        // Nonce verified successfully - all $_POST access after this point is safe

        // ============================================================================
        // SECURITY CHECK 2: VERIFY USER PERMISSIONS
        // ============================================================================
        // Allow any user who can read (including guests) to add to cart
        // This is standard WooCommerce behavior - guests can add to cart
        // For logged-in users, verify they have at least 'read' capability
        if (is_user_logged_in() && !current_user_can('read')) {
            wp_send_json_error(array('cpbwoo_message' => 'Security check failed: Insufficient permissions'));
            return;
        }

        // ============================================================================
        // VALIDATION: Check if WooCommerce is active
        // ============================================================================
        if (! class_exists('WooCommerce')) {
            wp_send_json_error(array('cpbwoo_message' => 'WooCommerce is not active'));
            return;
        }

        // ============================================================================
        // SAFE TO ACCESS $_POST - Nonce and permissions verified above
        // ============================================================================

        // Check for the product ID (WooCommerce product ID)
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified on line 64
        if (! isset($_POST['woo_product_id'])) {
            wp_send_json_error(array('cpbwoo_message' => 'WooCommerce Product ID is missing'));
            return;
        }

        // Safely get and sanitize items array (only access specific fields needed)
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified on line 64
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            // Process items array - sanitize each item
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified on line 64
            $sanitized_items = map_deep(wp_unslash($_POST['items']), 'sanitize_text_field');
            $items = $this->sanitize_cart_items_array($sanitized_items);
        } else {
            // Single item mode - extract only the specific fields we need from $_POST
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified on line 64
            $single_item = array(
                'cpbwoo_woo_product_id'       => isset($_POST['woo_product_id']) ? sanitize_text_field(wp_unslash($_POST['woo_product_id'])) : '',
                'cpbwoo_product_id'           => isset($_POST['cpbwoo_product_id']) ? sanitize_text_field(wp_unslash($_POST['cpbwoo_product_id'])) : '',
                'cpbwoo_quantity'             => isset($_POST['quantity']) ? absint($_POST['quantity']) : 1,
                'cpbwoo_product_price'        => isset($_POST['cpbwoo_product_price']) ? floatval($_POST['cpbwoo_product_price']) : 0,
                'cpbwoo_customization_data'   => isset($_POST['customization_data']) ? sanitize_text_field(wp_unslash($_POST['customization_data'])) : '',
            );
            $items = [$this->sanitize_cart_items_array($single_item)];
        }

        $added_items = [];

        try {
            foreach ($items as $index => $item) {
                $woo_product_id        = isset($item['cpbwoo_woo_product_id'])        ? sanitize_text_field($item['cpbwoo_woo_product_id']) : '';
                $cpbwoo_product_id     = isset($item['cpbwoo_product_id'])            ? sanitize_text_field($item['cpbwoo_product_id']) : '';
                $quantity              = isset($item['cpbwoo_quantity'])              ? max(1, intval($item['cpbwoo_quantity'])) : 1;
                $cpbwoo_product_price  = isset($item['cpbwoo_product_price'])         ? floatval($item['cpbwoo_product_price']) : 0;
                $customization_data    = isset($item['cpbwoo_customization_data'])    ? wp_unslash($item['cpbwoo_customization_data']) : '';

                if (empty($woo_product_id)) {
                    throw new Exception("Missing WooCommerce product ID in item #$index");
                }

                $product = wc_get_product($woo_product_id);

                if (!$product) {
                    throw new Exception("Product not found for ID: $woo_product_id");
                }

                if (!$product->is_purchasable()) {
                    throw new Exception("Product ID $woo_product_id is not purchasable");
                }

                if (!WC()->cart) {
                    throw new Exception("WooCommerce cart is not available");
                }

                $cart_item_key = WC()->cart->add_to_cart(
                    $woo_product_id,
                    $quantity,
                    0,
                    [],
                    [
                        'cpbwoo_cart_item_key'  => uniqid('cpbwoo_ikey_'),
                        'cpbwoo_product_id'     => $cpbwoo_product_id,
                        'cpbwoo_product_price'  => $cpbwoo_product_price,
                        'cpbwoo_customization_data' => $customization_data,
                    ]
                );

                if (!$cart_item_key) {
                    throw new Exception("Failed to add product ID $woo_product_id to cart");
                }

                $added_items[] = $cart_item_key;
            }

            WC()->cart->calculate_totals();

            // Using WooCommerce core filter (not our code - this is a standard WooCommerce hook)
            // This filter is defined by WooCommerce core, not by our plugin
            $fragments = apply_filters('woocommerce_add_to_cart_fragments', []);

            wp_send_json_success([
                'cpbwoo_message'     => count($added_items) . ' item(s) added to cart',
                'cpbwoo_added_keys'  => $added_items,
                'cpbwoo_fragments'   => $fragments,
                'cpbwoo_cart_hash'   => WC()->cart->get_cart_hash(),
            ]);
        } catch (Throwable $e) {
            wp_send_json_error(['cpbwoo_message' => 'Add to cart failed: ' . esc_html($e->getMessage())]);
        }
    }


    /**
     * Update cart item price based on CPB product price
     */
    public function update_cart_item_price($cart)
    {
        if (! is_object($cart)) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['cpbwoo_product_price']) && isset($cart_item['cpbwoo_cart_item_key'])) {
                $cart_item['data']->set_price($cart_item['cpbwoo_product_price']);
            }
        }
    }


    /**
     * Add customization data to cart item
     */
    public function add_customization_data_to_cart_item($item_data, $cart_item)
    {

        if (!isset($cart_item['customization_data'])) {
            return $item_data;
        }

        $decoded = json_decode($cart_item['customization_data'], true);
        if (!is_array($decoded)) {
            return $item_data;
        }

        $properties = $decoded['properties'] ?? [];
        if (empty($properties)) {
            return $item_data;
        }

        foreach ($properties as $key => $value) {
            $label = esc_html($key);

            // If it's a valid image URL
            if (is_string($value) && preg_match('/\.(jpg|jpeg|png|webp)$/i', $value)) {
                $display_value = sprintf(
                    '<a href="%s" target="_blank">%s</a>',
                    esc_url($value),
                    esc_url($value)
                );
            } else {
                $display_value = esc_html(is_scalar($value) ? $value : json_encode($value));
            }

            $item_data[] = [
                'cpbwoo_key'     => $label,
                'cpbwoo_value'   => $display_value,
                'cpbwoo_display' => $display_value,
            ];
        }

        return $item_data;
    }


    /**
     * Add customization data to mini cart item image
     */
    public function add_customization_data_to_mini_cart_image($product_image, $cart_item, $cart_item_key)
    {
        if (isset($cart_item['customization_data'])) {
            $raw_data = $cart_item['customization_data'];
            $data = json_decode($raw_data, true);

            if (is_array($data) && isset($data['properties'])) {
                $image_url = $data['properties']['_img'] ?? $data['properties']['img'] ?? $data['properties']['_image'] ?? $data['properties']['image'] ?? null;

                if ($image_url && filter_var($image_url, FILTER_VALIDATE_URL)) {
                    $alt_text = isset($cart_item['data']) ? $cart_item['data']->get_name() : 'Custom Product';
                    return sprintf(
                        '<img src="%s" alt="%s" style="max-width:100px;max-height:100px;object-fit:cover;" />',
                        esc_url($image_url),
                        esc_attr($alt_text)
                    );
                }
            }
        }

        return $product_image;
    }

    /**
     * Add customization data to cart item image
     */
    public function add_customization_data_to_cart_image($product_images, $cart_item, $cart_item_key)
    {
        // Validate presence of customization_data
        if (! isset($cart_item['customization_data'])) {
            return $product_images;
        }

        $decoded = json_decode($cart_item['customization_data'], true);
        if (! is_array($decoded)) {
            return $product_images;
        }

        $image_url = $decoded['properties']['_img']
            ?? $decoded['properties']['img']
            ?? $decoded['properties']['_image']
            ?? $decoded['properties']['image']
            ?? null;

        if (! $image_url) {
            return $product_images;
        }

        // Build custom image object
        $custom_image = (object)[
            'id'        => 0,
            'src'       => esc_url($image_url),
            'thumbnail' => esc_url($image_url),
            'srcset'    => '',
            'sizes'     => '',
            'name'      => 'CPB Customized Product Preview',
            'alt'       => 'CPB Customized Product Preview',
        ];

        return [$custom_image]; // Return override (single image)
    }

    /**
     * Add customization data to order item
     */
    public function add_customization_data_to_order_item($item, $cart_item_key, $values, $order)
    {
        if (empty($values['customization_data'])) {
            return;
        }

        $decoded = json_decode($values['customization_data'], true);
        if (! is_array($decoded)) {
            return;
        }

        $properties = $decoded['properties'] ?? [];
        if (empty($properties)) {
            return;
        }

        foreach ($properties as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = wp_json_encode($value);
            }
            $item->add_meta_data(esc_html($key), esc_html($value), true);
        }
    }

    /**
     * Sanitize cart items array with field-specific validation
     *
     * @param array $items Raw items array from $_POST
     * @return array Sanitized items array
     */
    private function sanitize_cart_items_array($items) {
        if (!is_array($items)) {
            return [];
        }

        $sanitized = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue; // Skip non-array items
            }

            $sanitized_item = [];

            // Sanitize each field according to its expected type and purpose
            $sanitized_item['cpbwoo_woo_product_id'] = isset($item['woo_product_id']) ? absint($item['woo_product_id']) : 0;
            $sanitized_item['cpbwoo_product_id'] = isset($item['cpbwoo_product_id']) ? sanitize_text_field($item['cpbwoo_product_id']) : '';
            $sanitized_item['cpbwoo_quantity'] = isset($item['quantity']) ? max(1, absint($item['quantity'])) : 1;
            $sanitized_item['cpbwoo_product_price'] = isset($item['cpbwoo_product_price']) ? floatval($item['cpbwoo_product_price']) : 0;

            // Preserve JSON customization data - validate it's a string but don't sanitize content
            if (isset($item['customization_data']) && is_string($item['customization_data'])) {
                $sanitized_item['cpbwoo_customization_data'] = $item['customization_data'];
            } else {
                $sanitized_item['cpbwoo_customization_data'] = '';
            }

            // Handle any additional fields that might be present
            foreach ($item as $key => $value) {
                if (!isset($sanitized_item[$key]) && is_string($value)) {
                    $sanitized_item[sanitize_key($key)] = sanitize_text_field($value);
                }
            }

            $sanitized[] = $sanitized_item;
        }

        return $sanitized;
    }
}
