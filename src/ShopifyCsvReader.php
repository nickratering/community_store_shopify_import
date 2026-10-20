<?php

declare(strict_types=1);

namespace CommunityStoreShopifyImport;

use Concrete\Core\Error\UserMessageException;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Turns a Shopify products CSV export (one row per variant / image) into one array per product.
 */
class ShopifyCsvReader
{
    public const REQUIRED_COLUMNS = [
        'Handle', 'Title', 'Body (HTML)', 'Type', 'Published', 'Status',
        'Option1 Name', 'Option1 Value', 'Option2 Name', 'Option2 Value', 'Option3 Name', 'Option3 Value',
        'Variant SKU', 'Variant Grams', 'Variant Inventory Tracker', 'Variant Inventory Qty', 'Variant Inventory Policy',
        'Variant Price', 'Variant Compare At Price', 'Variant Requires Shipping', 'Variant Taxable',
        'Image Src', 'Image Position', 'Image Alt Text', 'Variant Image',
    ];

    /**
     * @return array<string, array> keyed by Shopify handle
     */
    public function read(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new UserMessageException(t('Unable to open %s', $path));
        }

        try {
            return $this->readStream($fh);
        } finally {
            fclose($fh);
        }
    }

    public function readString(string $contents): array
    {
        $fh = fopen('php://temp', 'w+b');
        fwrite($fh, $contents);
        rewind($fh);

        try {
            return $this->readStream($fh);
        } finally {
            fclose($fh);
        }
    }

    /**
     * @param resource $fh
     *
     * @return array<string, array> keyed by Shopify handle
     */
    public function readStream($fh): array
    {
        $header = fgetcsv($fh, 0, ',', '"', '');
        if (!$header) {
            throw new UserMessageException(t('The CSV file is empty.'));
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        if (!in_array('Handle', $header, true)) {
            throw new UserMessageException(t('This does not look like a Shopify products export: the "Handle" column is missing.'));
        }
        $missing = array_diff(self::REQUIRED_COLUMNS, $header);
        if ($missing) {
            throw new UserMessageException(t('These columns are missing from the CSV: %s', implode(', ', $missing)));
        }

        $products = [];
        while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $row = array_combine($header, array_pad($cells, count($header), ''));
            $handle = trim($row['Handle']);
            if ($handle === '') {
                continue;
            }
            if (!isset($products[$handle])) {
                $products[$handle] = $this->createProduct($row);
            }
            $this->addRow($products[$handle], $row);
        }

        foreach ($products as &$product) {
            ksort($product['images']);
            $product['images'] = array_values(array_unique($product['images']));
        }

        return $products;
    }

    private function createProduct(array $row): array
    {
        $optionNames = [];
        for ($i = 1; $i <= 3; $i++) {
            $name = trim($row["Option{$i} Name"] ?? '');
            if ($name !== '') {
                $optionNames[$i] = $name;
            }
        }
        // Shopify uses a fake "Title" option for products without variants
        if (array_values($optionNames) === ['Title'] && trim($row['Option1 Value']) === 'Default Title') {
            $optionNames = [];
        }

        return [
            'handle' => trim($row['Handle']),
            'title' => trim($row['Title']),
            'body' => trim($row['Body (HTML)']),
            'vendor' => trim($row['Vendor']),
            'category' => trim($row['Product Category'] ?? ''),
            'type' => trim($row['Type']),
            'tags' => array_filter(array_map('trim', explode(',', $row['Tags']))),
            'published' => strtolower(trim($row['Published'])) === 'true',
            'status' => strtolower(trim($row['Status'] ?? 'active')),
            'giftCard' => strtolower(trim($row['Gift Card'] ?? '')) === 'true',
            'seoTitle' => trim($row['SEO Title'] ?? ''),
            'seoDescription' => trim($row['SEO Description'] ?? ''),
            'optionNames' => $optionNames,
            'variants' => [],
            'images' => [],
            'imageAlts' => [],
        ];
    }

    private function addRow(array &$product, array $row): void
    {
        $src = trim($row['Image Src']);
        if ($src !== '') {
            $pos = (int) $row['Image Position'] ?: (count($product['images']) + 1_000);
            $product['images'][$pos] = $src;
            $product['imageAlts'][$src] = trim($row['Image Alt Text']);
        }

        // image-only rows have no price and no option values
        if (trim($row['Variant Price']) === '' && trim($row['Option1 Value']) === '') {
            return;
        }

        $values = [];
        foreach (array_keys($product['optionNames']) as $i) {
            $values[$i] = trim($row["Option{$i} Value"]);
        }
        $barcodes = preg_split('/[;,]/', $row['Variant Barcodes'] ?? ($row['Variant Barcode'] ?? ''));

        $variantImage = trim($row['Variant Image']);
        if ($variantImage !== '' && !in_array($variantImage, $product['images'], true)) {
            $product['images'][100_000 + count($product['images'])] = $variantImage;
        }

        $product['variants'][] = [
            'options' => $values,
            'sku' => trim($row['Variant SKU']),
            'barcode' => trim((string) ($barcodes[0] ?? '')),
            'grams' => (float) $row['Variant Grams'],
            'tracked' => trim($row['Variant Inventory Tracker']) !== '',
            'qty' => (int) $row['Variant Inventory Qty'],
            'backorder' => strtolower(trim($row['Variant Inventory Policy'])) === 'continue',
            'price' => trim($row['Variant Price']),
            'compareAtPrice' => trim($row['Variant Compare At Price']),
            'cost' => trim($row['Cost per item'] ?? ''),
            'shippable' => strtolower(trim($row['Variant Requires Shipping'])) === 'true',
            'taxable' => strtolower(trim($row['Variant Taxable'])) === 'true',
            'image' => $variantImage,
        ];
    }
}
