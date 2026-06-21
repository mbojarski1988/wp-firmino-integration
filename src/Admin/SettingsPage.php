<?php

declare(strict_types=1);

namespace FirminoIntegration\Admin;

use FirminoIntegration\Contracts\HttpClientInterface;
use FirminoIntegration\Exceptions\ApiException;
use FirminoIntegration\ValueObjects\Settings;

final class SettingsPage
{
    public function __construct(private readonly HttpClientInterface $client) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_firmino_test_connection', [$this, 'handleTestConnection']);
    }

    public function addMenu(): void
    {
        add_options_page(
            __('Firmino', 'firmino-integration'),
            __('Firmino', 'firmino-integration'),
            'manage_options',
            'firmino-integration',
            [$this, 'renderPage'],
        );
    }

    public function registerSettings(): void
    {
        register_setting(
            'firmino_settings_group',
            Settings::optionKey(),
            ['sanitize_callback' => [$this, 'sanitize']],
        );

        add_settings_section(
            'firmino_api_section',
            __('Ustawienia API', 'firmino-integration'),
            '__return_false',
            'firmino-integration',
        );

        $fields = [
            'base_url'              => __('Adres bazowy URL', 'firmino-integration'),
            'login'                 => __('Login', 'firmino-integration'),
            'password'              => __('Hasło', 'firmino-integration'),
            'document_type'         => __('Typ dokumentu', 'firmino-integration'),
            'receipt_document_type' => __('Typ dokumentu paragonu', 'firmino-integration'),
            'default_vat_rate'      => __('Domyślna stawka VAT', 'firmino-integration'),
            'default_country'       => __('Domyślny kod kraju', 'firmino-integration'),
            'default_locality'      => __('Domyślna miejscowość', 'firmino-integration'),
        ];

        foreach ( $fields as $id => $label ) {
            add_settings_field(
                $id,
                $label,
                [$this, 'renderField'],
                'firmino-integration',
                'firmino_api_section',
                ['field' => $id],
            );
        }
    }

    public function sanitize(mixed $input): array
    {
        if ( ! is_array($input) ) {
            $input = [];
        }

        $defaults = Settings::defaults();

        $clean = [
            'base_url'              => esc_url_raw($input['base_url'] ?? $defaults['base_url']),
            'login'                 => sanitize_text_field($input['login'] ?? ''),
            'password'              => sanitize_text_field($input['password'] ?? ''),
            'document_type'         => sanitize_text_field($input['document_type'] ?? $defaults['document_type']),
            'receipt_document_type' => sanitize_text_field($input['receipt_document_type'] ?? ''),
            'default_vat_rate'      => sanitize_text_field($input['default_vat_rate'] ?? ''),
            'default_country'       => sanitize_text_field($input['default_country'] ?? $defaults['default_country']),
            'default_locality'      => sanitize_text_field($input['default_locality'] ?? ''),
        ];

        if ( $clean['base_url'] === '' ) {
            $clean['base_url'] = $defaults['base_url'];
        }

        if ( $clean['login'] === '' && $clean['password'] === '' ) {
            add_settings_error(
                Settings::optionKey(),
                'firmino_missing_credentials',
                __('Ostrzeżenie: brak loginu i hasła — połączenie z API Firmino nie będzie działać.', 'firmino-integration'),
                'warning',
            );
        }

        if ( $clean['document_type'] === '' ) {
            $clean['document_type'] = $defaults['document_type'];
            add_settings_error(
                Settings::optionKey(),
                'firmino_missing_document_type',
                __('Typ dokumentu nie może być pusty. Przywrócono wartość domyślną "fas".', 'firmino-integration'),
                'warning',
            );
        }

        return $clean;
    }

    public function renderPage(): void
    {
        $notice = get_transient('firmino_test_notice');
        if ( $notice ) {
            delete_transient('firmino_test_notice');
            $class = $notice['success'] ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Firmino Integration', 'firmino-integration'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('firmino_settings_group');
                do_settings_sections('firmino-integration');
                submit_button();
                ?>
            </form>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="margin-top:20px;">
                <?php wp_nonce_field('firmino_test_connection'); ?>
                <input type="hidden" name="action" value="firmino_test_connection">
                <?php submit_button(__('Testuj połączenie', 'firmino-integration'), 'secondary', 'submit', false); ?>
            </form>
            <p><?php esc_html_e('Dane uwierzytelniające są wysyłane przy użyciu autoryzacji HTTP Basic.', 'firmino-integration'); ?></p>
        </div>
        <?php
    }

    public function renderField(array $args): void
    {
        $field    = $args['field'];
        $settings = Settings::fromOption();
        $value    = match ($field) {
            'base_url'              => $settings->baseUrl,
            'login'                 => $settings->login,
            'password'              => $settings->password,
            'document_type'         => $settings->documentType,
            'receipt_document_type' => $settings->receiptDocumentType,
            'default_vat_rate'      => $settings->defaultVatRate,
            'default_country'       => $settings->defaultCountry,
            'default_locality'      => $settings->defaultLocality,
            default                 => '',
        };

        $optionKey = esc_attr(Settings::optionKey());
        $fieldAttr = esc_attr($field);
        $valueAttr = esc_attr($value);

        if ( $field === 'password' ) {
            printf(
                '<input type="password" name="%1$s[%2$s]" value="%3$s" class="regular-text" autocomplete="new-password">',
                $optionKey,
                $fieldAttr,
                $valueAttr,
            );
            return;
        }

        $maxLength = $field === 'default_country' ? ' maxlength="2"' : '';
        printf(
            '<input type="text" name="%1$s[%2$s]" value="%3$s" class="regular-text"%4$s>',
            $optionKey,
            $fieldAttr,
            $valueAttr,
            $maxLength,
        );

        $descriptions = [
            'document_type'         => __('Użyj typu dokumentu Firmino, np. fas dla faktury.', 'firmino-integration'),
            'receipt_document_type' => __('Opcjonalnie. Użyj typu dokumentu dla paragonu osoby fizycznej, np. par. Pozostaw puste, aby użyć domyślnego typu.', 'firmino-integration'),
            'default_vat_rate'      => __('Opcjonalne. Użyj, gdy stawka WooCommerce nie jest akceptowana przez Firmino, np. 23.', 'firmino-integration'),
        ];

        if ( isset($descriptions[$field]) ) {
            echo '<p class="description">' . esc_html($descriptions[$field]) . '</p>';
        }
    }

    public function handleTestConnection(): void
    {
        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('Brak dostępu.', 'firmino-integration'));
        }

        check_admin_referer('firmino_test_connection');

        try {
            $this->client->request('customers/find', ['page' => 1, 'size' => 1]);
            $notice = ['success' => true, 'message' => __('Połączenie udane.', 'firmino-integration')];
        } catch ( ApiException $e ) {
            $notice = ['success' => false, 'message' => $e->getMessage()];
        }

        set_transient('firmino_test_notice', $notice, 30);
        wp_safe_redirect(admin_url('options-general.php?page=firmino-integration'));
        exit;
    }
}
