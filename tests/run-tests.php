<?php
/**
 * QNB_3DPay_Api birim testleri (WordPress gerektirmez).
 * Çalıştırma: php tests/run-tests.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/includes/class-qnb-3dpay-api.php';

$failures = 0;
$count    = 0;

function check(string $name, $expected, $actual): void
{
    global $failures, $count;
    $count++;
    if ($expected === $actual) {
        echo "  ok   {$name}\n";
        return;
    }
    $failures++;
    echo "  FAIL {$name}\n       beklenen: " . var_export($expected, true) . "\n       gelen   : " . var_export($actual, true) . "\n";
}

// QNB demo hesabı (dokümandaki 3D PAY BİLGİLERİ) ve bilinen test vektörleri.
$merchant_id   = '085300000009704';
$user_code     = 'QNB_API_KULLANICI_3DPAY';
$merchant_pass = '12345678';

echo "Hash\n";
check('istek hash vektörü', 'zmSUxYPhmCj7QOzqpk/28LuE1Oc=', QNB_3DPay_Api::request_hash([
    'MbrId'            => '5',
    'OrderId'          => '2020110828BC',
    'PurchAmount'      => '100.01',
    'OkUrl'            => 'http://localhost/finansbank-payfor/3d/response.php',
    'FailUrl'          => 'http://localhost/finansbank-payfor/3d/response.php',
    'TxnType'          => 'Auth',
    'InstallmentCount' => '0',
    'Rnd'              => '0.43625700 1604831630',
], $merchant_pass));

$resp = [
    'OrderId'        => '20221031FD04',
    'AuthCode'       => '',
    'ProcReturnCode' => 'V033',
    '3DStatus'       => '1',
    'ResponseRnd'    => 'PF638028511007418219',
    'ResponseHash'   => 'rVcKoOOl3jKukGLHcQaVM6ZuznU=',
];
check('yanıt hash vektörü doğrulanır', true, QNB_3DPay_Api::verify_response_hash($resp, $merchant_id, $merchant_pass, $user_code));
check('3DStatus değişince hash tutmaz', false, QNB_3DPay_Api::verify_response_hash(array_merge($resp, ['3DStatus' => '']), $merchant_id, $merchant_pass, $user_code));
check('ProcReturnCode 00 yapılınca hash tutmaz', false, QNB_3DPay_Api::verify_response_hash(array_merge($resp, ['ProcReturnCode' => '00']), $merchant_id, $merchant_pass, $user_code));
check('yanlış MerchantPass ile tutmaz', false, QNB_3DPay_Api::verify_response_hash($resp, $merchant_id, 'x', $user_code));
check('ResponseHash yoksa false', false, QNB_3DPay_Api::verify_response_hash(array_diff_key($resp, ['ResponseHash' => 1]), $merchant_id, $merchant_pass, $user_code));
check('ResponseRnd yoksa false', false, QNB_3DPay_Api::verify_response_hash(array_merge($resp, ['ResponseRnd' => '']), $merchant_id, $merchant_pass, $user_code));
check('boş MerchantPass ile false', false, QNB_3DPay_Api::verify_response_hash($resp, $merchant_id, '', $user_code));

echo "Tutar\n";
foreach ([
    '150'        => '150.00',
    '150.5'      => '150.50',
    '150.50'     => '150.50',
    '0.01'       => '0.01',
    '1234.005'   => '1234.01',
    '1234.004'   => '1234.00',
    '19.995'     => '20.00',
    '0.30000000000000004' => '0.30',
    '007.10'     => '7.10',
] as $in => $out) {
    check("format_amount('{$in}')", $out, QNB_3DPay_Api::format_amount((string) $in));
}
foreach (['0', '0.00', '0.004', '-5', 'abc', '', '1,50', '1e3'] as $bad) {
    check("format_amount('{$bad}') geçersiz", null, QNB_3DPay_Api::format_amount($bad));
}

echo "Diğer\n";
check('TRY kodu', '949', QNB_3DPay_Api::currency_code('try'));
check('desteklenmeyen para birimi', null, QNB_3DPay_Api::currency_code('JPY'));
check('expiry 1/2031', '0131', QNB_3DPay_Api::format_expiry('1', '2031'));
check('expiry 12/29', '1229', QNB_3DPay_Api::format_expiry('12', '29'));
check('luhn geçerli', true, QNB_3DPay_Api::luhn_valid('4111111111111111'));
check('luhn geçersiz', false, QNB_3DPay_Api::luhn_valid('4111111111111112'));
check('luhn kısa', false, QNB_3DPay_Api::luhn_valid('411111111111'));
$oid = QNB_3DPay_Api::generate_order_id(12345);
check('order id biçimi', 1, preg_match('/^WC12345T[A-Z0-9]+$/', $oid));
check('order id ≤ 24', true, strlen($oid) <= 24);
check('order id benzersiz', true, $oid !== QNB_3DPay_Api::generate_order_id(12345));
check('rnd 20 karakter', 20, strlen(QNB_3DPay_Api::generate_rnd()));
check('kod açıklaması 5 → 05', 'İşlem onaylanmadı', QNB_3DPay_Api::describe_code('5'));
check('Windows-1254 → UTF-8', 'Şifre', QNB_3DPay_Api::to_utf8("\xDEifre"));
check('UTF-8 dokunulmaz', 'Şifre', QNB_3DPay_Api::to_utf8('Şifre'));

echo "Yanıt ayrıştırma\n";
$redirect_html = '<html><head><title>x</title></head><body onload="document.forms[0].submit()">'
    . '<form action="https://acs.example.com/pareq" method="post"><input type="hidden" name="PaReq" value="abc"/>'
    . '<input type="hidden" name="TermUrl" value="https://vpostest.qnbfinansbank.com/Gateway/Mpi.aspx"/><input type="hidden" name="MD" value="m1"/></form></body></html>';
$r = QNB_3DPay_Api::analyze_gateway_response($redirect_html);
check('ACS formu → redirect', 'redirect', $r['type']);
check('ACS formu MD alanı', 'm1', $r['fields']['MD'] ?? null);

$error_html = '<html><body><form action="https://shop.test/wc-api/qnb_3dpay_callback/?order_id=1" method="post">'
    . '<input type="hidden" name="ProcReturnCode" value="M030"><input type="hidden" name="ErrMsg" value="HASH Uyusmazligi">'
    . '<input type="hidden" name="Pan" value="4155650100416111"></form></body></html>';
$r = QNB_3DPay_Api::analyze_gateway_response($error_html);
check('hata formu → error', 'error', $r['type']);
check('hata formu kodu', 'M030', $r['code']);
check('hata formu mesajı', 'HASH Uyusmazligi', $r['message']);

$r = QNB_3DPay_Api::analyze_gateway_response('ProcReturnCode=M047;;ErrMsg=IP kısıtlaması;;OrderId=X1');
check(';; hata → error', 'error', $r['type']);
check(';; hata kodu', 'M047', $r['code']);
$r = QNB_3DPay_Api::analyze_gateway_response('ProcReturnCode=00;;OrderId=X1;;AuthCode=123456');
check(';; 00 → result', 'result', $r['type']);
check('boş yanıt → error', 'error', QNB_3DPay_Api::analyze_gateway_response("  \n")['type']);
check('düz metin → error', 'error', QNB_3DPay_Api::analyze_gateway_response('Service Unavailable')['type']);

echo "Base href ve maskeleme\n";
$injected = QNB_3DPay_Api::inject_base_href('<html><head><title>x</title></head></html>', QNB_3DPay_Api::TEST_GATEWAY_URL);
check('base eklenir', true, strpos($injected, '<head><base href="https://vpostest.qnbfinansbank.com/Gateway/">') !== false);
check('mevcut base korunur', '<head><base href="/">', QNB_3DPay_Api::inject_base_href('<head><base href="/">', QNB_3DPay_Api::TEST_GATEWAY_URL));
$masked = QNB_3DPay_Api::mask_body($error_html);
check('HTML Pan maskelenir', false, strpos($masked, '4155650100416111'));
$masked = QNB_3DPay_Api::mask_body('<input value="4155650100416111" name="Pan">');
check('value önce gelse de maskelenir', false, strpos($masked, '4155650100416111'));
$masked = QNB_3DPay_Api::mask_body('OrderId=1;;Pan=4155650100416111;;Cvv2=715;;ErrMsg=x');
check(';; Pan/Cvv2 maskelenir', 'OrderId=1;;Pan=***;;Cvv2=***;;ErrMsg=x', $masked);
$m = QNB_3DPay_Api::mask(['Pan' => '4155650100416111', 'Cvv2' => '715', 'UserPass' => 'p', 'OrderId' => 'A']);
check('dizi maskeleme Pan', '415565******6111', $m['Pan']);
check('dizi maskeleme Cvv2', '***', $m['Cvv2']);
check('dizi maskeleme diğer alan', 'A', $m['OrderId']);
$m = QNB_3DPay_Api::mask(['Hash' => 'abc=', 'OkUrl' => 'https://s.test/wc-api/x/?order_id=5&key=wc_order_SECRET&r=ok']);
check('Hash maskelenir', '***', $m['Hash']);
check('OkUrl sipariş anahtarı maskelenir', 'https://s.test/wc-api/x/?order_id=5&key=***&r=ok', $m['OkUrl']);

echo "\n{$count} test, {$failures} hata\n";
exit($failures > 0 ? 1 : 0);
