.. _remix.memoize:

=======================
Memoize Trait
=======================

`Infocyph\InterMix\Remix\MemoizeTrait`
adds *per-object* result-caching of expensive operations.

.. code-block:: php

   class Translator {
       use MemoizeTrait;

       public function messages(): array
       {
           // disk I/O only the first time
           return $this->memoize(__METHOD__, fn () => require 'messages.php');
       }
   }

   $t = new Translator();
   $t->messages();   // hit disk
   $t->messages();   // returns cached array

Clear cache:

.. code-block:: php

   $t->memoizeClear();          // everything
   $t->memoizeClear(__METHOD__); // just this key
