<?php
/**
 * String Translation Engine.
 *
 * Hooks into gettext to translate theme/plugin strings.
 *
 * @package Maharat\Multilingual\Translation
 */

namespace Maharat\Multilingual\Translation;

use Maharat\Multilingual\Core\LanguageManager;

defined( 'ABSPATH' ) || exit;

class StringTranslation {

    private LanguageManager $language_manager;

    /**
     * In-memory string cache.
     *
     * @var array<string, array<string, string>>
     */
    private array $cache = [];

    /**
     * Whether the cache has been primed.
     */
    private bool $primed = false;

    public function __construct( LanguageManager $language_manager ) {
        $this->language_manager = $language_manager;
    }

    /**
     * Initialise hooks.
     */
    public function init(): void {
        // Hook into gettext filters.
        add_filter( 'gettext', [ $this, 'filter_gettext' ], 10, 3 );
        add_filter( 'gettext_with_context', [ $this, 'filter_gettext_with_context' ], 10, 4 );
        add_filter( 'ngettext', [ $this, 'filter_ngettext' ], 10, 5 );
    }

    /**
     * Filter gettext.
     *
     * @param string $translation Translated text.
     * @param string $text        Original text.
     * @param string $domain      Text domain.
     * @return string
     */
    public function filter_gettext( string $translation, string $text, string $domain ): string {
        $custom = $this->get_translation( $text, $domain );
        return $custom ?? $translation;
    }

    /**
     * Filter gettext_with_context.
     *
     * @param string $translation Translated text.
     * @param string $text        Original text.
     * @param string $context     Context.
     * @param string $domain      Text domain.
     * @return string
     */
    public function filter_gettext_with_context( string $translation, string $text, string $context, string $domain ): string {
        $custom = $this->get_translation( $text, $domain, $context );
        return $custom ?? $translation;
    }

    /**
     * Filter ngettext.
     *
     * @param string $translation Translated text.
     * @param string $single      Singular form.
     * @param string $plural      Plural form.
     * @param int    $number      Number.
     * @param string $domain      Text domain.
     * @return string
     */
    public function filter_ngettext( string $translation, string $single, string $plural, int $number, string $domain ): string {
        $text   = ( 1 === $number ) ? $single : $plural;
        $custom = $this->get_translation( $text, $domain );
        return $custom ?? $translation;
    }

    /**
     * Look up a string translation from the database.
     *
     * @param string $text    Original text.
     * @param string $domain  Text domain.
     * @param string $context Optional context.
     * @return string|null The translated string or null.
     */
    public function get_translation( string $text, string $domain = 'default', string $context = '' ): ?string {
        $lang = $this->language_manager->get_current_language();

        // Default language doesn't need translation.
        if ( $this->language_manager->is_default_language( $lang ) ) {
            return null;
        }

        $this->prime_cache( $lang );

        $key = $this->cache_key( $text, $domain, $context );

        return $this->cache[ $lang ][ $key ] ?? null;
    }

