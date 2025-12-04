# Changelog

All notable changes to Custom Product Builder for WooCommerce.

## [1.1.0] - 2025-01-13

### Fixed
- Security vulnerabilities: sanitized all input variables ($_POST, $_COOKIE)
- Security vulnerabilities: escaped all output using esc_html(), esc_attr(), esc_url()
- Activation test warnings: improved error handling and function existence checks
- Canonical compatibility warnings: corrected add_options_page parameters
- Canonical compatibility warnings: replaced deprecated current_time('timestamp') with time()
- Canonical compatibility warnings: used global $wp_version instead of get_bloginfo('version')
- Validation test warnings: updated WordPress compatibility to 6.8
- Validation test warnings: updated WooCommerce compatibility to 9.4
- Validation test warnings: improved uninstall.php structure and function definitions

### Added
- App Docs link in plugin meta links for better documentation access

### Improved
- Enhanced installation mode detection using Shopify parameters
- Added axios cache update integration for inventory API server
- Better error handling in activation/deactivation hooks with try-catch blocks
- More robust WooCommerce version checking with proper function existence validation

## [1.0.0] - 2025-08-28

### Added
- Initial release for WooCommerce Marketplace
- Comprehensive WooCommerce compatibility checks
- Settings interface with shop configuration
- Support for custom script URLs with conditional visibility
- Mobile responsiveness across all devices
- Proper localization support for international stores
- Error handling and user feedback messages
- CPB icon integration for better visual identification
- Doc and Settings links in plugin actions
- Toggleable 'Use Default Initializer' option in settings
- Security with proper nonce verification
- Cart integration with custom product data handling
- Currency conversion support for international pricing
- Performance optimization for faster page loading
- Comprehensive uninstall cleanup process
- Drag-and-drop product builder interface
- Real-time preview functionality
- Advanced customization options and tools
- WooCommerce integration for seamless checkout
- Admin dashboard for plugin management
- Support for external product IDs
- Compatibility with popular WooCommerce themes
- WordPress coding standards compliance

