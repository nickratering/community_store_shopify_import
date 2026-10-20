<?php

declare(strict_types=1);

namespace CommunityStoreShopifyImport\Command;

use Concrete\Core\Foundation\Command\Command;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Imports a single product of a Shopify CSV stored in the File Manager (one step of the dashboard batch).
 *
 * Its images are imported by ImportImageCommand jobs that run before it.
 */
class ImportProductCommand extends Command
{
    /**
     * @var string
     */
    protected $runID;

    /**
     * @var int
     */
    protected $fileID;

    /**
     * @var string
     */
    protected $handle;

    public function __construct(string $runID, int $fileID, string $handle)
    {
        $this->runID = $runID;
        $this->fileID = $fileID;
        $this->handle = $handle;
    }

    public function getRunID(): string
    {
        return $this->runID;
    }

    public function getFileID(): int
    {
        return $this->fileID;
    }

    public function getHandle(): string
    {
        return $this->handle;
    }
}
