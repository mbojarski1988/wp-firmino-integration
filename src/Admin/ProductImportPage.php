<?php

declare(strict_types=1);

namespace FirminoIntegration\Admin;

use FirminoIntegration\Services\ProductImportService;
use FirminoIntegration\ValueObjects\ImportOptions;
use FirminoIntegration\ValueObjects\ImportResult;
use FirminoIntegration\ValueObjects\Settings;

final class ProductImportPage
{
    public function __construct(
        private readonly ProductImportService $importService,
        private readonly Settings $settings,
    ) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_firmino_import_products', [$this, 'handleImport']);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Import produktów Firmino', 'firmino-integration'),
            __('Import Firmino', 'firmino-integration'),
            'manage_woocommerce',
            'firmino-import',
            [$this, 'renderPage'],
        );
    }

    public function renderPage(): void
    {
        if ( ! current_user_can('manage_woocommerce') ) {
            wp_die(esc_html__('Brak dostępu.', 'firmino-integration'));
        }

        $userId    = get_current_user_id();
        $autoState = get_transient('firmino_import_auto_' . $userId);
        $isAuto    = isset($_GET['auto']) && $autoState;

        $notice = get_transient('firmino_import_notice');
        if ( $notice ) {
            delete_transient('firmino_import_notice');
            $class = $notice['success'] ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($notice['message']) . '</p></div>';
        }

        $vatDisplay = $this->settings->defaultVatRate !== ''
            ? $this->settings->defaultVatRate
            : __('(nie ustawiono)', 'firmino-integration');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Import produktów Firmino', 'firmino-integration'); ?></h1>
            <p><?php esc_html_e('Importuj produkty WooCommerce do artykułów Firmino.', 'firmino-integration'); ?></p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <?php wp_nonce_field('firmino_import_products'); ?>
                <input type="hidden" name="action" value="firmino_import_products">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="firmino_limit"><?php esc_html_e('Rozmiar partii', 'firmino-integration'); ?></label></th>
                        <td>
                            <input type="number" min="1" max="200" id="firmino_limit" name="limit" value="20">
                            <p class="description"><?php esc_html_e('Limit API Firmino to 500 zapytań dziennie. Zacznij od małej wartości.', 'firmino-integration'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="firmino_offset"><?php esc_html_e('Przesunięcie', 'firmino-integration'); ?></label></th>
                        <td><input type="number" min="0" id="firmino_offset" name="offset" value="0"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Kod produktu', 'firmino-integration'); ?></th>
                        <td>
                            <select name="code_source">
                                <option value="sku"><?php esc_html_e('Użyj SKU (w razie braku ID)', 'firmino-integration'); ?></option>
                                <option value="id"><?php esc_html_e('Użyj ID produktu', 'firmino-integration'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Uwzględnij warianty', 'firmino-integration'); ?></th>
                        <td><label><input type="checkbox" name="include_variations" value="1"> <?php esc_html_e('Importuj warianty produktów (pomiń rodziców zmiennych).', 'firmino-integration'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Aktualizuj istniejące', 'firmino-integration'); ?></th>
                        <td><label><input type="checkbox" name="update_existing" value="1"> <?php esc_html_e('Aktualizuj produkty, które mają już ID Firmino.', 'firmino-integration'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Wymuś import', 'firmino-integration'); ?></th>
                        <td><label><input type="checkbox" name="force_import" value="1"> <?php esc_html_e('Zawsze twórz nowy artykuł w Firmino.', 'firmino-integration'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Automatyczny import wszystkiego', 'firmino-integration'); ?></th>
                        <td><label><input type="checkbox" name="auto" value="1"> <?php esc_html_e('Kontynuuj partiami aż do zakończenia.', 'firmino-integration'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Domyślny VAT', 'firmino-integration'); ?></th>
                        <td>
                            <p class="description">
                                <?php printf(
                                    /* translators: %s: VAT value */
                                    esc_html__('Aktualna domyślna stawka VAT: %s', 'firmino-integration'),
                                    esc_html($vatDisplay),
                                ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Rozpocznij import', 'firmino-integration')); ?>
            </form>
        </div>
        <?php if ( $isAuto && is_array($autoState) ) : ?>
            <form id="firmino-auto-import" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:none;">
                <?php wp_nonce_field('firmino_import_products'); ?>
                <input type="hidden" name="action" value="firmino_import_products">
                <input type="hidden" name="limit" value="<?php echo esc_attr((string) $autoState['limit']); ?>">
                <input type="hidden" name="offset" value="<?php echo esc_attr((string) $autoState['offset']); ?>">
                <input type="hidden" name="code_source" value="<?php echo esc_attr((string) $autoState['code_source']); ?>">
                <input type="hidden" name="include_variations" value="<?php echo esc_attr((string) $autoState['include_variations']); ?>">
                <input type="hidden" name="update_existing" value="<?php echo esc_attr((string) $autoState['update_existing']); ?>">
                <input type="hidden" name="force_import" value="<?php echo esc_attr((string) $autoState['force_import']); ?>">
                <input type="hidden" name="auto" value="1">
            </form>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var form = document.getElementById('firmino-auto-import');
                    if (form) { form.submit(); }
                });
            </script>
        <?php endif; ?>
        <?php
    }

    public function handleImport(): void
    {
        if ( ! current_user_can('manage_woocommerce') ) {
            wp_die(esc_html__('Brak dostępu.', 'firmino-integration'));
        }

        check_admin_referer('firmino_import_products');

        $options = new ImportOptions(
            limit:             max(1, absint(wp_unslash($_POST['limit'] ?? 20))),
            offset:            absint(wp_unslash($_POST['offset'] ?? 0)),
            codeSource:        sanitize_text_field(wp_unslash($_POST['code_source'] ?? 'sku')),
            includeVariations: ! empty($_POST['include_variations']),
            updateExisting:    ! empty($_POST['update_existing']),
            forceImport:       ! empty($_POST['force_import']),
        );

        $isAuto  = ! empty($_POST['auto']);
        $userId  = get_current_user_id();
        $aggKey  = 'firmino_import_aggregate_' . $userId;
        $autoKey = 'firmino_import_auto_' . $userId;

        $result    = $this->importService->importBatch($options);
        $aggregate = $isAuto ? $this->getAggregate($aggKey)->merge($result) : $result;

        if ( $isAuto ) {
            set_transient($aggKey, $this->resultToArray($aggregate), 30);
        }

        if ( $isAuto && $result->hasMore ) {
            set_transient($autoKey, $this->optionsToArray($options, $options->offset + $options->limit), 30);
            set_transient('firmino_import_notice', [
                'success' => true,
                'message' => __('Import trwa... następna partia w kolejce.', 'firmino-integration'),
            ], 30);
            wp_safe_redirect(admin_url('admin.php?page=firmino-import&auto=1'));
            exit;
        }

        if ( $isAuto ) {
            delete_transient($autoKey);
            delete_transient($aggKey);
        }

        set_transient('firmino_import_notice', [
            'success' => $aggregate->errors === 0,
            'message' => $aggregate->summary(),
        ], 30);

        wp_safe_redirect(admin_url('admin.php?page=firmino-import'));
        exit;
    }

    private function getAggregate(string $key): ImportResult
    {
        $stored = get_transient($key);
        if ( is_array($stored) ) {
            return new ImportResult(
                added:   (int) ($stored['added'] ?? 0),
                updated: (int) ($stored['updated'] ?? 0),
                skipped: (int) ($stored['skipped'] ?? 0),
                errors:  (int) ($stored['errors'] ?? 0),
                hasMore: false,
            );
        }

        return new ImportResult(added: 0, updated: 0, skipped: 0, errors: 0, hasMore: false);
    }

    private function resultToArray(ImportResult $result): array
    {
        return [
            'added'   => $result->added,
            'updated' => $result->updated,
            'skipped' => $result->skipped,
            'errors'  => $result->errors,
        ];
    }

    private function optionsToArray(ImportOptions $options, int $newOffset): array
    {
        return [
            'limit'              => $options->limit,
            'offset'             => $newOffset,
            'code_source'        => $options->codeSource,
            'include_variations' => $options->includeVariations ? 1 : 0,
            'update_existing'    => $options->updateExisting ? 1 : 0,
            'force_import'       => $options->forceImport ? 1 : 0,
        ];
    }
}
