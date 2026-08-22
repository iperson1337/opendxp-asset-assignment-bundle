<?php

declare(strict_types=1);

namespace Iperson1337\AssetAssignmentBundle\Service;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Distributed execution lock — prevents concurrent asset assignment runs for the same config.
 *
 * TTL is 4 hours: long enough to cover any realistic run. If a worker crashes mid-run
 * the lock will auto-expire after 4 hours rather than blocking forever.
 */
final class ExecutionLockService
{
    private const float DEFAULT_TTL = 1800.0; // 30 minutes

    /** @var array<string, LockInterface> */
    private array $locks = [];

    public function __construct(private readonly LockFactory $lockFactory) {}

    /**
     * Non-blocking acquire. Returns false immediately if another execution holds the lock.
     */
    public function tryAcquire(string $configName, float $ttl = self::DEFAULT_TTL): bool
    {
        $lock = $this->lockFactory->createLock($this->key($configName), $ttl, false);

        if (!$lock->acquire(false)) {
            return false;
        }

        $this->locks[$configName] = $lock;
        return true;
    }

    public function release(string $configName): void
    {
        if (isset($this->locks[$configName])) {
            $this->locks[$configName]->release();
            unset($this->locks[$configName]);
        }
    }

    /**
     * Force-release the lock regardless of which process holds it.
     * Use when cancelling execution externally (e.g. via UI button) after a crash.
     */
    public function forceRelease(string $configName): void
    {
        $lock = $this->lockFactory->createLock($this->key($configName));
        $lock->release();
        unset($this->locks[$configName]);
    }

    private function key(string $configName): string
    {
        return 'asset_assignment_execution_' . $configName;
    }
}
