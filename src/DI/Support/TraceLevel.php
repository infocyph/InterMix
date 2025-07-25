<?php

namespace Infocyph\InterMix\DI\Support;

enum TraceLevel: int
{
    case Off = 0;   // Off
    case Node = 1;   // class/definition boundaries (default)
    case Verbose = 2;   // parameters, properties, lazy initialisers …
}
