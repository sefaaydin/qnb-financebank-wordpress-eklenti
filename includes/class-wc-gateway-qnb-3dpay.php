<?php
/**
 * WooCommerce ödeme geçidi: QNB Finansbank Sanal POS — 3D Pay (3D Payment) modeli.
 *
 * Akış:
 *  1) process_payment: Kart bilgileri ve hash ile Default.aspx'e sunucu-sunucu POST.
 *     Banka 3D doğrulama sayfasını (HTML) döndürür; sayfa oturuma yazılır.
 *  2) handle_redirect (GET): Müşterinin tarayıcısına banka sayfası basılır, 3D (SMS) ekranına gider.
 *  3) handle_callback (POST, bankadan): ResponseHash doğrulanır, ProcReturnCode=00 ise sipariş ödenir.
 *     3D Pay modelinde provizyonu banka kendisi yapar; ayrıca provizyon isteği yoktur.
 *  4) handle_return (GET): Başarısız ödemede sepeti geri yükler, hatayı gösterip ödeme sayfasına döner.
 */

if (!defined('ABSPATH')) { exit; }

class WC_Gateway_QNB_3DPay extends WC_Payment_Gateway
{
    const GATEWAY_ID   = 'qnb_3dpay';
    const API_REDIRECT = 'qnb_3dpay_redirect';
    const API_CALLBACK = 'qnb_3dpay_callback';
    const API_RETURN   = 'qnb_3dpay_return';
    const LOG_SOURCE   = 'qnb-3dpay';
    const HTML_KEY     = 'qnb_3dpay_html_';

    /** @var bool */
    protected $testmode = false;

    /** @var bool */
    protected $debug = false;

    /** @var WC_Logger_Interface|null */
    private $logger = null;

