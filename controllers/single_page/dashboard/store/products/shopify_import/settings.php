<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreShopifyImport\Controller\SinglePage\Dashboard\Store\Products\ShopifyImport;

use CommunityStoreShopifyImport\Settings as ImportSettings;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Package\CommunityStore\Src\CommunityStore\Group\GroupList;

defined('C5_EXECUTE') or die('Access Denied.');

class Settings extends DashboardPageController
{
    public function view()
    {
        $settings = $this->app->make(ImportSettings::class);
        $groups = [];
        foreach (GroupList::getGroupList() as $group) {
            $groups[(int) $group->getGroupID()] = $group->getGroupName();
        }

        $this->set('groups', $groups);
        $this->set('fields', [
            'title' => t('Title contains any of'),
            'handle' => t('Handle (URL name) contains any of'),
            'type' => t('Product type contains any of'),
            'category' => t('Product category contains any of'),
            'vendor' => t('Vendor contains any of'),
            'tag' => t('A tag contains any of'),
        ]);
        $this->set('rules', $settings->getRules());
        $this->set('fallbackMode', $settings->getFallbackMode());
        $this->set('fallbackGroupID', $settings->getFallbackGroupID());
        $this->set('maxImageWidth', $settings->getMaxImageWidth());
    }

    public function save()
    {
        if (!$this->token->validate('shopify_import_settings')) {
            $this->error->add($this->token->getErrorMessage());
        }
        $post = $this->request->request;
        $fields = (array) ($post->all()['ruleField'] ?? []);
        $values = (array) ($post->all()['ruleValue'] ?? []);
        $groupIDs = (array) ($post->all()['ruleGroup'] ?? []);
        $maxImageWidth = (int) $post->get('maxImageWidth');
        if ($maxImageWidth < 100 || $maxImageWidth > 10_000) {
            $this->error->add(t('The maximum image width must be between 100 and 10000 pixels.'));
        }

        if ($this->error->has()) {
            $this->view();

            return;
        }

        $rules = [];
        foreach ($fields as $i => $field) {
            $rules[] = [
                'field' => (string) $field,
                'value' => (string) ($values[$i] ?? ''),
                'groupID' => (int) ($groupIDs[$i] ?? 0),
            ];
        }
        $this->app->make(ImportSettings::class)->save(
            $rules,
            (string) $post->get('fallbackMode'),
            (int) $post->get('fallbackGroupID'),
            $maxImageWidth
        );
        $this->flash('success', t('Settings saved.'));

        return $this->buildRedirect($this->action(''));
    }
}
