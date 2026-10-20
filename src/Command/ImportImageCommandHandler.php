<?php

declare(strict_types=1);

namespace CommunityStoreShopifyImport\Command;

use CommunityStoreShopifyImport\ImportRepository;
use CommunityStoreShopifyImport\ProductImporter;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Logging\LoggerFactory;

defined('C5_EXECUTE') or die('Access Denied.');

class ImportImageCommandHandler
{
    /**
     * @var ProductImporter
     */
    private $importer;

    /**
     * @var ImportRepository
     */
    private $repository;

    /**
     * @var LoggerFactory
     */
    private $loggerFactory;

    public function __construct(ProductImporter $importer, ImportRepository $repository, LoggerFactory $loggerFactory)
    {
        $this->importer = $importer;
        $this->repository = $repository;
        $this->loggerFactory = $loggerFactory;
    }

    public function __invoke(ImportImageCommand $command)
    {
        try {
            $this->importer->setRefreshImages($command->isRefresh());
            $this->importer->importProductImage($command->getUrl(), $command->getHandle(), $command->getAlt());
        } catch (\Throwable $e) {
            // a missing image is not fatal: the product job tries again and reports the problem
            $this->loggerFactory->createLogger(ImportProductCommandHandler::LOG_CHANNEL)->error(
                sprintf('Failed to import image %s of %s: %s', $command->getUrl(), $command->getHandle(), $e->getMessage()),
                ['exception' => $e]
            );
            if ($e instanceof UserMessageException) {
                $this->repository->addResult($command->getRunID(), $command->getHandle(), ImportRepository::STATUS_WARNING, $e->getMessage());
            }

            return;
        }

        foreach ($this->importer->getWarnings() as $warning) {
            $this->repository->addResult($command->getRunID(), $command->getHandle(), ImportRepository::STATUS_WARNING, $warning);
        }
    }
}
