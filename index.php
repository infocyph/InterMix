<?php
/**
 * index.php — browser-friendly APCu cache test-runner
 *
 * Place beside composer autoload, then open in your browser.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Infocyph\InterMix\Cache\Cache;
use Infocyph\InterMix\Cache\Item\ApcuCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;

/*------ tiny helpers --------------------------------------------------*/

// tap() polyfill (like Laravel)
if (!function_exists('tap')) {
    function tap($value, callable $cb)
    {
        $cb($value);
        return $value;
    }
}

// minimal assertion
class Assert
{
    public static function ok(bool $cond, string $why = ''): void
    {
        if (!$cond) {
            throw new AssertionError($why ?: 'Assertion failed');
        }
    }
    public static function same(mixed $exp, mixed $got, string $why = ''): void
    {
        if ($exp !== $got) {
            $d = var_export($exp, true) . ' !== ' . var_export($got, true);
            throw new AssertionError($why ? "$why ($d)" : $d);
        }
    }
}

/*------ skip if APCu unavailable --------------------------------------*/
if (!extension_loaded('apcu') || !apcu_enabled()) {
    header('Content-Type:text/plain');
    echo 'APCu extension not loaded/enabled – nothing to run.';
    exit;
}

/*------ common setup --------------------------------------------------*/
apcu_clear_cache();
$cache = Cache::apcu('web-tests');

