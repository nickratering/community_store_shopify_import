<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var Concrete\Core\Page\View\PageView $view
 * @var Concrete\Core\Form\Service\Form $form
 * @var Concrete\Core\Validation\CSRF\Token $token
 * @var array<int, string> $groups
 * @var array<string, string> $fields
 * @var array $rules
 * @var string $fallbackMode
 * @var int $fallbackGroupID
 * @var int $maxImageWidth
 */

use CommunityStoreShopifyImport\Settings;
use Concrete\Core\Support\Facade\Url;

$groupOptions = ['' => t('Choose a group')] + $groups;
$fieldOptions = $fields;
// an empty row is always shown, so a rule can be added without JavaScript
$rows = array_merge($rules, [['field' => 'type', 'value' => '', 'groupID' => 0]]);
?>

<form method="post" action="<?= h($view->action('save')) ?>">
    <?= $token->output('shopify_import_settings') ?>

    <fieldset class="mb-4">
        <legend><?= t('Product groups') ?></legend>
        <p class="text-muted">
            <?= t('A product is added to the group of every rule it matches. A rule matches when the field contains one of its values, also as part of a word: "shirt" matches "T-shirt". Separate multiple values with commas. Matching is not case sensitive.') ?>
            <?php if (!$groups) { ?>
                <?= t('There are no product groups yet: create them in %s.', '<a href="' . h(Url::to('/dashboard/store/products/groups')) . '">' . t('Products › Groups') . '</a>') ?>
            <?php } ?>
        </p>

        <table class="table" id="shopify-import-rules">
            <thead>
            <tr>
                <th><?= t('When the Shopify product…') ?></th>
                <th><?= t('Value') ?></th>
                <th><?= t('Add to group') ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $rule) { ?>
                <tr>
                    <td><?= $form->select('ruleField[]', $fieldOptions, $rule['field']) ?></td>
                    <td><?= $form->text('ruleValue[]', $rule['value'], ['placeholder' => t('e.g. shirt, jacket')]) ?></td>
                    <td><?= $form->select('ruleGroup[]', $groupOptions, $rule['groupID'] ?: '') ?></td>
                    <td class="text-end">
                        <button type="button" class="btn btn-outline-danger btn-sm" data-shopify-import="remove-rule" title="<?= h(t('Remove')) ?>">&times;</button>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-shopify-import="add-rule"><?= t('Add rule') ?></button>

        <div class="form-group mt-4">
            <?= $form->label('fallbackMode', t('Products that match no rule')) ?>
            <div class="form-check">
                <?= $form->radio('fallbackMode', Settings::FALLBACK_TYPE, $fallbackMode, ['id' => 'fallbackModeType']) ?>
                <label class="form-check-label" for="fallbackModeType"><?= t('Add to a group named after the Shopify product type (created when missing)') ?></label>
            </div>
            <div class="form-check">
                <?= $form->radio('fallbackMode', Settings::FALLBACK_GROUP, $fallbackMode, ['id' => 'fallbackModeGroup']) ?>
                <label class="form-check-label" for="fallbackModeGroup"><?= t('Add to this group:') ?></label>
                <?= $form->select('fallbackGroupID', $groupOptions, $fallbackGroupID ?: '', ['class' => 'form-select-sm d-inline-block w-auto ms-2']) ?>
            </div>
            <div class="form-check">
                <?= $form->radio('fallbackMode', Settings::FALLBACK_NONE, $fallbackMode, ['id' => 'fallbackModeNone']) ?>
                <label class="form-check-label" for="fallbackModeNone"><?= t('Do not add to a group') ?></label>
            </div>
        </div>
    </fieldset>

    <fieldset class="mb-4">
        <legend><?= t('Images') ?></legend>
        <div class="form-group">
            <?= $form->label('maxImageWidth', t('Maximum image width (pixels)')) ?>
            <?= $form->number('maxImageWidth', $maxImageWidth, ['min' => 100, 'max' => 10_000, 'step' => 1]) ?>
            <div class="form-text"><?= t('Shopify delivers the images resized to this width. Very large images can make product pages run out of memory.') ?></div>
        </div>
    </fieldset>

    <div class="ccm-dashboard-form-actions-wrapper">
        <div class="ccm-dashboard-form-actions">
            <a href="<?= h(Url::to('/dashboard/store/products/shopify_import')) ?>" class="btn btn-secondary float-start"><?= t('Back to import') ?></a>
            <button type="submit" class="btn btn-primary float-end"><?= t('Save') ?></button>
        </div>
    </div>
</form>

<script>
    $(function () {
        var $body = $('#shopify-import-rules tbody');
        $('[data-shopify-import="add-rule"]').on('click', function () {
            var $row = $body.find('tr').last().clone();
            $row.find('input').val('');
            $row.find('select[name="ruleGroup[]"]').val('');
            $body.append($row);
        });
        $body.on('click', '[data-shopify-import="remove-rule"]', function () {
            var $row = $(this).closest('tr');
            if ($body.find('tr').length > 1) {
                $row.remove();
            } else {
                $row.find('input').val('');
                $row.find('select[name="ruleGroup[]"]').val('');
            }
        });
    });
</script>
