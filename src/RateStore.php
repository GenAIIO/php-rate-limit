<?php

namespace GenAI\RateLimit;

/**
 * Backing store for fixed-window counters. Implementations record a hit against a
 * key inside the current window and return the running count for that window.
 *
 * Runtime contract (PHP 5.3-safe).
 */
interface RateStore
{
    /**
     * Record one hit for $key in the current fixed window of $window seconds and
     * return the number of hits seen in that window so far (including this one).
     *
     * @param string $key
     * @param int    $window  window length in seconds
     * @return int
     */
    public function hit($key, $window);
}
