<?php
defined( 'ABSPATH' ) || exit;

/**
 * Renders the language switcher widget (shortcode + floating button).
 */
class AET_Switcher_UI {

    /**
     * [site_language_switcher] shortcode.
     */
    public function render_shortcode( array $atts = [] ): string {
        $atts = shortcode_atts( [ 'style' => 'inline' ], $atts, 'site_language_switcher' );
        return $this->build_html( 'inline' );
    }

    /**
     * Floating switcher – rendered in wp_footer.
     */
    public function render_floating(): void {
        if ( ! get_option( 'aet_show_floating', '1' ) ) {
            return;
        }
        echo $this->build_html( 'floating' ); // phpcs:ignore
    }

    /**
     * Build the switcher HTML.
     *
     * @param string $type  'inline' | 'floating'
     */
    private function build_html( string $type ): string {
        $current = AET_Plugin::lang();
        $is_ar   = ( 'ar' === $current );

        $ar_label  = 'العربية';
        $en_label  = 'English';
        $wrap_class = 'aet-switcher aet-switcher--' . esc_attr( $type );

        ob_start();
        ?>
        <div class="<?php echo $wrap_class; ?>" role="navigation" aria-label="Language switcher">
            <button
                class="aet-btn<?php echo $is_ar ? ' aet-btn--active' : ''; ?>"
                data-lang="ar"
                aria-pressed="<?php echo $is_ar ? 'true' : 'false'; ?>"
                title="<?php esc_attr_e( 'Switch to Arabic', 'aet' ); ?>"
            >
                <span class="aet-flag" aria-hidden="true">🇸🇦</span>
                <span class="aet-label"><?php echo esc_html( $ar_label ); ?></span>
            </button>

            <span class="aet-divider" aria-hidden="true">|</span>

            <button
                class="aet-btn<?php echo ! $is_ar ? ' aet-btn--active' : ''; ?>"
                data-lang="en"
                aria-pressed="<?php echo ! $is_ar ? 'true' : 'false'; ?>"
                title="<?php esc_attr_e( 'Switch to English', 'aet' ); ?>"
            >
                <span class="aet-flag" aria-hidden="true">🇬🇧</span>
                <span class="aet-label"><?php echo esc_html( $en_label ); ?></span>
            </button>
        </div>
        <?php
        return ob_get_clean();
    }
}
