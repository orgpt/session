<?php
defined( 'ABSPATH' ) || exit;

/**
 * AET Diagnostics – memory profiler and system info panel.
 * Shown in Settings → AET Translator → Diagnostics tab.
 */
class AET_Diagnostics {

    public static function render(): void {
        $data = self::collect();
        ?>
        <div class="aet-diag">
        <h2>🔍 System Diagnostics</h2>

        <?php self::section( '⚠️ Critical Issues', self::critical_issues( $data ) ); ?>
        <?php self::section( '🧠 Memory', self::memory_rows( $data ) ); ?>
        <?php self::section( '🌐 WordPress Locale', self::locale_rows( $data ) ); ?>
        <?php self::section( '📦 Translation Files Loaded', self::translation_rows( $data ) ); ?>
        <?php self::section( '🔌 Active Plugins', self::plugin_rows( $data ) ); ?>
        <?php self::section( '🎨 Theme', self::theme_rows( $data ) ); ?>
        <?php self::section( '🍪 Session / Cookie', self::session_rows( $data ) ); ?>
        <?php self::section( '⚙️ PHP', self::php_rows( $data ) ); ?>

        <p style="margin-top:20px;color:#888;font-size:12px">
            Generated: <?php echo esc_html( gmdate( 'Y-m-d H:i:s' ) ); ?> UTC &nbsp;|&nbsp;
            Peak memory at render: <?php echo esc_html( size_format( memory_get_peak_usage( true ) ) ); ?>
        </p>
        </div>
        <?php
    }

    /* ── Data collection ────────────────────────────────────────── */

    private static function collect(): array {
        global $l10n, $wp_translation_file_cache;

        // Loaded translation domains
        $loaded_domains = [];
        if ( ! empty( $l10n ) && is_array( $l10n ) ) {
            foreach ( $l10n as $domain => $obj ) {
                $loaded_domains[] = $domain;
            }
        }

        // Translation files from WP 6.5+ cache
        $translation_files = [];
        if ( ! empty( $wp_translation_file_cache ) && is_array( $wp_translation_file_cache ) ) {
            foreach ( $wp_translation_file_cache as $file => $obj ) {
                $size = file_exists( $file ) ? filesize( $file ) : 0;
                $translation_files[ $file ] = $size;
            }
        }

        // Find large .php translation files on disk
        $lang_dirs = [
            WP_LANG_DIR,
            WP_LANG_DIR . '/plugins',
            WP_LANG_DIR . '/themes',
        ];
        $disk_files = [];
        foreach ( $lang_dirs as $dir ) {
            if ( ! is_dir( $dir ) ) continue;
            $files = glob( $dir . '/*.php' );
            if ( ! $files ) continue;
            foreach ( $files as $f ) {
                $disk_files[ $f ] = filesize( $f );
            }
        }
        arsort( $disk_files );

        // Active plugins memory estimate
        $plugins = get_option( 'active_plugins', [] );

        return [
            'memory_usage'      => memory_get_usage( true ),
            'memory_peak'       => memory_get_peak_usage( true ),
            'memory_limit_php'  => self::parse_bytes( ini_get( 'memory_limit' ) ),
            'memory_limit_wp'   => self::parse_bytes( WP_MEMORY_LIMIT ),
            'wp_locale'         => get_locale(),
            'wplang_option'     => get_option( 'WPLANG', '(empty = en_US)' ),
            'is_rtl'            => is_rtl(),
            'text_direction'    => $GLOBALS['wp_locale']->text_direction ?? 'unknown',
            'loaded_domains'    => $loaded_domains,
            'translation_files' => $translation_files,
            'disk_lang_files'   => array_slice( $disk_files, 0, 30 ),
            'active_plugins'    => $plugins,
            'theme'             => wp_get_theme()->get( 'Name' ),
            'theme_version'     => wp_get_theme()->get( 'Version' ),
            'wp_version'        => get_bloginfo( 'version' ),
            'php_version'       => PHP_VERSION,
            'aet_lang'          => AET_Plugin::lang(),
            'aet_cookie'        => $_COOKIE['aet_lang'] ?? '(not set)',
            'aet_session'       => $_SESSION['aet_lang'] ?? '(not set)',
            'session_status'    => session_status(),
        ];
    }

    /* ── Issue detector ─────────────────────────────────────────── */

    private static function critical_issues( array $d ): array {
        $rows = [];

        $mem_pct = $d['memory_limit_php'] > 0
            ? round( $d['memory_peak'] / $d['memory_limit_php'] * 100 )
            : 0;

        if ( $mem_pct >= 80 ) {
            $rows[] = [
                '🔴 Memory critical',
                'Peak usage is ' . $mem_pct . '% of limit (' . size_format( $d['memory_peak'] ) . ' / ' . size_format( $d['memory_limit_php'] ) . '). Site WILL crash.',
            ];
        }

        $locale = $d['wp_locale'];
        if ( $locale && ! in_array( $locale, [ 'en_US', 'en_GB', '' ], true ) ) {
            $rows[] = [
                '🔴 WordPress locale is "' . esc_html( $locale ) . '"',
                'WordPress is set to a non-English locale. This forces WP to load large translation .php files for EVERY active plugin and theme on EVERY page load. '
                . '<strong>Fix: Add <code>define(\'WPLANG\', \'\');</code> to wp-config.php</strong> — AET handles Arabic display via CSS/JS with zero memory cost.',
            ];
        }

        $big = array_filter( $d['disk_lang_files'], fn( $s ) => $s > 5 * 1024 * 1024 );
        if ( $big ) {
            $total = array_sum( $big );
            $rows[] = [
                '🟠 ' . count( $big ) . ' large translation files on disk',
                size_format( $total ) . ' total. These are loaded into RAM on each request when WP locale is non-English. Files: <br><small>'
                . implode( '<br>', array_map( fn( $f, $s ) => basename( $f ) . ' (' . size_format( $s ) . ')', array_keys( $big ), $big ) )
                . '</small>',
            ];
        }

        if ( empty( $rows ) ) {
            $rows[] = [ '✅ No critical issues detected', 'Memory usage looks healthy at this point.' ];
        }

        return $rows;
    }