    public function __construct()
    {
        $this->id                 = self::GATEWAY_ID;
        $this->method_title       = 'QNB Finansbank Sanal POS (3D Pay) - Devasa Bilişim';
        $this->method_description = 'QNB Finansbank Sanal POS ile 3D Pay (3D Secure) kredi/banka kartı ödemesi. Test modu, hash doğrulama ve detaylı log içerir.';
        $this->has_fields         = true;
        $this->supports           = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->testmode    = ($this->get_option('test_mode', 'no') === 'yes');
        $this->debug       = ($this->get_option('debug', 'no') === 'yes');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'save_license_key'], 20);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('woocommerce_api_' . self::API_REDIRECT, [$this, 'handle_redirect']);
        add_action('woocommerce_api_' . self::API_CALLBACK, [$this, 'handle_callback']);
        add_action('woocommerce_api_' . self::API_RETURN, [$this, 'handle_return']);
    }

    /* ---------------------------------------------------------------------
     * Ayarlar
     * ------------------------------------------------------------------- */

    public function init_form_fields()
    {
        $lic_status  = Devasa_QNB_License_Client::get_status();
        $lic_valid   = !empty($lic_status['valid']);
        $lic_display = $lic_valid
            ? '<span style="color:#00a32a;font-weight:bold">AKTİF</span>'
              . (!empty($lic_status['expires']) ? ' (Bitiş: ' . esc_html($lic_status['expires']) . ')' : ' (Süresiz)')
            : '<span style="color:#d63638;font-weight:bold">GEÇERSİZ</span>'
              . ' - ' . esc_html($lic_status['message'] ?? '');
        if (!empty($lic_status['checked'])) {
            $lic_display .= ' <small>(Son kontrol: ' . esc_html($lic_status['checked']) . ')</small>';
        }

        $callback_url = WC()->api_request_url(self::API_CALLBACK);

        $this->form_fields = [
            'license_heading' => [
                'title'       => 'Lisans Bilgileri',
                'type'        => 'title',
                'description' => $lic_display,
            ],
            'license_key' => [
                'title'       => 'Lisans Anahtarı',
                'type'        => 'text',
                'description' => 'Devasa Bilişim tarafından verilen lisans anahtarını girin.',
                'default'     => '',
                'css'         => 'font-family:monospace;letter-spacing:1px;min-width:350px',
            ],

            'general_heading' => [
                'title' => 'Genel Ayarlar',
                'type'  => 'title',
            ],
            'enabled' => [
                'title'   => 'Aktif',
                'type'    => 'checkbox',
                'label'   => 'QNB Finansbank Sanal POS (3D Pay) aktif',
                'default' => 'no',
            ],
            'title' => [
                'title'       => 'Başlık',
                'type'        => 'text',
                'description' => 'Ödeme sayfasında müşterinin göreceği başlık.',
                'default'     => 'Kredi / Banka Kartı (QNB Finansbank)',
            ],
            'description' => [
                'title'   => 'Açıklama',
                'type'    => 'textarea',
                'default' => 'Ödemeniz 3D Secure ile güvenli şekilde alınır.',
            ],

            'live_heading' => [
                'title'       => 'Canlı Ortam Bilgileri',
                'type'        => 'title',
                'description' => 'QNB Finansbank\'ın üye işyerinize verdiği bilgiler. Banka 3D dönüş adresi otomatik gönderilir: <code>' . esc_html($callback_url) . '</code>',
            ],
            'mbr_id' => [
                'title'       => 'Kurum Kodu (MbrId)',
                'type'        => 'text',
                'default'     => QNB_3DPay_Api::DEFAULT_MBR_ID,
                'description' => 'QNB Finansbank için 5.',
            ],
            'merchant_id' => [
                'title' => 'Üye İşyeri No (MerchantID)',
                'type'  => 'text',
            ],
            'user_code' => [
                'title' => 'API Kullanıcı Kodu (UserCode)',
                'type'  => 'text',
            ],
            'user_pass' => [
                'title' => 'API Kullanıcı Şifresi (UserPass)',
                'type'  => 'password',
            ],
            'merchant_pass' => [
                'title'       => '3D Anahtarı (MerchantPass)',
                'type'        => 'password',
                'description' => 'Hash hesaplamasında kullanılan üye işyeri 3D anahtarı.',
            ],

            'test_heading' => [
                'title'       => 'Test Ortamı',
                'type'        => 'title',
                'description' => 'Varsayılan değerler QNB dokümanındaki "3D PAY BİLGİLERİ" demo hesabıdır (vpos.qnbfinansbank.com/Help/testInformation). Test kart bilgileri de aynı sayfadadır.',
            ],
            'test_mode' => [
                'title'       => 'Test Modu',
                'type'        => 'checkbox',
                'label'       => 'Test modunu aktif et (vpostest.qnbfinansbank.com)',
                'default'     => 'no',
                'description' => 'Açıkken canlı ortama hiçbir istek gitmez ve gerçek tahsilat yapılmaz.',
            ],
            'test_mbr_id' => [
                'title'   => 'Kurum Kodu (Test)',
                'type'    => 'text',
                'default' => QNB_3DPay_Api::DEFAULT_MBR_ID,
            ],
            'test_merchant_id' => [
                'title'   => 'MerchantID (Test)',
                'type'    => 'text',
                'default' => '085300000009704',
            ],
            'test_user_code' => [
                'title'   => 'UserCode (Test)',
                'type'    => 'text',
                'default' => 'QNB_API_KULLANICI_3DPAY',
            ],
            'test_user_pass' => [
                'title'   => 'UserPass (Test)',
                'type'    => 'password',
                'default' => 'UcBN0',
            ],
            'test_merchant_pass' => [
                'title'   => 'MerchantPass (Test)',
                'type'    => 'password',
                'default' => '12345678',
            ],

            'advanced_heading' => [
                'title' => 'Gelişmiş',
                'type'  => 'title',
            ],
            'live_gateway_url' => [
                'title'       => 'Canlı 3D Gateway Adresi',
                'type'        => 'text',
                'default'     => '',
                'placeholder' => QNB_3DPay_Api::LIVE_GATEWAY_URL,
                'description' => 'Boş bırakın. Banka farklı bir adres verirse doldurun.',
            ],
            'test_gateway_url' => [
                'title'       => 'Test 3D Gateway Adresi',
                'type'        => 'text',
                'default'     => '',
                'placeholder' => QNB_3DPay_Api::TEST_GATEWAY_URL,
                'description' => 'Boş bırakın. Banka farklı bir adres verirse doldurun.',
            ],
            'debug' => [
                'title'       => 'Debug Log',
                'type'        => 'checkbox',
                'label'       => 'WooCommerce günlüklerine detay yaz (kaynak: ' . self::LOG_SOURCE . ')',
                'default'     => 'no',
                'description' => 'Hatalar her zaman loglanır. Kart numarası, CVV ve şifreler hiçbir zaman açık yazılmaz.',
            ],
        ];
    }

    /**
     * Gateway adresleri yalnızca HTTPS olabilir.
     */
    public function validate_live_gateway_url_field($key, $value)
    {
        return $this->sanitize_gateway_url($value);
    }

    public function validate_test_gateway_url_field($key, $value)
    {
        return $this->sanitize_gateway_url($value);
    }

    private function sanitize_gateway_url($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $url = esc_url_raw($value, ['https']);
        if ($url === '') {
            WC_Admin_Settings::add_error('3D Gateway adresi https:// ile başlamalıdır; değer kaydedilmedi.');
        }
        return $url;
    }

    /**
     * Ayarlar her kaydedildiğinde lisans anahtarını yeniden doğrular (anahtar aynı olsa bile).
     */
    public function save_license_key()
    {
        Devasa_QNB_License_Client::save_key((string) $this->get_option('license_key', ''));
    }

    /**
     * @return array{env:string, mbr_id:string, merchant_id:string, user_code:string, user_pass:string, merchant_pass:string, gateway_url:string}
     */
    protected function get_credentials(bool $test): array
    {
        $p = $test ? 'test_' : '';

        $gateway = trim((string) $this->get_option($test ? 'test_gateway_url' : 'live_gateway_url', ''));
        if ($gateway === '') {
            $gateway = $test ? QNB_3DPay_Api::TEST_GATEWAY_URL : QNB_3DPay_Api::LIVE_GATEWAY_URL;
        }

        $mbr = trim((string) $this->get_option($p . 'mbr_id', QNB_3DPay_Api::DEFAULT_MBR_ID));

        return [
            'env'           => $test ? 'test' : 'live',
            'mbr_id'        => $mbr !== '' ? $mbr : QNB_3DPay_Api::DEFAULT_MBR_ID,
            'merchant_id'   => trim((string) $this->get_option($p . 'merchant_id', '')),
            'user_code'     => trim((string) $this->get_option($p . 'user_code', '')),
            'user_pass'     => (string) $this->get_option($p . 'user_pass', ''),
            'merchant_pass' => (string) $this->get_option($p . 'merchant_pass', ''),
            'gateway_url'   => $gateway,
        ];
    }

    protected function credentials_complete(array $c): bool
    {
        return $c['merchant_id'] !== '' && $c['user_code'] !== '' && $c['merchant_pass'] !== '';
    }

    public function is_available()
    {
        if (!parent::is_available()) {
            return false;
        }
        if (QNB_3DPay_Api::currency_code(get_woocommerce_currency()) === null) {
            return false;
        }
        return $this->credentials_complete($this->get_credentials($this->testmode));
    }

    /* ---------------------------------------------------------------------
     * Ödeme formu
     * ------------------------------------------------------------------- */

    public function enqueue_assets()
    {
        if ('yes' !== $this->enabled) {
            return;
        }
        if (!function_exists('is_checkout') || !(is_checkout() || is_checkout_pay_page())) {
            return;
        }
        wp_enqueue_style('qnb-3dpay-card', QNB_3DPAY_URL . 'assets/css/qnb-card-form.css', [], QNB_3DPAY_VERSION);
        wp_enqueue_script('qnb-3dpay-card', QNB_3DPAY_URL . 'assets/js/qnb-card-form.js', ['jquery'], QNB_3DPAY_VERSION, true);
    }

    public function payment_fields()
    {
        if (!Devasa_QNB_License_Client::is_valid()) {
            echo '<p class="qnb-license-error">Bu ödeme yöntemi şu anda kullanılamıyor. Lütfen site yöneticisiyle iletişime geçin.</p>';
            return;
        }

        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }

        if ($this->testmode) {
            echo '<p class="qnb-test-badge">TEST MODU — gerçek tahsilat yapılmaz.</p>';
        }
        ?>
        <fieldset id="qnb-card-form" class="qnb-card-form wc-payment-form">
            <div class="qnb-card-top">
                <span class="qnb-chip" aria-hidden="true"></span>
                <span class="qnb-secure">3D Secure</span>
            </div>
            <div class="qnb-field">
                <label for="qnb_card_number">Kart Numarası</label>
                <input id="qnb_card_number" type="text" inputmode="numeric" autocomplete="cc-number" autocorrect="off" spellcheck="false" placeholder="0000 0000 0000 0000" maxlength="23" />
            </div>
            <div class="qnb-field">
                <label for="qnb_card_holder">Kart Üzerindeki İsim</label>
                <input id="qnb_card_holder" type="text" autocomplete="cc-name" spellcheck="false" placeholder="Ad Soyad" maxlength="64" />
            </div>
            <div class="qnb-row">
                <div class="qnb-field">
                    <label for="qnb_exp_month">Ay</label>
                    <input id="qnb_exp_month" type="text" inputmode="numeric" autocomplete="cc-exp-month" placeholder="AA" maxlength="2" />
                </div>
                <div class="qnb-field">
                    <label for="qnb_exp_year">Yıl</label>
                    <input id="qnb_exp_year" type="text" inputmode="numeric" autocomplete="cc-exp-year" placeholder="YY" maxlength="4" />
                </div>
                <div class="qnb-field">
                    <label for="qnb_cvv">CVV</label>
                    <input id="qnb_cvv" type="password" inputmode="numeric" autocomplete="cc-csc" placeholder="***" maxlength="4" />
                </div>
            </div>
            <p class="qnb-note">Kart bilgileriniz saklanmaz; doğrulama bankanızın 3D Secure ekranında yapılır.</p>
            <?php
            // Görünür alanlar isimsizdir; böylece adres/kargo değişince WooCommerce'in gönderdiği
            // update_order_review isteğine kart bilgisi gitmez. Değerler yalnızca "Siparişi ver"
            // anında aşağıdaki gizli alanlara kopyalanır (assets/js/qnb-card-form.js).
            foreach (['qnb_card_number', 'qnb_card_holder', 'qnb_exp_month', 'qnb_exp_year', 'qnb_cvv'] as $hidden) {
                echo '<input type="hidden" name="' . esc_attr($hidden) . '" value="" autocomplete="off" />';
            }
            ?>
        </fieldset>
        <?php
    }

    /**
     * POST'tan kart bilgisini okur ve doğrular.
     *
     * @return array{number:string, holder:string, month:string, year:string, cvv:string}|string Geçerliyse kart, değilse hata mesajı
     */
    protected function read_posted_card()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checkout nonce'u kendisi doğrular.
        $number = preg_replace('/\D+/', '', (string) wp_unslash($_POST['qnb_card_number'] ?? ''));
        $holder = sanitize_text_field((string) wp_unslash($_POST['qnb_card_holder'] ?? ''));
        $month  = preg_replace('/\D+/', '', (string) wp_unslash($_POST['qnb_exp_month'] ?? ''));
        $year   = preg_replace('/\D+/', '', (string) wp_unslash($_POST['qnb_exp_year'] ?? ''));
        $cvv    = preg_replace('/\D+/', '', (string) wp_unslash($_POST['qnb_cvv'] ?? ''));
        // phpcs:enable

        if ($number === '' && $month === '' && $year === '' && $cvv === '') {
            return 'Kart bilgileri alınamadı. Lütfen kart bilgilerinizi girip tekrar deneyin (sayfayı yenilemeniz gerekebilir).';
        }

        if (!QNB_3DPay_Api::luhn_valid($number)) {
            return 'Kart numarası hatalı.';
        }

        $m = (int) $month;
        if ($month === '' || $m < 1 || $m > 12) {
            return 'Son kullanma ayı hatalı.';
        }

        if (strlen($year) === 2) {
            $full_year = 2000 + (int) $year;
        } elseif (strlen($year) === 4) {
            $full_year = (int) $year;
        } else {
            return 'Son kullanma yılı hatalı.';
        }

        $now_y = (int) gmdate('Y');
        $now_m = (int) gmdate('n');
        if ($full_year < $now_y || ($full_year === $now_y && $m < $now_m) || $full_year > $now_y + 25) {
            return 'Kartın son kullanma tarihi geçersiz.';
        }

        if (!preg_match('/^\d{3,4}$/', $cvv)) {
            return 'CVV hatalı.';
        }

        return [
            'number' => $number,
            'holder' => function_exists('mb_substr') ? mb_substr($holder, 0, 64) : substr($holder, 0, 64),
            'month'  => str_pad((string) $m, 2, '0', STR_PAD_LEFT),
            'year'   => substr((string) $full_year, -2),
            'cvv'    => $cvv,
        ];
    }

    public function validate_fields()
    {
        if (!Devasa_QNB_License_Client::is_valid()) {
            wc_add_notice('Bu ödeme yöntemi şu anda kullanılamıyor. Lütfen site yöneticisiyle iletişime geçin.', 'error');
            return false;
        }

        $card = $this->read_posted_card();
        if (is_string($card)) {
            wc_add_notice($card, 'error');
            return false;
        }
        return true;
    }

    /* ---------------------------------------------------------------------
     * 1) Ödeme başlatma
     * ------------------------------------------------------------------- */

    public function process_payment($order_id)
    {
        if (!Devasa_QNB_License_Client::is_valid()) {
            wc_add_notice('Bu ödeme yöntemi şu anda kullanılamıyor. Lütfen site yöneticisiyle iletişime geçin.', 'error');
            return ['result' => 'failure'];
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wc_add_notice('Sipariş bulunamadı.', 'error');
            return ['result' => 'failure'];
        }

        $creds = $this->get_credentials($this->testmode);
        if (!$this->credentials_complete($creds)) {
            $this->log('Üye işyeri bilgileri eksik (' . $creds['env'] . ').', 'error');
            wc_add_notice('Ödeme yöntemi yapılandırılmamış. Lütfen site yöneticisiyle iletişime geçin.', 'error');
            return ['result' => 'failure'];
        }

        $currency = QNB_3DPay_Api::currency_code($order->get_currency());
        $amount   = QNB_3DPay_Api::format_amount((string) $order->get_total());
        if ($currency === null || $amount === null) {
            wc_add_notice('Bu sipariş tutarı veya para birimi ile ödeme alınamıyor.', 'error');
            return ['result' => 'failure'];
        }

        $card = $this->read_posted_card();
        if (is_string($card)) {
            wc_add_notice($card, 'error');
            return ['result' => 'failure'];
        }

        $qnb_order_id = QNB_3DPay_Api::generate_order_id($order->get_id());

        $params = [
            'MbrId'            => $creds['mbr_id'],
            'MerchantID'       => $creds['merchant_id'],
            'UserCode'         => $creds['user_code'],
            'UserPass'         => $creds['user_pass'],
            'SecureType'       => '3DPay',
            'TxnType'          => 'Auth',
            'InstallmentCount' => '0',
            'Currency'         => $currency,
            'OkUrl'            => $this->callback_url($order, 'ok'),
            'FailUrl'          => $this->callback_url($order, 'fail'),
            'OrderId'          => $qnb_order_id,
            'PurchAmount'      => $amount,
            'Lang'             => $this->get_lang(),
            'Rnd'              => QNB_3DPay_Api::generate_rnd(),
            'CardHolderName'   => $card['holder'] !== '' ? $card['holder'] : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'Pan'              => $card['number'],
            'Expiry'           => QNB_3DPay_Api::format_expiry($card['month'], $card['year']),
            'Cvv2'             => $card['cvv'],
        ];
        if ($params['UserPass'] === '') {
            unset($params['UserPass']);
        }
        $params['Hash'] = QNB_3DPay_Api::request_hash($params, $creds['merchant_pass']);

        // Callback bu değerlerle eşleştirme ve tutar kontrolü yapar.
        $order->update_meta_data('_qnb_order_id', $qnb_order_id);
        $order->update_meta_data('_qnb_amount', $amount);
        $order->update_meta_data('_qnb_currency', $currency);
        $order->update_meta_data('_qnb_env', $creds['env']);
        $order->update_meta_data('_qnb_pay_page', $this->is_pay_page_request() ? 'yes' : 'no');
        $order->delete_meta_data('_qnb_last_error');
        $order->save();

        $this->log(sprintf('[Sipariş #%d] 3D Pay isteği → %s | %s', $order->get_id(), $creds['gateway_url'], wp_json_encode(QNB_3DPay_Api::mask($params), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

        $response = wp_remote_post($creds['gateway_url'], [
            'timeout'    => 45,
            'body'       => $params,
            'headers'    => ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'],
            'user-agent' => 'Devasa-QNB-3DPay/' . QNB_3DPAY_VERSION . '; ' . home_url('/'),
        ]);

        // Kart verisi bu noktadan sonra bellekte tutulmaz.
        unset($params, $card);

        if (is_wp_error($response)) {
            $this->log(sprintf('[Sipariş #%d] Bankaya bağlanılamadı: %s', $order->get_id(), $response->get_error_message()), 'error');
            $order->add_order_note('QNB 3D Pay: Bankaya bağlanılamadı — ' . esc_html($response->get_error_message()));
            wc_add_notice('Bankaya bağlanılamadı. Lütfen birkaç dakika sonra tekrar deneyin.', 'error');
            return ['result' => 'failure'];
        }

        $http = (int) wp_remote_retrieve_response_code($response);
        $body = QNB_3DPay_Api::to_utf8((string) wp_remote_retrieve_body($response));

        if ($http < 200 || $http >= 300) {
            $this->log(sprintf('[Sipariş #%d] Beklenmeyen HTTP kodu %d: %s', $order->get_id(), $http, $this->trim_for_log($body, 500)), 'error');
            $order->add_order_note('QNB 3D Pay: Banka beklenmeyen HTTP kodu döndürdü (' . $http . ').');
            wc_add_notice('Ödeme başlatılamadı. Lütfen daha sonra tekrar deneyin.', 'error');
            return ['result' => 'failure'];
        }

        $result = QNB_3DPay_Api::analyze_gateway_response($body);

        if ($result['type'] === 'redirect') {
            $form = QNB_3DPay_Api::parse_form($body);
            $this->log(sprintf('[Sipariş #%d] 3D yönlendirme sayfası alındı (HTTP %d, %d bayt) → form: %s, alanlar: %s',
                $order->get_id(), $http, strlen($body), $form['action'] ?: '(yok)', implode(',', array_keys($form['fields']))));

            $this->store_redirect_html($order, QNB_3DPay_Api::inject_base_href($body, $creds['gateway_url']));
            $order->add_order_note(sprintf('QNB 3D Pay başlatıldı%s. Banka sipariş no: %s, tutar: %s', $creds['env'] === 'test' ? ' (TEST)' : '', $qnb_order_id, $amount));

            return [
                'result'   => 'success',
                'redirect' => $this->redirect_url($order),
            ];
        }

        if ($result['type'] === 'result') {
            // Banka 3D ekranına gitmeden işlemi sonuçlandırdı (nadir). Yanıt doğrudan bankadan (TLS) geldiği için
            // imza aranmaz; sipariş no ve tutar kontrolleri yine uygulanır.
            $outcome = $this->apply_bank_result($order, $result['fields'], 'Doğrudan yanıt', true);
            if ($outcome === 'paid' || $outcome === 'on-hold') {
                return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
            }
            wc_add_notice($this->customer_error_message($result['fields']['ErrMsg'] ?? '', $result['code']), 'error');
            return ['result' => 'failure'];
        }

        // Hata: işlem başlatılamadı, tahsilat yok.
        if (empty($result['fields'])) {
            $this->log(sprintf('[Sipariş #%d] Banka yanıtı anlaşılamadı: %s', $order->get_id(), $this->trim_for_log($body, 500)), 'error');
        }
        $this->log_bank_result($order, '3D Pay başlatma', $result['code'], $result['message'], $result['fields']);
        $order->update_status('failed', sprintf(
            'QNB 3D Pay başlatılamadı: %s - %s',
            esc_html($result['code'] ?: '(kod yok)'),
            esc_html($result['message'] ?: QNB_3DPay_Api::describe_code($result['code']))
        ));
        wc_add_notice($this->customer_error_message($result['message'], $result['code']), 'error');
        return ['result' => 'failure'];
    }

    /* ---------------------------------------------------------------------
     * 2) 3D sayfasına yönlendirme (GET)
     * ------------------------------------------------------------------- */

    public function handle_redirect()
    {
        $this->send_nocache_headers();

        $order = $this->get_order_from_request(true);
        if (!$order) {
            $this->fail_to_checkout('Ödeme oturumu bulunamadı. Lütfen tekrar deneyin.');
        }

        if ($order->is_paid()) {
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        $html = $this->pull_redirect_html($order);
        if ($html === '') {
            $this->fail_to_checkout('3D Secure oturumunun süresi doldu. Lütfen kart bilgilerinizi tekrar girin.', $order);
        }

        // Müşteri banka sayfasına ulaştı: sipariş dönüşsüz kalırsa iptalde not düşülür.
        $order->update_meta_data('_qnb_3d_shown', (string) time());
        $order->save();

        header('Content-Type: text/html; charset=UTF-8');
        header('Referrer-Policy: no-referrer');
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bankanın 3D yönlendirme sayfası, olduğu gibi basılmalı.
        exit;
    }

    /* ---------------------------------------------------------------------
     * 3) Banka dönüşü (POST → OkUrl/FailUrl)
     * ------------------------------------------------------------------- */

    public function handle_callback()
    {
        $this->send_nocache_headers();

        // Ödeme yöntemi kontrolü bilerek yapılmaz: müşteri 3D sürerken başka sekmede ödeme yöntemini
        // değiştirmiş olsa bile bankadaki gerçek tahsilat kaybolmamalı (apply_bank_result ele alır).
        $order = $this->get_order_from_request(false);
        if (!$order) {
            $this->log('Callback: geçersiz sipariş/anahtar.', 'debug');
            wp_die('Geçersiz istek.', 'Ödeme', ['response' => 400]);
        }

        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) {
            // Bankadan POST gelmeden açılmış: sonucu bilmiyoruz, dönüş sayfasına gönder.
            wp_safe_redirect($this->return_url($order));
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Banka POST'u; doğrulama ResponseHash ile yapılır.
        $posted = $this->sanitize_bank_post(wp_unslash($_POST));
        $id     = $order->get_id();

        if (!$this->acquire_lock($id)) {
            // Aynı anda ikinci callback: ilki işliyor. Kısa bekleyip sonuca göre yönlendir.
            sleep(2);
            $order = wc_get_order($id);
            wp_safe_redirect($order && ($order->is_paid() || $order->has_status('on-hold')) ? $this->get_return_url($order) : $this->return_url($order));
            exit;
        }

        try {
            // Kilit alındıktan sonra sipariş önbellek atlanarak yeniden okunur (eşzamanlı istek değiştirmiş olabilir).
            $this->flush_order_cache($id);
            $order   = wc_get_order($id);
            $outcome = $order instanceof WC_Order ? $this->apply_bank_result($order, $posted, '3D dönüşü', false) : 'ignored';
        } finally {
            $this->release_lock($id);
        }

        if (!$order instanceof WC_Order) {
            wp_safe_redirect(wc_get_checkout_url());
        } elseif ($outcome === 'paid' || $outcome === 'on-hold') {
            wp_safe_redirect($this->get_return_url($order));
        } else {
            wp_safe_redirect($this->return_url($order));
        }
        exit;
    }

    /**
     * Banka sonucunu doğrular ve siparişe uygular.
     *
     * @param array<string,string> $data            Bankadan gelen alanlar
     * @param bool                 $trusted_channel Yanıt doğrudan bankadan (sunucu-sunucu, TLS) mı geldi
     * @return string paid | on-hold | failed | ignored
     */
    protected function apply_bank_result(WC_Order $order, array $data, string $stage, bool $trusted_channel): string
    {
        $is_test   = ($order->get_meta('_qnb_env') === 'test');
        $creds     = $this->get_credentials($is_test);
        $code      = trim((string) ($data['ProcReturnCode'] ?? ''));
        $status3d  = trim((string) ($data['3DStatus'] ?? ''));
        $message   = trim(wp_strip_all_tags((string) ($data['ErrMsg'] ?? '')));
        $bank_oid  = trim((string) ($data['OrderId'] ?? ''));
        $stored_id = (string) $order->get_meta('_qnb_order_id');

        $hash_ok   = $trusted_channel || QNB_3DPay_Api::verify_response_hash($data, $creds['merchant_id'], $creds['merchant_pass'], $creds['user_code']);
        $order_ok  = $stored_id !== '' && hash_equals($stored_id, $bank_oid);
        $method_ok = ($order->get_payment_method() === $this->id);
        $approved  = ($code === '00');

        // Test ortamında başlatılmış denemenin sonucu, site canlıya geçtikten sonra kabul edilmez:
        // test hesabı bilgileri herkese açık olduğundan imza sahte üretilebilir.
        $stale_test = ($is_test && !$this->testmode);

        // Sipariş zaten ödenmiş: tekrar gelen callback (not düşülmez) ya da ikinci bir tahsilat (uyarı notu).
        if ($order->is_paid()) {
            $this->log(sprintf('[Sipariş #%d] Ödenmiş siparişe callback geldi (kod %s, banka sipariş no %s).', $order->get_id(), $code ?: '-', $bank_oid ?: '-'), 'info');
            if (!$stale_test && $approved && $hash_ok && $bank_oid !== '' && $bank_oid !== (string) $order->get_transaction_id()) {
                $this->log(sprintf('[Sipariş #%d] Ödenmiş siparişe ikinci onay geldi (banka sipariş no %s). Çift tahsilat olabilir.', $order->get_id(), $bank_oid), 'critical');
                $order->add_order_note(sprintf(
                    'QNB 3D Pay UYARI: Sipariş zaten ödenmişti ancak bankadan ikinci bir onay geldi (banka sipariş no: %s, onay kodu: %s). Çift tahsilat olabilir; QNB üye işyeri panelinden kontrol edip gerekirse iade edin.',
                    esc_html($bank_oid),
                    esc_html((string) ($data['AuthCode'] ?? '-'))
                ));
            }
            return 'paid';
        }

        $this->log_bank_result($order, $stage, $code, $message, $data, $trusted_channel ? null : $hash_ok);

        if ($stale_test) {
            $this->log(sprintf('[Sipariş #%d] Test modunda başlatılmış denemenin sonucu canlı modda geldi; yok sayıldı.', $order->get_id()), 'critical');
            $order->add_order_note('QNB 3D Pay: Test modunda başlatılmış bir denemenin sonucu, test modu kapalıyken geldi ve yok sayıldı.');
            $this->set_last_error($order, 'Ödeme doğrulanamadı. Lütfen tekrar deneyin.');
            return 'ignored';
        }

        if ($approved) {
            if (!$hash_ok) {
                // İmzasız "onay": sahte olabilir. Sipariş durumuna dokunulmaz.
                $this->log(sprintf('[Sipariş #%d] İmzası doğrulanamayan onay (00) yanıtı yok sayıldı.', $order->get_id()), 'critical');
                $order->add_order_note('QNB 3D Pay: Onay kodu (00) taşıyan ancak imzası (ResponseHash) doğrulanamayan bir yanıt alındı ve yok sayıldı.');
                $this->set_last_error($order, 'Ödeme doğrulanamadı. Kartınızdan çekim yapıldıysa lütfen bizimle iletişime geçin.');
                return 'ignored';
            }

            // İmza geçerli: bankada tahsilat gerçekleşti. Eşleşmeyen bir durum varsa siparişi beklemeye al.
            $stored_amount  = (string) $order->get_meta('_qnb_amount');
            $current_amount = (string) QNB_3DPay_Api::format_amount((string) $order->get_total());
            $stored_cur     = (string) $order->get_meta('_qnb_currency');
            $current_cur    = (string) QNB_3DPay_Api::currency_code($order->get_currency());

            $problem = '';
            if (!$order_ok) {
                $problem = sprintf('banka sipariş numarası güncel denemeyle eşleşmiyor (beklenen %s, gelen %s)', $stored_id ?: '-', $bank_oid ?: '-');
            } elseif (!$method_ok) {
                $problem = 'sipariş bu arada başka bir ödeme yöntemine (' . $order->get_payment_method() . ') geçirilmiş';
            } elseif ($stored_amount === '' || $stored_amount !== $current_amount) {
                $problem = sprintf('tutar tutarsız (bankaya gönderilen %s, sipariş tutarı %s)', $stored_amount ?: '-', $current_amount ?: '-');
            } elseif ($stored_cur !== '' && $stored_cur !== $current_cur) {
                $problem = 'para birimi değişmiş';
            }

            if ($problem !== '') {
                $this->remember_unmatched_approval($order, $data);
                $this->log(sprintf('[Sipariş #%d] Onay geldi ancak %s. Sipariş beklemeye alındı.', $order->get_id(), $problem), 'critical');
                $order->update_status('on-hold', esc_html(sprintf(
                    'QNB 3D Pay: Bankadan geçerli onay geldi (banka sipariş no %s, onay kodu %s) ancak %s. Ödemeyi QNB üye işyeri panelinden kontrol etmeden siparişi göndermeyin.',
                    $bank_oid,
                    (string) ($data['AuthCode'] ?? '-'),
                    $problem
                )));
                return 'on-hold';
            }

            $order->update_meta_data('_qnb_auth_code', (string) ($data['AuthCode'] ?? ''));
            $order->update_meta_data('_qnb_host_ref_num', (string) ($data['HostRefNum'] ?? ''));
            $order->update_meta_data('_qnb_3d_status', $status3d);
            $order->delete_meta_data('_qnb_last_error');
            $order->save();

            if ($status3d !== '' && $status3d !== '1') {
                $order->add_order_note('QNB 3D Pay uyarı: Ödeme onaylandı ancak 3DStatus=' . esc_html($status3d) . ' (tam 3D doğrulama değil).');
            }

            $earlier = $order->get_meta('_qnb_unmatched_approvals');
            if (is_array($earlier) && $earlier) {
                $ids = implode(', ', array_filter(array_column($earlier, 'order_id')));
                $this->log(sprintf('[Sipariş #%d] Sipariş ödendi; daha önce eşleşmeyen onay(lar) var: %s. Çift tahsilat olabilir.', $order->get_id(), $ids), 'critical');
                $order->add_order_note(esc_html(sprintf(
                    'QNB 3D Pay UYARI: Bu sipariş şimdi ödendi; ancak daha önce aynı sipariş için bankadan eşleşmeyen onay(lar) gelmişti (banka sipariş no: %s). Çift tahsilat olabilir; QNB panelinden kontrol edip fazla çekimi iade edin.',
                    $ids ?: '-'
                )));
            }

            $order->payment_complete($bank_oid);
            $order->add_order_note(esc_html(sprintf(
                "QNB 3D Pay ödemesi alındı%s.\nBanka sipariş no: %s\nOnay kodu: %s\nReferans (HostRefNum, imzasız alan): %s",
                $is_test ? ' (TEST — gerçek tahsilat yok)' : '',
                $bank_oid,
                (string) ($data['AuthCode'] ?? '-'),
                (string) ($data['HostRefNum'] ?? '-')
            )));
            return 'paid';
        }

        // ---- Başarısız sonuç: bankada tahsilat yok ----
        $customer_msg = $this->customer_error_message($message, $code);

        if (!$order_ok && $stored_id !== '' && $bank_oid !== '') {
            // Önceki bir denemeye ait geç gelen hata: güncel denemeyi bozmamak için sipariş durumuna dokunma.
            $this->log(sprintf('[Sipariş #%d] Eski denemeye ait hata yanıtı yok sayıldı (gelen %s, güncel %s).', $order->get_id(), $bank_oid, $stored_id), 'warning');
            $this->set_last_error($order, $customer_msg);
            return 'failed';
        }

        if (!$method_ok || $order->has_status('on-hold') || $order->get_meta('_qnb_unmatched_approvals')) {
            // Sipariş başka yönteme geçmiş ya da daha önce gelen bir onay nedeniyle beklemede: durumu bozma.
            $order->add_order_note(esc_html(sprintf('QNB 3D Pay: Başarısız deneme (%s - %s); sipariş durumu değiştirilmedi.', $code ?: '(kod yok)', $message ?: QNB_3DPay_Api::describe_code($code))));
            $this->set_last_error($order, $customer_msg);
            return 'failed';
        }

        $this->set_last_error($order, $customer_msg);
        $order->update_status('failed', esc_html(sprintf(
            'QNB 3D Pay başarısız: %s - %s%s',
            $code ?: '(kod yok)',
            $message ?: QNB_3DPay_Api::describe_code($code),
            $hash_ok ? '' : ' [yanıt imzası doğrulanamadı]'
        )));
        return 'failed';
    }

    /**
     * Siparişle eşleştirilemeyen ama bankada gerçekleşmiş onayları kaydeder (iade/kontrol için).
     *
     * @param array<string,string> $data
     */
    protected function remember_unmatched_approval(WC_Order $order, array $data): void
    {
        $list   = $order->get_meta('_qnb_unmatched_approvals');
        $list   = is_array($list) ? $list : [];
        $list[] = [
            'order_id'     => (string) ($data['OrderId'] ?? ''),
            'auth_code'    => (string) ($data['AuthCode'] ?? ''),
            'host_ref_num' => (string) ($data['HostRefNum'] ?? ''),
            'time'         => time(),
        ];
        $order->update_meta_data('_qnb_unmatched_approvals', array_slice($list, -10));
        $order->save();
    }

    protected function set_last_error(WC_Order $order, string $message): void
    {
        $order->update_meta_data('_qnb_last_error', $message);
        $order->save();
    }

    /* ---------------------------------------------------------------------
     * 4) Başarısız dönüş (GET) — müşterinin oturumu burada mevcut
     * ------------------------------------------------------------------- */

    public function handle_return()
    {
        $this->send_nocache_headers();

        $order = $this->get_order_from_request(false);
        if (!$order) {
            $this->fail_to_checkout('Ödeme tamamlanamadı. Lütfen tekrar deneyin.');
        }

        if ($order->is_paid() || $order->has_status('on-hold')) {
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        $msg = (string) $order->get_meta('_qnb_last_error');
        if ($msg !== '') {
            $order->delete_meta_data('_qnb_last_error');
            $order->save();
        }

        $this->fail_to_checkout($msg !== '' ? $msg : 'Ödeme tamamlanamadı. Lütfen tekrar deneyin.', $order);
    }

    /* ---------------------------------------------------------------------
     * Yardımcılar
     * ------------------------------------------------------------------- */

    protected function callback_url(WC_Order $order, string $result): string
    {
        return add_query_arg([
            'order_id' => $order->get_id(),
            'key'      => $order->get_order_key(),
            'r'        => $result,
        ], WC()->api_request_url(self::API_CALLBACK));
    }

    protected function redirect_url(WC_Order $order): string
    {
        return add_query_arg([
            'order_id' => $order->get_id(),
            'key'      => $order->get_order_key(),
        ], WC()->api_request_url(self::API_REDIRECT));
    }

    protected function return_url(WC_Order $order): string
    {
        return add_query_arg([
            'order_id' => $order->get_id(),
            'key'      => $order->get_order_key(),
        ], WC()->api_request_url(self::API_RETURN));
    }

    /**
     * "Siparişi öde" (order-pay) sayfasından mı ödeme yapılıyor?
     */
    protected function is_pay_page_request(): bool
    {
        if (function_exists('is_wc_endpoint_url') && did_action('parse_request') && is_wc_endpoint_url('order-pay')) {
            return true;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return isset($_POST['woocommerce_pay']);
    }

    /**
     * URL'deki order_id + key ile siparişi bulur; anahtar eşleşmezse null.
     */
    protected function get_order_from_request(bool $require_this_method): ?WC_Order
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $order_id = absint($_GET['order_id'] ?? 0);
        $key      = sanitize_text_field(wp_unslash((string) ($_GET['key'] ?? '')));
        // phpcs:enable

        if (!$order_id || $key === '') {
            return null;
        }
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return null;
        }
        if (!hash_equals((string) $order->get_order_key(), $key)) {
            return null;
        }
        if ($require_this_method && $order->get_payment_method() !== $this->id) {
            return null;
        }
        // Bu eklentiyle hiç 3D denemesi yapılmamış sipariş için dönüş uç noktaları kullanılamaz.
        if (!$require_this_method && (string) $order->get_meta('_qnb_order_id') === '') {
            return null;
        }
        return $order;
    }

    /**
     * Banka POST'unu düz diziye çevirir. Değerler imza doğrulaması için olduğu gibi (trim'siz) tutulur.
     *
     * @param mixed $raw
     * @return array<string,string>
     */
    protected function sanitize_bank_post($raw): array
    {
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $k => $v) {
            if (!is_scalar($v)) {
                continue;
            }
            $k = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $k);
            if ($k === '' || strlen($k) > 64) {
                continue;
            }
            $out[$k] = QNB_3DPay_Api::to_utf8((string) $v);
        }
        return $out;
    }

    /**
     * Bankanın 3D sayfası tek kullanımlık ve 15 dakika geçerli olarak saklanır. Anahtar sipariş
     * anahtarına bağlıdır; WooCommerce oturumu olmayan (ör. Cart-Token ile gelen) isteklerde de çalışır.
     */
    protected function redirect_html_key(WC_Order $order): string
    {
        return self::HTML_KEY . $order->get_id() . '_' . substr(hash('sha256', (string) $order->get_order_key() . wp_salt('auth')), 0, 20);
    }

    protected function store_redirect_html(WC_Order $order, string $html): void
    {
        set_transient($this->redirect_html_key($order), $html, 15 * MINUTE_IN_SECONDS);
    }

    protected function pull_redirect_html(WC_Order $order): string
    {
        $key  = $this->redirect_html_key($order);
        $html = (string) get_transient($key);
        delete_transient($key);
        return $html;
    }

    /**
     * Müşteriye hata gösterir, gerekirse sepeti siparişten geri yükler ve ödeme sayfasına döner.
     */
    protected function fail_to_checkout(string $message, ?WC_Order $order = null): void
    {
        $target = wc_get_checkout_url();

        if ($order instanceof WC_Order) {
            $via      = (string) $order->get_created_via();
            $pay_page = ($order->get_meta('_qnb_pay_page') === 'yes');
            if ($pay_page || ($via !== '' && !in_array($via, ['checkout', 'store-api'], true))) {
                // "Siparişi öde" sayfasından gelen deneme: aynı sayfaya dön, sepete dokunma.
                $target = $order->get_checkout_payment_url();
            } else {
                $this->restore_cart($order);
            }
        }

        if (function_exists('wc_add_notice') && WC()->session) {
            wc_add_notice(esc_html($message), 'error');
        }
        wp_safe_redirect($target);
        exit;
    }

    /**
     * Sepet boşsa sipariş kalemlerini sepete geri ekler (başarısız ödemede müşteri yeniden denesin diye).
     */
    protected function restore_cart(WC_Order $order): void
    {
        if (function_exists('wc_load_cart') && null === WC()->cart) {
            wc_load_cart();
        }
        if (!WC()->cart || !WC()->cart->is_empty()) {
            return;
        }

        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $product_id   = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $variations   = [];

            if ($variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation && method_exists($variation, 'get_variation_attributes')) {
                    foreach ($variation->get_variation_attributes() as $attr_key => $value) {
                        $meta_key              = substr($attr_key, strlen('attribute_'));
                        $variations[$attr_key] = $value !== '' ? $value : (string) $item->get_meta($meta_key);
                    }
                }
            }

            try {
                WC()->cart->add_to_cart($product_id, $item->get_quantity(), $variation_id, $variations);
            } catch (Exception $e) {
                $this->log('Sepet geri yüklenemedi: ' . $e->getMessage(), 'warning');
            }
        }
    }

    /**
     * Aynı sipariş için eşzamanlı iki callback'in ikisinin de işlemesini engeller.
     */
    protected function acquire_lock(int $order_id): bool
    {
        global $wpdb;
        $name = '_qnb_3dpay_lock_' . $order_id;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $name,
                (string) time()
            ));
            if ($inserted) {
                return true;
            }
            // Takılı kalmış eski kilit (ör. PHP hatası) 2 dakikadan eskiyse temizle.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $since = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
            if ($since && (time() - $since) > 120) {
                $wpdb->delete($wpdb->options, ['option_name' => $name]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                continue;
            }
            return false;
        }
        return false;
    }

    /**
     * Sipariş nesne önbelleğini temizler (HPOS OrderCache ve klasik post önbelleği).
     */
    protected function flush_order_cache(int $order_id): void
    {
        if (function_exists('wc_get_container') && class_exists(\Automattic\WooCommerce\Caches\OrderCache::class)) {
            try {
                wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class)->remove($order_id);
            } catch (\Throwable $e) {
                // Önbellek servisi yoksa sessizce devam et.
            }
        }
        clean_post_cache($order_id);
    }

    protected function release_lock(int $order_id): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name' => '_qnb_3dpay_lock_' . $order_id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    protected function send_nocache_headers(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!headers_sent()) {
            nocache_headers();
            header('X-LiteSpeed-Cache-Control: no-cache');
        }
    }

    protected function get_lang(): string
    {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        return strpos((string) $locale, 'tr') === 0 ? 'TR' : 'EN';
    }

    protected function customer_error_message(string $bank_message, string $code): string
    {
        $bank_message = trim(wp_strip_all_tags($bank_message));
        $code         = trim(wp_strip_all_tags($code));
        $suffix       = $code !== '' ? ' (' . $code . ')' : '';
        if ($bank_message === '') {
            return 'Ödeme başarısız: ' . QNB_3DPay_Api::describe_code($code) . $suffix;
        }
        return 'Ödeme başarısız: ' . $bank_message . $suffix;
    }

    /**
     * Banka yanıtını loglar ve sipariş notu düşer (yalnızca hassas olmayan, beyaz listedeki alanlar).
     *
     * @param array<string,string> $fields
     */
    protected function log_bank_result(WC_Order $order, string $stage, string $code, string $message, array $fields, ?bool $hash_ok = null): void
    {
        $success = ($code === '00');
        $safe    = [];
        foreach (['OrderId', 'AuthCode', 'HostRefNum', 'ProcReturnCode', '3DStatus', 'TxnType', 'PurchAmount', 'Currency', 'ErrorCode', 'TransId', 'IrcDet'] as $k) {
            if (isset($fields[$k]) && trim($fields[$k]) !== '') {
                $safe[$k] = function_exists('mb_substr') ? mb_substr(trim($fields[$k]), 0, 100) : substr(trim($fields[$k]), 0, 100);
            }
        }

        $this->log(sprintf(
            '[Sipariş #%d] [%s] Kod: %s | Mesaj: %s | Açıklama: %s%s | %s',
            $order->get_id(),
            $stage,
            $code ?: '(boş)',
            $message ?: '(boş)',
            QNB_3DPay_Api::describe_code($code),
            $hash_ok === null ? '' : ' | İmza: ' . ($hash_ok ? 'OK' : 'GEÇERSİZ'),
            wp_json_encode($safe, JSON_UNESCAPED_UNICODE)
        ), $success ? 'info' : 'error');

        $note = sprintf(
            "QNB 3D Pay [%s]\nSonuç: %s\nKod: %s\nMesaj: %s\nAçıklama: %s",
            $stage,
            $success ? 'BAŞARILI' : 'BAŞARISIZ',
            $code ?: '(boş)',
            $message ?: '(boş)',
            QNB_3DPay_Api::describe_code($code)
        );
        if ($hash_ok !== null) {
            $note .= "\nİmza doğrulama: " . ($hash_ok ? 'OK' : 'GEÇERSİZ');
        }
        $extra = [];
        foreach ($safe as $k => $v) {
            if ($k !== 'ProcReturnCode') {
                $extra[] = $k . ': ' . $v;
            }
        }
        if ($extra) {
            $note .= "\n" . implode(' | ', $extra);
        }
        // Banka alanları imzasız olabilir; nota düz metin olarak yazılır.
        $order->add_order_note(esc_html($note));
    }

    private function trim_for_log(string $s, int $max = 3000): string
    {
        $s = QNB_3DPay_Api::mask_body($s);
        $s = preg_replace('/\s+/', ' ', $s);
        return strlen($s) > $max ? substr($s, 0, $max) . '…' : $s;
    }

    /**
     * Hatalar ve uyarılar her zaman, info/debug yalnızca debug modunda yazılır.
     */
    protected function log(string $message, string $level = 'info'): void
    {
        $always = in_array($level, ['error', 'critical', 'warning'], true);
        if (!$always && !$this->debug) {
            return;
        }
        if (null === $this->logger) {
            $this->logger = wc_get_logger();
        }
        $this->logger->log($level, $message, ['source' => self::LOG_SOURCE]);
    }
}
