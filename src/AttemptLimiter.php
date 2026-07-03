<?php

namespace GenAI\RateLimit;

/**
 * Per-key failure lockout — the classic "too many wrong passwords" guard, distinct
 * from RateLimiter's fixed-window flood control. Count failures for a key (e.g. an
 * email); once they reach maxFails, the key is locked for lockSeconds. A success
 * clears it. The record self-resets after lockSeconds of inactivity, so a few
 * scattered typos over a day never accumulate into a lock.
 *
 *   if ($limiter->lockedFor($email) > 0) { ...reject, show countdown... }
 *   $ok = check_password(...);
 *   if ($ok) { $limiter->clear($email); }
 *   else     { $limiter->fail($email); }
 *
 * Runtime class (PHP 5.3-safe).
 */
class AttemptLimiter
{
    private $store;
    private $maxFails;
    private $lockSeconds;

    public function __construct(AttemptStore $store, $maxFails = 3, $lockSeconds = 300)
    {
        $this->store       = $store;
        $this->maxFails    = (int) $maxFails;
        $this->lockSeconds = (int) $lockSeconds;
    }

    /** Seconds remaining on an active lock for $key, or 0 if not locked. */
    public function lockedFor($key)
    {
        $rec = $this->store->read($this->norm($key));
        if ($rec === null) {
            return 0;
        }
        $left = (int) $rec['locked_until'] - time();
        return $left > 0 ? $left : 0;
    }

    /** Attempts left before a lock kicks in (informational, for messaging). */
    public function remaining($key)
    {
        $rec   = $this->store->read($this->norm($key));
        $fails = ($rec === null) ? 0 : (int) $rec['fails'];
        $left  = $this->maxFails - $fails;
        return $left > 0 ? $left : 0;
    }

    /** Record one failure; locks the key for lockSeconds once maxFails is reached. */
    public function fail($key)
    {
        $k     = $this->norm($key);
        $rec   = $this->store->read($k);
        $fails = (($rec === null) ? 0 : (int) $rec['fails']) + 1;
        $until = ($fails >= $this->maxFails) ? time() + $this->lockSeconds : 0;
        $this->store->write($k, $fails, $until, $this->lockSeconds);
    }

    /** Clear all failures for $key (call on a successful attempt). */
    public function clear($key)
    {
        $this->store->clear($this->norm($key));
    }

    public function getLockSeconds()
    {
        return $this->lockSeconds;
    }

    private function norm($key)
    {
        return strtolower(trim($key));
    }
}
