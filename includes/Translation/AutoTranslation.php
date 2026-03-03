<?php
/**
 * Auto Translation Engine.
 *
 * Supports Google Translate, DeepL, and OpenAI APIs.
 *
 * @package Maharat\Multilingual\Translation
 */

namespace Maharat\Multilingual\Translation;

defined( 'ABSPATH' ) || exit;

class AutoTranslation {

    /**
     * Supported translation providers.
     */
    private const PROVIDERS = [ 'google', 'deepl', 'openai' ];

    /**
     * Translate text using the configured provider.
     *
     * @param string $text        Text to translate.
     * @param string $source_lang Source language code.
     * @param string $target_lang Target language code.
     * @param string $provider    Provider name (auto-detect from settings if empty).
     * @return string|false Translated text or false on failure.
     */
    public function translate( string $text, string $source_lang, string $target_lang, string $provider = '' ): string|false {
        if ( empty( $provider ) ) {
            $provider = get_option( 'maharat_translation_api', '' );
        }

        if ( ! in_array( $provider, self::PROVIDERS, true ) ) {
            return false;
        }

        $api_key = $this->get_api_key( $provider );
        if ( empty( $api_key ) ) {
            return false;
        }

        /**
         * Filter text before sending to translation API.
         *
         * @param string $text        The text.
         * @param string $source_lang Source language.
         * @param string $target_lang Target language.
         */
        $text = apply_filters( 'maharat_before_auto_translate', $text, $source_lang, $target_lang );

        $result = match ( $provider ) {
            'google' => $this->google_translate( $text, $source_lang, $target_lang, $api_key ),
            'deepl'  => $this->deepl_translate( $text, $source_lang, $target_lang, $api_key ),
            'openai' => $this->openai_translate( $text, $source_lang, $target_lang, $api_key ),
            default  => false,
        };

        if ( false !== $result ) {
            $this->increment_api_counter( $provider, strlen( $text ) );

            /**
             * Fires after a text is auto-translated.
             *
             * @param string $result      Translated text.
             * @param string $text        Original text.
             * @param string $source_lang Source language.
             * @param string $target_lang Target language.
             * @param string $provider    Provider.
             */
            do_action( 'maharat_after_auto_translate', $result, $text, $source_lang, $target_lang, $provider );
        }

        return $result;
    }

    /**
     * Bulk translate an array of texts.
     *
     * @param array  $texts       Array of texts.
     * @param string $source_lang Source language.
     * @param string $target_lang Target language.
     * @return array Array of translated texts (same keys).
     */
    public function bulk_translate( array $texts, string $source_lang, string $target_lang ): array {
        $results = [];
        foreach ( $texts as $key => $text ) {
            $result = $this->translate( $text, $source_lang, $target_lang );
            $results[ $key ] = ( false !== $result ) ? $result : $text;
        }
        return $results;
    }

    /**
     * Get API usage statistics.
     *
     * @return array{google: int, deepl: int, openai: int}
     */
    public function get_usage_stats(): array {
        return [
            'google' => (int) get_option( 'maharat_api_usage_google', 0 ),
            'deepl'  => (int) get_option( 'maharat_api_usage_deepl', 0 ),
            'openai' => (int) get_option( 'maharat_api_usage_openai', 0 ),
        ];
    }

    /**
     * Reset API usage counter.
     *
     * @param string $provider Provider name.
     */
    public function reset_usage( string $provider ): void {
        if ( in_array( $provider, self::PROVIDERS, true ) ) {
            update_option( "maharat_api_usage_{$provider}", 0 );
        }
    }

    // =========================================================================
    // Provider Implementations
    // =========================================================================

    /**
     * Google Translate API.
     */
    private function google_translate( string $text, string $source, string $target, string $api_key ): string|false {
        $url = add_query_arg( [
            'key'    => $api_key,
            'q'      => $text,
            'source' => $source,
            'target' => $target,
            'format' => 'text',
        ], 'https://translation.googleapis.com/language/translate/v2' );

        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => [ 'Content-Type' => 'application/json' ],
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'google', $response->get_error_message() );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return $body['data']['translations'][0]['translatedText'] ?? false;
    }

    /**
     * DeepL API.
     */
    private function deepl_translate( string $text, string $source, string $target, string $api_key ): string|false {
        $is_free = str_ends_with( $api_key, ':fx' );
        $base    = $is_free
            ? 'https://api-free.deepl.com/v2/translate'
            : 'https://api.deepl.com/v2/translate';

        $response = wp_remote_post( $base, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'DeepL-Auth-Key ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'text'        => [ $text ],
                'source_lang' => strtoupper( $source ),
                'target_lang' => strtoupper( $target ),
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'deepl', $response->get_error_message() );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return $body['translations'][0]['text'] ?? false;
    }

    /**
     * OpenAI API (GPT).
     */
    private function openai_translate( string $text, string $source, string $target, string $api_key ): string|false {
        $model = get_option( 'maharat_openai_model', 'gpt-4o-mini' );

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'model'       => $model,
                'temperature' => 0.3,
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => "You are a professional translator. Translate the following text from {$source} to {$target}. Return ONLY the translated text, no explanations.",
                    ],
                    [
                        'role'    => 'user',
                        'content' => $text,
                    ],
                ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'openai', $response->get_error_message() );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return trim( $body['choices'][0]['message']['content'] ?? '' ) ?: false;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Get API key (decrypted).
     */
    private function get_api_key( string $provider ): string {
        $key = get_option( "maharat_api_key_{$provider}", '' );

        /**
         * Filter the API key (e.g. for decryption).
         *
         * @param string $key      The stored key.
         * @param string $provider The provider name.
         */
        return apply_filters( 'maharat_api_key', $key, $provider );
    }

    /**
     * Increment the character counter for a provider.
     */
    private function increment_api_counter( string $provider, int $chars ): void {
        $current = (int) get_option( "maharat_api_usage_{$provider}", 0 );
        update_option( "maharat_api_usage_{$provider}", $current + $chars );
    }

    /**
     * Log a translation API error.
     */
    private function log_error( string $provider, string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( sprintf( '[Maharat Auto-Translation] %s error: %s', $provider, $message ) );
        }
    }
}
