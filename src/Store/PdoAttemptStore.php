<?php

namespace GenAI\RateLimit\Store;

use GenAI\RateLimit\AttemptStore;

/**
 * Database-backed attempt records for the AttemptLimiter (per-account login
 * lockout): one ROW per key holding fails / locked_until / expires, instead of one
 * file. The record self-resets once `expires` passes. Uses the app's PDO
 * connection. Login writes are rare, so a delete+insert upsert keeps the SQL
 * portable (no SQLite-only syntax).
 *
 * Runtime class (PHP 5.3-safe).
 */
class PdoAttemptStore implements AttemptStore
{
    private $pdo;
    private $table;

    public function __construct($pdo, $table = 'login_attempts')
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
                    k            TEXT PRIMARY KEY,
                    fails        INTEGER NOT NULL DEFAULT 0,
                    locked_until INTEGER NOT NULL DEFAULT 0,
                    expires      INTEGER NOT NULL
                )"
            );
            $this->pdo->exec('PRAGMA busy_timeout = 2000');
        } catch (\Exception $e) {}
    }

    public function read($key)
    {
        $k = sha1($key);
        try {
            $sel = $this->pdo->prepare("SELECT fails, locked_until, expires FROM " . $this->table . " WHERE k = ?");
            $sel->execute(array($k));
            $row = $sel->fetch(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return null;
        }
        if (!$row) {
            return null;
        }
        if ((int) $row['expires'] < time()) {
            $this->clear($key);   // expired -> reset
            return null;
        }
        return array('fails' => (int) $row['fails'], 'locked_until' => (int) $row['locked_until']);
    }

    public function write($key, $fails, $lockedUntil, $ttl)
    {
        $k       = sha1($key);
        $expires = time() + (int) $ttl;
        try {
            $this->pdo->prepare("DELETE FROM " . $this->table . " WHERE k = ?")->execute(array($k));
            $this->pdo->prepare("INSERT INTO " . $this->table . " (k, fails, locked_until, expires) VALUES (?, ?, ?, ?)")
                ->execute(array($k, (int) $fails, (int) $lockedUntil, $expires));
        } catch (\Exception $e) {}
    }

    public function clear($key)
    {
        try {
            $this->pdo->prepare("DELETE FROM " . $this->table . " WHERE k = ?")->execute(array(sha1($key)));
        } catch (\Exception $e) {}
    }
}
