<?php
defined( 'ABSPATH' ) || exit;

class AET_Settings {

    const PAGE_SLUG    = 'aet-settings';
    const OPTION_GROUP = 'aet_options';

    public function register_menu(): void {
        add_options_page(
            __( 'Arabic ↔ English Translator', 'aet' ),
            __( 'AET Translator', 'aet' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        add_action( 'admin_post_aet_apply_wplang_fix', [ $this, 'apply_wplang_fix' ] );

        $fields = [
            'aet_default_language'  => 'sanitize_key',
            'aet_show_floating'     => 'absint',
            'aet_storage_mode'      => 'sanitize_key',
            'aet_exclude_selectors' => 'sanitize_textarea_field',
        ];
        foreach ( $fields as $option => $cb ) {
            register_setting( self::OPTION_GROUP, $option, [ 'sanitize_callback' => $cb ] );
        }

        add_settings_section( 'aet_main', __( 'General Settings', 'aet' ), '__return_empty_string', self::PAGE_SLUG );
        add_settings_field( 'aet_default_language',   __( 'Default Language', 'aet' ),      [ $this, 'field_default_language' ],  self::PAGE_SLUG, 'aet_main' );
        add_settings_field( 'aet_show_floating',      __( 'Floating Switcher', 'aet' ),      [ $this, 'field_show_floating' ],     self::PAGE_SLUG, 'aet_main' );
        add_settings_field( 'aet_storage_mode',       __( 'Storage Mode', 'aet' ),           [ $this, 'field_storage_mode' ],      self::PAGE_SLUG, 'aet_main' );
        add_settings_field( 'aet_exclude_selectors',  __( 'Exclude CSS Selectors', 'aet' ), [ $this, 'field_exclude_selectors' ], self::PAGE_SLUG, 'aet_main' );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings'; // phpcs:ignore
        $tabs = [
            'settings'    => '⚙️ Settings',
            'diagnostics' => '🔍 Diagnostics',
            'fix'         => '🛠️ Quick Fixes',
        ];
        ?>
        <div class="wrap">
        <h1>Arabic ↔ English Translator</h1>

        <nav class="nav-tab-wrapper" style="margin-bottom:20px">
        <?php foreach ( $tabs as $key => $label ) :
            $url    = admin_url( 'options-general.php?page=aet-settings&tab=' . $key );
            $active = ( $tab === $key ) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . $active . '">' . esc_html( $label ) . '</a>';
        endforeach; ?>
        </nav>

        <?php
        match ( $tab ) {
            'diagnostics' => $this->render_diagnostics_tab(),
            'fix'         => $this->render_fix_tab(),
            default       => $this->render_settings_tab(),
        };
        ?>
        </div>
        <?php
    }

    /* ── Tabs ───────────────────────────────────────────────────── */

    private function render_settings_tab(): void {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( self::OPTION_GROUP ); ?>
            <?php do_settings_sections( self::PAGE_SLUG ); ?>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_diagnostics_tab(): void {
        if ( ! class_exists( 'AET_Diagnostics' ) ) {
            require_once AET_DIR . 'includes/class-diagnostics.php';
        }
        echo '<style>
            .aet-diag{max-width:900px}
            .aet-diag-table td{vertical-align:top}
            .aet-diag h2{font-size:1.4em;margin-bottom:4px}
        </style>';
        AET_Diagnostics::render();

        echo '<p style="margin-top:24px">
            <a href="' . esc_url( add_query_arg( 'tab', 'diagnostics' ) ) . '" class="button">↺ Refresh</a>
            &nbsp;
            <a href="' . esc_url( add_query_arg( 'tab', 'fix' ) ) . '" class="button button-primary">🛠️ See Quick Fixes</a>
        </p>';
    }

    private function render_fix_tab(): void {
        $locale     = get_locale();
        $is_arabic  = ( $locale && ! in_array( $locale, [ 'en_US', 'en_GB', '' ], true ) );
        $wpconfig   = ABSPATH . 'wp-config.php';
        $config_ok  = is_writable( $wpconfig );
        ?>
        <div style="max-width:800px">

        <h2>🛠️ Quick Fixes</h2>

        <?php if ( $is_arabic ) : ?>
        <div style="background:#fff3cd;border-left:4px solid #ffc107;padding:16px 20px;margin-bottom:20px;border-radius:4px">
            <h3 style="margin-top:0">🔴 Root Cause Found: WordPress locale is <code><?php echo esc_html( $locale ); ?></code></h3>
            <p>Your WordPress is installed in a non-English language. This forces WP to load large compiled translation files 
            (often <strong>200–600 MB total</strong>) into PHP RAM on <em>every single page load</em>, for every plugin and theme.
            <br>No memory limit will ever be enough because the problem scales with the number of plugins.</p>
            <p><strong>The correct fix:</strong> Keep WordPress in English and let AET handle Arabic display via JavaScript 
            (zero memory cost). Your content stays in Arabic — only the <em>WordPress UI strings</em> change.</p>
        </div>
        <?php endif; ?>

        <h3>Fix 1 — Set WordPress to English (most important)</h3>
        <p>Add this line to <code>wp-config.php</code> <em>before</em> <code>/* That's all */</code>:</p>
        <pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:6px;overflow:auto">define('WPLANG', '');   // AET handles Arabic display via JS — no translation files needed</pre>

        <?php if ( $config_ok ) : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'aet_apply_fix' ); ?>
            <input type="hidden" name="action" value="aet_apply_wplang_fix">
            <button type="submit" class="button button-primary" onclick="return confirm('This will add define(WPLANG,\'\') to wp-config.php. Proceed?')">
                ✅ Apply automatically to wp-config.php
            </button>
        </form>
        <?php else : ?>
        <p><em>⚠️ wp-config.php is not writable from PHP. Edit it manually via FTP/SSH.</em></p>
        <?php endif; ?>

        <h3 style="margin-top:28px">Fix 2 — Optional Must-Use plugin (belt + suspenders)</h3>
        <p>Copy <code>aet-memory-guard.php</code> (included in the plugin zip) to:</p>
        <pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:6px">/wp-content/mu-plugins/aet-memory-guard.php</pre>
        <p>This runs before all plugins and strips <code>?lang=</code> before any translation loader sees it.</p>

        <h3 style="margin-top:28px">Fix 3 — Delete Arabic translation files from disk</h3>
        <p>After setting WPLANG to English, you can safely delete cached Arabic translation files:</p>
        <pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:6px">find <?php echo esc_html( WP_LANG_DIR ); ?> -name "*-ar*" -o -name "*_ar_*" | head -20</pre>
        <p>Then delete them via FTP or run: <code>find ... -delete</code></p>

        <h3 style="margin-top:28px">Fix 4 — Change site language via WordPress admin</h3>
        <p>Go to <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">Settings → General</a> 
        and change <strong>Site Language</strong> to <strong>English (United States)</strong>.</p>

        </div>
        <?php
    }

    public function apply_wplang_fix(): void {
        check_admin_referer( 'aet_apply_fix' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $wpconfig = ABSPATH . 'wp-config.php';
        if ( ! is_writable( $wpconfig ) ) {
            wp_redirect( add_query_arg( [ 'tab' => 'fix', 'aet_msg' => 'not_writable' ], admin_url( 'options-general.php?page=aet-settings' ) ) );
            exit;
        }

        $content = file_get_contents( $wpconfig );

        // Don't add if already defined
        if ( strpos( $content, "define('WPLANG'" ) !== false || strpos( $content, 'define("WPLANG"' ) !== false ) {
            // Replace existing
            $content = preg_replace( "/define\s*\(\s*['\"]WPLANG['\"]\s*,\s*[^)]+\)/", "define('WPLANG', '')", $content );
        } else {
            // Insert before the "That's all" comment
            $marker  = "/* That's all";
            $insert  = "define('WPLANG', ''); // AET: force English locale to prevent Arabic translation files loading\n\n";
            $content = str_replace( $marker, $insert . $marker, $content );
        }

        file_put_contents( $wpconfig, $content );

        // Also update DB option
        update_option( 'WPLANG', '' );

        wp_redirect( add_query_arg( [ 'tab' => 'fix', 'aet_msg' => 'fixed' ], admin_url( 'options-general.php?page=aet-settings' ) ) );
        exit;
    }

    /* ── Settings fields ────────────────────────────────────────── */

    public function field_default_language(): void {
        $val = get_option( 'aet_default_language', 'en' );
        ?>
        <select name="aet_default_language">
            <option value="en" <?php selected( $val, 'en' ); ?>>English</option>
            <option value="ar" <?php selected( $val, 'ar' ); ?>>العربية</option>
        </select>
        <?php
    }

    public function field_show_floating(): void {
        $val = get_option( 'aet_show_floating', '1' );
        echo '<input type="checkbox" name="aet_show_floating" value="1" ' . checked( $val, '1', false ) . '>';
        echo '<label> Show floating language switcher (bottom corner)</label>';
    }

    public function field_storage_mode(): void {
        $val     = get_option( 'aet_storage_mode', 'both' );
        $options = [
            'both'    => 'Session + Cookie (recommended)',
            'session' => 'PHP Session only',
            'cookie'  => 'Cookie only',
        ];
        echo '<select name="aet_storage_mode">';
        foreach ( $options as $k => $l ) {
            printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $val, $k, false ), esc_html( $l ) );
        }
        echo '</select>';
    }

    public function field_exclude_selectors(): void {
        $val = get_option( 'aet_exclude_selectors', '.notranslate, script, style, code, pre' );
        echo '<textarea name="aet_exclude_selectors" rows="3" class="large-text">' . esc_textarea( $val ) . '</textarea>';
        echo '<p class="description">CSS selectors whose text will not be translated.</p>';
    }
}
