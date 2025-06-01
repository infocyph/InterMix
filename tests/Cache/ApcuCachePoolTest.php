<?php

/**
 * tests/ApcuCachePoolTest.php
 *
 * Run these only when the APCu extension is loaded **and**
 * enabled in CLI (apcu.enable_cli=1).  Otherwise the whole
 * suite is skipped.
 */

use Infocyph\InterMix\Cache\Cache;
use Infocyph\InterMix\Cache\Item\ApcuCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Psr\Cache\InvalidArgumentException as Psr6InvalidArgumentException;

/* ── skip entirely if APCu unavailable ─────────────────────────────── */
if (!extension_loaded('apcu')) {
    test('APCu not loaded – skipping adapter tests')->skip();
    return;
}
ini_set('apcu.enable_cli', 1);
if (!apcu_enabled()) {
    test('APCu not enabled – skipping adapter tests')->skip();
    return;
}

/* ── boilerplate ──────────────────────────────────────────────────── */
beforeEach(function () {
    apcu_clear_cache();                           // fresh memory
    $this->cache = Cache::apcu('tests');          // APCu-backed pool

    /* register stream handler for resource tests */
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
    apcu_clear_cache();
});

/* ─── convenience get()/set() ─────────────────────────────────────── */
test('convenience set() and get() (apcu)', function () {
    expect($this->cache->get('nope'))->toBeNull()
        ->and($this->cache->set('foo', 'bar', 60))->toBeTrue()
        ->and($this->cache->get('foo'))->toBe('bar');
});

/* ─── PSR-16 get($key, $default) ─────────────────────────────────── */
test('get returns default when key missing (apcu)', function () {
    // Scalar default
    expect($this->cache->get('missing', 'default'))->toBe('default');

    // Callable default without prior set
    $computed = $this->cache->get('dyn', function (ApcuCacheItem $item) {
        $item->expiresAfter(1);
        return 'computed';
    });
    expect($computed)->toBe('computed');

    // Now that it’s been set, get() returns the cached value
    expect($this->cache->get('dyn'))->toBe('computed');

    // After expiry, returns the new default again
    sleep(2);
    expect($this->cache->get('dyn', 'fallback'))->toBe('fallback');
});

test('get throws for invalid key (apcu)', function () {
    expect(fn () => $this->cache->get('bad key', 'x'))
        ->toThrow(CacheInvalidArgumentException::class);
});

/* ─── PSR-6 behaviour ─────────────────────────────────────────────── */
test('PSR-6 getItem()/save() (apcu)', function () {
    $item = $this->cache->getItem('psr');
    expect($item)->toBeInstanceOf(ApcuCacheItem::class)
        ->and($item->isHit())->toBeFalse();

    $item->set(99)->expiresAfter(null)->save();
    expect($this->cache->getItem('psr')->get())->toBe(99);
});

/* ─── deferred queue ──────────────────────────────────────────────── */
test('saveDeferred() and commit() (apcu)', function () {
    $this->cache->getItem('x')->set('X')->saveDeferred();
    expect($this->cache->get('x'))->toBeNull();

    $this->cache->commit();
    expect($this->cache->get('x'))->toBe('X');
});

/* ─── ArrayAccess / magic props ───────────────────────────────────── */
test('ArrayAccess & magic props (apcu)', function () {
    $this->cache['k'] = 11;
    expect($this->cache['k'])->toBe(11);

    $this->cache->alpha = 'β';
    expect($this->cache->alpha)->toBe('β');
});

/* ─── TTL / expiration ───────────────────────────────────────────── */
test('expiration honours TTL (apcu)', function () {
    $this->cache->getItem('ttl')->set('live')->expiresAfter(1)->save();
    sleep(2);
    expect($this->cache->hasItem('ttl'))->toBeFalse();
});

/* ─── closure round-trip ──────────────────────────────────────────── */
test('closure value survives APCu', function () {
    $fn = fn ($n) => $n + 5;
    $this->cache->getItem('cb')->set($fn)->save();
    $g = $this->cache->getItem('cb')->get();
    expect($g(10))->toBe(15);
});

/* ─── stream resource round-trip ──────────────────────────────────── */
test('stream resource round-trip (apcu)', function () {
    $s = fopen('php://memory', 'r+');
    fwrite($s, 'stream');
    rewind($s);

    $this->cache->getItem('stream')->set($s)->save();
    $restored = $this->cache->getItem('stream')->get();
    expect(stream_get_contents($restored))->toBe('stream');
});

/* ─── invalid key triggers exception ─────────────────────────────── */
test('invalid key throws (apcu)', function () {
    expect(fn () => $this->cache->set('bad key', 'v'))
        ->toThrow(InvalidArgumentException::class);
});

/* ─── clear() empties cache ───────────────────────────────────────── */
test('clear() wipes all entries (apcu)', function () {
    $this->cache->set('q', '1');
    $this->cache->clear();
    expect($this->cache->hasItem('q'))->toBeFalse();
});

test('APCu adapter multiFetch()', function () {
    $this->cache->set('x', 'X');
    $this->cache->set('y', 'Y');

    $items = $this->cache->getItems(['x', 'y', 'z']);

    expect($items['x']->isHit())->toBeTrue()
        ->and($items['x']->get())->toBe('X')
        ->and($items['y']->get())->toBe('Y')
        ->and($items['z']->isHit())->toBeFalse();
});