    /**
     * Register a string for translation.
     *
     * @param string $text    The original string.
     * @param string $domain  Text domain.
     * @param string $context Optional context.
     * @return bool
     */
    public function register_string( string $text, string $domain = 'default', string $context = '' ): bool {
        global $wpdb;

        $default_lang = $this->language_manager->get_default_language();
        $languages    = $this->language_manager->get_languages();
        $name         = md5( $text . $domain . $context );

        foreach ( $languages as $lang ) {
            // Check if already exists.
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}maharat_string_translations
                 WHERE string_name = %s AND string_domain = %s AND language_code = %s LIMIT 1",
                $name,
                $domain,
                $lang->code
            ) );

            if ( ! $exists ) {
                $wpdb->insert( "{$wpdb->prefix}maharat_string_translations", [
                    'string_name'   => $name,
                    'string_domain' => $domain,
                    'string_value'  => $text,
                    'language_code' => $lang->code,
                    'translation'   => ( $lang->code === $default_lang ) ? $text : null,
                    'status'        => ( $lang->code === $default_lang ) ? 'translated' : 'untranslated',
                    'context'       => $context,
                ] );
            }
        }

        // Invalidate cache.
        $this->primed = false;
        $this->cache  = [];

        return true;
    }

    /**
     * Save a string translation.
     *
     * @param string $name         String name (hash).
     * @param string $domain       Text domain.
     * @param string $language_code Language code.
     * @param string $translation   Translated text.
     * @return bool
     */
    public function save_translation( string $name, string $domain, string $language_code, string $translation ): bool {
        global $wpdb;

        $result = $wpdb->update(
            "{$wpdb->prefix}maharat_string_translations",
            [
                'translation' => $translation,
                'status'      => 'translated',
            ],
            [
                'string_name'   => $name,
                'string_domain' => $domain,
                'language_code' => $language_code,
            ]
        );

        // Invalidate cache.
        $this->primed = false;
        $this->cache  = [];

        return false !== $result;
    }

    /**
     * Get all registered strings (optionally filtered).
     *
     * @param array $args Filters: domain, language_code, status, search, page, per_page.
     * @return array{items: array, total: int}
     */
    public function get_strings( array $args = [] ): array {
        global $wpdb;

        $defaults = [
            'domain'        => '',
            'language_code' => '',
            'status'        => '',
            'search'        => '',
            'page'          => 1,
            'per_page'      => 50,
        ];

        $args = wp_parse_args( $args, $defaults );

        $where = '1=1';
        $params = [];

        if ( ! empty( $args['domain'] ) ) {
            $where .= ' AND string_domain = %s';
            $params[] = $args['domain'];
        }

        if ( ! empty( $args['language_code'] ) ) {
            $where .= ' AND language_code = %s';
            $params[] = $args['language_code'];
        }

        if ( ! empty( $args['status'] ) ) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }

        if ( ! empty( $args['search'] ) ) {
            $where .= ' AND (string_value LIKE %s OR translation LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // Count total.
        $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}maharat_string_translations WHERE {$where}";
        $total = (int) ( empty( $params )
            ? $wpdb->get_var( $count_sql )
            : $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
        );

        // Fetch page.
        $offset = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );
        $limit_sql = "SELECT * FROM {$wpdb->prefix}maharat_string_translations WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $params[] = (int) $args['per_page'];
        $params[] = $offset;

        $items = $wpdb->get_results( $wpdb->prepare( $limit_sql, ...$params ) );

        return [
            'items' => $items ?: [],
            'total' => $total,
        ];
    }

    /**
     * Get all unique string domains.
     *
     * @return array<string>
     */
    public function get_domains(): array {
        global $wpdb;

        $results = $wpdb->get_col(
            "SELECT DISTINCT string_domain FROM {$wpdb->prefix}maharat_string_translations ORDER BY string_domain ASC"
        );

        return $results ?: [];
    }

    /**
     * The maharat_translate_string filter: public API.
     *
     * Usage: apply_filters( 'maharat_translate_string', $text, $domain, $context );
     *
     * @param string $text    The original text.
     * @param string $domain  Text domain.
     * @param string $context Optional context.
     * @return string
     */
    public function translate_string_filter( string $text, string $domain = 'default', string $context = '' ): string {
        return $this->get_translation( $text, $domain, $context ) ?? $text;
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * Prime the cache for a specific language.
     *
     * @param string $lang Language code.
     */
    private function prime_cache( string $lang ): void {
        if ( isset( $this->cache[ $lang ] ) ) {
            return;
        }

        // Try object cache.
        $cached = wp_cache_get( "maharat_strings_{$lang}", 'maharat' );
        if ( false !== $cached ) {
            $this->cache[ $lang ] = $cached;
            return;
        }

        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT string_name, string_domain, string_value, translation, context
             FROM {$wpdb->prefix}maharat_string_translations
             WHERE language_code = %s AND status = 'translated' AND translation IS NOT NULL",
            $lang
        ) );

        $this->cache[ $lang ] = [];
        foreach ( $rows as $row ) {
            $key = $this->cache_key( $row->string_value, $row->string_domain, $row->context );
            $this->cache[ $lang ][ $key ] = $row->translation;
        }

        wp_cache_set( "maharat_strings_{$lang}", $this->cache[ $lang ], 'maharat', HOUR_IN_SECONDS );
    }

    /**
     * Build a cache key for a string.
     *
     * @param string $text    Text.
     * @param string $domain  Domain.
     * @param string $context Context.
     * @return string
     */
    private function cache_key( string $text, string $domain, string $context ): string {
        return md5( $text . '|' . $domain . '|' . $context );
    }
}
