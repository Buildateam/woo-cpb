<?php

/**
 * Class CPB_Cart
 *
 * Handles all cart and checkout related functionality for the Custom Product Builder WooCommerce integration.
 *
 * @package CPB
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CPB_Cart
{
    /**
     * Reference to main plugin instance
     */
    private $plugin;

    /**
     * Constructor
     * 
     * @param CPB_Lite $plugin Main plugin instance
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
        /* CPB Cart AJAX handlers */
        add_action('wp_ajax_cpb_add_to_cart', [$this, 'add_to_cart']);
        add_action('wp_ajax_nopriv_cpb_add_to_cart', [$this, 'add_to_cart']);

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

        error_log('[CPB][add_to_cart][TEMP LOG] Incoming request: ' . print_r($_POST, true));

        // Check if WooCommerce is active
        if (! class_exists('WooCommerce')) {
            wp_send_json_error(array('message' => 'WooCommerce is not active'));
            return;
        }

        // Verify nonce for security
        if (! isset($_POST['nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cpb_add_to_cart_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        // Check for the product ID (WooCommerce product ID)
        if (! isset($_POST['woo_product_id'])) {
            wp_send_json_error(array('message' => 'WooCommerce Product ID is missing'));
            return;
        }

        // Safely get and sanitize items array (preserve structure for complex data)
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            // First sanitize the array, then unslash
            $sanitized_items = map_deep(wp_unslash($_POST['items']), 'sanitize_text_field');
            $items = $this->sanitize_cart_items_array($sanitized_items);
        } else {
            // Sanitize the entire $_POST array
            $sanitized_post = map_deep(wp_unslash($_POST), 'sanitize_text_field');
            $items = [$this->sanitize_cart_items_array($sanitized_post)];
        }

        $added_items = [];

        try {
            foreach ($items as $index => $item) {
                $woo_product_id     = isset($item['woo_product_id'])     ? sanitize_text_field($item['woo_product_id']) : '';
                $cpb_product_id     = isset($item['cpb_product_id'])     ? sanitize_text_field($item['cpb_product_id']) : '';
                $quantity           = isset($item['quantity'])           ? max(1, intval($item['quantity'])) : 1;
                $cpb_product_price  = isset($item['cpb_product_price'])  ? floatval($item['cpb_product_price']) : 0;
                $customization_data = isset($item['customization_data']) ? wp_unslash($item['customization_data']) : '';

                if (empty($woo_product_id)) {
                    throw new Exception("Missing WooCommerce product ID in item #$index");
                }

                $product = wc_get_product($woo_product_id);

                if (!$product) {
                    throw new Exception("Product not found for ID: $woo_product_id");
                }

                error_log("[CPB][add_to_cart] Product details - ID: $woo_product_id, Status: " . $product->get_status() . ", Type: " . $product->get_type() . ", Purchasable: " . ($product->is_purchasable() ? 'yes' : 'no') . ", In Stock: " . ($product->is_in_stock() ? 'yes' : 'no'));

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
                        'cpb_cart_item_key' => uniqid('cpb_ikey_'),
                        'cpb_product_id'     => $cpb_product_id,
                        'cpb_product_price'  => $cpb_product_price,
                        'customization_data' => $customization_data,
                    ]
                );

                if (!$cart_item_key) {
                    throw new Exception("Failed to add product ID $woo_product_id to cart");
                }

                $added_items[] = $cart_item_key;
                error_log("[CPB][add_to_cart] Added item #$index (product $woo_product_id) with key $cart_item_key");
            }

            WC()->cart->calculate_totals();
            $fragments = apply_filters('woocommerce_add_to_cart_fragments', []);

            wp_send_json_success([
                'message'     => count($added_items) . ' item(s) added to cart',
                'added_keys'  => $added_items,
                'fragments'   => $fragments,
                'cart_hash'   => WC()->cart->get_cart_hash(),
            ]);
        } catch (Throwable $e) {
            error_log('[CPB][add_to_cart] Error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Add to cart failed: ' . $e->getMessage()]);
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
            if (isset($cart_item['cpb_product_price']) && isset($cart_item['cpb_cart_item_key'])) {
                $cart_item['data']->set_price($cart_item['cpb_product_price']);
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
                'key'     => $label,
                'value'   => $display_value,
                'display' => $display_value,
            ];
        }

        return $item_data;
    }


    /**
     * Add customization data to mini cart item image
     */
    public function add_customization_data_to_mini_cart_image($product_image, $cart_item, $cart_item_key)
    {
        error_log("[CPB][MiniCartImage] Processing cart item key: $cart_item_key");

        if (isset($cart_item['customization_data'])) {
            $raw_data = $cart_item['customization_data'];
            error_log("[CPB][MiniCartImage] Raw customization_data: " . $raw_data);

            $data = json_decode($raw_data, true);
            error_log("[CPB][MiniCartImage] Decoded data: " . print_r($data, true));

            $image_url = $data['properties']['_img'] ?? $data['properties']['img'] ?? $data['properties']['_image'] ?? $data['properties']['image'] ?? null;
            error_log("[CPB][MiniCartImage] Image URL found: " . $image_url);

            if ($image_url && filter_var($image_url, FILTER_VALIDATE_URL)) {
                $alt_text = isset($cart_item['data']) ? $cart_item['data']->get_name() : 'Custom Product';
                return sprintf(
                    '<img src="%s" alt="%s" style="max-width:100px;max-height:100px;object-fit:cover;" />',
                    esc_url($image_url),
                    esc_attr($alt_text)
                );
            }
        } else {
            error_log("[CPB][MiniCartImage] No customization_data found for cart item key: $cart_item_key");
        }

        return $product_image;
    }

    /**
     * Add customization data to cart item image
     */
    public function add_customization_data_to_cart_image($product_images, $cart_item, $cart_item_key)
    {
        error_log("[CPB][CartImage] Processing cart item key: $cart_item_key");

        // DEBUG fallback image
        // $image_path = "https://picsum.photos/seed/$cart_item_key/200";

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
            $sanitized_item['woo_product_id'] = isset($item['woo_product_id']) ? absint($item['woo_product_id']) : 0;
            $sanitized_item['cpb_product_id'] = isset($item['cpb_product_id']) ? sanitize_text_field($item['cpb_product_id']) : '';
            $sanitized_item['quantity'] = isset($item['quantity']) ? max(1, absint($item['quantity'])) : 1;
            $sanitized_item['cpb_product_price'] = isset($item['cpb_product_price']) ? floatval($item['cpb_product_price']) : 0;

            // Preserve JSON customization data - validate it's a string but don't sanitize content
            if (isset($item['customization_data']) && is_string($item['customization_data'])) {
                $sanitized_item['customization_data'] = $item['customization_data'];
            } else {
                $sanitized_item['customization_data'] = '';
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
