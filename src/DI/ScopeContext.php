<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

/**
 * Opaque, process-local handle to an active logical DI scope.
 *
 * Applications may pass this value between in-process execution carriers, but
 * its implementation and ownership metadata remain internal to InterMix.
 */
interface ScopeContext {}
