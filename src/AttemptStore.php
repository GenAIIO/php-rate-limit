<?php

namespace GenAI\RateLimit;

/**
 * Backing store for the AttemptLimiter: a per-key record of how many failures have
 * accrued and, once locked, until when. Records expire on their own TTL so an
 * abandoned counter resets itself.
 *
 * Runtime contract (PHP 5.3-safe).
 */
interface AttemptStore
{
    /**
     * @param string $key
     * @return array|null  array('fails' => int, 'locked_until' => int) or null if absent/expired
     */
    public function read($key);

    /**
     * @param string $key
     * @param int    $fails
     * @param int    $lockedUntil  unix time the lock lifts (0 = not locked)
     * @param int    $ttl          seconds to keep the record before it self-resets
     */
    public function write($key, $fails, $lockedUntil, $ttl);

    /** Forget the key entirely (e.g. after a successful login). */
    public function clear($key);
}
