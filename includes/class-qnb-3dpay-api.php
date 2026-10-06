<?php
/**
 * QNB Finansbank (PayFor) 3D Pay yardımcıları.
 *
 * WordPress'e bağımlı olmayan saf fonksiyonlar: hash hesaplama, tutar/son kullanma
 * formatlama, banka yanıtı ayrıştırma. Bu sayede tests/run-tests.php ile WordPress
 * olmadan test edilebilir.
 *
 * Hash formülleri (QNB Payment Gateway dokümanı, 3D Pay):
 *   İstek : Base64(SHA1(MbrId + OrderId + PurchAmount + OkUrl + FailUrl + TxnType + InstallmentCount + Rnd + MerchantPass))
 *   Yanıt : Base64(SHA1(MerchantId + MerchantPass + OrderId + AuthCode + ProcReturnCode + 3DStatus + ResponseRnd + UserCode))
 */

if (!defined('ABSPATH')) { exit; }

final class QNB_3DPay_Api
{
    const LIVE_GATEWAY_URL = 'https://vpos.qnbfinansbank.com/Gateway/Default.aspx';
    const TEST_GATEWAY_URL = 'https://vpostest.qnbfinansbank.com/Gateway/Default.aspx';
    const DEFAULT_MBR_ID   = '5';

    /** ISO 4217 harf kodu => QNB sayısal para birimi kodu */
    const CURRENCIES = [
        'TRY' => '949',
        'USD' => '840',
        'EUR' => '978',
        'GBP' => '826',
    ];

    /**
     * Loglarda asla açık yazılmayacak alanlar. Hash da gizlenir: diğer girdileri logda bulunduğundan
     * açık yazılırsa MerchantPass'e karşı çevrimdışı deneme yapılabilir.
     */
    const SECRET_FIELDS = ['Pan', 'Cvv2', 'Expiry', 'UserPass', 'MerchantPass', 'Hash'];

    /**
     * Base64(SHA1(raw)) — QNB'nin tüm hash'lerinde kullanılan özet fonksiyonu.
     */
    public static function hash(string $data): string
    {
        return base64_encode(sha1($data, true));
    }

    /**
     * 3D Pay istek hash'i.
     *
     * @param array<string,string> $p İstek parametreleri (MbrId, OrderId, PurchAmount, OkUrl, FailUrl, TxnType, InstallmentCount, Rnd)
     */
    public static function request_hash(array $p, string $merchant_pass): string
    {
        return self::hash(
            (string) ($p['MbrId'] ?? '')
            . (string) ($p['OrderId'] ?? '')
            . (string) ($p['PurchAmount'] ?? '')
            . (string) ($p['OkUrl'] ?? '')
            . (string) ($p['FailUrl'] ?? '')
            . (string) ($p['TxnType'] ?? '')
            . (string) ($p['InstallmentCount'] ?? '')
            . (string) ($p['Rnd'] ?? '')
            . $merchant_pass
        );
    }

    /**
     * Bankanın OkUrl/FailUrl'e gönderdiği yanıtın beklenen hash'i.
     *
     * @param array<string,mixed> $r Bankadan gelen POST verisi
     */
    public static function response_hash(array $r, string $merchant_id, string $merchant_pass, string $user_code): string
    {
        return self::hash(
            $merchant_id
            . $merchant_pass
            . (string) ($r['OrderId'] ?? '')
            . (string) ($r['AuthCode'] ?? '')
            . (string) ($r['ProcReturnCode'] ?? '')
            . (string) ($r['3DStatus'] ?? '')
            . (string) ($r['ResponseRnd'] ?? '')
            . $user_code
        );
    }

    /**
     * Yanıt hash'ini sabit zamanlı karşılaştırmayla doğrular.
     * ResponseHash veya ResponseRnd yoksa doğrulama başarısız sayılır.
     */
    public static function verify_response_hash(array $r, string $merchant_id, string $merchant_pass, string $user_code): bool
    {
        $given = (string) ($r['ResponseHash'] ?? '');
        $rnd   = (string) ($r['ResponseRnd'] ?? '');
        if ($given === '' || $rnd === '' || $merchant_pass === '') {
            return false;
        }
        return hash_equals(self::response_hash($r, $merchant_id, $merchant_pass, $user_code), $given);
    }

