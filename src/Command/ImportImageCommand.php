<?php

declare(strict_types=1);

namespace CommunityStoreShopifyImport\Command;

use Concrete\Core\Foundation\Command\Command;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Downloads one product image into the File Manager (one step of the dashboard batch).
 */
class ImportImageCommand extends Command
{
    /**
     * @var string
     */
    protected $runID;

    /**
     * @var string
     */
    protected $handle;

    /**
     * @var string
     */
    protected $url;

    /**
     * @var string
     */
    protected $alt;

    /**
     * @var bool
     */
    protected $refresh;

    public function __construct(string $runID, string $handle, string $url, string $alt, bool $refresh = false)
    {
        $this->runID = $runID;
        $this->handle = $handle;
        $this->url = $url;
        $this->alt = $alt;
        $this->refresh = $refresh;
    }

    public function getRunID(): string
    {
        return $this->runID;
    }

    public function getHandle(): string
    {
        return $this->handle;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getAlt(): string
    {
        return $this->alt;
    }

    public function isRefresh(): bool
    {
        return $this->refresh;
    }
}
