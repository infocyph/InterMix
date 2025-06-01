.. _serializer.resource_handlers:

=====================
ResourceHandlers
=====================

`ResourceHandlers` is a **convenience base
class** intended to help you register multiple resource handlers via a single
static call (`registerDefaults()`).

It does **not** itself define any handlers; instead, you must extend it and
implement named `registerXxx()` methods.  When you call
`YourSubclass::registerDefaults()`, it automatically invokes every static
method that begins with `"register"`.

Why?
----

When your application or library needs to support *many* resource types (e.g.
streams, cURL handles, XML parsers, GD images, sockets, etc.), you can:

1. Group them in one “bundle” class that extends `ResourceHandlers`.
2. Inside it, write one `registerXxx()` method per resource type:
   - e.g. `registerStream()`, `registerCurl()`, `registerXmlParser()`, etc.
3. Call `YourSubclass::registerDefaults()` to register all of them at once.

This approach keeps your resource‐wrapper logic organized and self-documenting.

Class API
---------

.. py:function:: void ResourceHandlers::registerDefaults()

   Iterates over all public static methods on `self::class` whose names start
   with `"register"`, except `registerDefaults` itself, and invokes each one.
   In other words, every `registerXxx()` method in your subclass runs.

   Does nothing if no `registerXxx()` methods exist.

You **cannot** instantiate `ResourceHandlers` (its constructor is private).
It exists only to host static registration methods.

Example: Registering a Stream Handler
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Suppose you want to support PHP streams:

.. code-block:: php

   <?php
   namespace App\\Serializer;

   use Infocyph\\InterMix\\Serializer\\ResourceHandlers;
   use Infocyph\\InterMix\\Serializer\\ValueSerializer;

   class MyResourceHandlers extends ResourceHandlers
   {
       /**
        * Handles stream resources (both file‐ and network‐based).
        */
       public static function registerStream(): void
       {
           ValueSerializer::registerResourceHandler(
               'stream',
               // ---------- wrapFn -----------------------------------
               function (resource $res): array {
                   $meta = stream_get_meta_data($res);
                   rewind($res);
                   return [
                       'mode'    => $meta['mode'],
                       'content' => stream_get_contents($res),
                   ];
               },
               // -------- restoreFn ---------------------------------
               function (array $data): resource {
                   $s = fopen('php://memory', $data['mode']);
                   fwrite($s, $data['content']);
                   rewind($s);
                   return $s;
               }
           );
       }
   }

Once you have that subclass, you can register the handler in two ways:

1. **Call `registerStream()` directly**:

   .. code-block:: php

      MyResourceHandlers::registerStream();

2. **Call `registerDefaults()` to pick up every `registerXxx()`**:

   .. code-block:: php

      MyResourceHandlers::registerDefaults();
      // → automatically calls registerStream(), plus any other registerXxx()

   Then use ValueSerializer as usual:

   .. code-block:: php

      $fp   = fopen('php://memory','r+');
      fwrite($fp,'hello'); rewind($fp);

      $blob    = ValueSerializer::serialize($fp);
      $restored = ValueSerializer::unserialize($blob);
      echo stream_get_contents($restored); // "hello"

Adding More Resource Types
~~~~~~~~~~~~~~~~~~~~~~~~~~

To support additional resources:

1. **Extend** `ResourceHandlers`.
2. **Add** a new public static method named `registerFoo()` where “Foo” is any
   string you like (e.g. `registerCurl()`, `registerXmlParser()`, etc.).
3. Inside it, call:

   .. code-block:: php

      ValueSerializer::registerResourceHandler(
          '<type>',    // e.g. 'curl'
          fn(resource $res): array => /* wrap logic */,
          fn(array $data): resource   => /* restore logic */
      );

4. If you want all handlers applied at once, simply call:

   .. code-block:: php

      MyResourceHandlers::registerDefaults();

Example: cURL Handler
~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php
<?php
namespace App\\Serializer;

use Infocyph\\InterMix\\Serializer\\ResourceHandlers;
use Infocyph\\InterMix\\Serializer\\ValueSerializer;

class MyResourceHandlers extends ResourceHandlers
{
    public static function registerCurl(): void
    {
        if (!extension_loaded('curl')) {
            return;
        }
        ValueSerializer::registerResourceHandler(
            'curl',
            // wrapFn: store only the effective URL
            function ($ch): array {
                return ['url' => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)];
            },
            // restoreFn: open a new cURL handle with the same URL
            function (array $data): resource {
                return curl_init($data['url'] ?? '');
            }
        );
    }
}

// Usage:
MyResourceHandlers::registerDefaults();
// now you can ValueSerializer::serialize($my_curl_handle) safely.


Empty‐State and Testing
~~~~~~~~~~~~~~~~~~~~~~~
- By default, **no resource handlers** are registered.  If you call
  `ValueSerializer::wrap($someResource)` before any `registerResourceHandler()`,
  you get an `InvalidArgumentException`.
- You can always start with a clean slate:

  .. code-block:: php

     use Infocyph\\InterMix\\Serializer\\ValueSerializer;

     ValueSerializer::clearResourceHandlers(); // removes all handlers
