<?php

namespace Exodus4D\ESI\Lib;

use Cache\Adapter\PHPArray\ArrayCachePool;
use Psr\Cache\CacheItemPoolInterface;

class CacheFactory
{
    /**
     * Create PSR-6 cache
     *
     * @return CacheItemPoolInterface
     */
    public static function createCache(): CacheItemPoolInterface
    {
        return new ArrayCachePool();
    }
}
