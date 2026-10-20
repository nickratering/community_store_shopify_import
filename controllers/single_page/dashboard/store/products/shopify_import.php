<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreShopifyImport\Controller\SinglePage\Dashboard\Store\Products;

use CommunityStoreShopifyImport\Command\ImportImageCommand;
use CommunityStoreShopifyImport\Command\ImportProductCommand;
use CommunityStoreShopifyImport\Command\ImportProductCommandHandler;
use CommunityStoreShopifyImport\ImportRepository;
use CommunityStoreShopifyImport\ProductImporter;
use CommunityStoreShopifyImport\ShopifyCsvReader;
use CommunityStoreShopifyImport\ShopifyCsvValidator;
use Concrete\Core\Command\Batch\Batch;
use Concrete\Core\Entity\File\File as FileEntity;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\File\File;
use Concrete\Core\File\Import\FileImporter;
use Concrete\Core\File\Import\ImportOptions;
use Concrete\Core\Logging\LoggerFactory;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Permission\Checker;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

defined('C5_EXECUTE') or die('Access Denied.');

class ShopifyImport extends DashboardPageController
{
    public const CSV_MIME_TYPES = ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'];

    public function view()
    {
        $this->set('csvFile', null);
        $this->set('plan', null);
        $this->set('results', null);
        $this->set('warnings', []);
        $this->set('refreshImages', false);
        $this->set('runID', '');
    }

    /**
     * Step 1: validate the CSV, store it in the File Manager and show what will happen.
     */
    public function check()
    {
        $this->view();
        if (!$this->token->validate('shopify_import_check')) {
            $this->error->add($this->token->getErrorMessage());

            return;
        }

        try {
            [$file, $products] = $this->getSubmittedFile();
        } catch (UserMessageException $e) {
            $this->error->add($e->getMessage());

            return;
        } catch (\Throwable $e) {
            $this->app->make(LoggerFactory::class)->createLogger(ImportProductCommandHandler::LOG_CHANNEL)->error($e->getMessage(), ['exception' => $e]);
            $this->error->add(t('The file could not be read.'));

            return;
        }

        $result = $this->app->make(ShopifyCsvValidator::class)->validate($products);
        foreach ($result['errors'] as $error) {
            $this->error->add($error);
        }
        $importer = $this->app->make(ProductImporter::class);
        $this->set('csvFile', $file);
        $this->set('warnings', $result['warnings']);
        $this->set('plan', array_map([$importer, 'plan'], array_values($products)));
        $this->set('refreshImages', (bool) $this->request->request->get('refreshImages'));
        $this->set('runID', ImportRepository::createRunID());
    }

    /**
     * Step 2: queue one command per product; the dashboard shows the progress.
     */
    public function import()
    {
        if (!$this->token->validate('shopify_import_run')) {
            throw new UserMessageException($this->token->getErrorMessage());
        }
        $runID = $this->request->request->get('runID');
        if (!ImportRepository::isValidRunID($runID)) {
            throw new UserMessageException(t('Invalid request.'));
        }
        $file = $this->getViewableFile((int) $this->request->request->get('csvFID'));
        $products = (new ShopifyCsvReader())->readString($file->getApprovedVersion()->getFileContents());
        $result = $this->app->make(ShopifyCsvValidator::class)->validate($products);
        if ($result['errors']) {
            throw new UserMessageException(implode("\n", $result['errors']));
        }

        $refreshImages = (bool) $this->request->request->get('refreshImages');
        // small jobs, so that none of them runs into the web server timeout (often 30 seconds)
        $commands = [];
        foreach ($products as $handle => $p) {
            foreach ($p['images'] as $url) {
                $commands[] = new ImportImageCommand($runID, (string) $handle, $url, $p['imageAlts'][$url] ?? '', $refreshImages);
            }
            $commands[] = new ImportProductCommand($runID, (int) $file->getFileID(), (string) $handle);
        }

        return $this->dispatchBatch(Batch::create(t('Import Shopify products'), $commands));
    }

    /**
     * Step 3: show the outcome of the run.
     */
    public function completed($runID = null)
    {
        $this->view();
        if (!ImportRepository::isValidRunID($runID)) {
            return $this->buildRedirect($this->action(''));
        }
        $this->set('results', $this->app->make(ImportRepository::class)->getResults($runID));
    }

    /**
     * Old Shopify URL → new product page, to set up 301 redirects.
     */
    public function download_redirects()
    {
        $importer = $this->app->make(ProductImporter::class);
        $fh = fopen('php://temp', 'w+b');
        fputcsv($fh, ['old_url', 'new_url'], ',', '"', '');
        foreach ($this->app->make(ImportRepository::class)->getProductMap() as $handle => $pID) {
            $product = Product::getByID($pID);
            if ($product) {
                fputcsv($fh, ['/products/' . $handle, $importer->getProductPath($product)], ',', '"', '');
            }
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="shopify-redirects.csv"',
        ]);
    }

    /**
     * @throws UserMessageException
     *
     * @return array{0: FileEntity, 1: array} the stored CSV file and its products
     */
    private function getSubmittedFile(): array
    {
        $reader = new ShopifyCsvReader();
        $upload = $this->request->files->get('csvUpload');
        if ($upload instanceof UploadedFile && $upload->getError() !== UPLOAD_ERR_NO_FILE) {
            if (!$upload->isValid()) {
                throw new UserMessageException($upload->getErrorMessage());
            }
            if (mb_strtolower($upload->getClientOriginalExtension()) !== 'csv'
                || !in_array((string) mime_content_type($upload->getPathname()), self::CSV_MIME_TYPES, true)) {
                throw new UserMessageException(t('Only .csv files can be imported.'));
            }
            // validate the contents before the file is stored anywhere
            $products = $reader->read($upload->getPathname());

            $folder = $this->app->make(ProductImporter::class)->getFileFolder();
            $folderPermissions = new Checker($folder);
            if (!$folderPermissions->canAddFiles() || !$folderPermissions->canAddFileType('csv')) {
                throw new UserMessageException(t('You are not allowed to add CSV files to the "%s" folder of the File Manager.', ProductImporter::FILE_FOLDER));
            }
            $options = $this->app->make(ImportOptions::class);
            $options->setImportToFolder($folder);
            $version = $this->app->make(FileImporter::class)->importUploadedFile($upload, '', $options);

            return [$version->getFile(), $products];
        }

        $fID = (int) $this->request->request->get('csvFID');
        if ($fID > 0) {
            $file = $this->getViewableFile($fID);

            return [$file, $reader->readString($file->getApprovedVersion()->getFileContents())];
        }

        throw new UserMessageException(t('Upload a CSV file or choose one from the File Manager.'));
    }

    /**
     * @throws UserMessageException
     */
    private function getViewableFile(int $fID): FileEntity
    {
        $file = $fID > 0 ? File::getByID($fID) : null;
        // same message whether the file is missing or hidden, to not disclose which IDs exist
        if (!$file || !(new Checker($file))->canViewFileInFileManager()) {
            throw new UserMessageException(t('The selected file could not be found.'));
        }
        if (mb_strtolower((string) $file->getApprovedVersion()->getExtension()) !== 'csv') {
            throw new UserMessageException(t('Only .csv files can be imported.'));
        }

        return $file;
    }
}
