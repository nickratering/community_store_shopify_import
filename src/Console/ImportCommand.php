<?php

declare(strict_types=1);

namespace CommunityStoreShopifyImport\Console;

use CommunityStoreShopifyImport\ImportRepository;
use CommunityStoreShopifyImport\ProductImporter;
use CommunityStoreShopifyImport\ShopifyCsvReader;
use CommunityStoreShopifyImport\ShopifyCsvValidator;
use Concrete\Core\Error\UserMessageException;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

defined('C5_EXECUTE') or die('Access Denied.');

class ImportCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('cstore:shopify:import')
            ->setDescription('Import a Shopify products CSV export into Community Store')
            ->addArgument('csv', InputArgument::REQUIRED, 'Path to the Shopify products_export.csv')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only check the file and show what would be imported')
            ->addOption('only', 'o', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only import these Shopify handle(s)')
            ->addOption('skip-images', null, InputOption::VALUE_NONE, 'Do not download images')
            ->addOption('refresh-images', null, InputOption::VALUE_NONE, 'Download already imported images again (as a new file version)')
            ->addOption('redirects', 'r', InputOption::VALUE_REQUIRED, 'Write the old URL → new URL CSV to this file')
            ->setHelp('Products are matched on their Shopify handle, so the command can be run again to update them. Group mapping is configured in Dashboard › Store › Products › Shopify Import › Settings.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $products = (new ShopifyCsvReader())->read($input->getArgument('csv'));
        } catch (UserMessageException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }
        $only = $input->getOption('only');
        if ($only) {
            $products = array_intersect_key($products, array_flip($only));
        }

        $result = app(ShopifyCsvValidator::class)->validate($products);
        foreach ($result['warnings'] as $warning) {
            $output->writeln('<comment>Warning: ' . $warning . '</comment>');
        }
        foreach ($result['errors'] as $error) {
            $output->writeln('<error>Error: ' . $error . '</error>');
        }

        /** @var ProductImporter $importer */
        $importer = app(ProductImporter::class);
        $importer->setRefreshImages((bool) $input->getOption('refresh-images'));
        $importer->setLogger(static function ($message) use ($output) {
            $output->writeln($message);
        });

        if ($input->getOption('dry-run')) {
            $this->renderPlan($output, $importer, $products);

            return $result['errors'] ? 1 : 0;
        }
        if ($result['errors']) {
            $output->writeln('Nothing was imported.');

            return 1;
        }

        $failed = 0;
        foreach ($products as $handle => $p) {
            $output->writeln(sprintf('<info>%s</info> (%s)', $p['title'], $handle));
            try {
                $product = $importer->import($p, !$input->getOption('skip-images'));
                $output->writeln(sprintf('  = product #%d %s', $product->getID(), $importer->getProductPath($product)));
            } catch (\Throwable $e) {
                $failed++;
                $output->writeln(sprintf('  <error>%s</error>', $e->getMessage()));
                if ($output->isVerbose()) {
                    $output->writeln($e->getTraceAsString());
                }
            }
        }

        if ($input->getOption('redirects')) {
            $this->writeRedirects($input->getOption('redirects'), $importer);
            $output->writeln(sprintf('Redirects written to %s', $input->getOption('redirects')));
        }
        $output->writeln(sprintf('Imported %d product(s), %d failed.', count($products) - $failed, $failed));

        return $failed ? 1 : 0;
    }

    private function renderPlan(OutputInterface $output, ProductImporter $importer, array $products): void
    {
        $table = new Table($output);
        $table->setHeaders(['Handle', 'Action', 'Groups', 'Active', 'Options', 'Variants', 'Images', 'Price']);
        foreach ($products as $p) {
            $plan = $importer->plan($p);
            $table->addRow([
                $plan['handle'],
                $plan['productID'] ? 'update #' . $plan['productID'] : 'create',
                implode(', ', array_map(static fn ($g) => $g['name'] . ($g['id'] === null ? ' (new)' : ''), $plan['groups'])),
                $plan['active'] ? 'yes' : 'no',
                implode(' × ', $plan['options']),
                $plan['variants'],
                $plan['images'],
                $plan['price'],
            ]);
        }
        $table->render();
        $output->writeln(sprintf('%d product(s). Nothing was written (dry run).', count($products)));
    }

    private function writeRedirects(string $path, ProductImporter $importer): void
    {
        $fh = fopen($path, 'wb');
        fputcsv($fh, ['old_url', 'new_url'], ',', '"', '');
        foreach (app(ImportRepository::class)->getProductMap() as $handle => $pID) {
            $product = Product::getByID($pID);
            if ($product) {
                fputcsv($fh, ['/products/' . $handle, $importer->getProductPath($product)], ',', '"', '');
            }
        }
        fclose($fh);
    }
}
