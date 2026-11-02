<?php
declare(strict_types=1);

namespace Hitrov;

use PDO;

final class LaunchState
{
    private PDO $db;
    private $lock;

    public function __construct(string $directory)
    {
        $this->lock = fopen($directory . '/launch.lock', 'c');
        if ($this->lock === false || !flock($this->lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Another provisioning process is running');
        }
        $this->db = new PDO('sqlite:' . $directory . '/launch.sqlite');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE IF NOT EXISTS launch (id INTEGER PRIMARY KEY CHECK (id = 1), status TEXT NOT NULL, token TEXT NOT NULL, display_name TEXT NOT NULL)');
        $columns = $this->db->query('PRAGMA table_info(launch)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('instance_id', $columns, true)) {
            $this->db->exec('ALTER TABLE launch ADD COLUMN instance_id TEXT');
        }
        if (!in_array('notified', $columns, true)) {
            $this->db->exec('ALTER TABLE launch ADD COLUMN notified INTEGER NOT NULL DEFAULT 0');
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS capacity_backoff (id INTEGER PRIMARY KEY CHECK (id = 1), attempts INTEGER NOT NULL, retry_at INTEGER NOT NULL)');
    }

    public function current(): ?array
    {
        $row = $this->db->query('SELECT status, token, display_name, instance_id, notified FROM launch WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function begin(): array
    {
        if ($this->current() !== null) {
            throw new \RuntimeException('Previous launch needs reconciliation');
        }
        $token = bin2hex(random_bytes(16));
        $name = 'oci-free-arm-' . substr($token, 0, 12);
        $statement = $this->db->prepare('INSERT INTO launch (id, status, token, display_name) VALUES (1, ?, ?, ?)');
        $statement->execute(['pending', $token, $name]);
        return ['token' => $token, 'display_name' => $name];
    }

    public function observed(string $instanceId): void
    {
        $statement = $this->db->prepare("UPDATE launch SET status = 'provisioning', instance_id = ? WHERE id = 1");
        $statement->execute([$instanceId]);
    }

    public function succeeded(): void
    {
        $this->db->exec("UPDATE launch SET status = 'succeeded' WHERE id = 1");
    }

    public function notified(): void
    {
        $this->db->exec('UPDATE launch SET notified = 1 WHERE id = 1');
    }

    public function capacityRejected(): void
    {
        $this->db->exec("DELETE FROM launch WHERE id = 1 AND status = 'pending'");
    }

    public function secondsUntilRetry(): int
    {
        $row = $this->db->query('SELECT attempts, retry_at FROM capacity_backoff WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return 0;
        }
        return max(0, (int) $row['retry_at'] - time());
    }

    public function scheduleRetry(): int
    {
        $attempts = (int) ($this->db->query('SELECT attempts FROM capacity_backoff WHERE id = 1')->fetchColumn() ?: 0);
        $minutes = (int) min(3600, 300 * 2 ** $attempts);
        $statement = $this->db->prepare('INSERT INTO capacity_backoff (id, attempts, retry_at) VALUES (1, ?, ?) ON CONFLICT(id) DO UPDATE SET attempts = excluded.attempts, retry_at = excluded.retry_at');
        $statement->execute([$attempts + 1, time() + $minutes]);
        return $minutes;
    }

    public function clearRetry(): void
    {
        $this->db->exec('DELETE FROM capacity_backoff');
    }

    public function __destruct()
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
    }
}
