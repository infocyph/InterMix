.. _remix.helpers:

=========================
Global Helper Functions
=========================

tap()
-----

Already covered in :ref:`remix.tap-proxy`.

pipe()
------

.. code-block:: php

   $len = pipe('abc', 'strlen');     // 3

Executes the callback **and returns its result** (not the original
value).

measure()
---------

.. code-block:: php

   $result = measure(fn () => heavyWork(), $ms);
   echo "Heavy work took {$ms} ms";

`measure()` fills the second argument (by-ref) with elapsed time in
milliseconds.

retry()
-------

Execute a block **until it succeeds** or the maximum number of attempts
is reached.

.. code-block:: php

   $json = retry(
       attempts: 5,
       callback: fn () => file_get_contents($url),
       shouldRetry: fn ($e) => $e instanceof NetworkError,
       delayMs: 200,
       backoff: 2.0,       // exponential
   );
