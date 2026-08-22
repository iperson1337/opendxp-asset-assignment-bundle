<?php

namespace Iperson1337\AssetAssignmentBundle\Service;

/**
 * Interface for Asset Assignment Service
 */
interface AssetAssignmentServiceInterface
{
    /**
     * Initialize execution by counting files and setting up progress tracking
     */
    public function initializeExecution(string $configName): void;

    /**
     * Start execution of asset assignment process
     * 
     * @return bool True if execution completed successfully, false if cancelled or failed
     */
    public function startExecution(string $configName): bool;

    /**
     * Get execution status
     * 
     * @return array{
     *     isRunning: bool,
     *     totalItems: int,
     *     processedItems: int,
     *     movedFiles: int,
     *     attachedFiles: int,
     *     progress: float
     * }
     */
    public function getExecutionStatus(string $configName): array;

    /**
     * Cancel execution and cleanup
     */
    public function cancelExecutionAndCleanup(string $configName): void;
}

