<?php

namespace GenAI\RateLimit\Store;

use GenAI\RateLimit\AttemptStore;

/**
 * File-backed attempt records: one small file per key holding
 * "fails:locked_until:expires". The record self-resets once `expires` passes
 * (read returns null and the stale file is removed). Single-server; for a cluster,
 * back AttemptLimiter with a shared AttemptStore instead.
 *
 * Runtime class (PHP 5.3-safe).
 */
class FileAttemptStore implements AttemptStore
{
    private $dir;

    public function __construct($dir)
    {
        $dir = rtrim($dir, "/\\");
        if ($dir === '' || ($dir[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $dir))) {
            $dir = getcwd() . '/' . $dir;
        }
        $this->dir = $dir;
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
    }

    public function read($key)
    {
        $file = $this->file($key);
        $raw  = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $parts = explode(':', trim($raw));
        if (count($parts) < 3) {
            return null;
        }
        if ((int) $parts[2] < time()) {
            @unlink($file); // expired -> reset
            return null;
        }
        return array('fails' => (int) $parts[0], 'locked_until' => (int) $parts[1]);
    }

    public function write($key, $fails, $lockedUntil, $ttl)
    {
        $expires = time() + (int) $ttl;
        $line    = (int) $fails . ':' . (int) $lockedUntil . ':' . $expires;
        @file_put_contents($this->file($key), $line, LOCK_EX);
    }

    public function clear($key)
    {
        @unlink($this->file($key));
    }

    private function file($key)
    {
        return $this->dir . '/' . sha1($key);
    }
}
