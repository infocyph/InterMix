<?php

/**
 * tests/RedisCachePoolTest.php
 *
 * Executes the same behavioural checks as the File/APCu/Memcache/SQLite
 * suites, but against the Redis adapter.  The suite self-skips when:
 *   • phpredis extension is not loaded, or
 *   • no Redis server answers at 127.0.0.1:6379.
 */

use Infocyph\InterMix\Cache\Cache;
use Infocyph\InterMix\Cache\Item\RedisCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;

/* ── skip whole file when Redis unavailable ───────────────────────── */
if (!class_exists(Redis::class)) {
    test('phpredis ext not loaded – skipping')->skip();
    return;
}
try {
    $probe = new Redis();
    $probe->connect('127.0.0.1', 6379, 0.5);
    $probe->ping();
} catch (Throwable) {
    test('Redis server unreachable – skipping')->skip();
    return;
}

/* ── bootstrap / teardown ────────────────────────────────────────── */
beforeEach(function () {
    $client = new Redis();
    $client->connect('127.0.0.1', 6379);
    $client->flushDB();                               // fresh DB 0

    $this->cache = Cache::redis(
        'tests',
        'redis://127.0.0.1:6379',
        $client
    );

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
    $this->cache->clear();
});

/* ── 1. convenience helpers ─────────────────────────────────────── */
test('Redis set()/get()', function () {
    expect($this->cache->get('none'))->toBeNull()
        ->and($this->cache->set('foo', 'bar'))->toBeTrue()
        ->and($this->cache->get('foo'))->toBe('bar');
});

/* ── 2. PSR-6 behaviour ─────────────────────────────────────────── */
test('getItem()/save() (redis)', function () {
    $it = $this->cache->getItem('psr');
    expect($it)->toBeInstanceOf(RedisCacheItem::class)
        ->and($it->isHit())->toBeFalse();

    $it->set(777)->save();
    expect($this->cache->getItem('psr')->get())->toBe(777);
});

/* ── 3. deferred queue ──────────────────────────────────────────── */
test('saveDeferred() & commit() (redis)', function () {
    $this->cache->getItem('a')->set('A')->saveDeferred();
    expect($this->cache->get('a'))->toBeNull();

    $this->cache->commit();
    expect($this->cache->get('a'))->toBe('A');
});

/* ── 4. ArrayAccess & magic props ───────────────────────────────── */
test('ArrayAccess & magic (redis)', function () {
    $this->cache['k'] = 12;
    expect($this->cache['k'])->toBe(12);

    $this->cache->alpha = 'ζ';
    expect($this->cache->alpha)->toBe('ζ');
});

/* ── 6. TTL expiration ─────────────────────────────────────────── */
test('expiration honours TTL (redis)', function () {
    $this->cache->getItem('ttl')->set('x')->expiresAfter(1)->save();
    sleep(2);
    expect($this->cache->hasItem('ttl'))->toBeFalse();
});

/* ── 7. closure round-trip ──────────────────────────────────────── */
test('closure persists in redis', function () {
    $double = fn ($n) => $n * 2;
    $this->cache->getItem('cb')->set($double)->save();
    $fn = $this->cache->getItem('cb')->get();
    expect($fn(5))->toBe(10);
});

/* ── 8. stream resource round-trip ─────────────────────────────── */
test('stream resource round-trip (redis)', function () {
    $s = fopen('php://memory', 'r+');
    fwrite($s, 'blob');
    rewind($s);
    $this->cache->getItem('stream')->set($s)->save();
    $rest = $this->cache->getItem('stream')->get();
    expect(stream_get_contents($rest))->toBe('blob');
});

/* ── 9. invalid key guard ───────────────────────────────────────── */
test('invalid key throws (redis)', function () {
    expect(fn () => $this->cache->set('bad key', 'v'))
        ->toThrow(CacheInvalidArgumentException::class);
});

/* ── 10. clear wipes namespace ----------------------------------- */
test('clear() wipes entries (redis)', function () {
    $this->cache->set('z', 9);
    $this->cache->clear();
    expect($this->cache->hasItem('z'))->toBeFalse();
});

test('Redis adapter multiFetch()', function () {
    $this->cache->set('r1', 10);
    $this->cache->set('r2', 20);

    $items = $this->cache->getItems(['r1', 'r2', 'none']);

    expect($items['r1']->get())->toBe(10)
        ->and($items['r2']->get())->toBe(20)
        ->and($items['none']->isHit())->toBeFalse();
});
