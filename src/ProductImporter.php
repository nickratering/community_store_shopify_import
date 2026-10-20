<?php

declare(strict_types=1);

namespace CommunityStoreShopifyImport;

use Concrete\Core\Entity\File\File as FileEntity;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\File\Filesystem;
use Concrete\Core\File\Import\FileImporter;
use Concrete\Core\File\Import\ImportOptions;
use Concrete\Core\Http\Client\Client;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Events;
use Concrete\Core\Tree\Node\Type\FileFolder;
use Concrete\Core\Url\Validation\InvalidRemoteUrlException;
use Concrete\Core\Url\Validation\RemoteUrlRequestOptionsBuilder;
use Concrete\Core\Url\Validation\RemoteUrlValidator;
use Concrete\Package\CommunityStore\Src\CommunityStore\Group\Group;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductEvent;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductGroup;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductImage;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductOption\ProductOption;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductOption\ProductOptionItem;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductVariation\ProductVariation;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\ProductImageInfoUpdater;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\ProductPageMetadataUpdater;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

defined('C5_EXECUTE') or die('Access Denied.');

class ProductImporter
{
    public const FILE_FOLDER = 'Shopify import';

    // Image URLs come from an uploaded file, so only Shopify's CDN may be fetched
    public const IMAGE_HOSTS = ['cdn.shopify.com'];

    public const IMAGE_MAX_BYTES = 20_971_520;

    /**
     * @var Settings
     */
    private $settings;

    /**
     * @var ImportRepository
     */
    private $repository;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var FileImporter
     */
    private $fileImporter;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Client
     */
    private $httpClient;

    /**
     * @var callable|null
     */
    private $logger;

    /**
     * @var bool
     */
    private $refreshImages = false;

    /**
     * @var string[]
     */
    private $warnings = [];

    public function __construct(Settings $settings, ImportRepository $repository, EntityManagerInterface $em, FileImporter $fileImporter, Filesystem $filesystem, Client $httpClient)
    {
        $this->settings = $settings;
        $this->repository = $repository;
        $this->em = $em;
        $this->fileImporter = $fileImporter;
        $this->filesystem = $filesystem;
        $this->httpClient = $httpClient;
    }

    public function setRefreshImages(bool $refresh): void
    {
        $this->refreshImages = $refresh;
    }

    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Non-fatal problems of the last import() call.
     *
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * The Community Store groups a product belongs to, according to the settings.
     *
     * @return array<int, array{id: int|null, name: string}> id is null for a group that will be created
     */
    public function resolveGroups(array $p): array
    {
        $groups = [];
        foreach ($this->settings->getRules() as $rule) {
            if ($this->ruleMatches($rule, $p)) {
                $group = Group::getByID($rule['groupID']);
                if ($group) {
                    $groups[$group->getGroupID()] = ['id' => (int) $group->getGroupID(), 'name' => $group->getGroupName()];
                }
            }
        }
        if ($groups) {
            return array_values($groups);
        }

        switch ($this->settings->getFallbackMode()) {
            case Settings::FALLBACK_GROUP:
                $group = Group::getByID($this->settings->getFallbackGroupID());

                return $group ? [['id' => (int) $group->getGroupID(), 'name' => $group->getGroupName()]] : [];
            case Settings::FALLBACK_TYPE:
                if ($p['type'] === '') {
                    return [];
                }
                $group = Group::getByName($p['type']);

                return [['id' => $group ? (int) $group->getGroupID() : null, 'name' => $p['type']]];
            default:
                return [];
        }
    }

    /**
     * Describes what would be created, without touching the database.
     */
    public function plan(array $p): array
    {
        $existingID = $this->repository->getTargetID(ImportRepository::TYPE_PRODUCT, $p['handle']);
        $existing = $existingID ? Product::getByID($existingID) : null;
        $prices = $this->getPrices($p);

        return [
            'handle' => $p['handle'],
            'name' => $p['title'],
            'productID' => $existing ? (int) $existing->getID() : null,
            'groups' => $this->resolveGroups($p),
            'active' => $this->isActive($p),
            'options' => array_values($p['optionNames']),
            'variants' => empty($p['optionNames']) ? 0 : count($p['variants']),
            'images' => count($p['images']),
            'price' => $prices ? (Price::format(min($prices)) . (min($prices) != max($prices) ? ' – ' . Price::format(max($prices)) : '')) : '',
        ];
    }

