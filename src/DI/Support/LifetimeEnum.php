<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

/**
 * Enumeration for service lifetime management in the DI container.
 *
 * This enum defines how long a service instance should live and
 * when new instances should be created:
 *
 * - Singleton: One instance per container for the entire lifetime
 * - Transient: Always creates a fresh instance on each request
 * - Scoped: One instance per logical scope (fiber/request)
 */
enum LifetimeEnum: string
{
    /**
     * One instance per logical scope (fiber/request).
     * Instances are shared within the same scope but isolated between different scopes.
     */
    case Scoped = 'scoped';
    /**
     * One instance per container for the entire lifetime.
     * The same instance is returned on all subsequent requests.
     */
    case Singleton = 'singleton';

    /**
     * Always creates a fresh instance on each request.
     * No caching or reuse of instances occurs.
     */
    case Transient = 'transient';
}
