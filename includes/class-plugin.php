<?php
defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class – wires everything together.
 *
 * Memory-safety contract:
 *   - We consume ?lang= from $_GET immediately at plugins_loaded (priority 1)
 *     and REMOVE it from $_GET so WordPress core never sees it.
 *   - We never call load_textdomain() for Arabic.
 *   - We never hook locale/determine_locale filters.
 *   - All direction logic is CSS-only (body.rtl / html[dir]).
 */
final class AET_Plugin {

    private static ?AET_Plugin $instance = null;

    public AET_Session_Manager $session;
    public AET_Translator      $translator;
    public AET_Switcher_UI     $switcher;
    public AET_Woodmart_Compat $woodmart;
    public AET_Settings        $settings;
    public string              $current_lang;

    private function __construct() {
        // ── Consume ?lang= BEFORE WordPress processes query vars ────
        // This prevents WP core / WPML / Polylang from seeing it and
        // triggering their own locale + translation-file loading.
        $this->consume_lang_param();

        $this->session    = new AET_Session_Manager();
        $this->settings   = new AET_Settings();
        $this->translator = new AET_Translator();
        $this->switcher   = new AET_Switcher_UI();
        $this->woodmart   = new AET_Woodmart_Compat();

        $this->current_lang = $this->session->get_language();

        $this->hooks();
    }

    /**
     * Strip ?lang= from $_GET / $_REQUEST / $_SERVER[QUERY_STRING]
     * so WordPress and other plugins never see it.
     * We've already read it in AET_Session_Manager::get_language().
     */
    private function consume_lang_param(): void {
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! isset( $_GET['lang'] ) ) {
            return;
        }

        $lang = sanitize_key( $_GET['lang'] );
        if ( ! in_array( $lang, [ 'ar', 'en' ], true ) ) {
            unset( $_GET['lang'], $_REQUEST['lang'] );
            return;
        }

        // Persist then remove from superglobals
        // (Session manager reads it before this runs via get_language(),
        //  but we call set_language here to ensure cookie is written)
        // We instantiate session manager separately for this early call.
        $storage_mode = get_option( 'aet_storage_mode', 'both' );
        if ( in_array( $storage_mode, [ 'session', 'both' ], true ) ) {
            if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
                session_start();
            }
            if ( session_status() === PHP_SESSION_ACTIVE ) {
                $_SESSION['aet_lang'] = $lang;
            }
        }
        if ( in_array( $storage_mode, [ 'cookie', 'both' ], true ) && ! headers_sent() ) {
            setcookie( 'aet_lang', $lang, [
                'expires'  => time() + YEAR_IN_SECONDS,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            ] );
            $_COOKIE['aet_lang'] = $lang;
        }

        // ── Remove from WordPress's sight ──
        unset( $_GET['lang'], $_REQUEST['lang'] );

        // Rewrite QUERY_STRING so WP's query parser never sees lang=
        if ( isset( $_SERVER['QUERY_STRING'] ) ) {
            $qs = $_SERVER['QUERY_STRING'];
            $qs = preg_replace( '/(?:^|&)lang=[^&]*/', '', $qs );
            $qs = ltrim( $qs, '&' );
            $_SERVER['QUERY_STRING'] = $qs;
        }

        // Also fix REQUEST_URI
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $_SERVER['REQUEST_URI'] = preg_replace( '/([?&])lang=[^&]*(&|$)/', '$1', $_SERVER['REQUEST_URI'] );
            $_SERVER['REQUEST_URI'] = rtrim( $_SERVER['REQUEST_URI'], '?&' );
        }
    }

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function lang(): string {
        return self::get_instance()->current_lang;
    }

    private function hooks(): void {
        if ( is_admin() ) {
            add_action( 'admin_menu', [ $this->settings, 'register_menu' ] );
            add_action( 'admin_init', [ $this->settings, 'register_settings' ] );
            return;
        }

        // html lang/dir attributes
        add_filter( 'language_attributes', [ $this, 'filter_language_attributes' ], 99 );

        // Enqueue assets
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Inline JS config
        add_action( 'wp_head', [ $this, 'print_js_config' ], 5 );

        // Shortcode
        add_shortcode( 'site_language_switcher', [ $this->switcher, 'render_shortcode' ] );

        // Floating switcher in footer
        add_action( 'wp_footer', [ $this->switcher, 'render_floating' ] );

        // Woodmart compatibility
        $this->woodmart->init();
    }

    public function filter_language_attributes( string $output ): string {
        $lang   = $this->current_lang;
        $output = preg_replace( '/\s*lang="[^"]*"/', '', $output );
        $output = preg_replace( '/\s*dir="[^"]*"/', '', $output );
        $dir    = ( 'ar' === $lang ) ? 'rtl' : 'ltr';
        return trim( $output ) . ' lang="' . esc_attr( $lang ) . '" dir="' . esc_attr( $dir ) . '"';
    }

    public function enqueue_assets(): void {
        wp_enqueue_style( 'aet-switcher', AET_URL . 'assets/css/switcher.css', [], AET_VERSION );
        wp_enqueue_script( 'aet-translator', AET_URL . 'assets/js/translator.js', [], AET_VERSION, true );
    }

    public function print_js_config(): void {
        $exclude = get_option( 'aet_exclude_selectors', '.notranslate, script, style, code, pre' );
        $config  = [
            'lang'             => $this->current_lang,
            'defaultLang'      => get_option( 'aet_default_language', 'en' ),
            'excludeSelectors' => $exclude,
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'aet_translate' ),
            'cachePrefix'      => 'aet_',
            'homeUrl'          => home_url( '/' ),
        ];
        echo '<script>window.AET = ' . wp_json_encode( $config ) . ';</script>' . "\n";
    }
}
