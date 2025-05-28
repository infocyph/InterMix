<?php
/**
 * tests/SqliteCachePoolTest.php
 *
 * Runs only when the PDO SQLite driver is available.
 */

use Infocyph\InterMix\Cache\Cachepool;
use Infocyph\InterMix\Cache\Item\SqliteCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;

/* ── Skip entire suite if SQLite missing ─────────────────────────── */
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    test('SQLite PDO driver not present – skipping')->skip();
    return;
}

/* ── bootstrap / teardown ────────────────────────────────────────── */
beforeEach(function () {
    $this->dbFile = sys_get_temp_dir() . '/pest_sqlite_' . uniqid() . '.sqlite';
    $this->cache  = Cachepool::sqlite('tests', $this->dbFile);

    /* stream handler for resource test */
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
//    ValueSerializer::registerResourceHandler(
//        'stream',
//        fn ($r) => ['mode'=>stream_get_meta_data($r)['mode'],
//            'content'=>tap($r, fn()=>rewind($r)) && stream_get_contents($r)],
//        fn ($d)  => tap(fopen('php://memory', $d['mode']),
//            fn ($s)=>fwrite($s,$d['content'])&&rewind($s))
//    );
});

afterEach(function () {
    @unlink($this->dbFile);
});

/* ── 1. convenience set / get ───────────────────────────────────── */
test('sqlite set()/get()', function () {
    expect($this->cache->get('none'))->toBeNull()
        ->and($this->cache->set('foo', 'bar'))->toBeTrue()
        ->and($this->cache->get('foo'))->toBe('bar');
});

/* ── 2. PSR-6 behaviour ─────────────────────────────────────────── */
test('getItem()/save() (sqlite)', function () {
    $item = $this->cache->getItem('psr');
    expect($item)->toBeInstanceOf(SqliteCacheItem::class)
        ->and($item->isHit())->toBeFalse();

    $item->set(42)->save();
    expect($this->cache->getItem('psr')->get())->toBe(42);
});

/* ── 3. deferred queue ──────────────────────────────────────────── */
test('saveDeferred() & commit() (sqlite)', function () {
    $this->cache->getItem('a')->set('A')->saveDeferred();
    expect($this->cache->get('a'))->toBeNull();

    $this->cache->commit();
    expect($this->cache->get('a'))->toBe('A');
});

/* ── 4. ArrayAccess & magic props ───────────────────────────────── */
test('ArrayAccess & magic (sqlite)', function () {
    $this->cache['x'] = 5;
    expect($this->cache['x'])->toBe(5);

    $this->cache->alpha = 'ω';
    expect($this->cache->alpha)->toBe('ω');
});

/* ── 5. Iterator & Countable ────────────────────────────────────── */
test('Iterator & Countable (sqlite)', function () {
    $this->cache->set('k1', 'v1');
    $this->cache->set('k2', 'v2');

    expect(count($this->cache))->toBe(2);

    $vals = [];
    foreach ($this->cache as $k => $v) $vals[$k] = $v;
    expect($vals)->toMatchArray(['k1'=>'v1','k2'=>'v2']);
});

/* ── 6. TTL expiration ─────────────────────────────────────────── */
test('expiration honours TTL (sqlite)', function () {
    $this->cache->getItem('ttl')->set('x')->expiresAfter(1)->save();
    sleep(2);
    expect($this->cache->hasItem('ttl'))->toBeFalse();
});

/* ── 7. closure round-trip ──────────────────────────────────────── */
test('closure survives sqlite', function () {
    $fn = fn($n)=>$n+3;
    $this->cache->getItem('cb')->set($fn)->save();
    expect(($this->cache->getItem('cb')->get())(4))->toBe(7);
});

/* ── 8. stream resource round-trip ─────────────────────────────── */
test('stream resource round-trip (sqlite)', function () {
    $s=fopen('php://memory','r+');fwrite($s,'hello');rewind($s);
    $this->cache->getItem('stream')->set($s)->save();
    $rest=$this->cache->getItem('stream')->get();
    expect(stream_get_contents($rest))->toBe('hello');
});

/* ── 9. invalid key guard ───────────────────────────────────────── */
test('invalid key throws (sqlite)', function () {
    expect(fn()=> $this->cache->set('bad key','v'))
        ->toThrow(CacheInvalidArgumentException::class);
});

/* ── 10. clear wipes all entries ───────────────────────────────── */
test('clear() flushes table (sqlite)', function () {
    $this->cache->set('z', 9);
    $this->cache->clear();
    expect($this->cache->hasItem('z'))->toBeFalse();
});
