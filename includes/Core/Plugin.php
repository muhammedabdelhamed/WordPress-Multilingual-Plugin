<?php
/**
 * Main Plugin Class.
 *
 * Singleton entry point that wires all services together.
 *
 * @package Maharat\Multilingual\Core
 */

namespace Maharat\Multilingual\Core;

use Maharat\Multilingual\Admin\AdminUI;
use Maharat\Multilingual\Api\RestApi;
use Maharat\Multilingual\Compatibility\BuilderCompatibility;
use Maharat\Multilingual\Compatibility\WooCommerceCompat;
use Maharat\Multilingual\Frontend\LanguageSwitcher;
use Maharat\Multilingual\Migration\MigrationTool;
use Maharat\Multilingual\Seo\SeoHandler;
use Maharat\Multilingual\Translation\AutoTranslation;
use Maharat\Multilingual\Translation\StringTranslation;
use Maharat\Multilingual\Translation\TranslationManager;

defined( 'ABSPATH' ) || exit;

final class Plugin {

    /**
     * Singleton instance.
     */
    private static ?Plugin $instance = null;

    /**
     * Service container.
     */
    private Container $container;

    /**
     * Whether the plugin has been initialised.
     */
    private bool $initialised = false;

    /**
     * Get / create the singleton.
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->container = new Container();
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}

    /**
     * Access the container.
     */
    public function container(): Container {
        return $this->container;
    }

    /**
     * Initialise all services.
     */
    public function init(): void {
        if ( $this->initialised ) {
            return;
        }
        $this->initialised = true;

        $this->register_services();
        $this->boot_services();
    }

    /**
     * Register service definitions (lazy).
     */
    private function register_services(): void {
        $c = $this->container;

        // Core: Language Manager.
        $c->set( 'language_manager', static fn( Container $c ) => new LanguageManager() );

        // Core: URL Router.
        $c->set( 'url_router', static fn( Container $c ) => new UrlRouter( $c->get( 'language_manager' ) ) );

        // Translation Manager.
        $c->set( 'translation_manager', static fn( Container $c ) => new TranslationManager( $c->get( 'language_manager' ) ) );

        // String Translation.
        $c->set( 'string_translation', static fn( Container $c ) => new StringTranslation( $c->get( 'language_manager' ) ) );

        // Auto Translation.
        $c->set( 'auto_translation', static fn( Container $c ) => new AutoTranslation() );

        // Migration Tool.
        $c->set( 'migration_tool', static fn( Container $c ) => new MigrationTool(
            $c->get( 'language_manager' ),
            $c->get( 'translation_manager' ),
            $c->get( 'string_translation' )
        ) );

        // SEO Handler.
        $c->set( 'seo_handler', static fn( Container $c ) => new SeoHandler( $c->get( 'language_manager' ), $c->get( 'url_router' ) ) );

        // Builder Compatibility.
        $c->set( 'builder_compat', static fn( Container $c ) => new BuilderCompatibility() );

        // WooCommerce.
        $c->set( 'woocommerce', static fn( Container $c ) => new WooCommerceCompat( $c->get( 'language_manager' ), $c->get( 'translation_manager' ) ) );

        // REST API.
        $c->set( 'rest_api', static fn( Container $c ) => new RestApi(
            $c->get( 'language_manager' ),
            $c->get( 'translation_manager' ),
            $c->get( 'string_translation' ),
            $c->get( 'auto_translation' ),
            $c->get( 'migration_tool' )
        ) );

        // Admin UI.
        $c->set( 'admin_ui', static fn( Container $c ) => new AdminUI(
            $c->get( 'language_manager' ),
            $c->get( 'translation_manager' ),
            $c->get( 'string_translation' )
        ) );

        // Language Switcher.
        $c->set( 'language_switcher', static fn( Container $c ) => new LanguageSwitcher(
            $c->get( 'language_manager' ),
            $c->get( 'url_router' )
        ) );

        /**
         * Fires after all Maharat services are registered in the container.
         *
         * @param Container $container The service container.
         */
        do_action( 'maharat_services_registered', $c );
    }

    /**
     * Boot (initialise) the registered services.
     */
    private function boot_services(): void {
        // Always boot these.
        $always = [
            'language_manager',
            'url_router',
            'translation_manager',
            'string_translation',
            'seo_handler',
            'builder_compat',
            'language_switcher',
        ];

        foreach ( $always as $id ) {
            $service = $this->container->get( $id );
            if ( method_exists( $service, 'init' ) ) {
                $service->init();
            }
        }

        // Admin only.
        if ( is_admin() ) {
            $this->container->get( 'admin_ui' )->init();
        }

        // REST API.
        add_action( 'rest_api_init', fn() => $this->container->get( 'rest_api' )->register_routes() );

        // WooCommerce (conditional).
        add_action( 'woocommerce_init', fn() => $this->container->get( 'woocommerce' )->init() );

        /**
         * Fires after all Maharat services have been booted.
         */
        do_action( 'maharat_services_booted' );
    }

    /**
     * Helper to access a service quickly.
     *
     * @param string $id Service identifier.
     * @return mixed
     */
    public function __get( string $id ): mixed {
        return $this->container->get( $id );
    }
}