    /**
     * @throws UserMessageException|\Throwable
     */
    public function import(array $p, bool $withImages = true): Product
    {
        $this->warnings = [];
        $existingID = $this->repository->getTargetID(ImportRepository::TYPE_PRODUCT, $p['handle']);
        $existing = $existingID ? Product::getByID($existingID) : null;
        if (!$p['variants']) {
            throw new UserMessageException(t('The product has no variants or price.'));
        }

        $fileIDs = [];
        foreach ($p['images'] as $url) {
            $fID = $withImages
                ? $this->importImage($url, $p['handle'], $p['imageAlts'][$url] ?? '')
                : $this->repository->getTargetID(ImportRepository::TYPE_IMAGE, $this->imageKey($url));
            if ($fID) {
                $fileIDs[$url] = $fID;
            }
        }

        $data = $this->buildProductData($p, $existing, array_values($fileIDs), $this->getGroupIDs($p));

        $original = null;
        if ($existing) {
            $original = clone $existing;
            $original->setID($existing->getID());
        }
        $product = Product::saveProduct($data);
        $this->repository->setTargetID(ImportRepository::TYPE_PRODUCT, $p['handle'], (int) $product->getID());

        if ($existing) {
            // options and variations are rebuilt: the CSV has no stable IDs for them
            foreach ($product->getVariations()->toArray() as $variation) {
                $variation->delete();
            }
            ProductOption::removeOptionsForProduct($product);
            $product->getOptions()->clear();
            $this->em->flush();
        }

        ProductImage::addImagesForProduct($data, $product);
        ProductGroup::addGroupsForProduct($data, $product);

        if (!empty($p['optionNames'])) {
            $this->importOptionsAndVariations($p, $product, $fileIDs);
        }

        $this->em->refresh($product);
        app(ProductImageInfoUpdater::class)->applyToProduct($product);
        app(ProductPageMetadataUpdater::class)->applyToProduct($product);
        $this->applySeo($p, $product);

        $event = $original ? new ProductEvent($original, $product) : new ProductEvent($product);
        Events::dispatch($original ? ProductEvent::PRODUCT_UPDATE : ProductEvent::PRODUCT_ADD, $event);

        return $product;
    }

    /**
     * Imports one image of a product (one step of the dashboard batch); see getWarnings() for problems.
     */
    public function importProductImage(string $url, string $handle, string $alt): ?int
    {
        $this->warnings = [];

        return $this->importImage($url, $handle, $alt);
    }

    public function getProductPath(Product $product): string
    {
        $cID = $product->getPageID();
        if ($cID) {
            $page = Page::getByID($cID);
            if ($page && !$page->isError()) {
                return (string) $page->getCollectionPath();
            }
        }

        return '';
    }

    public function getFileFolder(): FileFolder
    {
        return $this->filesystem->getRootFolder()->getChildFolderByName(self::FILE_FOLDER, true);
    }

