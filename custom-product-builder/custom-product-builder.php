<?php
/**
 * Plugin Name: CPB - Custom Product Builder for WooCommerce
 * Plugin URI: https://cpbapp.com/integrations
 * Description: Advanced product customization solution with drag-and-drop builder interface. Requires active WooCommerce.com subscription.
 * Version: 1.1.0
 * Author: Custom Product Builder
 * Author URI: https://cpbapp.com
 * Text Domain: cpb
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.8
 * WC requires at least: 5.0
 * WC tested up to: 9.4
 * Requires PHP: 7.4
 * Network: false
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // No direct access.
}

// Check if WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="error"><p><strong>Custom Product Builder</strong> requires WooCommerce to be installed and active.</p></div>';
    });
    return;
}

// Check WooCommerce version
add_action( 'plugins_loaded', function() {
    if ( class_exists( 'WooCommerce' ) && version_compare( WC()->version, '5.0', '<' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="error"><p><strong>Custom Product Builder</strong> requires WooCommerce 5.0 or higher. You are running ' . esc_html( WC()->version ) . '</p></div>';
        });
        return;
    }
});

class CPB_Lite {

    private $cart;
    private $currency;

    /** Plugin constants */
    const PLUGIN_NAME = 'CPB - Custom Product Builder for WooCommerce';
    const VERSION = '1.1.0';
    const PLUGIN_VERSION = '1.1.0'; // Backward compatibility
    const TEXT_DOMAIN = 'cpb';
    const OPTION_SHOP_NAME = 'cpb_shop_name';

    /** URL of the storefront initializer script */
    const CPB_URL = 'https://app.thecustomproductbuilder.com/cpb?platform=woocommerce';
    const SCRIPT_URL = 'https://app.thecustomproductbuilder.com/cpb/storefront/initializer.js?platform=woocommerce';

    const pluginName = 'CPB';

    const OPTION_SHOP_ID   = 'cpb_shop_id';
    const OPTION_USE_DEFAULT_INITIALIZER = 'cpb_use_default_initializer';
    const OPTION_SCRIPT_URL = 'cpb_script_url';

    /** Product-level meta key that links Woo and the external builder. */
    const META_EXT_ID = '_cpb_external_product_id';

    public function __construct() {
        /* Initialization -------------------------------------------- */
        add_action( 'init', [ $this, 'load_textdomain' ] );
        add_action( 'before_woocommerce_init', [ $this, 'declare_hpos_compatibility' ] );

        // Initialize WooCommerce.com updater
        add_action( 'plugins_loaded', [ $this, 'init_wc_updater' ] );

        // Weekly subscription status check
        add_action( 'admin_init', [ $this, 'weekly_subscription_check' ] );

        // Check if WooCommerce is active
        if ( ! $this->is_woocommerce_active() ) {
            add_action( 'admin_notices', [ $this, 'woocommerce_missing_notice' ] );
            return;
        }

        /* Admin UI -------------------------------------------------- */
        add_action( 'admin_menu',  [ $this, 'add_settings_page' ] );
        add_action( 'admin_init',  [ $this, 'register_settings' ] );

        // Temporarily hidden - External ID field
        // add_action( 'woocommerce_product_options_general_product_data',
        //             [ $this, 'add_external_id_field' ] );
        
        // Temporarily hidden - External ID field save action
        // add_action( 'woocommerce_admin_process_product_object',
        //             [ $this, 'save_external_id_field' ] );
        
        /* Plugin list page customization */
        add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), [ $this, 'add_plugin_action_links' ] );
        add_filter( 'plugin_row_meta', [ $this, 'add_plugin_row_meta' ], 10, 2 );
        add_action( 'admin_head-plugins.php', [ $this, 'add_plugin_list_icon' ] );
        
        /* Front‑end hooks --------------------------------------------------- */
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_initializer' ], 10 );
        // add_action( 'wp',                [ $this, 'strip_product_layout' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_inline_styles' ], 20 );
        add_filter( 'body_class',        [ $this, 'add_body_class' ] );

        add_action('woocommerce_product_options_general_product_data', [ $this, 'add_cpb_enable_product_checkbox' ], 60);
        add_action('woocommerce_process_product_meta', [ $this, 'save_cpb_enable_product_checkbox' ]);
        
        /* Hide default Add to Cart button for CPB products in shop/category pages */
        add_filter('woocommerce_loop_add_to_cart_link', [ $this, 'hide_add_to_cart_for_cpb_products' ], 10, 2);

        /* Hide price for CPB products in shop/category pages */
        add_filter('woocommerce_get_price_html', [ $this, 'hide_price_for_cpb_products' ], 10, 2);

        add_action('woocommerce_product_options_general_product_data', [ $this, 'render_admin_button_backend' ], 50);
        
        $this->init_currency_handler();
        $this->init_cart_handler();
    }

    private function init_currency_handler() {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-cpb-currency.php';
        $this->currency = new CPB_Currency( $this );
    }

    private function init_cart_handler() {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-cpb-cart.php';
        $this->cart = new CPB_Cart( $this );
    }

    /**
     * Get base URL for CPB app URLs
     * Uses domain from custom script URL setting or falls back to CPB_URL constant
     */
    public static function get_cpb_base_url() {
        // Check for custom script URL in settings first
        $custom_script_url = get_option( self::OPTION_SCRIPT_URL, '' );

        if ( ! empty( $custom_script_url ) && filter_var( $custom_script_url, FILTER_VALIDATE_URL ) ) {
            // Extract domain from custom script URL
            $parsed_url = parse_url( $custom_script_url );
            $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        } else {
            // Fall back to extracting from CPB_URL constant
            $parsed_url = parse_url( self::CPB_URL );
            $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        }

        return $base_url;
    }

    /**
     * Build CPB app URL with shop name and encrypted store data
     */
    private function build_cpb_app_url( $path = '/cpb', $additional_params = array() ) {
        $base_url = self::get_cpb_base_url();
        $shop_name = $this->get_shop_name_from_origin();
        $encrypted_data = $this->encrypt_store_data();

        // Start with base URL and path
        $url = $base_url . $path;

        // Add platform parameter
        $params = array(
            'platform' => 'woocommerce',
            'platform_shop_name' => rawurlencode( $shop_name ),
            'store_data' => rawurlencode( $encrypted_data )
        );

        // Merge additional parameters
        $params = array_merge( $params, $additional_params );

        // Build query string
        $query_string = http_build_query( $params );

        return $url . '?' . $query_string;
    }

    /**
     * Add custom action links to plugin list page
     */
    public function add_plugin_action_links( $links ) {
        $cpb_url = $this->build_cpb_app_url();

        $custom_links = array(
            'open_app' => sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" style="color: #2271b1;">%s</a>',
                esc_url( $cpb_url ),
                __( 'Open App', 'cpb' )
            ),
            'docs' => sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" style="color: #2271b1;">%s</a>',
                esc_url( 'https://buildateam.zendesk.com/hc/en-us/articles/40833069185300-Integrations-woocommerce-instructions' ),
                __( 'Docs', 'cpb' )
            ),
            'settings' => sprintf(
                '<a href="%s" style="color: #2271b1;">%s</a>',
                esc_url( admin_url( 'options-general.php?page=cpb-settings' ) ),
                __( 'Settings', 'cpb' )
            )
        );

        // Add custom links at the beginning
        return array_merge( $custom_links, $links );
    }

    /**
     * Add custom meta links to plugin list page (appears after "Visit plugin site")
     */
    public function add_plugin_row_meta( $links, $file ) {
        if ( plugin_basename( __FILE__ ) === $file ) {
            $meta_links = array(
                'app_docs' => sprintf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                    esc_url( 'https://buildateam.zendesk.com/hc/en-us/categories/360001615812-Custom-Product-Builder-App' ),
                    __( 'App Docs', 'cpb' )
                )
            );

            $links = array_merge( $links, $meta_links );
        }

        return $links;
    }

    public function add_settings_page() {
        add_options_page(
            __( 'Custom Product Builder', self::TEXT_DOMAIN ), // page_title
            __( 'CPB Settings', self::TEXT_DOMAIN ),           // menu_title
            'manage_options',                                   // capability
            'cpb-settings',                                     // menu_slug
            [ $this, 'settings_page_html' ]                    // callback
            // Note: add_options_page doesn't support icon parameter like add_menu_page
        );
    }

    /** Registers our shop-level options. */
    public function register_settings() {
        register_setting( 'cpb_settings', self::OPTION_SHOP_NAME );
        register_setting( 'cpb_settings', self::OPTION_SHOP_ID   );
        register_setting( 'cpb_settings', self::OPTION_USE_DEFAULT_INITIALIZER );
        register_setting( 'cpb_settings', self::OPTION_SCRIPT_URL );
    }

    /** Renders the settings page. */
    public function settings_page_html() { ?>
        <div class="wrap">
            <h1>
                <img src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/icon-32x32.png'); ?>"
                     alt="<?php esc_attr_e( 'CPB Icon', self::TEXT_DOMAIN ); ?>"
                     style="width: 32px; height: 32px; vertical-align: sub; margin-right: 10px;">
                <?php esc_html_e( 'Custom Product Builder Settings', self::TEXT_DOMAIN ); ?>
            </h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'cpb_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Use Default Initializer</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr( self::OPTION_USE_DEFAULT_INITIALIZER ); ?>"
                                       value="1"
                                       <?php checked( get_option( self::OPTION_USE_DEFAULT_INITIALIZER, '1' ), '1' ); ?> />
                                Enable pre-implemented CPB initializer script
                            </label>
                            <p class="description">
                                When enabled, the CPB initializer script will be automatically loaded on product pages.
                                Disable this if you want to use a custom implementation.
                            </p>
                        </td>
                    </tr>
                    <tr class="script-url-row">
                        <th scope="row">Initializer URL</th>
                        <td>
                            <input type="url" class="regular-text"
                                   name="<?php echo esc_attr( self::OPTION_SCRIPT_URL ); ?>"
                                   value="<?php echo esc_attr( get_option( self::OPTION_SCRIPT_URL, '' ) ); ?>"
                                   placeholder="<?php echo esc_attr( self::SCRIPT_URL ); ?>" />
                            <p class="description">
                                Custom initializer script URL. Leave empty to use default:
                                <code><?php echo esc_html( self::SCRIPT_URL ); ?></code>
                            </p>
                        </td>
                </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const initializerCheckbox = document.querySelector('input[name="<?php echo esc_js( self::OPTION_USE_DEFAULT_INITIALIZER ); ?>"]');
                const scriptUrlRow = document.querySelector('tr.script-url-row');

                function toggleScriptUrlField() {
                    if (initializerCheckbox && scriptUrlRow) {
                        if (initializerCheckbox.checked) {
                            scriptUrlRow.style.display = '';
                        } else {
                            scriptUrlRow.style.display = 'none';
                        }
                    }
                }

                // Initial state
                toggleScriptUrlField();

                // Listen for changes
                if (initializerCheckbox) {
                    initializerCheckbox.addEventListener('change', toggleScriptUrlField);
                }
            });
            </script>
        </div>
    <?php }

    /** Adds the per-product external-ID input to the “General” tab. */
    public function add_external_id_field() {
        woocommerce_wp_text_input( [
            'id'          => self::META_EXT_ID,
            'label'       => __( 'CPB External Product ID', 'cpb' ),
            'description' => __( 'ID of the product in your external builder', 'cpb' ),
            'desc_tip'    => true,
            'type'        => 'text',
        ] );
    }

    /** Saves the external ID when the product is updated. */
    public function save_external_id_field( $product ) {
        if ( isset( $_POST[ self::META_EXT_ID ] ) ) {
            $product->update_meta_data(
                self::META_EXT_ID,
                sanitize_text_field( $_POST[ self::META_EXT_ID ] )
            );
        }
    }

    public function render_admin_button_backend() {
        global $post;

        // Only for users with the right capability
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        // Only show the button if CPB is enabled for this product
        if ( ! $this->is_cpb_product( $post->ID ) ) {
            return;
        }

        // Get the physical product URL
        $product_url = get_permalink( $post->ID );

        // Build URL using the refactored function with product URL as parameter
        $url = $this->build_cpb_app_url(
            '/cpb/route/products/product/' . rawurlencode( $product_url )
        );

        echo '<p class="form-field" style="margin-top:12px;">';
        echo '    <a href="' . esc_url( $url ) . '" ';
        echo '       class="button button-primary" target="_blank" rel="noopener noreferrer">';
        echo        esc_html__( 'Go to CPB admin', 'cpb' );
        echo '    </a>';
        echo '</p>';
    }

    /* =============================================================
     *  Front‑end helpers
     * ===========================================================*/

    private function is_cpb_product($product_id = null) {
        if (is_null($product_id)) {
            global $post;
            if (empty($post)) {
                return false;
            }
            $product_id = $post->ID;
        }

        $external_cpb_id = get_post_meta( $product_id, self::META_EXT_ID, true );
        $is_cpb_product_enabled = get_post_meta( $product_id, '_cpb_enabled', true );

        return ! empty( $external_cpb_id ) || $is_cpb_product_enabled === 'yes';
    }

    /** Load the external React bundle on every single‑product page. */
    public function enqueue_initializer() {
        if ( ! is_product() ) {
            return;
        }

        global $product; // WooCommerce sets this on product pages
        $woo_product_id = is_object( $product ) ? $product->get_id() : get_the_ID();

        if (!$this->is_cpb_product($woo_product_id)) {
            return;
        }

        // Check if default initializer is enabled
        if ( ! get_option( self::OPTION_USE_DEFAULT_INITIALIZER, '1' ) ) {
            return;
        }

        $script_url = $this->get_script_url();

        wp_enqueue_script(
            'cpb-initializer',
            $script_url,
            [],
            null,
            true // footer
        );

        $move_js = <<<JS
        document.addEventListener('DOMContentLoaded',function(){


            var intervalId = setInterval(() => {
                var b=document.getElementById('product-builder');
                if(!b){return;}

                // Set woocommerce product ID data-attribute (used for cart)
                b.setAttribute('data-woo-product-id', '{$woo_product_id}');

                // Find the main product container
                var productContainer = document.querySelector('body .woocommerce.product, body .woocommerce.product > main, .single-product-summary, .woocommerce div.product, .entry-summary');

                if(productContainer){
                    // Hide the original product content with CSS instead of removing

                    // Insert CPB builder right after the product container
                    productContainer.parentNode.insertBefore(b, productContainer.nextSibling);
                } else {
                    // Fallback: try to insert after notices if product container not found
                    var notice=document.querySelector('.wp-block-woocommerce-store-notices, .wc-block-store-notices');
                    if(notice&&notice.parentNode){
                        notice.parentNode.insertBefore(b, notice.nextSibling);
                    }
                }
                clearInterval(intervalId);
            }, 1000);

            setTimeout(() => {
                clearInterval(intervalId);
            }, 10000);
        });
        JS;
        wp_add_inline_script( 'cpb-initializer', $move_js );

        $currency_data = array_merge(
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('cpb_add_to_cart_nonce'),
                'cart_url' => wc_get_cart_url(), // Required to redirect after add to cart
            ),
            $this->currency->get_currency_data_for_frontend()
        );

        error_log('[CPB] Localizing script with data: ' . print_r($currency_data, true));

        wp_localize_script( 'cpb-initializer', 'cpb_ajax_object', $currency_data );

        // Add inline script to freeze the cpb_ajax_object from accidental reassignment
        $freeze_js = <<<JS
        Object.freeze(cpb_ajax_object);
        Object.defineProperty(window, 'cpb_ajax_object', {
            writable: false,
            configurable: false
        });
        JS;
        wp_add_inline_script( 'cpb-initializer', $freeze_js );

        wp_enqueue_script( 'cpb-initializer' );
    }

    /** Minimal inline CSS: hide leftover Gutenberg columns. */
    public function enqueue_inline_styles() {
        if ( ! is_product() ) {
            return;
        }

        global $post;
        if ( ! $this->is_cpb_product( $post->ID ) ) return; // only CPB products

        wp_register_style( 'cpb-inline', false );
        wp_enqueue_style(  'cpb-inline' );

        $css = <<<CSS
        .cpb-builder-active.single-product .product {
            display: block;
        }

        body .woocommerce.product, body .woocommerce.product > main, .single-product-summary, .woocommerce div.product, .entry-summary {
            display: none;
        }

         #product-builder {
            width: 100%;
            max-width: var(--wp--style--global--wide-size, 1320px);
            margin-inline: auto;
            height: 100dvh;
            box-sizing: border-box;
        }

        .cpb-builder-active .wp-block-columns.alignwide.is-layout-flex {
            display: none !important;
        }
        CSS;

        wp_add_inline_style( 'cpb-inline', $css );
    }

    /** Adds a body class so themes can target product‑builder pages. */
    public function add_body_class( $classes ) {
        if ( is_product() ) {
            global $post;
            if ( $this->is_cpb_product( $post->ID ) ) {
                $classes[] = 'cpb-builder-active';
            }
        }
        return $classes;
    }

    /**
     * Load plugin textdomain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            self::TEXT_DOMAIN,
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }

    /**
     * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS)
     */
    public function declare_hpos_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }

    /**
     * Get script URL from settings or use default
     */
    private function get_script_url() {
        global $post;

        $custom_url = get_option( self::OPTION_SCRIPT_URL, '' );

        // Get base URL (custom or default)
        if ( ! empty( $custom_url ) && filter_var( $custom_url, FILTER_VALIDATE_URL ) ) {
            $base_url = $custom_url;
        } else {
            $base_url = self::SCRIPT_URL;
        }

        // Add external_id only if not empty
        $external_id = get_post_meta( $post->ID, self::META_EXT_ID, true );
        if ( ! empty( $external_id ) ) {
            // Проверяем, есть ли уже параметр external_id= в URL
            if ( strpos( $base_url, 'external_id=' ) !== false ) {
                $base_url .= $external_id;
            } else {
                // Добавляем параметр если его нет
                $separator = strpos( $base_url, '?' ) !== false ? '&' : '?';
                $base_url .= $separator . 'external_id=' . $external_id;
            }
        }

        // Add shop_name parameter
        $shop_name = $this->get_shop_name_from_origin();
        if ( ! empty( $shop_name ) ) {
            $separator = strpos( $base_url, '?' ) !== false ? '&' : '?';
            $base_url .= $separator . 'platform_shop_name=' . rawurlencode( $shop_name );
        }

        // Add version
        $separator = strpos( $base_url, '?' ) !== false ? '&' : '?';
        $base_url .= $separator . 'v=' . self::VERSION;

        return esc_url( $base_url );
    }

    /**
     * Get shop name from origin (current domain)
     */
    private function get_shop_name_from_origin() {
        // Get the current site URL
        $site_url = get_site_url();

        // Parse the URL to get the host
        $parsed_url = parse_url( $site_url );
        $host = $parsed_url['host'] ?? '';

        // Remove 'www.' prefix if present
        $shop_name = preg_replace( '/^www\./', '', $host );

        return $shop_name;
    }

    /**
     * Add custom icon to plugin list and admin areas
     */
    public function add_plugin_list_icon() {
        $plugin_file = plugin_basename(__FILE__);
        $plugin_dir = plugin_dir_url(__FILE__);
        ?>
        <style>
        /* Plugin list icon (16x16) */
        .plugins tr[data-plugin="<?php echo esc_attr($plugin_file); ?>"] .plugin-title strong:before {
            content: '';
            display: inline-block;
            width: 16px;
            height: 16px;
            background: url('<?php echo esc_url($plugin_dir . 'assets/icon-16x16.png'); ?>') no-repeat center;
            background-size: 16px 16px;
            margin-right: 4px;
            vertical-align: middle;
        }

        /* Plugin list icon for Retina displays (32x32) */
        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            .plugins tr[data-plugin="<?php echo esc_attr($plugin_file); ?>"] .plugin-title strong:before {
                background-image: url('<?php echo esc_url($plugin_dir . 'assets/icon-32x32.png'); ?>');
            }
        }

        /* Plugin details modal icon (128x128) */
        .plugin-card-<?php echo esc_attr( sanitize_title(dirname($plugin_file)) ); ?> .plugin-icon img {
            max-width: 128px;
            max-height: 128px;
        }

        /* Plugin banner in details (772x250) */
        .plugin-card-<?php echo esc_attr( sanitize_title(dirname($plugin_file)) ); ?> .plugin-card-top {
            background: url('<?php echo esc_url($plugin_dir . 'assets/banner-772x250.png'); ?>') no-repeat center;
            background-size: cover;
            min-height: 250px;
        }

        /* Settings page icon */
        .settings_page_cpb-settings .wp-menu-image:before {
            background: url('<?php echo esc_url($plugin_dir . 'assets/icon-16x16.png'); ?>') no-repeat center;
            background-size: 16px 16px;
            content: '';
            width: 16px;
            height: 16px;
            display: inline-block;
        }

        /* High DPI settings icon */
        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            .settings_page_cpb-settings .wp-menu-image:before {
                background-image: url('<?php echo esc_url($plugin_dir . 'assets/icon-32x32.png'); ?>');
            }
        }
        </style>
        <?php
    }

    /**
     * Hide Add to Cart button for CPB products in shop/category pages
     */
    public function hide_add_to_cart_for_cpb_products( $button, $product ) {
        if ( $this->is_cpb_product( $product->get_id() ) ) {
            // remove "Add to Cart" for CPB products
            // return '';

            // Optionally, replace with "Customize" button
            $class_names = 'cpb-customize-button';
            if ( preg_match('/class=["\']([^"\']*)["\']/', $button, $matches) ) {
                $class_names .= ' ' . $matches[1];
            }
            $url = get_permalink($product->get_id());
            return '<a href="' . esc_url($url) . '" class="' . esc_attr($class_names) . '">Customize</a>';
        }

        return $button;
    }

    /**
     * Hide price for CPB products in shop/category pages
     */
    public function hide_price_for_cpb_products( $price, $product ) {
        if ( $this->is_cpb_product( $product->get_id() ) ) {
            // Hide price for CPB products - return empty string
            return '';
        }

        return $price;
    }

    /**
     * Add CPB Enable checkbox in product admin
     */
    public function add_cpb_enable_product_checkbox() {
        global $post;

        echo '<div class="options_group">';

        woocommerce_wp_checkbox( [
            'id'          => '_cpb_enabled',
            'label'       => __( 'Enable CPB', self::TEXT_DOMAIN ),
            'description' => __( 'Identify this product as a CPB product', self::TEXT_DOMAIN ),
            'desc_tip'    => true,
            'value'       => get_post_meta( $post->ID, '_cpb_enabled', true )
        ] );

        echo '</div>';
    }

    /**
     * Save CPB Enable checkbox in product admin
     */
    public function save_cpb_enable_product_checkbox( $post_id ) {
        $is_cpb_product = isset( $_POST['_cpb_enabled'] ) ? 'yes' : 'no';
        update_post_meta( $post_id, '_cpb_enabled', $is_cpb_product );
    }

    /**
     * Check if WooCommerce is active
     */
    private function is_woocommerce_active() {
        return class_exists( 'WooCommerce' ) || in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) );
    }

    /**
     * Display admin notice when WooCommerce is not active
     */
    public function woocommerce_missing_notice() {
        $class = 'notice notice-error';
        $message = sprintf(
            /* translators: %1$s: Plugin name, %2$s: WooCommerce link */
            __( '%1$s requires %2$s to be installed and active.', self::TEXT_DOMAIN ),
            '<strong>' . self::PLUGIN_NAME . '</strong>',
            '<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a>'
        );
        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), wp_kses_post( $message ) );
    }

    /**
     * Initialize WooCommerce.com updater for marketplace plugin
     */
    public function init_wc_updater() {
        // Only initialize if this is a WooCommerce.com marketplace install
        if ( ! is_admin() || ! class_exists( 'WC_Helper' ) ) {
            return;
        }

        $plugin_file = plugin_basename( __FILE__ );
        $product_id = '12345'; // Will be replaced with real Product ID after marketplace approval

        // Register plugin with WooCommerce Helper for automatic updates
        add_filter( 'woocommerce_helper_subscription_refresh_data', function( $data ) use ( $plugin_file, $product_id ) {
            if ( isset( $data[ $product_id ] ) ) {
                $data[ $product_id ]['plugin'] = $plugin_file;
            }
            return $data;
        });
    }



    /**
     * Plugin activation hook
     */
    public static function activate() {
        // Check WordPress version
        global $wp_version;
        if ( version_compare( $wp_version, '5.0', '<' ) ) {
            wp_die( esc_html__( 'Custom Product Builder requires WordPress 5.0 or higher.', self::TEXT_DOMAIN ) );
        }

        // Check PHP version
        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            wp_die( esc_html__( 'Custom Product Builder requires PHP 7.4 or higher.', self::TEXT_DOMAIN ) );
        }

        // Check if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            wp_die( esc_html__( 'Custom Product Builder requires WooCommerce to be installed and active.', self::TEXT_DOMAIN ) );
        }

        // Check WooCommerce version (with error handling)
        if ( class_exists( 'WooCommerce' ) && function_exists( 'WC' ) && WC() && WC()->version ) {
            if ( version_compare( WC()->version, '5.0', '<' ) ) {
                wp_die( sprintf(
                    /* translators: %s: WooCommerce version */
                    esc_html__( 'Custom Product Builder requires WooCommerce 5.0 or higher. You are running %s.', self::TEXT_DOMAIN ),
                    esc_html( WC()->version )
                ) );
            }
        }

        // Set default options (prevent duplicates)
        if ( false === get_option( self::OPTION_USE_DEFAULT_INITIALIZER ) ) {
            add_option( self::OPTION_USE_DEFAULT_INITIALIZER, '1' );
        }

        // Send activation notification (with error handling)
        try {
            self::send_lifecycle_notification( 'activate' );
        } catch ( Exception $e ) {
            // Log error but don't fail activation
            error_log( '[CPB] Activation notification failed: ' . $e->getMessage() );
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation hook
     */
    public static function deactivate() {
        // Send deactivation notification (with error handling)
        try {
            self::send_lifecycle_notification( 'deactivate' );
        } catch ( Exception $e ) {
            // Log error but don't fail deactivation
            error_log( '[CPB] Deactivation notification failed: ' . $e->getMessage() );
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Send secure plugin lifecycle notification to CPB service
     */
    private static function send_lifecycle_notification( $action ) {
        // Validate action parameter
        if ( ! in_array( $action, [ 'activate', 'deactivate' ], true ) ) {
            throw new InvalidArgumentException( 'Invalid action: ' . $action );
        }

        // Generate or get secure site token
        $site_token = get_option( 'cpb_site_token' );
        if ( empty( $site_token ) ) {
            $site_token = wp_generate_password( 32, false );
            update_option( 'cpb_site_token', $site_token );
        }

        // Get shop name using same logic as main plugin
        $shop_name = self::get_shop_name_from_origin_static();

        // Determine URL based on action (using dynamic URL construction)
        $url = self::get_lifecycle_notification_url($action);

        // Validate URL
        if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            throw new Exception( 'Invalid notification URL: ' . $url );
        }

        // Create secure payload
        $timestamp = time();
        $nonce = wp_create_nonce( 'cpb_lifecycle_' . $action . '_' . $timestamp );

        // Get WooCommerce version safely
        $wc_version = 'not_installed';
        if ( class_exists( 'WooCommerce' ) && function_exists( 'WC' ) && WC() && WC()->version ) {
            $wc_version = WC()->version;
        }

        global $wp_version;
        $notification_data = [
            'shop_name' => $shop_name,
            'site_url' => get_site_url(),
            'admin_email' => get_option( 'admin_email' ),
            'plugin_version' => self::VERSION,
            'wp_version' => $wp_version,
            'wc_version' => $wc_version,
            'action' => $action,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'site_token' => $site_token
        ];

        // Create signature for additional security
        // Use JSON_UNESCAPED_SLASHES to match Node.js JSON.stringify behavior
        $json_body = wp_json_encode( $notification_data, JSON_UNESCAPED_SLASHES );
        $signature = hash_hmac( 'sha256', $json_body, $site_token );

        // Debug logging
        error_log( sprintf(
            '[CPB] Signature debug for %s: JSON body: %s',
            $action,
            $json_body
        ) );
        error_log( sprintf(
            '[CPB] Signature debug for %s: Generated signature: %s (token: %s...)',
            $action,
            $signature,
            substr( $site_token, 0, 8 )
        ) );

        // Send secure notification (non-blocking)
        if ( function_exists( 'wp_remote_post' ) ) {
            $response = wp_remote_post( $url, [
                'body' => $json_body,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'CPB-Plugin/' . self::VERSION,
                    'X-CPB-Token' => $site_token,
                    'X-CPB-Shop' => $shop_name,
                    'X-CPB-Signature' => $signature,
                    'X-CPB-Timestamp' => $timestamp
                ],
                'timeout' => 15,
                'blocking' => false, // Non-blocking to avoid slowing down activation/deactivation
                'sslverify' => true
            ]);

            // Check for errors (only if blocking was true)
            if ( is_wp_error( $response ) ) {
                throw new Exception( 'HTTP request failed: ' . $response->get_error_message() );
            }
        } else {
            throw new Exception( 'wp_remote_post function not available' );
        }

        // Log the notification attempt (without sensitive data)
        error_log( sprintf(
            '[CPB] Sent %s notification for shop: %s to %s (token: %s...)',
            $action,
            $shop_name,
            $url,
            substr( $site_token, 0, 8 )
        ) );
    }


    /**
     * Encrypt store data for secure transmission to CPB app
     */
    private function encrypt_store_data() {
        // Collect store data
        $store_data = [
            'shop_name' => $this->get_shop_name_from_origin(),
            'site_url' => get_site_url(),
            'admin_email' => get_option( 'admin_email' ),
            'store_name' => get_bloginfo( 'name' ),
            'wc_currency' => get_woocommerce_currency(),
            'timestamp' => time(),
            'plugin_version' => self::VERSION
        ];

        $json_data = wp_json_encode( $store_data );

        // Try AES-GCM if available, fallback to XOR
        if ( function_exists( 'openssl_encrypt' ) ) {
            $key = substr( hash( 'sha256', 'CPB_SECURE_KEY_2024' ), 0, 32 );
            $iv = random_bytes( 12 );
            $encrypted = openssl_encrypt( $json_data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
            return base64_encode( $iv . $encrypted . $tag );
        }

        // Fallback XOR
        $key = 'CPB_SECURE_KEY_2024';
        $encrypted = '';
        for ( $i = 0; $i < strlen( $json_data ); $i++ ) {
            $encrypted .= chr( ord( $json_data[$i] ) ^ ord( $key[$i % strlen( $key )] ) );
        }
        return base64_encode( $encrypted );
    }

    /**
     * Static version of get_shop_name_from_origin for use in static methods
     */
    private static function get_shop_name_from_origin_static() {
        // Get the current site URL
        $site_url = get_site_url();

        // Parse the URL to get the host
        $parsed_url = parse_url( $site_url );
        $host = $parsed_url['host'] ?? '';

        // Remove 'www.' prefix if present
        $shop_name = preg_replace( '/^www\./', '', $host );

        return $shop_name;
    }

    /**
     * Get CPB Backend API endpoint for lifecycle notifications
     * Uses domain from Initializer URL setting or falls back to hardcoded constants
     */
    private static function get_lifecycle_notification_url($action) {
        $base_url = self::get_cpb_base_url();

        // Construct the appropriate endpoint URL
        $endpoint = ($action === 'activate') ? 'activate' : 'deactivate';
        return $base_url . '/cpb/platforms/woocommerce/plugin/' . $endpoint;
    }

    /**
     * Weekly subscription status check
     */
    public function weekly_subscription_check() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Check subscription status once per week (cached)
        $last_check = get_transient( 'cpb_subscription_weekly_check' );
        if ( $last_check ) {
            return; // Already checked this week
        }

        // Set cache for 1 week
        set_transient( 'cpb_subscription_weekly_check', time(), WEEK_IN_SECONDS );

        $current_status = $this->get_wc_subscription_status();
        $previous_status = get_option( 'cpb_previous_subscription_status', 'unknown' );

        // If status changed from active to inactive - send deactivate
        if ( $previous_status === 'active' && $current_status === 'inactive' ) {
            $this->send_payment_notification( 'deactivate', 'subscription_expired' );
        }
        // If status changed from inactive to active - send activate
        elseif ( $previous_status === 'inactive' && $current_status === 'active' ) {
            $this->send_payment_notification( 'activate', 'subscription_renewed' );
        }
        // If first time checking and subscription is inactive - send deactivate
        elseif ( $previous_status === 'unknown' && $current_status === 'inactive' ) {
            $this->send_payment_notification( 'deactivate', 'no_active_subscription' );
        }

        // Update previous status
        update_option( 'cpb_previous_subscription_status', $current_status );
    }

    /**
     * Get WooCommerce.com subscription status
     */
    private function get_wc_subscription_status() {
        // Check WooCommerce Helper data
        $subscriptions = get_option( 'woocommerce_helper_data', array() );
        $product_id = '12345'; // Will be replaced with real Product ID

        if ( empty( $subscriptions['subscriptions'] ) ) {
            return 'inactive';
        }

        $subscription = $subscriptions['subscriptions'][ $product_id ] ?? null;

        if ( ! $subscription ) {
            return 'inactive';
        }

        // Check if subscription is active and not expired
        $is_active = isset( $subscription['expires'] ) &&
                    $subscription['expires'] > time() &&
                    ( $subscription['active'] ?? false );

        return $is_active ? 'active' : 'inactive';
    }

    /**
     * Send payment-related notification
     */
    private function send_payment_notification( $action, $reason ) {
        // Generate or get secure site token
        $site_token = get_option( 'cpb_site_token' );
        if ( empty( $site_token ) ) {
            $site_token = wp_generate_password( 32, false );
            update_option( 'cpb_site_token', $site_token );
        }

        // Get shop name
        $shop_name = $this->get_shop_name_from_origin();

        // Determine URL based on action (using dynamic URL construction)
        $url = self::get_lifecycle_notification_url($action);

        // Create secure payload
        $timestamp = time();
        $nonce = wp_create_nonce( 'cpb_payment_' . $action . '_' . $timestamp );

        $notification_data = [
            'shop_name' => $shop_name,
            'site_url' => get_site_url(),
            'admin_email' => get_option( 'admin_email' ),
            'plugin_version' => self::VERSION,
            'action' => $action,
            'reason' => $reason,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'site_token' => $site_token
        ];

        // Create signature for additional security
        // Use JSON_UNESCAPED_SLASHES to match Node.js JSON.stringify behavior
        $json_body = wp_json_encode( $notification_data, JSON_UNESCAPED_SLASHES );
        $signature = hash_hmac( 'sha256', $json_body, $site_token );

        // Send secure notification (non-blocking)
        wp_remote_post( $url, [
            'body' => $json_body,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'CPB-Plugin/' . self::VERSION,
                'X-CPB-Token' => $site_token,
                'X-CPB-Shop' => $shop_name,
                'X-CPB-Signature' => $signature,
                'X-CPB-Timestamp' => $timestamp,
                'X-CPB-Reason' => $reason
            ],
            'timeout' => 15,
            'blocking' => false,
            'sslverify' => true
        ]);

        // Log the notification
        error_log( sprintf(
            '[CPB] Sent payment %s notification for shop: %s (reason: %s)',
            $action,
            $shop_name,
            $reason
        ) );
    }

}

// Initialize plugin
new CPB_Lite();

// Register activation/deactivation hooks
register_activation_hook( __FILE__, [ 'CPB_Lite', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'CPB_Lite', 'deactivate' ] );
?>
