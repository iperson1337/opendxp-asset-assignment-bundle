<?php

declare(strict_types=1);

namespace Iperson1337\AssetAssignmentBundle\Service;

use OpenDxp\Bundle\DataHubBundle\Configuration\Dao;
use OpenDxp\Bundle\DataHubBundle\Execution\RunningExecution;
use OpenDxp\Bundle\DataHubBundle\Execution\RunningExecutionProviderInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Назначения ассетов в индикаторе «Идёт выполнение» Data Hub. Прогресс берётся из того же
 * кеша, куда его пишет {@see AssetAssignmentService}, одним getItems() на все конфиги адаптера.
 */
final class RunningAssetAssignmentProvider implements RunningExecutionProviderInterface
{
    private const string ADAPTER_TYPE = 'assetAssignment';

    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    public function getRunningExecutions(): iterable
    {
        $configNames = [];
        foreach (Dao::getList() as $configuration) {
            if ($configuration->getType() === self::ADAPTER_TYPE) {
                $configNames[AssetAssignmentService::PROGRESS_STORE_PREFIX . $configuration->getName()] = $configuration->getName();
            }
        }

        if ($configNames === []) {
            return;
        }

        foreach ($this->cache->getItems(array_keys($configNames)) as $key => $item) {
            if (!$item->isHit() || !is_array($data = $item->get())) {
                continue;
            }

            $total = (int) ($data['total'] ?? 0);
            $processed = (int) ($data['processed'] ?? 0);

            // Та же граница, что у isRunning в AssetAssignmentService::getExecutionStatus().
            if ($total > 0 && $processed < $total) {
                yield new RunningExecution((string) $configNames[$key], $processed, $total);
            }
        }
    }
}