    /* ── Section builders ───────────────────────────────────────── */

    private static function memory_rows( array $d ): array {
        $pct = $d['memory_limit_php'] > 0
            ? round( $d['memory_peak'] / $d['memory_limit_php'] * 100 ) . '%'
            : 'N/A';
        return [
            [ 'Current usage',       size_format( $d['memory_usage'] ) ],
            [ 'Peak usage',          size_format( $d['memory_peak'] ) . ' (' . $pct . ' of PHP limit)' ],
            [ 'PHP memory_limit',    size_format( $d['memory_limit_php'] ) ],
            [ 'WP_MEMORY_LIMIT',     size_format( $d['memory_limit_wp'] ) ],
        ];
    }

    private static function locale_rows( array $d ): array {
        return [
            [ 'get_locale()',        esc_html( $d['wp_locale'] ) ],
            [ 'WPLANG option',       esc_html( $d['wplang_option'] ) ],
            [ 'is_rtl()',            $d['is_rtl'] ? '✅ true' : '❌ false' ],
            [ 'text_direction',      esc_html( $d['text_direction'] ) ],
            [ 'AET current lang',    esc_html( $d['aet_lang'] ) ],
        ];
    }

    private static function translation_rows( array $d ): array {
        $rows = [];

        if ( $d['translation_files'] ) {
            foreach ( $d['translation_files'] as $file => $size ) {
                $rows[] = [ basename( $file ), size_format( $size ) ];
            }
        } else {
            $rows[] = [ 'Loaded translation files', '(none detected in WP cache — see disk files below)' ];
        }

        if ( $d['loaded_domains'] ) {
            $rows[] = [ '— Text domains in $l10n —', implode( ', ', array_map( 'esc_html', $d['loaded_domains'] ) ) ];
        }

        // Disk files
        foreach ( $d['disk_lang_files'] as $file => $size ) {
            $flag = $size > 5 * 1024 * 1024 ? ' 🔴' : ( $size > 1024 * 1024 ? ' 🟠' : '' );
            $rows[] = [ '📄 ' . basename( $file ) . $flag, size_format( $size ) ];
        }

        return $rows ?: [ [ 'No translation files found', '—' ] ];
    }

    private static function plugin_rows( array $d ): array {
        $rows = [];
        foreach ( $d['active_plugins'] as $plugin ) {
            $data  = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
            $name  = $data['Name'] ?? $plugin;
            $rows[] = [ esc_html( $name ), esc_html( $plugin ) ];
        }
        return $rows ?: [ [ 'No active plugins', '—' ] ];
    }

    private static function theme_rows( array $d ): array {
        return [
            [ 'Active theme',   esc_html( $d['theme'] ) ],
            [ 'Version',        esc_html( $d['theme_version'] ) ],
            [ 'WordPress',      esc_html( $d['wp_version'] ) ],
        ];
    }

    private static function session_rows( array $d ): array {
        return [
            [ 'aet_lang cookie',  esc_html( $d['aet_cookie'] ) ],
            [ 'aet_lang session', esc_html( $d['aet_session'] ) ],
            [ 'session_status',   esc_html( (string) $d['session_status'] ) . ' (1=none, 2=active)' ],
        ];
    }

    private static function php_rows( array $d ): array {
        return [
            [ 'PHP version',      esc_html( $d['php_version'] ) ],
            [ 'max_execution_time', esc_html( ini_get( 'max_execution_time' ) ) . 's' ],
            [ 'upload_max_filesize', esc_html( ini_get( 'upload_max_filesize' ) ) ],
            [ 'opcache enabled',  function_exists( 'opcache_get_status' ) ? '✅ yes' : '❌ no' ],
        ];
    }

    /* ── HTML helpers ────────────────────────────────────────────── */

    private static function section( string $title, array $rows ): void {
        if ( empty( $rows ) ) return;
        echo '<h3 style="margin:24px 0 8px;border-bottom:2px solid #e0e0e0;padding-bottom:6px">'
            . esc_html( $title ) . '</h3>';
        echo '<table class="aet-diag-table widefat striped" style="margin-bottom:0">';
        echo '<tbody>';
        foreach ( $rows as [ $label, $value ] ) {
            echo '<tr>';
            echo '<td style="width:280px;font-weight:600;padding:8px 12px">' . wp_kses_post( $label ) . '</td>';
            echo '<td style="padding:8px 12px;word-break:break-all">' . wp_kses_post( $value ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /* ── Byte parser ─────────────────────────────────────────────── */

    private static function parse_bytes( string $val ): int {
        $val  = trim( $val );
        $last = strtolower( $val[ strlen( $val ) - 1 ] );
        $num  = (int) $val;
        return match ( $last ) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }
}
