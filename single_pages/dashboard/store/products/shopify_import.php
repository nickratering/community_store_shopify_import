<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var Concrete\Core\Page\View\PageView $view
 * @var Concrete\Core\Form\Service\Form $form
 * @var Concrete\Core\Validation\CSRF\Token $token
 * @var Concrete\Core\Error\ErrorList\ErrorList $error
 * @var Concrete\Core\Entity\File\File|null $csvFile
 * @var array|null $plan
 * @var array|null $results
 * @var string[] $warnings
 * @var bool $refreshImages
 * @var string $runID
 */

use CommunityStoreShopifyImport\ImportRepository;
use Concrete\Core\Support\Facade\Url;

$app = Concrete\Core\Support\Facade\Application::getFacadeApplication();
?>

<div class="ccm-dashboard-header-buttons">
    <a href="<?= h(Url::to('/dashboard/store/products/shopify_import/settings')) ?>" class="btn btn-secondary"><?= t('Settings') ?></a>
</div>

<?php if ($results !== null) {
    $imported = count($results[ImportRepository::STATUS_OK]) + count($results[ImportRepository::STATUS_WARNING]); ?>

    <?php if (!$imported && !$results[ImportRepository::STATUS_ERROR]) { ?>
        <div class="alert alert-info"><?= t('No results were found for this import.') ?></div>
    <?php } ?>

    <?php if ($imported) { ?>
        <div class="alert alert-success"><?= t2('%d product was imported.', '%d products were imported.', $imported) ?></div>
    <?php } ?>

    <?php if ($results[ImportRepository::STATUS_ERROR]) { ?>
        <div class="alert alert-danger">
            <strong><?= t2('%d product could not be imported:', '%d products could not be imported:', count($results[ImportRepository::STATUS_ERROR])) ?></strong>
            <ul class="mb-0">
                <?php foreach ($results[ImportRepository::STATUS_ERROR] as $row) { ?>
                    <li><strong><?= h($row['handle']) ?></strong>: <?= h($row['message']) ?></li>
                <?php } ?>
            </ul>
        </div>
    <?php } ?>

    <?php if ($results[ImportRepository::STATUS_WARNING]) { ?>
        <div class="alert alert-warning">
            <strong><?= t('Imported with warnings:') ?></strong>
            <ul class="mb-0">
                <?php foreach ($results[ImportRepository::STATUS_WARNING] as $row) { ?>
                    <li><strong><?= h($row['handle']) ?></strong>: <?= h($row['message']) ?></li>
                <?php } ?>
            </ul>
        </div>
    <?php } ?>

    <?php if ($results[ImportRepository::STATUS_OK]) { ?>
        <details class="mb-4">
            <summary><?= t('Show imported products') ?></summary>
            <ul class="mt-2">
                <?php foreach ($results[ImportRepository::STATUS_OK] as $row) { ?>
                    <li><a href="<?= h(Url::to('/dashboard/store/products/edit', (int) $row['productID'])) ?>"><?= h($row['message']) ?></a></li>
                <?php } ?>
            </ul>
        </details>
    <?php } ?>

    <p>
        <a href="<?= h(Url::to('/dashboard/store/products')) ?>" class="btn btn-primary"><?= t('View products') ?></a>
        <a href="<?= h($view->action('download_redirects')) ?>" class="btn btn-secondary"><?= t('Download redirects (old → new URLs)') ?></a>
        <a href="<?= h($view->action('')) ?>" class="btn btn-link"><?= t('Import another file') ?></a>
    </p>

<?php } elseif ($plan !== null && $csvFile !== null) { ?>

    <p>
        <?= t('File: %s', '<strong>' . h($csvFile->getApprovedVersion()->getFileName()) . '</strong>') ?>
        &middot; <?= t2('%d product found', '%d products found', count($plan)) ?>
    </p>

    <?php if ($error->has()) { ?>
        <div class="alert alert-danger"><?= t('Fix these problems in Shopify (or in the CSV file) and upload it again. Nothing has been imported.') ?></div>
    <?php } ?>

    <?php if ($warnings) { ?>
        <div class="alert alert-warning">
            <strong><?= t('Please check:') ?></strong>
            <ul class="mb-0"><?php foreach ($warnings as $warning) { ?><li><?= h($warning) ?></li><?php } ?></ul>
        </div>
    <?php } ?>

    <div class="ccm-dashboard-content-full">
        <table class="ccm-search-results-table">
            <thead>
            <tr>
                <th><span><?= t('Product') ?></span></th>
                <th><span><?= t('Action') ?></span></th>
                <th><span><?= t('Groups') ?></span></th>
                <th><span><?= t('Options') ?></span></th>
                <th class="text-end"><span><?= t('Variants') ?></span></th>
                <th class="text-end"><span><?= t('Images') ?></span></th>
                <th class="text-end"><span><?= t('Price') ?></span></th>
                <th><span><?= t('Active') ?></span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($plan as $row) { ?>
                <tr>
                    <td><?= h($row['name']) ?><br><small class="text-muted"><?= h($row['handle']) ?></small></td>
                    <td>
                        <?php if ($row['productID']) { ?>
                            <span class="badge bg-info"><?= t('Update') ?></span>
                        <?php } else { ?>
                            <span class="badge bg-success"><?= t('New') ?></span>
                        <?php } ?>
                    </td>
                    <td>
                        <?php foreach ($row['groups'] as $group) { ?>
                            <?= h($group['name']) ?><?= $group['id'] === null ? ' <small class="text-muted">(' . t('new') . ')</small>' : '' ?><br>
                        <?php } ?>
                    </td>
                    <td><?= h($row['options'] ? implode(' × ', $row['options']) : '–') ?></td>
                    <td class="text-end"><?= $row['variants'] ? (int) $row['variants'] : '–' ?></td>
                    <td class="text-end"><?= (int) $row['images'] ?></td>
                    <td class="text-end text-nowrap"><?= h($row['price']) ?></td>
                    <td><?= $row['active'] ? t('Yes') : '<span class="text-warning">' . t('No') . '</span>' ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>

    <form id="shopify-import-run" method="post" action="<?= h($view->action('import')) ?>">
        <div class="ccm-dashboard-form-actions-wrapper">
            <div class="ccm-dashboard-form-actions">
                <a href="<?= h($view->action('')) ?>" class="btn btn-secondary float-start"><?= t('Cancel') ?></a>
                <?php if (!$error->has()) { ?>
                    <button type="submit" class="btn btn-primary float-end"><?= t2('Import %d product', 'Import %d products', count($plan)) ?></button>
                <?php } ?>
            </div>
        </div>
    </form>

    <?php if (!$error->has()) { ?>
        <script>
            $(function () {
                $('#shopify-import-run').on('submit', function (e) {
                    e.preventDefault();
                    new ConcreteProgressiveOperation({
                        url: <?= json_encode((string) $view->action('import')) ?>,
                        data: [
                            {name: 'ccm_token', value: <?= json_encode($token->generate('shopify_import_run')) ?>},
                            {name: 'csvFID', value: <?= (int) $csvFile->getFileID() ?>},
                            {name: 'runID', value: <?= json_encode($runID) ?>},
                            {name: 'refreshImages', value: <?= $refreshImages ? 1 : 0 ?>}
                        ],
                        title: <?= json_encode(t('Importing products')) ?>,
                        onComplete: function () {
                            window.location.href = <?= json_encode((string) $view->action('completed', $runID)) ?>;
                        }
                    });
                });
            });
        </script>
    <?php } ?>

<?php } else { ?>

    <form method="post" action="<?= h($view->action('check')) ?>" enctype="multipart/form-data">
        <?= $token->output('shopify_import_check') ?>

        <p>
            <?= t('Import products from Shopify into the store. In Shopify, go to %s and choose %s.', '<strong>' . t('Products › Export') . '</strong>', '<strong>' . t('All products · Plain CSV file') . '</strong>') ?>
            <?= t('Products that were imported before are updated, not duplicated.') ?>
        </p>
        <p>
            <?= t('Product groups and the image size can be configured in the %s.', '<a href="' . h(Url::to('/dashboard/store/products/shopify_import/settings')) . '">' . t('settings') . '</a>') ?>
        </p>

        <div class="form-group mb-3">
            <?= $form->label('csvUpload', t('Upload CSV file')) ?>
            <?= $form->file('csvUpload', ['accept' => '.csv,text/csv']) ?>
        </div>

        <div class="form-group mb-3">
            <?= $form->label('csvFID', t('Or choose a CSV file from the File Manager')) ?>
            <?= $app->make('helper/concrete/file_manager')->file('shopify-csv', 'csvFID', t('Choose file')) ?>
        </div>

        <div class="form-check mb-3">
            <?= $form->checkbox('refreshImages', 1, false) ?>
            <?= $form->label('refreshImages', t('Download images that were imported before again'), ['class' => 'form-check-label']) ?>
        </div>

        <div class="ccm-dashboard-form-actions-wrapper">
            <div class="ccm-dashboard-form-actions">
                <button type="submit" class="btn btn-primary float-end"><?= t('Check file') ?></button>
            </div>
        </div>
    </form>

<?php } ?>
