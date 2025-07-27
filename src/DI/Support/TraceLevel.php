<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

/**
 * Verbosity / severity scale used by {@see DebugTracer}.
 *
 * Lower numbers are *more important / less noisy*; anything whose value
 * exceeds the tracer’s current threshold is skipped.
 *
 *  Off     – disable tracing
 *  Error   – fatal problems only
 *  Warn    – recoverable issues, mis-configuration
 *  Info    – high-level lifecycle events
 *  Node    – DI node / definition boundaries (default)
 *  Verbose – parameters, lazy resolutions, etc.
 */
enum TraceLevel: int
{
    case Off     = 0;
    case Error   = 1;
    case Warn    = 2;
    case Info    = 3;
    case Node    = 4;
    case Verbose = 5;
}
