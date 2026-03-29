<?php
defined( 'ABSPATH' ) || exit;

/**
 * Woodmart + WooCommerce RTL compatibility.
 *
 * ARCHITECTURE – Zero locale manipulation:
 * ─────────────────────────────────────────
 * We NEVER touch WordPress locale, $wp_locale, or any filter that
 * causes WP to load translation (.php/.mo) files. Those files are
 * 50-150 MB each and instantly exhaust shared-host memory limits.
 *
 * Instead we achieve RTL purely via:
 *   1. html[dir="rtl"]          ← AET_Plugin already sets this
 *   2. body.rtl class           ← filter_body_class()
 *   3. Direct RTL stylesheet    ← handle_woodmart_rtl()
 *   4. CSS custom property      ← --aet-dir injected into :root
 *
 * Woodmart JS reads document.dir === 'rtl' automatically – no PHP locale needed.
 */
class AET_Woodmart_Compat {

    private bool $is_arabic = false;

    public function init(): void {
        $this->is_arabic = ( 'ar' === AET_Plugin::lang() );

        add_action( 'wp_enqueue_scripts', [ $this, 'handle_woodmart_rtl' ], 99 );
        add_filter( 'body_class',         [ $this, 'filter_body_class' ] );
        add_action( 'wp_head',            [ $this, 'inject_direction_css' ], 1 );
    }

    public function refresh(): void {
        $this->is_arabic = ( 'ar' === AET_Plugin::lang() );
    }

    public function inject_direction_css(): void {
        $dir   = $this->is_arabic ? 'rtl' : 'ltr';
        $start = $this->is_arabic ? 'right' : 'left';
        $end   = $this->is_arabic ? 'left'  : 'right';
        echo '<style id="aet-direction-vars">',
             ':root{--aet-dir:', esc_html( $dir ), ';',
             '--aet-start:', esc_html( $start ), ';',
             '--aet-end:', esc_html( $end ), '}',
             '</style>', "\n";
    }

    public function handle_woodmart_rtl(): void {
        if ( ! $this->is_arabic ) {
            return;
        }

        foreach ( [ 'woodmart-style-rtl', 'woodmart-rtl' ] as $handle ) {
            if ( wp_style_is( $handle, 'registered' ) ) {
                wp_enqueue_style( $handle );
            }
        }

        if ( wp_style_is( 'woodmart-style', 'registered' ) ) {
            wp_style_add_data( 'woodmart-style', 'rtl', 'replace' );
        }

        foreach ( [ 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-general' ] as $handle ) {
            if ( wp_style_is( $handle, 'registered' ) ) {
                wp_style_add_data( $handle, 'rtl', 'replace' );
            }
        }

        $inline = '
body.rtl{direction:rtl;text-align:right}
body.rtl .site-header,body.rtl .site-footer,body.rtl .entry-content,body.rtl .widget{text-align:right}
body.rtl .wd-header-nav>ul{flex-direction:row-reverse}
body.rtl .products.columns-4,body.rtl .products.columns-3{direction:rtl}
body.rtl .woocommerce-breadcrumb{text-align:right}
body.rtl input,body.rtl select,body.rtl textarea{text-align:right}
        ';
        wp_add_inline_style( 'aet-switcher', $inline );
    }

    public function filter_body_class( array $classes ): array {
        $classes = array_filter( $classes, static fn( $c ) => ! in_array( $c, [ 'rtl', 'ltr' ], true ) );
        $classes[] = $this->is_arabic ? 'rtl' : 'ltr';
        $classes[] = 'aet-lang-' . AET_Plugin::lang();
        return array_values( $classes );
    }
}
