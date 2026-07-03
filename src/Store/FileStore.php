<?php

namespace GenAI\RateLimit\Store;

use GenAI\RateLimit\RateStore;

/**
 * File-backed fixed-window counters: one small file per (key, window) bucket,
 * incremented under an exclusive lock so concurrent requests count accurately.
 * Stale buckets are swept opportunistically. Good for a single server; for a
 * cluster, back the limiter with a shared store instead.
 *
 * Runtime class (PHP 5.3-safe).
 */
class FileStore implements RateStore
{
    private $dir;

    public function __construct($dir)
    {
        $dir = rtrim($dir, "/\\");
        // Absolutize against the CWD now (request time, CWD = app root), so the
        // path is stable regardless of where it is later resolved.
        if ($dir === '' || ($dir[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $dir))) {
            $dir = getcwd() . '/' . $dir;
        }
        $this->dir = $dir;
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
    }

    public function hit($key, $window)
    {
        $window = (int) $window;
        if ($window < 1) {
            $window = 1;
        }
        $bucket = (int) floor(time() / $window);
        $file   = $this->dir . '/' . sha1($key . ':' . $bucket);

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return 1; // can't track -> fail open (don't lock users out on FS errors)
        }
        flock($fp, LOCK_EX);
        $count = (int) stream_get_contents($fp) + 1;
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, (string) $count);
        flock($fp, LOCK_UN);
        fclose($fp);

        $this->gc($window);

        return $count;
    }

    /** Sweep expired buckets occasionally (cheap; runs on ~2% of hits). */
    private function gc($window)
    {
        if (mt_rand(1, 50) !== 1) {
            return;
        }
        $files = glob($this->dir . '/*');
        if ($files === false) {
            return;
        }
        $cutoff = time() - 2 * $window;
        foreach ($files as $f) {
            if (is_file($f) && @filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }
}
