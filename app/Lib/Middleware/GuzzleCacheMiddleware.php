<?php

/**
 * GuzzleCacheMiddleware
 * PSR-6 compatible middleware for Guzzle
 */

namespace Exodus4D\ESI\Lib\Middleware;

use Exodus4D\ESI\Lib\Middleware\Cache\CacheEntry;
use Exodus4D\ESI\Lib\Middleware\Cache\Strategy\CacheStrategyInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class GuzzleCacheMiddleware
{
    public const DEFAULT_CACHE_ENABLED              = true;
    public const DEFAULT_CACHE_HTTP_METHODS         = ['GET'];
    public const DEFAULT_CACHE_DEBUG                = false;
    public const DEFAULT_CACHE_RE_VALIDATION_HEADER = 'X-Guzzle-Cache-ReValidation';
    public const DEFAULT_CACHE_DEBUG_HEADER         = 'X-Guzzle-Cache';
    public const DEFAULT_CACHE_DEBUG_HEADER_HIT     = 'HIT';
    public const DEFAULT_CACHE_DEBUG_HEADER_MISS    = 'MISS';
    public const DEFAULT_CACHE_DEBUG_HEADER_STALE   = 'STALE';

    private $defaultOptions = [
        'cache_enabled'      => self::DEFAULT_CACHE_ENABLED,
        'cache_http_methods' => self::DEFAULT_CACHE_HTTP_METHODS,
        'cache_debug'        => self::DEFAULT_CACHE_DEBUG,
        'cache_debug_header' => self::DEFAULT_CACHE_DEBUG_HEADER
    ];

    /** @var Promise[] */
    protected $waitingRevalidate = [];

    /** @var Client */
    protected $client;

    /** @var CacheStrategyInterface */
    protected $cacheStrategy;

    /** @var callable */
    private $nextHandler;

    public function __construct(callable $nextHandler, array $defaultOptions = [], ?CacheStrategyInterface $cacheStrategy = null)
    {
        $this->nextHandler = $nextHandler;
        $this->defaultOptions = array_replace($this->defaultOptions, $defaultOptions);
        $this->cacheStrategy = $cacheStrategy ?? throw new \Exception('CacheStrategyInterface required');

        register_shutdown_function([$this, 'purgeReValidation']);
    }

    public function purgeReValidation(): void
    {
        \GuzzleHttp\Promise\inspect_all($this->waitingRevalidate);
    }

    public function __invoke(RequestInterface $request, array $options)
    {
        $options = array_replace($this->defaultOptions, $options);
        $next = $this->nextHandler;

        if (!$options['cache_enabled']) {
            return $next($request, $options);
        }

        if (!in_array(strtoupper($request->getMethod()), (array)$options['cache_http_methods'])) {
            return $next($request, $options)->then(
                fn (ResponseInterface $response) => static::addDebugHeader($response, self::DEFAULT_CACHE_DEBUG_HEADER_MISS, $options)
            );
        }

        if ($request->hasHeader(self::DEFAULT_CACHE_RE_VALIDATION_HEADER)) {
            return $next($request->withoutHeader(self::DEFAULT_CACHE_RE_VALIDATION_HEADER), $options);
        }

        $onlyFromCache = false;
        $staleResponse = false;
        $maxStaleCache = null;
        $minFreshCache = null;

        if ($request->hasHeader('Cache-Control')) {
            $reqCacheControl = \GuzzleHttp\Psr7\parse_header($request->getHeader('Cache-Control'));
            $onlyFromCache = static::inArrayDeep($reqCacheControl, 'only-if-cached');
            $staleResponse = static::inArrayDeep($reqCacheControl, 'max-stale');
            $maxStaleCache = (int) static::arrayKeyDeep($reqCacheControl, 'max-stale') ?: null;
            $minFreshCache = (int) static::arrayKeyDeep($reqCacheControl, 'min-fresh') ?: null;
        }

        $cacheEntry = $this->cacheStrategy->fetch($request);

        if ($cacheEntry instanceof CacheEntry) {
            $body = $cacheEntry->getResponse()->getBody();
            if ($body->tell() > 0) {
                $body->rewind();
            }

            if ($cacheEntry->isFresh() && ($minFreshCache === null || $cacheEntry->getStaleAge() + $minFreshCache <= 0)) {
                return new FulfilledPromise(static::addDebugHeader($cacheEntry->getResponse(), self::DEFAULT_CACHE_DEBUG_HEADER_HIT, $options));
            } elseif ($staleResponse || ($maxStaleCache !== null && $cacheEntry->getStaleAge() <= $maxStaleCache)) {
                return new FulfilledPromise(static::addDebugHeader($cacheEntry->getResponse(), self::DEFAULT_CACHE_DEBUG_HEADER_HIT, $options));
            } elseif ($cacheEntry->hasValidationInformation() && !$onlyFromCache) {
                $request = static::getRequestWithReValidationHeader($request, $cacheEntry);
                if ($cacheEntry->staleWhileValidate()) {
                    static::addReValidationRequest($request, $this->cacheStrategy, $cacheEntry);
                    return new FulfilledPromise(static::addDebugHeader($cacheEntry->getResponse(), self::DEFAULT_CACHE_DEBUG_HEADER_STALE, $options));
                }
            }
        } else {
            $cacheEntry = null;
        }

        if (is_null($cacheEntry) && $onlyFromCache) {
            return new FulfilledPromise(new Response(504));
        }

        return $next($request, $options)->then(
            $this->onFulfilled($request, $cacheEntry, $options),
            $this->onRejected($cacheEntry, $options)
        );
    }

    protected function onFulfilled(RequestInterface $request, ?CacheEntry $cacheEntry, array $options): \Closure
    {
        return function (ResponseInterface $response) use ($request, $cacheEntry, $options) {
            if ($response->getStatusCode() >= 500) {
                $responseStale = static::getStaleResponse($cacheEntry, $options);
                if ($responseStale instanceof ResponseInterface) {
                    return $responseStale;
                }
            }

            $update = false;
            if ($response->getStatusCode() == 304 && $cacheEntry instanceof CacheEntry) {
                $response = $response->withStatus($cacheEntry->getResponse()->getStatusCode())
                                     ->withBody($cacheEntry->getResponse()->getBody());
                $response = static::addDebugHeader($response, self::DEFAULT_CACHE_DEBUG_HEADER_HIT, $options);

                foreach ($cacheEntry->getOriginalResponse()->getHeaders() as $headerName => $headerValue) {
                    if (!$response->hasHeader($headerName) && $headerName !== $options['cache_debug_header']) {
                        $response = $response->withHeader($headerName, $headerValue);
                    }
                }
                $update = true;
            } else {
                $response = static::addDebugHeader($response, self::DEFAULT_CACHE_DEBUG_HEADER_MISS, $options);
            }

            return static::addToCache($this->cacheStrategy, $request, $response, $update);
        };
    }

    protected function onRejected(?CacheEntry $cacheEntry, array $options): \Closure
    {
        return function ($reason) use ($cacheEntry, $options) {
            if ($reason instanceof TransferException) {
                $response = static::getStaleResponse($cacheEntry, $options);
                if ($response) {
                    return $response;
                }
            }
            return new RejectedPromise($reason);
        };
    }

    protected static function addDebugHeader(ResponseInterface $response, string $value, array $options): ResponseInterface
    {
        if ($options['cache_enabled'] && $options['cache_debug']) {
            $response = $response->withHeader($options['cache_debug_header'], $value);
        }
        return $response;
    }

    protected static function addToCache(CacheStrategyInterface $cacheStrategy, RequestInterface $request, ResponseInterface $response, $update = false): ResponseInterface
    {
        if (!$response->getBody()->isSeekable()) {
            $response = $response->withBody(\GuzzleHttp\Psr7\Utils::streamFor($response->getBody()->getContents()));
        }
        if ($update) {
            $cacheStrategy->update($request, $response);
        } else {
            $cacheStrategy->cache($request, $response);
        }
        return $response;
    }

    protected function addReValidationRequest(RequestInterface $request, CacheStrategyInterface &$cacheStrategy, CacheEntry $cacheEntry): bool
    {
        if ($this->client) {
            $request = $request->withHeader(self::DEFAULT_CACHE_RE_VALIDATION_HEADER, '1');
            $this->waitingRevalidate[] = $this->client->sendAsync($request)->then(
                function (ResponseInterface $response) use ($request, &$cacheStrategy, $cacheEntry) {
                    $update = false;
                    if ($response->getStatusCode() == 304) {
                        $response = $response->withStatus($cacheEntry->getResponse()->getStatusCode())
                                             ->withBody($cacheEntry->getResponse()->getBody());
                        foreach ($cacheEntry->getResponse()->getHeaders() as $headerName => $headerValue) {
                            if (!$response->hasHeader($headerName)) {
                                $response = $response->withHeader($headerName, $headerValue);
                            }
                        }
                        $update = true;
                    }
                    static::addToCache($cacheStrategy, $request, $response, $update);
                }
            );
            return true;
        }
        return false;
    }

    protected static function getStaleResponse(?CacheEntry $cacheEntry, array $options): ?ResponseInterface
    {
        if ($cacheEntry && $cacheEntry->serveStaleIfError()) {
            return static::addDebugHeader($cacheEntry->getResponse(), self::DEFAULT_CACHE_DEBUG_HEADER_STALE, $options);
        }
        return null;
    }

    protected static function getRequestWithReValidationHeader(RequestInterface $request, CacheEntry $cacheEntry): RequestInterface
    {
        if ($cacheEntry->getResponse()->hasHeader('Last-Modified')) {
            $request = $request->withHeader('If-Modified-Since', $cacheEntry->getResponse()->getHeader('Last-Modified'));
        }
        if ($cacheEntry->getResponse()->hasHeader('Etag')) {
            $request = $request->withHeader('If-None-Match', $cacheEntry->getResponse()->getHeader('Etag'));
        }
        return $request;
    }

    public static function inArrayDeep(array $array, string $search): bool
    {
        $found = false;
        array_walk($array, function ($value, $key, $search) use (&$found) {
            if (!$found && is_array($value) && in_array($search, $value)) {
                $found = true;
            }
        }, $search);
        return $found;
    }

    public static function arrayKeyDeep(array $array, string $searchKey): string
    {
        $found = '';
        array_walk($array, function ($value, $key, $searchKey) use (&$found) {
            if (empty($found) && is_array($value) && array_key_exists($searchKey, $value)) {
                $found = (string)$value[$searchKey];
            }
        }, $searchKey);
        return $found;
    }

    public static function arrayFlattenByValue(array $array): array
    {
        $return = [];
        array_walk_recursive($array, function ($value) use (&$return) {$return[] = $value;});
        return $return;
    }

    public static function factory(array $defaultOptions = [], ?CacheStrategyInterface $cacheStrategy = null): \Closure
    {
        return function (callable $handler) use ($defaultOptions, $cacheStrategy) {
            return new static($handler, $defaultOptions, $cacheStrategy);
        };
    }
}

