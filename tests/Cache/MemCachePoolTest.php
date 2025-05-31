<?php

/**
 * tests/MemCachePoolTest.php
 *
 * Runs only when the Memcached extension is loaded *and*
 * a Memcached daemon is reachable at 127.0.0.1:11211.
 */

use Infocyph\InterMix\Cache\Cachepool;
use Infocyph\InterMix\Cache\Item\MemCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;

/* ── Skip suite if Memcached unavailable ─────────────────────────── */

if (!class_exists(\Memcached::class)) {
    test('Memcached ext not loaded – skipping')->skip();
    return;
}

$probe = new Memcached();
$probe->addServer('127.0.0.1', 11211);
$probe->set('ping', 'pong');
if ($probe->getResultCode() !== Memcached::RES_SUCCESS) {
    test('No Memcached server at 127.0.0.1:11211 – skipping')->skip();
    return;
}

/* ── Test bootstrap / teardown ───────────────────────────────────── */

beforeEach(function () {
    $client = new Memcached();
    $client->addServer('127.0.0.1', 11211);
    $client->flush();                          // fresh slate

    $this->cache = Cachepool::memcache(
        'tests',
        [['127.0.0.1', 11211, 0]],
        $client
    );

    /* register stream handler for resource test */
    ValueSerializer::registerResourceHandler(
        'stream',
        // ----- wrap ----------------------------------------------------
        function (mixed $res): array {
            if (!is_resource($res)) {
                throw new InvalidArgumentException('Expected resource');
            }
            $meta = stream_get_meta_data($res);
            rewind($res);
            return [
                'mode'    => $meta['mode'],
                'content' => stream_get_contents($res),
            ];
        },
        // ----- restore -------------------------------------------------
        function (array $data): mixed {
            $s = fopen('php://memory', $data['mode']);
            fwrite($s, $data['content']);
            rewind($s);
            return $s;                                 // <- real resource
        }
    );
});

afterEach(function () {
    /** @var \Infocyph\InterMix\Cache\Adapter\MemCacheAdapter $adapt */
    $adapt = (new ReflectionObject($this->cache))
        ->getProperty('adapter')->getValue($this->cache);
    (new ReflectionProperty($adapt, 'mc'))
        ->getValue($adapt)
        ->flush();
});

/* ── Convenience helpers ────────────────────────────────────────── */

test('memcache set()/get()', function () {
    expect($this->cache->get('miss'))->toBeNull()
        ->and($this->cache->set('foo', 'bar'))->toBeTrue()
        ->and($this->cache->get('foo'))->toBe('bar');
});

test('PSR-6 getItem()/save()', function () {
    $it = $this->cache->getItem('psr');
    expect($it)->toBeInstanceOf(MemCacheItem::class)
        ->and($it->isHit())->toBeFalse();

    $it->set(321)->save();
    expect($this->cache->getItem('psr')->get())->toBe(321);
});

test('saveDeferred() + commit()', function () {
    $this->cache->getItem('a')->set('A')->saveDeferred();
    expect($this->cache->get('a'))->toBeNull();

    $this->cache->commit();
    expect($this->cache->get('a'))->toBe('A');
});

test('ArrayAccess & magic props', function () {
    $this->cache['x'] = 7;
    expect($this->cache['x'])->toBe(7);

    $this->cache->alpha = 'ω';
    expect($this->cache->alpha)->toBe('ω');
});

test('TTL expiration', function () {
    $this->cache->getItem('ttl')->set('x')->expiresAfter(1)->save();
    sleep(2);
    expect($this->cache->hasItem('ttl'))->toBeFalse();
});

test('closure round-trip', function () {
    $fn = fn ($n) => $n * 3;
    $this->cache->getItem('cb')->set($fn)->save();
    $g = $this->cache->getItem('cb')->get();
    expect($g(3))->toBe(9);
});

test('stream resource round-trip', function () {
    $s = fopen('php://memory', 'r+');
    fwrite($s, 'data');
    rewind($s);
    $this->cache->getItem('stream')->set($s)->save();
    $r = $this->cache->getItem('stream')->get();
    expect(stream_get_contents($r))->toBe('data');
});

test('invalid key throws', function () {
    expect(fn () => $this->cache->set('bad key', 'v'))
        ->toThrow(CacheInvalidArgumentException::class);
});

test('clear() flushes cache', function () {
    $this->cache->set('z', 9);
    $this->cache->clear();
    expect($this->cache->hasItem('z'))->toBeFalse();
});

test('Memcached adapter multiFetch()', function () {
    $this->cache->set('m1', 'foo');
    $this->cache->set('m2', 'bar');

    $items = $this->cache->getItems(['m1', 'm2', 'missing']);

    expect($items['m1']->get())->toBe('foo')
        ->and($items['m2']->get())->toBe('bar')
        ->and($items['missing']->isHit())->toBeFalse();
});
