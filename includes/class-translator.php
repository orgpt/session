<?php
defined( 'ABSPATH' ) || exit;

/**
 * Server-side proxy to Google Translate free endpoint.
 * Keeps API calls server-side to avoid CORS issues.
 */
class AET_Translator {

    const ENDPOINT            = 'https://translate.googleapis.com/translate_a/single';
    const CACHE_GROUP         = 'aet_translations';
    const MAX_BATCH_ITEMS     = 40;
    const MAX_TEXT_LENGTH     = 2000;
    const MAX_TOTAL_CHARS     = 20000;

    public function __construct() {
        add_action( 'wp_ajax_nopriv_aet_translate', [ $this, 'ajax_translate' ] );
        add_action( 'wp_ajax_aet_translate',        [ $this, 'ajax_translate' ] );
    }

    /**
     * AJAX handler – receives a batch of text strings and returns translations.
     *
     * Request body (JSON):
     *   { texts: string[], target: "ar"|"en", source: "en"|"ar" }
     *
     * Response:
     *   { translations: string[] }
     */
    public function ajax_translate(): void {
        check_ajax_referer( 'aet_translate', 'nonce' );

        $body = json_decode( file_get_contents( 'php://input' ), true );
        if ( ! is_array( $body ) ) {
            wp_send_json_error( 'Invalid payload', 400 );
        }

        $raw_texts = (array) ( $body['texts'] ?? [] );
        $texts     = array_slice( array_map( 'sanitize_textarea_field', $raw_texts ), 0, self::MAX_BATCH_ITEMS );
        $target    = sanitize_key( $body['target'] ?? 'ar' );
        $source    = sanitize_key( $body['source'] ?? 'en' );

        if ( ! in_array( $target, [ 'ar', 'en' ], true ) || empty( $texts ) ) {
            wp_send_json_error( 'Invalid params', 400 );
        }

        $total_chars  = 0;
        $translations = [];

        foreach ( $texts as $text ) {
            if ( function_exists( 'mb_substr' ) ) {
                $text = mb_substr( $text, 0, self::MAX_TEXT_LENGTH );
            } else {
                $text = substr( $text, 0, self::MAX_TEXT_LENGTH );
            }
            $total_chars += strlen( $text );

            if ( $total_chars > self::MAX_TOTAL_CHARS ) {
                break;
            }

            $translations[] = $this->translate( $text, $source, $target );
        }

        wp_send_json_success( [ 'translations' => $translations ] );
    }

    /**
     * Translate a single string.  Results are cached in WordPress object cache.
     */
    public function translate( string $text, string $source, string $target ): string {
        if ( '' === trim( $text ) ) {
            return $text;
        }

        $cache_key = md5( $source . $target . $text );
        $cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
        if ( false !== $cached ) {
            return $cached;
        }

        $url = add_query_arg(
            [
                'client'   => 'gtx',
                'sl'       => $source,
                'tl'       => $target,
                'dt'       => 't',
                'q'        => rawurlencode( $text ),
            ],
            self::ENDPOINT
        );

        $response = wp_remote_get( $url, [
            'timeout'    => 8,
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo( 'version' ) . ')',
            'headers'    => [
                'Accept'          => 'application/json',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $text;   // Fallback to original on network error
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data[0] ) ) {
            return $text;
        }

        // Google's response: $data[0] is array of [translated_chunk, original_chunk, ...]
        $translated = '';
        foreach ( (array) $data[0] as $chunk ) {
            if ( isset( $chunk[0] ) ) {
                $translated .= $chunk[0];
            }
        }

        $translated = trim( $translated );
        if ( '' === $translated ) {
            return $text;
        }

        wp_cache_set( $cache_key, $translated, self::CACHE_GROUP, HOUR_IN_SECONDS * 6 );
        return $translated;
    }
}
