<?php

declare(strict_types=1);

namespace CommunityStoreShopifyImport;

use Concrete\Core\Database\Connection\Connection;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Remembers what was imported (so a new run updates instead of duplicating) and the results of each run.
 */
class ImportRepository
{
    public const TYPE_PRODUCT = 'product';

    public const TYPE_IMAGE = 'image';

    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    public const STATUS_ERROR = 'error';

    /**
     * @var Connection
     */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function getTargetID(string $itemType, string $shopifyKey): ?int
    {
        $id = $this->db->fetchOne(
            'SELECT targetID FROM CommunityStoreShopifyImportItems WHERE itemType = ? AND shopifyKey = ?',
            [$itemType, $shopifyKey]
        );

        return $id === false ? null : (int) $id;
    }

    public function setTargetID(string $itemType, string $shopifyKey, int $targetID): void
    {
        $this->db->executeStatement(
            'INSERT INTO CommunityStoreShopifyImportItems (itemType, shopifyKey, targetID) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE targetID = VALUES(targetID)',
            [$itemType, $shopifyKey, $targetID]
        );
    }

    /**
     * @return array<string, int> Shopify handle => product ID
     */
    public function getProductMap(): array
    {
        $map = [];
        foreach ($this->db->fetchAllAssociative('SELECT shopifyKey, targetID FROM CommunityStoreShopifyImportItems WHERE itemType = ? ORDER BY shopifyKey', [self::TYPE_PRODUCT]) as $row) {
            $map[$row['shopifyKey']] = (int) $row['targetID'];
        }

        return $map;
    }

    public function addResult(string $runID, string $handle, string $status, string $message, ?int $productID = null): void
    {
        $this->db->insert('CommunityStoreShopifyImportResults', [
            'runID' => $runID,
            'handle' => $handle,
            'status' => $status,
            'message' => $message,
            'productID' => $productID,
            'createdAt' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * One result per product: a product is imported by several jobs (its images, then the product itself).
     *
     * @return array<string, array> status => list of ['handle', 'message', 'productID']
     */
    public function getResults(string $runID): array
    {
        $severity = [self::STATUS_OK => 0, self::STATUS_WARNING => 1, self::STATUS_ERROR => 2];
        $byHandle = [];
        foreach ($this->db->fetchAllAssociative('SELECT * FROM CommunityStoreShopifyImportResults WHERE runID = ? ORDER BY id', [$runID]) as $row) {
            $handle = $row['handle'];
            if (!isset($byHandle[$handle])) {
                $byHandle[$handle] = ['handle' => $handle, 'status' => self::STATUS_OK, 'name' => '', 'messages' => [], 'productID' => null];
            }
            $item = &$byHandle[$handle];
            if ($severity[$row['status']] > $severity[$item['status']]) {
                $item['status'] = $row['status'];
            }
            if ($row['productID']) {
                // the product job stores the product name when it succeeds
                $item['productID'] = (int) $row['productID'];
                if ($row['status'] === self::STATUS_OK) {
                    $item['name'] = $row['message'];
                }
            }
            if ($row['status'] !== self::STATUS_OK) {
                $item['messages'][] = $row['message'];
            }
            unset($item);
        }

        $results = [self::STATUS_OK => [], self::STATUS_WARNING => [], self::STATUS_ERROR => []];
        foreach ($byHandle as $item) {
            if ($item['status'] !== self::STATUS_ERROR && $item['productID'] === null) {
                // the product job has not run (yet), so the product was not imported
                $item['status'] = self::STATUS_ERROR;
                $item['messages'][] = t('The product was not imported.');
            }
            $results[$item['status']][] = [
                'handle' => $item['handle'],
                'message' => $item['status'] === self::STATUS_OK ? $item['name'] : implode(' ', array_unique($item['messages'])),
                'productID' => $item['productID'],
            ];
        }

        return $results;
    }

    public static function isValidRunID($runID): bool
    {
        return is_string($runID) && preg_match('/^[0-9a-f]{32}$/', $runID) === 1;
    }

    public static function createRunID(): string
    {
        return bin2hex(random_bytes(16));
    }
}
