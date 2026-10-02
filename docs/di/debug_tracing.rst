.. _di.debug-tracing:

================
Debug tracing
================

Tracing is configured before finalization:

.. code-block:: php

   use Infocyph\InterMix\DI\Support\TraceLevelEnum;

   $builder->enableDebugTracing(true, TraceLevelEnum::Verbose);
   $runtime = $builder->build();

Container exposes debug(id) for a focused resolution trace and tracer() for explicit diagnostic access.

Diagnostics are optional and should remain disabled on ordinary hot paths unless required by the host.
