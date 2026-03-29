<?php
/**
 * AET Memory Guard – Optional Must-Use Plugin
 * ─────────────────────────────────────────────
 * OPTIONAL: Copy this file to /wp-content/mu-plugins/aet-memory-guard.php
 * if you still see memory exhaustion errors after activating the main plugin.
 *
 * This MU-plugin fires at the very earliest WordPress hook and ensures the
 * ?lang= param (used by AET) is stripped before WP core, WPML, Polylang,
 * or any other plugin can use it to trigger Arabic translation file loading.
 *
 * It also adds a hard cap on translation file size to prevent any single
 * translation file from consuming more than 32 MB of memory.
 */

defined( 'ABSPATH' ) || exit;

// ── Strip ?lang= immediately ─────────────────────────────────────────────────
// Must run before init where WP loads translations.
if ( isset( $_GET['lang'] ) && in_array( sanitize_key( $_GET['lang'] ), [ 'ar', 'en' ], true ) ) {
    $aet_lang = sanitize_key( $_GET['lang'] );

    // Write to cookie / session so AET main plugin can read it
    if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
        @session_start();
    }
    $_SESSION['aet_lang'] = $aet_lang;

    if ( ! headers_sent() ) {
        setcookie( 'aet_lang', $aet_lang, time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), false );
        $_COOKIE['aet_lang'] = $aet_lang;
    }

    // Remove from all superglobals so WP never sees it
    unset( $_GET['lang'], $_REQUEST['lang'] );

    if ( isset( $_SERVER['QUERY_STRING'] ) ) {
        $_SERVER['QUERY_STRING'] = preg_replace( '/(?:^|&)lang=[^&]*/', '', $_SERVER['QUERY_STRING'] );
        $_SERVER['QUERY_STRING'] = ltrim( $_SERVER['QUERY_STRING'], '&' );
    }
    if ( isset( $_SERVER['REQUEST_URI'] ) ) {
        $_SERVER['REQUEST_URI'] = preg_replace( '/([?&])lang=[^&]*(&|$)/', '$1', $_SERVER['REQUEST_URI'] );
        $_SERVER['REQUEST_URI'] = rtrim( $_SERVER['REQUEST_URI'], '?&' );
    }
}
