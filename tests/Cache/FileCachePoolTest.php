<?php

/**  tests/FileCachePoolTest.php  */

use Infocyph\InterMix\Cache\Cache;
use Infocyph\InterMix\Cache\Item\FileCacheItem;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Psr\Cache\InvalidArgumentException as Psr6InvalidArgumentException;

beforeEach(function () {
    /* fresh temp directory for each run */
    $this->cacheDir = sys_get_temp_dir() . '/pest_cache_' . uniqid();

    /* build a file-backed cachepool via static factory */
    $this->cache = Cache::file('tests', $this->cacheDir);
    /* register stream handler only for the test run */
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
    /* recursive dir cleanup */
    if (!is_dir($this->cacheDir)) {
        return;
    }

    $it  = new RecursiveDirectoryIterator($this->cacheDir, FilesystemIterator::SKIP_DOTS);
    $rim = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($rim as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir($this->cacheDir);
});

/* ───────────────────────────────────────────────────────────── */

/* ─── convenience set()/get() ─────────────────────────────────── */
test('convenience set() and get()', function () {
    expect($this->cache->get('nope'))->toBeNull()
        ->and($this->cache->set('foo', 'bar', 60))->toBeTrue()
        ->and($this->cache->get('foo'))->toBe('bar');
});

/* ─── PSR-16 get($key, $default) ───────────────────────────────── */
test('get returns default when key missing (file)', function () {
    // Scalar default
    expect($this->cache->get('missing', 'def'))->toBe('def');

    // Callable default
    $computed = $this->cache->get('x', function (FileCacheItem $item) {
        $item->expiresAfter(1);
        return 'xyz';
    });
    expect($computed)->toBe('xyz');
    expect($this->cache->get('x'))->toBe('xyz');

    // After TTL expires, fallback
    sleep(2);
    expect($this->cache->get('x', 'fallback'))->toBe('fallback');
});

test('get throws for invalid key (file)', function () {
    expect(fn () => $this->cache->get('bad key', 'v'))
        ->toThrow(CacheInvalidArgumentException::class);
});

/* ─── PSR-6 getItem()/save() ───────────────────────────────────── */
test('PSR-6 getItem()/save()', function () {
    $item = $this->cache->getItem('psr');

    expect($item)->toBeInstanceOf(FileCacheItem::class)
        ->and($item->isHit())->toBeFalse();

    $item->set(123)->expiresAfter(null)->save();

    $fetched = $this->cache->getItem('psr');
    expect($fetched->isHit())->toBeTrue()
        ->and($fetched->get())->toBe(123);
});

test('saveDeferred() and commit()', function () {
    expect($this->cache->get('a'))->toBeNull();

    $this->cache->getItem('a')->set('A')->saveDeferred();
    $this->cache->getItem('b')->set('B')->saveDeferred();

    expect($this->cache->get('a'))->toBeNull();   // not yet persisted

    $this->cache->commit();

    expect($this->cache->get('a'))->toBe('A')
        ->and($this->cache->get('b'))->toBe('B');
});

test('ArrayAccess support', function () {
    $this->cache['x'] = 10;

    expect(isset($this->cache['x']))->toBeTrue()
        ->and($this->cache['x'])->toBe(10);

    unset($this->cache['x']);
    expect(isset($this->cache['x']))->toBeFalse();
});

test('magic __get/__set/__isset/__unset', function () {
    $this->cache->alpha = 'beta';

    expect(isset($this->cache->alpha))->toBeTrue()
        ->and($this->cache->alpha)->toBe('beta');

    unset($this->cache->alpha);
    expect(isset($this->cache->alpha))->toBeFalse();
});
test('runtime re-namespace and directory swap', function () {
    $newDir = sys_get_temp_dir() . '/pest_cache_new_' . uniqid();

    $this->cache->setNamespaceAndDirectory('newns', $newDir);

    expect($this->cache->set('foo', 'bar'))->toBeTrue()
        ->and($this->cache->get('foo'))->toBe('bar');

    $namespaceDir = $newDir . '/cache_newns';
    expect(is_dir($namespaceDir))
        ->toBeTrue()
        ->and(glob($namespaceDir . '/*.cache'))->not->toBeEmpty();

    /* manual clean-up of this secondary dir (afterEach cleans only first dir) */
    foreach (glob($namespaceDir . '/*') as $f) {
        @unlink($f);
    }
    @rmdir($namespaceDir);
    @rmdir($newDir);
});

test('expiration honours TTL', function () {
    $this->cache->getItem('ttl')->set('x')->expiresAfter(1)->save();
    sleep(2);
    expect($this->cache->getItem('ttl')->isHit())->toBeFalse();
});

test('closure round-trips via ValueSerializer', function () {
    $double = fn (int $n) => $n * 2;
    $this->cache->getItem('cb')->set($double)->save();
    $restored = $this->cache->getItem('cb')->get();
    expect($restored(7))->toBe(14);
});

test('stream resource round-trip', function () {

    $s = fopen('php://memory', 'r+');
    fwrite($s, 'hello');
    rewind($s);
    $this->cache->getItem('stream')->set($s)->save();
    $r = $this->cache->getItem('stream')->get();
    expect(stream_get_contents($r))->toBe('hello');
});

test('custom resource handler works', function () {
    $dirPath  = __DIR__;                // path we will open/restore
    $dirRes   = opendir($dirPath);
    $resType  = get_resource_type($dirRes);   // "stream"

    // register handler *capturing* $dirPath
    ValueSerializer::registerResourceHandler(
        $resType,
        fn ($r)           => ['path' => $dirPath],          // wrap
        fn (array $data)  => opendir($data['path'])         // restore
    );

    $this->cache->getItem('dirRes')->set($dirRes)->save();

    $restored = $this->cache->getItem('dirRes')->get();
    expect(is_resource($restored))->toBeTrue()
        ->and(get_resource_type($restored))->toBe($resType);
});

test('invalid cache key throws', function () {
    expect(fn () => $this->cache->set('space key', 'v'))
        ->toThrow(InvalidArgumentException::class);
});

test('persistence across pool instances', function () {
    $this->cache->set('persist', 'yes');

    $again = Cache::file('tests', $this->cacheDir); // new instance
    expect($again->get('persist'))->toBe('yes');
});

test('clear() removes everything', function () {
    $this->cache->set('a', 1);
    $this->cache->set('b', 2);

    $this->cache->clear();

    expect($this->cache->hasItem('a'))->toBeFalse()
        ->and($this->cache->hasItem('b'))->toBeFalse();
});

test('file adapter bulk getItems()', function () {
    $this->cache->set('a', 1);
    $this->cache->set('b', 2);

    $items = $this->cache->getItems(['a', 'b', 'c']);

    expect($items['a']->isHit())->toBeTrue()
        ->and($items['a']->get())->toBe(1)
        ->and($items['b']->get())->toBe(2)
        ->and($items['c']->isHit())->toBeFalse();
});