// register stream handler once
ValueSerializer::registerResourceHandler(
    'stream',
    // ---------- wrap --------------------------------------------------
    function ($res): array {
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
    // ---------- restore ----------------------------------------------
    function (array $d) {
        $s = fopen('php://memory', $d['mode']);
        fwrite($s, $d['content']);
        rewind($s);
        return $s;
    }
);


/*------ DSL for tests -------------------------------------------------*/
$tests = [];
function it(string $name, callable $fn)
{
    global $tests;
    $tests[$name] = $fn;
}

/*------ define tests --------------------------------------------------*/

// convenience get/set
it('convenience set/get', function () use ($cache) {
    Assert::same(null, $cache->get('nope'));
    Assert::ok($cache->set('foo', 'bar', 60));
    Assert::same('bar', $cache->get('foo'));
});

// PSR-16 get(key, default) with scalar default
it('PSR-16 get returns scalar default when missing', function () use ($cache) {
    Assert::same('default', $cache->get('missing', 'default'));
    // After calling with default, key should still be unset
    Assert::same(null, $cache->get('missing'));
});

// PSR-16 get(key, default) with callable default
it('PSR-16 get executes callable default and caches result', function () use ($cache) {
    // First time: key does not exist, so callable should run
    $val = $cache->get('dyn', function (ApcuCacheItem $item) {
        $item->expiresAfter(1);
        return 'computed';
    });
    Assert::same('computed', $val);

    // Now that it’s been cached, get() without default returns stored value
    Assert::same('computed', $cache->get('dyn'));

    // After TTL expires, get() with a fallback default is returned
    sleep(2);
    Assert::same('fallback', $cache->get('dyn', 'fallback'));
});

// PSR-16 invalid key throws
it('PSR-16 get throws for invalid key', function () use ($cache) {
    $thrown = false;
    try {
        $cache->get('bad key', 'x');
    } catch (CacheInvalidArgumentException) {
        $thrown = true;
    }
    Assert::ok($thrown, 'CacheInvalidArgumentException not thrown for invalid key');
});

// PSR-6 getItem / save
it('PSR-6 getItem/save', function () use ($cache) {
    $item = $cache->getItem('psr');
    Assert::ok($item instanceof ApcuCacheItem && !$item->isHit());
    $item->set(99)->save();
    Assert::same(99, $cache->getItem('psr')->get());
});

// deferred queue
it('saveDeferred/commit', function () use ($cache) {
    $cache->getItem('x')->set('X')->saveDeferred();
    Assert::same(null, $cache->get('x'));
    $cache->commit();
    Assert::same('X', $cache->get('x'));
});

// ArrayAccess + magic props
it('ArrayAccess & magic', function () use ($cache) {
    $cache['k'] = 11;
    Assert::same(11, $cache['k']);
    $cache->alpha = 'β';
    Assert::same('β', $cache->alpha);
});

// iterator & countable
it('Iterator & Countable', function () use ($cache) {
    $cache->clear();
    $cache->set('a', 1);
    $cache->set('b', 2);
    Assert::same(2, count($cache));
    Assert::same(['a' => 1, 'b' => 2], iterator_to_array($cache));
});

// TTL expiration
it('TTL expiration', function () use ($cache) {
    $cache->getItem('ttl')->set('live')->expiresAfter(1)->save();
    sleep(2);
    Assert::ok(!$cache->hasItem('ttl'));
});

// closure value round-trip
it('closure round-trip', function () use ($cache) {
    $fn = fn (int $n) => $n + 5;
    $cache->getItem('cb')->set($fn)->save();
    $g = $cache->getItem('cb')->get();
    Assert::same(15, $g(10));
});

// stream resource round-trip
it('stream round-trip', function () use ($cache) {
    $s = fopen('php://memory', 'r+');
    fwrite($s, 'stream');
    rewind($s);
    $cache->getItem('stream')->set($s)->save();
    $rest = $cache->getItem('stream')->get();
    Assert::same('stream', stream_get_contents($rest));
});

// invalid key exception on set
it('invalid key throws on set', function () use ($cache) {
    $thrown = false;
    try {
        $cache->set('bad key', 'v');
    } catch (CacheInvalidArgumentException) {
        $thrown = true;
    }
    Assert::ok($thrown, 'CacheInvalidArgumentException not thrown');
});

// clear wipes
it('clear wipes cache', function () use ($cache) {
    $cache->set('q', '1');
    $cache->clear();
    Assert::ok(!$cache->hasItem('q'));
});

// multiFetch
it('multiFetch', function () use ($cache) {
    $cache->set('x', 'X');
    $cache->set('y', 'Y');
    $items = $cache->getItems(['x', 'y', 'z']);

    Assert::ok($items['x']->isHit() && $items['x']->get() === 'X', 'x not correct');
    Assert::same('Y', $items['y']->get(), 'y not correct');
    Assert::ok(!$items['z']->isHit(), 'z should be miss');
});

/*------ run tests -----------------------------------------------------*/
$results = [];
foreach ($tests as $name => $fn) {
    try {
        $fn();
        $results[$name] = [true, ''];
    } catch (Throwable $e) {
        $results[$name] = [false, $e->getMessage() . "\n" . $e->getTraceAsString()];
    }
}

/*------ render --------------------------------------------------------*/
$pass = array_filter($results, fn ($r) => $r[0]);
$fail = array_filter($results, fn ($r) => !$r[0]);

header('Content-Type:text/html;charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en"><meta charset="utf-8">
<style>
    body{font-family:system-ui;margin:2rem}
    .ok{color:#0a0;font-weight:700}
    .bad{color:#c00;font-weight:700}
    table{border-collapse:collapse;margin-top:1rem}
    td{padding:4px 8px;border-bottom:1px solid #eee;vertical-align:top}
    pre{white-space:pre-wrap;font-size:.9em;margin:0}
</style>
<h2>APCu Cache Test Suite</h2>
<p class="<?= $fail ? 'bad' : 'ok' ?>">
    <?= count($pass) ?> / <?= count($results) ?> passed <?= $fail ? '✗' : '✓' ?>
</p>

<table>
    <?php foreach ($results as $name => [$ok, $msg]): ?>
        <tr>
            <td><?= htmlspecialchars($name) ?></td>
            <td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✗' ?></td>
            <td><?php if (!$ok): ?><pre><?= htmlspecialchars($msg) ?></pre><?php endif ?></td>
        </tr>
    <?php endforeach ?>
</table>
</html>