    private function ruleMatches(array $rule, array $p): bool
    {
        $haystacks = $rule['field'] === 'tag' ? $p['tags'] : [(string) ($p[$rule['field']] ?? '')];
        $haystacks = array_map('mb_strtolower', array_filter($haystacks, 'strlen'));
        // comma separated values: the rule matches when any of them is part of the field
        foreach (Settings::splitRuleValue($rule['value']) as $needle) {
            $needle = mb_strtolower($needle);
            foreach ($haystacks as $haystack) {
                if (mb_strpos($haystack, $needle) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return int[]
     */
    private function getGroupIDs(array $p): array
    {
        $ids = [];
        foreach ($this->resolveGroups($p) as $group) {
            if ($group['id'] === null) {
                $created = Group::add($group['name']);
                $this->log(sprintf('  + group %s', $group['name']));
                $group['id'] = (int) $created->getGroupID();
            }
            $ids[] = $group['id'];
        }

        return $ids;
    }

    /**
     * @return float[]
     */
    private function getPrices(array $p): array
    {
        $prices = [];
        foreach ($p['variants'] as $variant) {
            if (is_numeric($variant['price'])) {
                $prices[] = (float) $variant['price'];
            }
        }

        return $prices;
    }

    private function buildProductData(array $p, ?Product $existing, array $fileIDs, array $groupIDs): array
    {
        $variants = $p['variants'];
        $first = $variants[0];
        $prices = $this->getPrices($p);
        $hasOptions = !empty($p['optionNames']);

        // Shopify: "compare at" is the original price, "price" is what the customer pays
        $price = $prices ? min($prices) : 0;
        $salePrice = '';
        if (!$hasOptions && $first['compareAtPrice'] !== '' && (float) $first['compareAtPrice'] > (float) $first['price']) {
            $price = (float) $first['compareAtPrice'];
            $salePrice = (float) $first['price'];
        }

        $data = [
            'pID' => $existing ? $existing->getID() : 0,
            'pDateAdded_dt' => '',
            'pName' => $p['title'],
            'pSKU' => $hasOptions ? '' : $first['sku'],
            'pBarcode' => $hasOptions ? '' : $first['barcode'],
            'pDesc' => $this->excerpt($p['body']),
            'pDetail' => $p['body'],
            'pPrice' => $price,
            'pCostPrice' => $first['cost'],
            'pWholesalePrice' => '',
            'pSalePrice' => $salePrice,
            'pSaleStart_dt' => '',
            'pSaleEnd_dt' => '',
            'pFeatured' => 0,
            'pQty' => array_sum(array_map(static fn ($v) => max(0, $v['qty']), $variants)),
            'pBackOrder' => $first['backorder'] ? 1 : 0,
            'pNoQty' => 0,
            'pTaxClass' => 1,
            'pTaxable' => $first['taxable'] ? 1 : 0,
            'pfID' => $fileIDs[0] ?? 0,
            'pifID' => array_slice($fileIDs, 1),
            'pActive' => $this->isActive($p) ? 1 : 0,
            'pShippable' => $first['shippable'] ? 1 : 0,
            'pWidth' => '',
            'pHeight' => '',
            'pStackedHeight' => '',
            'pLength' => '',
            'pWeight' => $this->convertWeight($first['grams']),
            'pPackageData' => '',
            'pNumberItems' => '',
            'pSeperateShip' => 0,
            'pPriceSuggestions' => '',
            'pPriceMaximum' => '',
            'pPriceMinimum' => '',
            'pAllowDecimalQty' => 0,
            'pQtySteps' => 0,
            'pQtyLabel' => '',
            'pMaxQty' => '',
            'pNotificationEmails' => '',
            'pOrderCompleteCID' => 0,
            'pDateAvailableStart_dt' => '',
            'pDateAvailableEnd_dt' => '',
            'pOutOfStockMessage' => '',
            'pAddToCartText' => '',
            'pManufacturer' => 0,
            'pProductGroups' => $groupIDs,
            'poName' => $hasOptions ? array_values($p['optionNames']) : [],
        ];

        if (!$first['tracked']) {
            $data['pQtyUnlim'] = 1;
        }
        if ($hasOptions) {
            $data['pVariations'] = 1;
        }

        if ($existing) {
            $data['pageCID'] = $existing->getPageID();
        } else {
            $data['createPage'] = 1;
            $data['selectPageTemplate'] = '';
        }

        return $data;
    }

    private function importOptionsAndVariations(array $p, Product $product, array $fileIDs): void
    {
        // option index (1..3) => [value => ProductOptionItem]
        $items = [];
        $sort = 0;
        foreach ($p['optionNames'] as $i => $name) {
            $option = ProductOption::add($product, $name, $sort++, 'select', $this->slug($name), true, true, 'select', '');
            $product->getOptions()->add($option);
            $values = [];
            foreach ($p['variants'] as $variant) {
                $values[(string) $variant['options'][$i]] = true;
            }
            $itemSort = 0;
            foreach (array_keys($values) as $value) {
                $value = (string) $value;
                $item = ProductOptionItem::add($option, $value, $itemSort++, '', 0, 0, false, true);
                $option->getOptionItems()->add($item);
                $items[$i][$value] = $item;
            }
        }
        $this->em->flush();

        ProductVariation::addVariations(['pVariations' => 1], $product);

        $matched = [];
        foreach ($p['variants'] as $variant) {
            $itemIDs = [];
            foreach ($variant['options'] as $i => $value) {
                $itemIDs[] = $items[$i][(string) $value]->getID();
            }
            $variation = ProductVariation::getByOptionItemIDs($itemIDs);
            if (!$variation) {
                $this->warn(t('No variation found for %s.', implode(' / ', $variant['options'])));
                continue;
            }
            $price = $variant['price'];
            $salePrice = '';
            if ($variant['compareAtPrice'] !== '' && (float) $variant['compareAtPrice'] > (float) $price) {
                $salePrice = $price;
                $price = $variant['compareAtPrice'];
            }
            $variation->setVariationSKU($variant['sku']);
            $variation->setVariationBarcode($variant['barcode']);
            $variation->setVariationPrice($price);
            $variation->setVariationSalePrice($salePrice);
            $variation->setVariationCostPrice($variant['cost']);
            $variation->setVariationStockLevel(max(0, $variant['qty']));
            $variation->setVariationIsUnlimited(!$variant['tracked']);
            $variation->setVariationWeight($this->convertWeight($variant['grams']));
            $variation->setVariationFID($variant['image'] !== '' && isset($fileIDs[$variant['image']]) ? $fileIDs[$variant['image']] : null);
            $variation->setVariationDisabled(0);
            $variation->save(true);
            $matched[$variation->getID()] = true;
        }

        // combinations that don't exist in Shopify (e.g. a size only sold in one colour)
        foreach ($product->getVariations() as $variation) {
            if (!isset($matched[$variation->getID()])) {
                $variation->setVariationDisabled(1);
                $variation->save(true);
            }
        }
        $this->em->flush();
    }

    private function importImage(string $url, string $handle, string $alt): ?int
    {
        $key = $this->imageKey($url);
        $knownID = $this->repository->getTargetID(ImportRepository::TYPE_IMAGE, $key);
        $existingFile = $knownID ? $this->em->find(FileEntity::class, $knownID) : null;
        if ($existingFile && !$this->refreshImages) {
            return $knownID;
        }

        $name = $this->safeFileName((string) parse_url($url, PHP_URL_PATH));
        try {
            $contents = $this->download($url);
        } catch (\Throwable $e) {
            $this->warn(t('Image %s was skipped: %s', $name, $e instanceof UserMessageException ? $e->getMessage() : t('the download failed')));

            return null;
        }

        $tmpDir = sys_get_temp_dir() . '/community_store_shopify_import_' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700);
        $tmpFile = $tmpDir . '/' . $name;
        file_put_contents($tmpFile, $contents);
        try {
            $options = app(ImportOptions::class);
            if ($existingFile) {
                $options->setAddNewVersionTo($existingFile);
            } else {
                $options->setImportToFolder($this->getFileFolder()->getChildFolderByName($handle, true));
            }
            $version = $this->fileImporter->importLocalFile($tmpFile, $name, $options);
        } finally {
            @unlink($tmpFile);
            @rmdir($tmpDir);
        }
        if ($alt !== '') {
            $version->updateDescription($alt);
        }
        $fID = (int) $version->getFileID();
        $this->repository->setTargetID(ImportRepository::TYPE_IMAGE, $key, $fID);
        $this->log(sprintf('  %s image %s (#%d)', $existingFile ? '~' : '+', $name, $fID));

        return $fID;
    }

    /**
     * Downloads an image from the Shopify CDN, resized to the configured maximum width.
     */
    private function download(string $url): string
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!in_array($host, self::IMAGE_HOSTS, true)) {
            throw new UserMessageException(t('only images on %s can be imported', implode(', ', self::IMAGE_HOSTS)));
        }
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'width=' . $this->settings->getMaxImageWidth();
        try {
            $validated = (new RemoteUrlValidator())->validate($url, ['https']);
        } catch (InvalidRemoteUrlException $e) {
            throw new UserMessageException(t('invalid image URL'));
        }
        $options = (new RemoteUrlRequestOptionsBuilder())->build($validated) + [RequestOptions::TIMEOUT => 60, RequestOptions::HTTP_ERRORS => false];
        $response = $this->httpClient->send(new Request('GET', (string) $validated), $options);
        if ($response->getStatusCode() !== 200) {
            throw new UserMessageException(t('the server responded with HTTP %s', $response->getStatusCode()));
        }
        $contents = (string) $response->getBody();
        if ($contents === '' || strlen($contents) > self::IMAGE_MAX_BYTES || @getimagesizefromstring($contents) === false) {
            throw new UserMessageException(t('the download is not a valid image'));
        }

        return $contents;
    }

    private function applySeo(array $p, Product $product): void
    {
        if ($p['seoTitle'] === '' && $p['seoDescription'] === '') {
            return;
        }
        $cID = $product->getPageID();
        $page = $cID ? Page::getByID($cID) : null;
        if (!$page || $page->isError()) {
            return;
        }
        if ($p['seoTitle'] !== '') {
            $page->setAttribute('meta_title', $p['seoTitle']);
        }
        if ($p['seoDescription'] !== '') {
            $page->setAttribute('meta_description', $p['seoDescription']);
        }
    }

    private function isActive(array $p): bool
    {
        // "unlisted" and "draft" have no equivalent in Community Store
        return $p['status'] === 'active' && $p['published'];
    }

    private function excerpt(string $html): string
    {
        if (preg_match('/<p[^>]*>.*?<\/p>/is', $html, $m)) {
            return $m[0];
        }

        return $html;
    }

    private function convertWeight(float $grams)
    {
        if ($grams <= 0) {
            return '';
        }
        switch (app('config')->get('community_store.weightUnit')) {
            case 'kg':
                return round($grams / 1_000, 3);
            case 'lb':
                return round($grams / 453.592_37, 3);
            case 'oz':
                return round($grams / 28.349_523_125, 3);
            default:
                return $grams;
        }
    }

    private function safeFileName(string $path): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($path));
        $name = ltrim((string) $name, '.');

        return $name !== '' ? $name : 'image.jpg';
    }

    private function slug(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($value)), '_');
    }

    private function imageKey(string $url): string
    {
        // the ?v= query changes when an image is re-uploaded in Shopify, the path does not
        return md5((string) parse_url($url, PHP_URL_PATH));
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
        $this->log('  ! ' . $message);
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)($message);
        }
    }
}
