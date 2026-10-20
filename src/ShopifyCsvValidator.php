<?php

declare(strict_types=1);

namespace CommunityStoreShopifyImport;

use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\Page\Page;
use Concrete\Package\CommunityStore\Src\CommunityStore\Group\Group;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Checks parsed Shopify products before importing them.
 *
 * Errors block the import of the whole file, warnings are only shown.
 */
class ShopifyCsvValidator
{
    /**
     * @var ProductImporter
     */
    private $importer;

    /**
     * @var Settings
     */
    private $settings;

    /**
     * @var Repository
     */
    private $config;

    public function __construct(ProductImporter $importer, Settings $settings, Repository $config)
    {
        $this->importer = $importer;
        $this->settings = $settings;
        $this->config = $config;
    }

    /**
     * @return array{errors: string[], warnings: string[]}
     */
    public function validate(array $products): array
    {
        $errors = [];
        $warnings = [];

        if (!$products) {
            $errors[] = t('The file does not contain any products.');
        }

        $targetCID = (int) $this->config->get('community_store.productPublishTarget');
        $target = $targetCID ? Page::getByID($targetCID) : null;
        if (!$target || $target->isError()) {
            $warnings[] = t('No parent page for product pages is set in Store › Settings › Products: products will be imported without a product page.');
        }
        foreach ($this->settings->getRules() as $rule) {
            if (!Group::getByID($rule['groupID'])) {
                $warnings[] = t('A group mapping rule refers to a product group that no longer exists. Check the import settings.');
                break;
            }
        }

        $newGroups = [];
        $withoutGroup = 0;
        foreach ($products as $handle => $p) {
            $label = $p['title'] !== '' ? sprintf('%s (%s)', $p['title'], $handle) : $handle;

            if ($p['title'] === '') {
                $errors[] = t('%s: the product has no title.', $handle);
            }
            if (!$p['variants']) {
                $errors[] = t('%s: the product has no variants or price.', $label);
                continue;
            }
            if ($p['giftCard']) {
                $errors[] = t('%s: gift cards are not supported by Community Store.', $label);
            }

            $combinations = [];
            foreach ($p['variants'] as $variant) {
                if (!is_numeric($variant['price']) || (float) $variant['price'] < 0) {
                    $errors[] = t('%s: invalid price "%s".', $label, $variant['price']);
                }
                foreach ($variant['options'] as $i => $value) {
                    if ($value === '') {
                        $errors[] = t('%s: a variant has no value for option "%s".', $label, $p['optionNames'][$i]);
                    }
                }
                $key = implode("\0", $variant['options']);
                if (isset($combinations[$key])) {
                    $errors[] = t('%s: the variant "%s" appears more than once.', $label, implode(' / ', $variant['options']));
                }
                $combinations[$key] = true;
            }

            if (count($p['variants']) === 1 && is_numeric($p['variants'][0]['price']) && (float) $p['variants'][0]['price'] === 0.0) {
                $warnings[] = t('%s: the price is 0.', $label);
            }
            if ($p['status'] !== 'active' || !$p['published']) {
                $warnings[] = t('%s: the product is "%s" in Shopify and will be imported as inactive.', $label, $p['published'] ? $p['status'] : t('not published'));
            }
            if (!$p['images']) {
                $warnings[] = t('%s: the product has no images.', $label);
            }

            $groups = $this->importer->resolveGroups($p);
            if (!$groups) {
                $withoutGroup++;
            }
            foreach ($groups as $group) {
                if ($group['id'] === null) {
                    $newGroups[$group['name']] = true;
                }
            }
        }

        if ($newGroups) {
            $warnings[] = t('These product groups will be created: %s', implode(', ', array_keys($newGroups)));
        }
        if ($withoutGroup) {
            $warnings[] = t2('%d product will not be added to a product group.', '%d products will not be added to a product group.', $withoutGroup);
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }
}
