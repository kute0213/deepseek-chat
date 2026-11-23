<?php
declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * 统一雪花 ID 生成：41 位毫秒 + 10 位 worker + 12 位序列。
 * 通过 id_sequence 行锁保证并发与重启后全局唯一。
 */
final class Snowflake
{
    private const EPOCH = 1704067200000; // 2024-01-01 00:00:00 UTC
    private const MAX_SEQ = 4095;
    private static ?self $instance = null;

    private PDO $pdo;
    private int $workerId;

    public function __construct(PDO $pdo, int $workerId)
    {
        $this->pdo = $pdo;
        $this->workerId = $workerId & 1023;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self(
            Database::pdo(),
            (int) (Config::get('snowflake_worker_id', 1) & 1023)
        );
    }

    /** @return string[] */
    public function nextBatch(int $count): array
    {
        $count = max(1, min($count, 4000));
        $transactionStarted = !$this->pdo->inTransaction();
        if ($transactionStarted) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->query('SELECT last_ms, last_seq FROM id_sequence WHERE id = 1 FOR UPDATE');
            $row = $stmt->fetch();
            if (!$row) {
                $this->pdo->exec('INSERT INTO id_sequence (id, last_ms, last_seq) VALUES (1, 0, 0)');
                $row = ['last_ms' => 0, 'last_seq' => 0];
            }

            $lastMs = (int) $row['last_ms'];
            $lastSeq = (int) $row['last_seq'];
            $ms = (int) floor(microtime(true) * 1000);
            if ($ms < $lastMs) {
                $ms = $lastMs;
            }
            $seq = $ms === $lastMs ? $lastSeq : -1;
            if ($seq + $count > self::MAX_SEQ) {
                $ms++;
                $seq = -1;
            }

            $ids = [];
            for ($i = 0; $i < $count; $i++) {
                if ($seq >= self::MAX_SEQ) {
                    $ms++;
                    $seq = -1;
                }
                $seq++;
                $id = (($ms - self::EPOCH) << 22) | ($this->workerId << 12) | $seq;
                $ids[] = (string) $id;
            }

            $update = $this->pdo->prepare('UPDATE id_sequence SET last_ms = ?, last_seq = ? WHERE id = 1');
            $update->execute([$ms, $seq]);

            if ($transactionStarted) {
                $this->pdo->commit();
            }
            return $ids;
        } catch (\Throwable $e) {
            if ($transactionStarted && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function nextId(): string
    {
        return $this->nextBatch(1)[0];
    }
}
