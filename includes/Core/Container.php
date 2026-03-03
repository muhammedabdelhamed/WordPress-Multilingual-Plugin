<?php
/**
 * Service Container.
 *
 * Lightweight dependency injection container.
 *
 * @package Maharat\Multilingual\Core
 */

namespace Maharat\Multilingual\Core;

defined( 'ABSPATH' ) || exit;

class Container {

    /**
     * Registered service definitions.
     *
     * @var array<string, callable>
     */
    private array $definitions = [];

    /**
     * Resolved singleton instances.
     *
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * Register a service definition (lazy).
     *
     * @param string   $id       Service identifier.
     * @param callable $factory  Factory closure.
     * @return void
     */
    public function set( string $id, callable $factory ): void {
        $this->definitions[ $id ] = $factory;
        unset( $this->instances[ $id ] );
    }

    /**
     * Resolve a service. Singletons are cached.
     *
     * @param string $id Service identifier.
     * @return mixed
     * @throws \InvalidArgumentException If service is not registered.
     */
    public function get( string $id ): mixed {
        if ( isset( $this->instances[ $id ] ) ) {
            return $this->instances[ $id ];
        }

        if ( ! isset( $this->definitions[ $id ] ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'Service "%s" is not registered in the container.', $id )
            );
        }

        $this->instances[ $id ] = call_user_func( $this->definitions[ $id ], $this );

        return $this->instances[ $id ];
    }

    /**
     * Check if a service is registered.
     *
     * @param string $id Service identifier.
     * @return bool
     */
    public function has( string $id ): bool {
        return isset( $this->definitions[ $id ] ) || isset( $this->instances[ $id ] );
    }
}
