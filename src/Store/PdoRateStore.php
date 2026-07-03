<?php

namespace GenAI\RateLimit\Store;

use GenAI\RateLimit\RateStore;

/**
 * Database-backed fixed-window counters — one ROW per (key, window) bucket instead
 * of one file, so a shared host isn't littered with tiny files / inodes. Increments
 * atomically inside a transaction; stale buckets are swept opportunistically. Uses
 * the app's PDO connection (the same one genai/sql-mapper configures).
 *
 * SQLite-friendly SQL (the default app DB). Fails OPEN on any store error — a DB
 * hiccup must never lock real users out.
 *
 * Runtime class (PHP 5.3-safe).
 */
class PdoRateStore implements RateStore
{
    private $pdo;
    private $table;

    public function __construct($pdo, $table = 'rate_hits')
    {
        $this->pdo   = $pdo;
        $this->table = $table;
        $this->ensureSchema();
    }

    private function ensureSchema()
    {
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS " . $this->table . " (
                    k       TEXT PRIMARY KEY,
                    count   INTEGER NOT NULL DEFAULT 0,
                    expires INTEGER NOT NULL
                )"
            );
            $this->pdo->exec('PRAGMA busy_timeout = 2000');   // SQLite: wait, don't error, on a write lock
        } catch (\Exception $e) {
            // first request may race to create it; harmless
        }
    }

    public function hit($key, $window)
    {
        $window = (int) $window;
        if ($window < 1) {
            $window = 1;
        }
        $bucket  = (int) floor(time() / $window);
        $k       = sha1($key . ':' . $bucket);
        $expires = ($bucket + 2) * $window;   // safe to purge after two windows

        try {
            $this->pdo->beginTransaction();
            // Ensure the row exists (count 0), then increment — works whether or not
            // PDO is in exception mode (a duplicate-key INSERT just no-ops the row).
            $ins = $this->pdo->prepare("INSERT INTO " . $this->table . " (k, count, expires) VALUES (?, 0, ?)");
            try { $ins->execute(array($k, $expires)); } catch (\Exception $dup) { /* row already there */ }
            $this->pdo->prepare("UPDATE " . $this->table . " SET count = count + 1 WHERE k = ?")->execute(array($k));
            $sel = $this->pdo->prepare("SELECT count FROM " . $this->table . " WHERE k = ?");
            $sel->execute(array($k));
            $count = (int) $sel->fetchColumn();
            $this->pdo->commit();
        } catch (\Exception $e) {
            try { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } } catch (\Exception $e2) {}
            return 1;   // fail open
        }

        $this->gc($window);
        return $count;
    }

    /** Sweep expired buckets occasionally (cheap; runs on ~2% of hits). */
    private function gc($window)
    {
        if (mt_rand(1, 50) !== 1) {
            return;
        }
        try {
            $this->pdo->prepare("DELETE FROM " . $this->table . " WHERE expires < ?")->execute(array(time()));
        } catch (\Exception $e) {}
    }
}
