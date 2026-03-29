<?php
/**
 * Plugin Name:       Arabic ↔ English Translator
 * Plugin URI:        https://example.com/arabic-english-translator
 * Description:       Automatic Arabic ↔ English translation with language switcher, RTL/LTR support, WooCommerce & Woodmart compatibility. Memory-optimised: never loads Arabic translation files.
 * Version:           1.1.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Your Name
 * License:           GPL v2 or later
 * Text Domain:       aet
 */

defined( 'ABSPATH' ) || exit;

define( 'AET_VERSION', '1.1.1' );
define( 'AET_FILE',    __FILE__ );
define( 'AET_DIR',     plugin_dir_path( __FILE__ ) );
define( 'AET_URL',     plugin_dir_url( __FILE__ ) );

// ── Autoloader ───────────────────────────────────────────────────────────────
spl_autoload_register( static function ( string $class ): void {
    $map = [
        'AET_Plugin'          => 'class-plugin.php',
        'AET_Session_Manager' => 'class-session-manager.php',
        'AET_Translator'      => 'class-translator.php',
        'AET_Switcher_UI'     => 'class-switcher-ui.php',
        'AET_Woodmart_Compat' => 'class-woodmart-compat.php',
        'AET_Settings'        => 'class-settings.php',
    ];
    if ( isset( $map[ $class ] ) ) {
        require_once AET_DIR . 'includes/' . $map[ $class ];
    }
} );

// ── Bootstrap at plugins_loaded priority 1 ───────────────────────────────────
// Priority 1 ensures we run BEFORE:
//   - WordPress loads locale / translation files (happens at init)
//   - WPML / Polylang / other translation plugins
//   - Woodmart theme setup
// This guarantees ?lang= is consumed and removed before anything else sees it.
add_action( 'plugins_loaded', static function (): void {
    // Emergency memory safety: keep frontend locale English so WP doesn't load
    // huge non-English translation PHP files on every request.
    add_filter( 'pre_determine_locale', static function ( $locale ) {
        if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return $locale;
        }
        return 'en_US';
    }, 0 );

    AET_Plugin::get_instance();
}, 1 );

// ── Activation defaults ───────────────────────────────────────────────────────
register_activation_hook( __FILE__, static function (): void {
    $defaults = [
        'aet_default_language'  => 'en',
        'aet_show_floating'     => '1',
        'aet_storage_mode'      => 'both',
        'aet_exclude_selectors' => '.notranslate, script, style, code, pre, .aet-switcher',
    ];
    foreach ( $defaults as $key => $value ) {
        if ( false === get_option( $key ) ) {
            update_option( $key, $value );
        }
    }
} );

// Register admin-post handler for the WPLANG fix button
add_action( 'admin_post_aet_apply_wplang_fix', static function () {
    $settings = AET_Plugin::get_instance()->settings;
    $settings->apply_wplang_fix();
} );
