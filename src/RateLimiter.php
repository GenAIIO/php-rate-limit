<?php

namespace GenAI\RateLimit;

/**
 * Fixed-window rate limiter over a RateStore. tooMany($key) records a hit for the
 * key and reports whether it has now exceeded the configured limit within the
 * window. The key is yours to choose — typically "<ip>|<path>".
 *
 * Runtime class (PHP 5.3-safe).
 */
class RateLimiter
{
    private $store;
    private $limit;
    private $window;

    public function __construct(RateStore $store, $limit = 20, $window = 60)
    {
        $this->store  = $store;
        $this->limit  = (int) $limit;
        $this->window = (int) $window;
    }

    /** Record a hit for $key; true once the key is over the limit this window. */
    public function tooMany($key)
    {
        return $this->store->hit($key, $this->window) > $this->limit;
    }

    public function getLimit()
    {
        return $this->limit;
    }

    public function getWindow()
    {
        return $this->window;
    }
}
