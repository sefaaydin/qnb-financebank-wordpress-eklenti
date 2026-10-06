<?php
/**
 * Blok tabanlı ödeme sayfası (WooCommerce Cart & Checkout Blocks) entegrasyonu.
 *
 * Kart alanları assets/js/qnb-blocks.js ile çizilir; girilen değerler paymentMethodData
 * olarak Store API'ye gider ve WooCommerce bunları $_POST'a koyup
 * WC_Gateway_QNB_3DPay::process_payment()'ı çağırır — akış klasik ödeme sayfasıyla aynıdır.
 */

if (!defined('ABSPATH')) { exit; }

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class QNB_3DPay_Blocks extends AbstractPaymentMethodType
{
    protected $name = 'qnb_3dpay';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_qnb_3dpay_settings', []);
    }

    private function gateway(): ?WC_Gateway_QNB_3DPay
    {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return null;
        }
        $gateways = WC()->payment_gateways()->payment_gateways();
        return (isset($gateways[$this->name]) && $gateways[$this->name] instanceof WC_Gateway_QNB_3DPay) ? $gateways[$this->name] : null;
    }

    public function is_active()
    {
        $gateway = $this->gateway();
        return $gateway ? $gateway->is_available() : false;
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'qnb-3dpay-blocks',
            QNB_3DPAY_URL . 'assets/js/qnb-blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'],
            QNB_3DPAY_VERSION,
            true
        );
        wp_enqueue_style('qnb-3dpay-card', QNB_3DPAY_URL . 'assets/css/qnb-card-form.css', [], QNB_3DPAY_VERSION);

        return ['qnb-3dpay-blocks'];
    }

    public function get_payment_method_data()
    {
        $gateway = $this->gateway();

        return [
            'title'        => $this->get_setting('title', 'Kredi / Banka Kartı (QNB Finansbank)'),
            'description'  => $this->get_setting('description', ''),
            'testmode'     => $this->get_setting('test_mode', 'no') === 'yes',
            'licenseValid' => Devasa_QNB_License_Client::is_valid(),
            'supports'     => $gateway ? array_values(array_filter($gateway->supports, [$gateway, 'supports'])) : ['products'],
        ];
    }
}
