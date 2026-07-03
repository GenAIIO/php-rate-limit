<?php

namespace GenAI\RateLimit;

use GenAI\RateLimit\Bundle\RateLimitProperty;
use GenAI\RateLimit\Store\FileAttemptStore;
use GenAI\RateLimit\Store\FileStore;
use GenAI\RateLimit\Store\PdoAttemptStore;
use GenAI\RateLimit\Store\PdoRateStore;

/**
 * Builds a RateLimiter from RateLimitProperty config. Wire it as a bean in a
 * #[Configuration] so the interceptor (and anything else) can autowire it:
 *
 *   #[Bean(RateLimiter::class)]
 *   public function rateLimiter(RateLimitProperty $cfg) {
 *       return RateLimitFactory::build($cfg);
 *   }
 *
 * Runtime class (PHP 5.3-safe).
 */
class RateLimitFactory
{
    public static function build(RateLimitProperty $cfg)
    {
        $store = new FileStore($cfg->getPath());
        return new RateLimiter($store, $cfg->getLimit(), $cfg->getWindow());
    }

    /** Per-account failed-login lockout (separate counters under <path>/login). */
    public static function lockout(RateLimitProperty $cfg)
    {
        $store = new FileAttemptStore($cfg->getPath() . '/login');
        return new AttemptLimiter($store, $cfg->getLoginMaxFails(), $cfg->getLoginLock());
    }

    // ---- Database-backed variants (no per-key files; good for shared hosting) ----

    /** Flood control backed by a DB table (the app's PDO) instead of files. */
    public static function buildPdo($pdo, RateLimitProperty $cfg)
    {
        return new RateLimiter(new PdoRateStore($pdo), $cfg->getLimit(), $cfg->getWindow());
    }

    /** Per-account login lockout backed by a DB table instead of files. */
    public static function lockoutPdo($pdo, RateLimitProperty $cfg)
    {
        return new AttemptLimiter(new PdoAttemptStore($pdo), $cfg->getLoginMaxFails(), $cfg->getLoginLock());
    }
}