    /**
     * Tutarı QNB formatına çevirir: noktalı, 2 ondalık ("1234.50").
     * Float kullanmaz; string üzerinden yarım-yukarı yuvarlar.
     *
     * @return string|null Geçersiz veya sıfır/negatif tutarda null
     */
    public static function format_amount(string $amount): ?string
    {
        $amount = trim($amount);
        if (!preg_match('/^\d+(\.\d+)?$/', $amount)) {
            return null;
        }

        $parts = explode('.', $amount, 2);
        $int   = ltrim($parts[0], '0');
        $int   = $int === '' ? '0' : $int;
        $frac  = $parts[1] ?? '';

        // 15 haneden uzun tam kısım taşma riski taşır; sanal POS için anlamsız.
        if (strlen($int) > 15) {
            return null;
        }

        $minor = ((int) $int) * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0');
        if (strlen($frac) > 2 && (int) $frac[2] >= 5) {
            $minor++;
        }

        if ($minor <= 0) {
            return null;
        }

        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function currency_code(string $iso): ?string
    {
        $iso = strtoupper(trim($iso));
        return self::CURRENCIES[$iso] ?? null;
    }

    /**
     * Son kullanma tarihini QNB'nin beklediği AAYY biçimine çevirir.
     */
    public static function format_expiry(string $month, string $year): string
    {
        $m = str_pad(preg_replace('/\D+/', '', $month), 2, '0', STR_PAD_LEFT);
        $y = substr(preg_replace('/\D+/', '', $year), -2);
        return $m . str_pad($y, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Bankaya gönderilecek benzersiz sipariş numarası.
     * Aynı WooCommerce siparişi tekrar denendiğinde banka "mükerrer sipariş" hatası
     * vermesin diye her denemede yeni bir son ek üretilir. Yalnızca harf/rakam, ≤ 24 karakter.
     */
    public static function generate_order_id(int $wc_order_id): string
    {
        $suffix = strtoupper(base_convert((string) time(), 10, 36)) . strtoupper(bin2hex(random_bytes(2)));
        return 'WC' . $wc_order_id . 'T' . $suffix;
    }

    public static function generate_rnd(): string
    {
        return strtoupper(bin2hex(random_bytes(10)));
    }

    /**
     * Luhn (mod 10) kart numarası kontrolü.
     */
    public static function luhn_valid(string $pan): bool
    {
        if (!preg_match('/^\d{13,19}$/', $pan)) {
            return false;
        }
        $sum    = 0;
        $double = false;
        for ($i = strlen($pan) - 1; $i >= 0; $i--) {
            $d = (int) $pan[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum   += $d;
            $double = !$double;
        }
        return $sum % 10 === 0;
    }

    /**
     * Banka yanıtları zaman zaman ISO-8859-9 / Windows-1254 gelir; UTF-8'e çevirir.
     */
    public static function to_utf8(string $s): string
    {
        if ($s === '' || !function_exists('mb_check_encoding') || mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $out = @mb_convert_encoding($s, 'UTF-8', 'Windows-1254');
        return is_string($out) ? $out : $s;
    }

    /**
     * "Anahtar=Değer;;Anahtar=Değer" biçimindeki yanıtı diziye çevirir.
     *
     * @return array<string,string>
     */
    public static function parse_delimited(string $body): array
    {
        $out = [];
        foreach (explode(';;', trim($body)) as $pair) {
            if ($pair === '' || strpos($pair, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $pair, 2);
            $k = trim($k);
            if ($k !== '') {
                $out[$k] = trim($v);
            }
        }
        return $out;
    }

    /**
     * HTML içindeki ilk formun action'ını ve input alanlarını çıkarır.
     *
     * @return array{action:string, fields:array<string,string>}
     */
    public static function parse_form(string $html): array
    {
        $result = ['action' => '', 'fields' => []];

        if (class_exists('DOMDocument')) {
            $prev = libxml_use_internal_errors(true);
            $doc  = new DOMDocument();
            $ok   = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            if ($ok) {
                $forms = $doc->getElementsByTagName('form');
                if ($forms->length > 0) {
                    /** @var DOMElement $form */
                    $form             = $forms->item(0);
                    $result['action'] = (string) $form->getAttribute('action');
                    foreach ($form->getElementsByTagName('input') as $input) {
                        /** @var DOMElement $input */
                        $name = (string) $input->getAttribute('name');
                        if ($name !== '') {
                            $result['fields'][$name] = (string) $input->getAttribute('value');
                        }
                    }
                }
                return $result;
            }
        }

        // DOM eklentisi yoksa basit regex yedeği.
        if (preg_match('~<form[^>]*action=["\']([^"\']*)["\']~i', $html, $m)) {
            $result['action'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        if (preg_match_all('~<input\b[^>]*>~i', $html, $inputs)) {
            foreach ($inputs[0] as $tag) {
                if (preg_match('~\bname=["\']([^"\']+)["\']~i', $tag, $n)) {
                    $v = preg_match('~\bvalue=["\']([^"\']*)["\']~i', $tag, $vm) ? $vm[1] : '';
                    $result['fields'][$n[1]] = html_entity_decode($v, ENT_QUOTES, 'UTF-8');
                }
            }
        }
        return $result;
    }

    /**
     * Default.aspx yanıtını sınıflandırır.
     *
     *  - redirect : Müşterinin tarayıcısında çalıştırılacak 3D yönlendirme sayfası
     *  - result   : Banka işlemi 3D sayfasına gitmeden sonuçlandırdı (alanlar 'fields' içinde)
     *  - error    : İşlem başlatılamadı (kod/mesaj ile)
     *
     * @return array{type:string, code:string, message:string, fields:array<string,string>}
     */
    public static function analyze_gateway_response(string $body): array
    {
        $body = trim($body);
        $out  = ['type' => 'error', 'code' => '', 'message' => '', 'fields' => []];

        if ($body === '') {
            $out['message'] = 'Bankadan boş yanıt alındı.';
            return $out;
        }

        $is_html = stripos($body, '<form') !== false || stripos($body, '<html') !== false;

        if ($is_html) {
            $form   = self::parse_form($body);
            $fields = $form['fields'];
            $code   = (string) ($fields['ProcReturnCode'] ?? '');
            $err    = (string) ($fields['ErrorCode'] ?? '');
            $msg    = trim((string) ($fields['ErrMsg'] ?? ''));

            // Hata/sonuç alanı taşıyan form: banka sonucu doğrudan OkUrl/FailUrl'e postalıyor.
            if ($err !== '' || ($code !== '' && $code !== '00')) {
                $out['code']    = $code !== '' ? $code : $err;
                $out['message'] = $msg;
                $out['fields']  = $fields;
                return $out;
            }

            $has_form = stripos($body, '<form') !== false;
            if ($has_form) {
                $out['type']   = 'redirect';
                $out['fields'] = $fields;
                return $out;
            }

            $out['message'] = 'Bankadan beklenmeyen bir sayfa döndü.';
            return $out;
        }

        if (strpos($body, '=') !== false) {
            $fields = self::parse_delimited($body);
            $code   = (string) ($fields['ProcReturnCode'] ?? '');
            if ($code === '00') {
                $out['type']   = 'result';
                $out['code']   = $code;
                $out['fields'] = $fields;
                return $out;
            }
            $out['code']    = $code !== '' ? $code : (string) ($fields['ErrorCode'] ?? '');
            $out['message'] = trim((string) ($fields['ErrMsg'] ?? ''));
            $out['fields']  = $fields;
            return $out;
        }

        $out['message'] = 'Bankadan beklenmeyen bir yanıt alındı.';
        return $out;
    }

    /**
     * Bankanın döndürdüğü 3D sayfası göreli adres içeriyorsa, sitemiz üzerinden
     * gösterildiğinde kırılmasın diye <base> etiketi ekler.
     */
    public static function inject_base_href(string $html, string $gateway_url): string
    {
        if (stripos($html, '<base') !== false) {
            return $html;
        }
        $parts = parse_url($gateway_url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return $html;
        }
        $dir  = isset($parts['path']) ? rtrim(str_replace('\\', '/', dirname($parts['path'])), '/') : '';
        $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $dir . '/';
        $tag  = '<base href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '">';

        if (preg_match('~<head\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $pos) . $tag . substr($html, $pos);
        }
        return $tag . $html;
    }

    /**
     * Log için hassas alanları maskeler.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function mask(array $data): array
    {
        foreach ($data as $k => $v) {
            if (($k === 'OkUrl' || $k === 'FailUrl') && is_string($v)) {
                // Sipariş anahtarı (key=wc_order_...) logda tutulmaz.
                $data[$k] = (string) preg_replace('/([?&]key=)[^&]+/', '$1***', $v);
                continue;
            }
            if (in_array($k, self::SECRET_FIELDS, true)) {
                if ($k === 'Pan' && is_string($v) && strlen($v) >= 10) {
                    $data[$k] = substr($v, 0, 6) . str_repeat('*', strlen($v) - 10) . substr($v, -4);
                } else {
                    $data[$k] = '***';
                }
            }
        }
        return $data;
    }

    /**
     * Ham banka yanıtını (HTML veya "a=b;;c=d") log için maskeler: kart, CVV, son kullanma ve şifre değerleri.
     */
    public static function mask_body(string $body): string
    {
        $names = 'Pan|Cvv2|Expiry|UserPass|MerchantPass|CardNumber|Cvv|CardCvv2';

        // name="Pan" ... value="..."
        $body = preg_replace('~(<input\b[^>]*\bname=["\'](?:' . $names . ')["\'][^>]*\bvalue=["\'])[^"\']*~i', '$1***', $body);
        // value="..." ... name="Pan"
        $body = preg_replace('~(<input\b[^>]*\bvalue=["\'])[^"\']*(["\'][^>]*\bname=["\'](?:' . $names . ')["\'])~i', '$1***$2', $body);
        // Pan=...;;
        $body = preg_replace('~(^|;;|&)((?:' . $names . ')=)[^;&]*~i', '$1$2***', $body);

        return (string) $body;
    }

    /**
     * QNB dönüş kodlarının Türkçe açıklaması.
     */
    public static function describe_code(string $code): string
    {
        static $map = [
            '00'   => 'Onaylandı',
            '01'   => 'Kartı veren bankayı arayın',
            '02'   => 'Kartı veren bankayı arayın (özel durum)',
            '03'   => 'Geçersiz üye işyeri',
            '04'   => 'Karta el koyun',
            '05'   => 'İşlem onaylanmadı',
            '06'   => 'Kart kara listede',
            '09'   => 'Tekrar deneyin',
            '12'   => 'Geçersiz işlem',
            '13'   => 'Geçersiz tutar',
            '14'   => 'Geçersiz kart numarası',
            '15'   => 'Kartı veren banka tanımsız',
            '33'   => 'Kartın süresi dolmuş',
            '34'   => 'CVV2 hatalı',
            '41'   => 'Kayıp kart',
            '43'   => 'Çalıntı kart',
            '51'   => 'Bakiye / limit yetersiz',
            '54'   => 'Kartın süresi dolmuş',
            '55'   => 'Hatalı şifre',
            '57'   => 'Kart sahibine bu işlem izni verilmemiş',
            '58'   => 'Terminale bu işlem izni verilmemiş',
            '61'   => 'Para çekme limiti aşıldı',
            '62'   => 'Kısıtlı kart',
            '63'   => 'Güvenlik ihlali',
            '65'   => 'İşlem adedi limiti aşıldı',
            '75'   => 'Şifre deneme sayısı aşıldı',
            '91'   => 'Kartı veren banka hizmet dışı',
            '96'   => 'Sistem hatası',
            'M002' => 'Para birimi kodu geçersiz',
            'M004' => 'Tutar sıfır, boş veya hatalı',
            'M005' => 'CVV2 boş olamaz',
            'M006' => 'Son kullanma tarihi hatalı (AAYY olmalı)',
            'M007' => 'FailUrl eksik',
            'M008' => 'Kart numarası eksik veya hatalı uzunlukta',
            'M009' => 'Şifre eşleşmedi',
            'M010' => 'OkUrl hatalı',
            'M013' => 'Kurum kodu (MbrId) eksik',
            'M014' => 'Bilinmeyen güvenlik tipi',
            'M015' => 'Bilinmeyen işlem tipi',
            'M016' => 'Kullanıcı kodu eksik',
            'M017' => 'Kullanıcı şifresi eksik',
            'M018' => 'CVV2 hatalı',
            'M019' => 'İşlem tutarı izin verilen aralıkta değil',
            'M024' => 'Üye işyeri numarası eksik',
            'M025' => 'Bir alan hatalı uzunlukta',
            'M030' => 'Hash uyuşmazlığı (3D anahtarı / MerchantPass kontrol edin)',
            'M038' => 'OkUrl en fazla 200 karakter olabilir',
            'M041' => 'Geçersiz kart numarası',
            'M047' => 'Bu kullanıcı için IP kısıtlaması var (sunucu IP\'sini bankaya tanımlatın)',
            'M049' => 'Kimlik doğrulaması yapılamadı',
            'V000' => 'İşlem tamamlanamadı / devam ediyor',
            'V001' => 'Üye işyeri bulunamadı',
            'V003' => 'Sistem kapalı',
            'V004' => 'Kullanıcı doğrulanamadı',
            'V005' => 'Bilinmeyen kart tipi',
            'V006' => 'Kullanıcıya bu işlem tipi için izin verilmemiş',
            'V007' => 'Terminal aktif değil',
            'V008' => 'Üye işyeri bulunamadı',
            'V009' => 'Üye işyeri aktif değil',
            'V010' => 'Terminal bu işlem tipi için yetkili değil',
            'V013' => 'İşlem bulunamadı',
        ];

        $code = trim($code);
        if ($code === '') {
            return 'Kod yok';
        }
        if (isset($map[$code])) {
            return $map[$code];
        }
        // "5" gibi tek haneli ISO kodları "05" olarak da aranır.
        if (ctype_digit($code) && strlen($code) === 1 && isset($map['0' . $code])) {
            return $map['0' . $code];
        }
        return 'Tanımlanmamış kod';
    }
}
