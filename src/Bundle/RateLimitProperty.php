<?php

namespace GenAI\RateLimit\Bundle;

use GenAI\Property\AbstractProperty;
use GenAI\Property\Attribute\Property;
use GenAI\Property\Util\Map;

/**
 * Rate-limit config ([ratelimit] group). limit = max state-changing requests per
 * IP+path within window seconds; over that returns 429. path = where file-backed
 * counters live (relative to the project root). Optional — sensible defaults apply
 * when the section is absent.
 *
 * Runtime class (PHP 5.3-safe).
 */
#[Property(group: 'ratelimit', optional: true)]
class RateLimitProperty extends AbstractProperty
{
    private $limit;
    private $window;
    private $path;
    private $loginMaxFails;
    private $loginLock;

    public function bindData(Map $data)
    {
        $this->limit         = $data->get('limit');
        $this->window        = $data->get('window');
        $this->path          = $data->get('path');
        $this->loginMaxFails = $data->get('login_max_fails');
        $this->loginLock     = $data->get('login_lock');
    }

    public function getLimit()
    {
        return ($this->limit !== null && $this->limit !== '') ? (int) $this->limit : 20;
    }

    public function getWindow()
    {
        return ($this->window !== null && $this->window !== '') ? (int) $this->window : 60;
    }

    public function getPath()
    {
        return ($this->path !== null && $this->path !== '') ? $this->path : 'cache/ratelimit';
    }

    /** Failed logins for one account before it locks (AttemptLimiter). */
    public function getLoginMaxFails()
    {
        return ($this->loginMaxFails !== null && $this->loginMaxFails !== '') ? (int) $this->loginMaxFails : 3;
    }

    /** How long a locked account stays locked, in seconds. */
    public function getLoginLock()
    {
        return ($this->loginLock !== null && $this->loginLock !== '') ? (int) $this->loginLock : 300;
    }
}
