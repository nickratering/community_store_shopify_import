<?php

declare(strict_types=1);

namespace CommunityStoreShopifyImport;

use Concrete\Core\Config\Repository\Liaison;
use Concrete\Core\Package\PackageService;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Import settings, stored in the package file configuration.
 */
class Settings
{
    public const FIELDS = ['title', 'handle', 'type', 'category', 'vendor', 'tag'];

    public const FALLBACK_NONE = 'none';

    public const FALLBACK_TYPE = 'type';

    public const FALLBACK_GROUP = 'group';

    public const DEFAULT_MAX_IMAGE_WIDTH = 2_400;

    /**
     * @var Liaison
     */
    private $config;

    public function __construct(PackageService $packageService)
    {
        $this->config = $packageService->getClass('community_store_shopify_import')->getFileConfig();
    }

    /**
     * @return array<int, array{field: string, value: string, groupID: int}>
     */
    public function getRules(): array
    {
        $rules = [];
        foreach ((array) $this->config->get('mapping.rules', []) as $rule) {
            $rule = $this->normalizeRule($rule);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    public function getFallbackMode(): string
    {
        $mode = (string) $this->config->get('mapping.fallback', self::FALLBACK_TYPE);

        return in_array($mode, [self::FALLBACK_NONE, self::FALLBACK_TYPE, self::FALLBACK_GROUP], true) ? $mode : self::FALLBACK_TYPE;
    }

    public function getFallbackGroupID(): int
    {
        return (int) $this->config->get('mapping.fallback_group', 0);
    }

    public function getMaxImageWidth(): int
    {
        $width = (int) $this->config->get('import.max_image_width', self::DEFAULT_MAX_IMAGE_WIDTH);

        return $width > 0 ? $width : self::DEFAULT_MAX_IMAGE_WIDTH;
    }

    public function save(array $rules, string $fallbackMode, int $fallbackGroupID, int $maxImageWidth): void
    {
        $clean = [];
        foreach ($rules as $rule) {
            $rule = $this->normalizeRule($rule);
            if ($rule !== null) {
                $clean[] = $rule;
            }
        }
        $this->config->save('mapping.rules', $clean);
        $this->config->save('mapping.fallback', in_array($fallbackMode, [self::FALLBACK_NONE, self::FALLBACK_TYPE, self::FALLBACK_GROUP], true) ? $fallbackMode : self::FALLBACK_TYPE);
        $this->config->save('mapping.fallback_group', $fallbackGroupID);
        $this->config->save('import.max_image_width', $maxImageWidth > 0 ? $maxImageWidth : self::DEFAULT_MAX_IMAGE_WIDTH);
    }

    /**
     * @return string[]
     */
    public static function splitRuleValue(string $value): array
    {
        $values = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            // matching is case insensitive, so "Hoodie" and "hoodie" are the same value
            if ($item !== '' && !isset($values[mb_strtolower($item)])) {
                $values[mb_strtolower($item)] = $item;
            }
        }

        return array_values($values);
    }

    private function normalizeRule($rule): ?array
    {
        if (!is_array($rule)) {
            return null;
        }
        $field = (string) ($rule['field'] ?? '');
        $value = implode(', ', self::splitRuleValue((string) ($rule['value'] ?? '')));
        $groupID = (int) ($rule['groupID'] ?? 0);
        if (!in_array($field, self::FIELDS, true) || $value === '' || $groupID <= 0) {
            return null;
        }

        return ['field' => $field, 'value' => $value, 'groupID' => $groupID];
    }
}
