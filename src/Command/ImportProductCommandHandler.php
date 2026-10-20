<?php

declare(strict_types=1);

namespace CommunityStoreShopifyImport\Command;

use CommunityStoreShopifyImport\ImportRepository;
use CommunityStoreShopifyImport\ProductImporter;
use CommunityStoreShopifyImport\ShopifyCsvReader;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\File\File;
use Concrete\Core\Logging\LoggerFactory;

defined('C5_EXECUTE') or die('Access Denied.');

class ImportProductCommandHandler
{
    public const LOG_CHANNEL = 'community_store_shopify_import';

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

    public function __invoke(ImportProductCommand $command)
    {
        // a product with many large images can take longer than the default limit
        @set_time_limit(300);

        $logger = $this->loggerFactory->createLogger(self::LOG_CHANNEL);
        $runID = $command->getRunID();
        $handle = $command->getHandle();

        try {
            $file = File::getByID($command->getFileID());
            if (!$file) {
                throw new UserMessageException(t('The CSV file no longer exists.'));
            }
            $products = (new ShopifyCsvReader())->readString($file->getApprovedVersion()->getFileContents());
            if (!isset($products[$handle])) {
                throw new UserMessageException(t('The product is no longer in the CSV file.'));
            }

            // images were imported by the previous jobs; any that are missing are downloaded now
            $this->importer->setRefreshImages(false);
            $product = $this->importer->import($products[$handle]);
        } catch (\Throwable $e) {
            // only messages meant for users are shown in the dashboard, the details go to the log
            $message = $e instanceof UserMessageException ? $e->getMessage() : t('Unexpected error, see Reports › Logs for details.');
            $this->repository->addResult($runID, $handle, ImportRepository::STATUS_ERROR, $message);
            $logger->error(sprintf('Failed to import %s: %s', $handle, $e->getMessage()), ['exception' => $e]);

            return;
        }

        $this->repository->addResult($runID, $handle, ImportRepository::STATUS_OK, $product->getName(), (int) $product->getID());
        foreach ($this->importer->getWarnings() as $warning) {
            $this->repository->addResult($runID, $handle, ImportRepository::STATUS_WARNING, $warning);
        }
        $logger->notice(sprintf('Imported %s as product #%d.', $handle, $product->getID()));
    }
}
