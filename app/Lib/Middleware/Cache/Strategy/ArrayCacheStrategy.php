<?php

namespace Exodus4D\ESI\Lib\Middleware\Cache\Strategy;

use Cache\Adapter\PHPArray\ArrayCachePool;
use Exodus4D\ESI\Lib\Middleware\Cache\CacheEntry;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

class ArrayCacheStrategy implements CacheStrategyInterface
{
    /** @var CacheItemPoolInterface */
    protected $cache;

    public function __construct(?CacheItemPoolInterface $cache = null)
    {
        $this->cache = $cache ?? new ArrayCachePool();
    }

    /**
     * @param $request
     * @return CacheEntry|null
     */
    public function fetch($request): ?CacheEntry
    {
        $key = $this->getCacheKey($request);
        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            return new CacheEntry($item->get());
        }
        return null;
    }

    public function cache($request, $response): void
    {
        $key = $this->getCacheKey($request);
        $item = $this->cache->getItem($key);
        $item->set($response);
        $this->cache->save($item);
    }

    public function update($request, $response): void
    {
        $this->cache($request, $response);
    }

    /**
     * Простейший ключ кэша по URL
     */
    protected function getCacheKey($request): string
    {
        return md5((string)$request->getUri());
    }
}
