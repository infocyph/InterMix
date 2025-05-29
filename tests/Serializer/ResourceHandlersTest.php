<?php
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Serializer\ResourceHandlers;

// A subclass that actually implements the registerXxx() methods:
class MyResourceHandlers extends ResourceHandlers
{
    /** Streams (file & network) */
    public static function registerStream(): void
    {
        ValueSerializer::registerResourceHandler(
            'stream',
            function ($res): array {
                $meta = stream_get_meta_data($res);
                rewind($res);
                return [
                    'mode'    => $meta['mode'],
                    'content' => stream_get_contents($res),
                ];
            },
            function (array $d) {
                $s = fopen('php://memory', $d['mode']);
                fwrite($s, $d['content']);
                rewind($s);
                return $s;
            }
        );
    }
}

it('stream handler round-trip via MyResourceHandlers', function () {
    MyResourceHandlers::registerStream();

    $s = fopen('php://memory', 'r+');
    fwrite($s, 'hello');
    rewind($s);

    $blob = ValueSerializer::serialize($s);
    $rest = ValueSerializer::unserialize($blob);

    expect(is_resource($rest))
        ->toBeTrue()
        ->and(get_resource_type($rest))->toBe('stream')
        ->and(stream_get_contents($rest))->toBe('hello');
});

it('registerDefaults invokes registerStream()', function () {
    MyResourceHandlers::registerDefaults();

    $s = fopen('php://memory', 'r+');
    fwrite($s, 'xyz');
    rewind($s);

    $blob = ValueSerializer::serialize($s);
    $rest = ValueSerializer::unserialize($blob);

    expect(get_resource_type($rest))
        ->toBe('stream')
        ->and(stream_get_contents($rest))->toBe('xyz');
});
