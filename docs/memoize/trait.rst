.. _memoize.trait:

==================
MemoizeTrait
==================

Per‐object memoization trait that stores results in a private array.

**Usage**:

.. code-block:: php

   use Infocyph\InterMix\Memoize\MemoizeTrait;

   class Repo
   {
       use MemoizeTrait;

       public int $counter = 0;

       public function fetchData(): array
       {
           // only the *first* call invokes the closure
           return $this->memoize(__METHOD__, fn() => ++$this->counter);
       }
   }

Example:

.. code-block:: php

   $repo = new Repo();

   $first  = $repo->fetchData(); // counter = 1
   $second = $repo->fetchData(); // counter still = 1, cached
   $repo->memoizeClear();        // clear all entries
   $third  = $repo->fetchData(); // counter = 2
