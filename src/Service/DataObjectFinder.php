<?php

namespace Iperson1337\AssetAssignmentBundle\Service;

use OpenDxp\Model\DataObject;

/**
 * Service for finding DataObjects by match field
 */
final readonly class DataObjectFinder
{
    /**
     * Find multiple DataObjects by match field in a single query — use for batch processing.
     *
     * @param string $className Full class name (e.g., \OpenDxp\Model\DataObject\Product)
     * @param string $matchField Field name to search by
     * @param array<string> $values Values to search for
     * @return array<string, DataObject\Concrete> Keyed by match field value
     */
    public function findByMatchFieldBatch(string $className, string $matchField, array $values): array
    {
        if (empty($values) || !class_exists($className)) {
            return [];
        }

        $listingClass = $className . '\\Listing';
        if (!class_exists($listingClass)) {
            // Fallback: individual lookups
            $result = [];
            foreach ($values as $value) {
                $obj = $this->findByMatchField($className, $matchField, $value);
                if ($obj !== null) {
                    $result[$value] = $obj;
                }
            }
            return $result;
        }

        try {
            $listing = new $listingClass();
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $listing->setCondition("{$matchField} IN ({$placeholders})", $values);
            $listing->setUnpublished(true);
            $objects = $listing->load();

            $getter = 'get' . ucfirst($matchField);
            $result = [];
            foreach ($objects as $obj) {
                $result[$obj->$getter()] = $obj;
            }
            return $result;
        } catch (\Throwable $e) {
            // Fallback: individual lookups
            $result = [];
            foreach ($values as $value) {
                $obj = $this->findByMatchField($className, $matchField, $value);
                if ($obj !== null) {
                    $result[$value] = $obj;
                }
            }
            return $result;
        }
    }

    /**
     * Find DataObject by match field using getBy{Field} method
     *
     * @param string $className Full class name (e.g., \OpenDxp\Model\DataObject\Product)
     * @param string $matchField Field name to search by
     * @param string $value Value to search for
     * @return DataObject\Concrete|null
     */
    public function findByMatchField(string $className, string $matchField, string $value): ?DataObject\Concrete
    {
        if (!class_exists($className)) {
            return null;
        }

        $getterMethod = 'getBy' . ucfirst($matchField);

        // Call getBy{Field} method exactly as in original code: getByCode($baseCode, 1)
        // Pimcore generates these methods dynamically, so method_exists may not work correctly
        try {
            $result = call_user_func([$className, $getterMethod], $value, ['limit' => 1, 'unpublished' => true]);
        } catch (\Throwable $e) {
            // Method doesn't exist or other error - return null
            return null;
        }

        // Original code returned result directly, but we need to handle both Concrete and Listing
        if ($result instanceof DataObject\Concrete) {
            return $result;
        }

        // If result is a Listing (Pimcore can return Listing when limit > 1 or multiple results)
        if ($result instanceof DataObject\Listing) {
            // If limit is 1, get the first object
            if ($result->count() > 0) {
                return $result->current();
            }
        }

        return null;
    }
}

