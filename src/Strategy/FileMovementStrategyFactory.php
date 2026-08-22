<?php

namespace Iperson1337\AssetAssignmentBundle\Strategy;

use Psr\Log\LoggerInterface;

/**
 * Factory for creating file movement strategies
 */
class FileMovementStrategyFactory
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Create strategy by name
     *
     * @param string $strategyName Strategy name (create_folder_and_move, move_to_target)
     * @param string $configName Configuration name
     * @return FileMovementStrategyInterface
     * @throws \Exception
     */
    public function create(string $strategyName, string $configName): FileMovementStrategyInterface
    {
        return match ($strategyName) {
            'create_folder_and_move' => new CreateFolderAndMoveStrategy(
                $this->logger,
                $configName
            ),
            'move_to_target' => new MoveToTargetStrategy(
                $this->logger,
                $configName
            ),
            default => throw new \Exception("Unknown file movement strategy: {$strategyName}")
        };
    }
}

