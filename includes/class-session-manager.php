<?php
defined( 'ABSPATH' ) || exit;

/**
 * Manages language persistence via PHP session and/or cookie.
 *
 * IMPORTANT: We deliberately store only 'ar' or 'en' — never pass
 * these to WordPress locale functions. The ?lang= query param is
 * consumed by this plugin only and must NOT propagate to WP core
 * or any translation-loading mechanism.
 */
class AET_Session_Manager {

    const COOKIE_NAME = 'aet_lang';
    const SESSION_KEY = 'aet_lang';
    const COOKIE_TTL  = YEAR_IN_SECONDS;

    private string $storage_mode;

    public function __construct() {
        $this->storage_mode = get_option( 'aet_storage_mode', 'both' );
        $this->maybe_start_session();
    }

    private function maybe_start_session(): void {
        if ( ! in_array( $this->storage_mode, [ 'session', 'both' ], true ) ) {
            return;
        }
        if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
            session_start();
        }
    }

    public function get_language(): string {
        $lang = '';

        // Query param takes priority (consumed before WP processes it)
        // phpcs:ignore WordPress.Security.NonceVerification
        $qp = isset( $_GET['lang'] ) ? sanitize_key( $_GET['lang'] ) : '';
        if ( in_array( $qp, [ 'ar', 'en' ], true ) ) {
            return $qp;
        }

        if ( in_array( $this->storage_mode, [ 'session', 'both' ], true ) ) {
            $lang = $_SESSION[ self::SESSION_KEY ] ?? '';
        }

        if ( '' === $lang && in_array( $this->storage_mode, [ 'cookie', 'both' ], true ) ) {
            $lang = $_COOKIE[ self::COOKIE_NAME ] ?? '';
        }

        if ( ! in_array( $lang, [ 'ar', 'en' ], true ) ) {
            $lang = get_option( 'aet_default_language', 'en' );
        }

        return $lang;
    }

    public function set_language( string $lang ): void {
        if ( ! in_array( $lang, [ 'ar', 'en' ], true ) ) {
            return;
        }

        if ( in_array( $this->storage_mode, [ 'session', 'both' ], true ) ) {
            $_SESSION[ self::SESSION_KEY ] = $lang;
        }

        if ( in_array( $this->storage_mode, [ 'cookie', 'both' ], true ) && ! headers_sent() ) {
            setcookie(
                self::COOKIE_NAME,
                $lang,
                [
                    'expires'  => time() + self::COOKIE_TTL,
                    'path'     => COOKIEPATH ?: '/',
                    'domain'   => COOKIE_DOMAIN ?: '',
                    'secure'   => is_ssl(),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]
            );
            $_COOKIE[ self::COOKIE_NAME ] = $lang;
        }
    }
}
