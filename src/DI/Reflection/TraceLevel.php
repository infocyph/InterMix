<?php

namespace Infocyph\InterMix\DI\Reflection;

enum TraceLevel: int
{
    case Node = 1;   // class/definition boundaries (default)
    case Verbose = 2;   // parameters, properties, lazy initialisers …
}
