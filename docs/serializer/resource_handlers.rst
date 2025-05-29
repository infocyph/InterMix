.. _serializer.resource_handlers:

=====================
ResourceHandlers
=====================

`Infocyph\InterMix\Serializer\ResourceHandlers` is a **base class**
that discovers and invokes your custom `registerXxx()` methods via
`registerDefaults()`.  You extend it to add real handlers:

.. code-block:: php

   use Infocyph\InterMix\Serializer\ValueSerializer;
   use Infocyph\InterMix\Serializer\ResourceHandlers;

   class MyResourceHandlers extends ResourceHandlers
   {
       public static function registerStream(): void
       {
           ValueSerializer::registerResourceHandler(
               'stream',
               fn($r): array => [
                   'mode'    => stream_get_meta_data($r)['mode'],
                   'content' => tap($r, fn()=>rewind($r)) && stream_get_contents($r),
               ],
               fn(array $d) => tap(fopen('php://memory', $d['mode']),
                                   fn($s)=>fwrite($s,$d['content'])&&rewind($s))
           );
       }

       public static function registerCurl(): void { /* … */ }
       public static function registerXmlParser(): void { /* … */ }
   }

Registering Handlers
--------------------

- **Per-type**: call `MyResourceHandlers::registerStream()`, etc.
- **All-at-once**: `MyResourceHandlers::registerDefaults()` invokes every
  `registerXxx()` method it finds.

Example: Streams
---------------

.. code-block:: php

   MyResourceHandlers::registerStream();

   $s   = fopen('php://memory','r+');
   fwrite($s,'hello'); rewind($s);

   // Now ValueSerializer knows how to handle streams:
   $blob = ValueSerializer::serialize($s);
   $rest = ValueSerializer::unserialize($blob);

   echo stream_get_contents($rest);   // outputs 'hello'

Example: registerDefaults()
---------------------------

.. code-block:: php

   MyResourceHandlers::registerDefaults();
   // calls registerStream(), registerCurl(), registerXmlParser(), …

   // Then you can serialize any of those:
   $blob = ValueSerializer::serialize($resource);
   $val  = ValueSerializer::unserialize($blob);

Extending for New Resource Types
--------------------------------

1. Subclass `ResourceHandlers`.
2. Add `public static function registerYourType(): void`.
3. In it, call `ValueSerializer::registerResourceHandler(...)`.
4. Optionally call `registerDefaults()` to pick it up.

```php
class ExtraHandlers extends ResourceHandlers
{
    public static function registerGd(): void
    {
        ValueSerializer::registerResourceHandler(
            'gd',
            fn($im): array => ['png'=> imagepng($im, null, 0, PNG_NO_FILTER)],
            fn($d): resource => imagecreatefromstring($d['png'])
        );
    }
}

ExtraHandlers::registerGd();
