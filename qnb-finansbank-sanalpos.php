<?php
/**
 * Plugin Name: QNB Finansbank Sanal POS (3D Pay) - Devasa Bilişim
 * Description: WooCommerce için QNB Finansbank Sanal POS — 3D Pay (3D Payment) ödeme geçidi. Test modu, hash doğrulama, detaylı log ve lisans kontrolü içerir.
 * Version: 1.0.0
 * Author: Devasa
 * Author URI: https://devasa.com.tr
 * phone: +90 554 816 85 16 | info@devasa.com.tr
 * powered by: Devasa Bilişim
 * Text Domain: qnb-finansbank-sanalpos
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * WC tested up to: 11.1
 */

if (!defined('ABSPATH')) { exit; }

define('QNB_3DPAY_VERSION', '1.0.0');
define('QNB_3DPAY_FILE', __FILE__);
define('QNB_3DPAY_PATH', plugin_dir_path(__FILE__));
define('QNB_3DPAY_URL', plugin_dir_url(__FILE__));

require_once QNB_3DPAY_PATH . 'includes/class-devasa-qnb-license-client.php';
require_once QNB_3DPAY_PATH . 'includes/class-qnb-3dpay-api.php';

// HPOS (özel sipariş tabloları) ve Blok ödeme sayfası uyumluluğu.
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once QNB_3DPAY_PATH . 'includes/class-wc-gateway-qnb-3dpay.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_Gateway_QNB_3DPay';
        return $gateways;
    });
}, 11);

// Blok tabanlı ödeme sayfası (Cart & Checkout Blocks) desteği.
add_action('woocommerce_blocks_loaded', function () {
    if (!class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
        return;
    }
    require_once QNB_3DPAY_PATH . 'includes/class-qnb-3dpay-blocks.php';

    add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
        $registry->register(new QNB_3DPay_Blocks());
    });
});

// Müşteri banka 3D sayfasına gitti ama siteye hiç dönmediyse (sekme kapandı, bağlantı koptu) WooCommerce
// ödenmemiş siparişi süre dolunca iptal eder. Bankada tahsilat olmuş olabileceği için iptal notuna
// banka sipariş numarası yazılır; yönetici QNB panelinden kontrol edebilsin.
add_action('woocommerce_order_status_cancelled', function ($order_id, $order = null) {
    $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
    if (!$order || $order->get_payment_method() !== 'qnb_3dpay' || $order->is_paid()) {
        return;
    }
    $bank_oid = (string) $order->get_meta('_qnb_order_id');
    if ($bank_oid === '' || !$order->get_meta('_qnb_3d_shown')) {
        return;
    }
    $order->add_order_note(sprintf(
        'QNB 3D Pay: Müşteri banka 3D sayfasına yönlendirilmişti ancak siteye dönüş olmadan sipariş iptal edildi. Bankada tahsilat olup olmadığını QNB üye işyeri panelinden kontrol edin (banka sipariş no: %s).',
        esc_html($bank_oid)
    ));
}, 10, 2);

// Lisans cron görevi
add_action(Devasa_QNB_License_Client::CRON_HOOK, [Devasa_QNB_License_Client::class, 'check_remote']);
register_activation_hook(__FILE__, [Devasa_QNB_License_Client::class, 'schedule_cron']);
register_deactivation_hook(__FILE__, [Devasa_QNB_License_Client::class, 'unschedule_cron']);

// Lisans geçersizse admin uyarısı göster
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    $status = Devasa_QNB_License_Client::get_status();
    if (!empty($status['valid'])) {
        return;
    }

    $msg = $status['message'] ?? 'Lisans doğrulanamadı.';
    $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=qnb_3dpay');

    echo '<div class="notice notice-error"><p>';
    echo '<strong>QNB Finansbank Sanal POS:</strong> ' . esc_html($msg);
    echo ' <a href="' . esc_url($url) . '">Lisans ayarlarına git</a>';
    echo '</p></div>';
});

// Eklentiler listesinde "Ayarlar" bağlantısı
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=qnb_3dpay');
    array_unshift($links, '<a href="' . esc_url($url) . '"><b>Ayarlar</b></a>');
    return $links;
});
