<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreShopifyImport;

use CommunityStoreShopifyImport\Console\ImportCommand;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;

defined('C5_EXECUTE') or die('Access Denied.');

class Controller extends Package
{
    /**
     * The URL validation used for downloading images was added in 9.5.1.
     *
     * @var string
     */
    protected $appVersionRequired = '9.5.1';

    /**
     * @var string
     */
    protected $phpVersionRequired = '7.4';

    /**
     * @var string
     */
    protected $pkgHandle = 'community_store_shopify_import';

    /**
     * @var string
     */
    protected $pkgVersion = '1.0.0';

    /**
     * @var array
     */
    protected $packageDependencies = ['community_store' => true];

    /**
     * @var array
     */
    protected $pkgAutoloaderRegistries = [
        'src' => '\CommunityStoreShopifyImport',
    ];

    /**
     * @return string
     */
    public function getPackageName()
    {
        return t('Shopify Import for Community Store');
    }

    /**
     * @return string
     */
    public function getPackageDescription()
    {
        return t('Import products, variants and images from a Shopify CSV export into Community Store.');
    }

    /**
     * @return \Concrete\Core\Entity\Package
     */
    public function install()
    {
        $pkg = parent::install();
        $this->installPages($pkg);

        return $pkg;
    }

    public function upgrade()
    {
        parent::upgrade();
        $this->installPages($this->getPackageEntity());
    }

    public function uninstall()
    {
        parent::uninstall();

        $db = $this->app->make(Connection::class);
        foreach (['CommunityStoreShopifyImportResults', 'CommunityStoreShopifyImportItems'] as $table) {
            if ($db->tableExists($table)) {
                $db->executeStatement('DROP TABLE ' . $db->quoteIdentifier($table));
            }
        }
    }

    public function on_start()
    {
        if ($this->app->isRunThroughCommandLineInterface()) {
            $this->app->make('console')->add(new ImportCommand());
        }
    }

    private function installPages($pkg): void
    {
        $pages = [
            '/dashboard/store/products/shopify_import' => [t('Shopify Import'), t('Import products from a Shopify CSV export.')],
            '/dashboard/store/products/shopify_import/settings' => [t('Settings'), t('Product group mapping and image settings for the Shopify import.')],
        ];
        foreach ($pages as $path => [$name, $description]) {
            $page = Page::getByPath($path);
            if (!is_object($page) || $page->isError()) {
                $page = SinglePage::add($path, $pkg);
            }
            if (is_object($page) && !$page->isError()) {
                $page->update(['cName' => $name, 'cDescription' => $description]);
            }
        }
    }
}
