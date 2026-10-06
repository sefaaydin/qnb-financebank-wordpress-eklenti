# QNB Finansbank Sanal POS (3D Pay) — WooCommerce Eklentisi

QNB Finansbank Sanal POS ile WooCommerce mağazanızda **3D Pay (3D Payment)** modeliyle kredi/banka kartı ödemesi alın.

**Geliştirici:** Devasa Bilişim | [devasa.com.tr](https://devasa.com.tr)
**İletişim:** +90 554 816 85 16 | info@devasa.com.tr

---

## Özellikler

- 3D Pay (3D Secure) ödeme — provizyonu banka 3D doğrulamasının ardından kendisi yapar
- Klasik ödeme sayfası (`[woocommerce_checkout]`) **ve** Blok tabanlı ödeme sayfası (Cart & Checkout Blocks) desteği
- "Siparişi öde" (order-pay) sayfası desteği
- Test modu: ayrı test hesap bilgileri ve `vpostest.qnbfinansbank.com` ortamı
- Banka yanıtının imza (ResponseHash) doğrulaması, sipariş numarası / tutar / para birimi eşleştirmesi
- Eşzamanlı ve tekrar gelen banka dönüşlerine karşı kilit ve idempotent işlem
- Başarısız ödemede sepetin korunması ve hata mesajının ödeme sayfasında gösterilmesi
- WooCommerce günlüklerine detaylı log (kaynak: `qnb-3dpay`); kart numarası, CVV, şifre ve hash hiçbir zaman açık yazılmaz
- Sipariş notlarına banka yanıt kodu, açıklaması, onay kodu ve referans numarası
- HPOS (yüksek performanslı sipariş tabloları) uyumlu
- LiteSpeed Cache ile uyumlu (ödeme uç noktaları önbelleğe alınmaz)
- Devasa lisans kontrolü (alan adı bazlı, günlük doğrulama, 7 gün çevrimdışı tolerans)

## Gereksinimler

- WordPress 6.0+
- WooCommerce 7.0+ (WooCommerce 11.1 ile test edildi)
- PHP 7.4+
- SSL sertifikası (HTTPS)
- QNB Finansbank sanal POS üye işyeri hesabı (3D Pay yetkili)
- Devasa Bilişim lisans anahtarı

## Kurulum

1. Eklenti klasörünü `/wp-content/plugins/` altına yükleyin (veya ZIP olarak Eklentiler > Yeni Ekle'den yükleyin).
2. Eklentiyi etkinleştirin.
3. **WooCommerce > Ayarlar > Ödemeler > QNB Finansbank Sanal POS (3D Pay)** bölümüne gidin.
4. Lisans anahtarını girip kaydedin; durum **AKTİF** görünmelidir.
5. Canlı ortam bilgilerini girin:

| Alan | Açıklama |
|---|---|
| Kurum Kodu (MbrId) | QNB Finansbank için `5` |
| Üye İşyeri No (MerchantID) | Bankanın verdiği üye işyeri numarası |
| API Kullanıcı Kodu (UserCode) | 3D Pay için tanımlı API kullanıcısı |
| API Kullanıcı Şifresi (UserPass) | API kullanıcısının şifresi |
| 3D Anahtarı (MerchantPass) | Hash hesaplamasında kullanılan üye işyeri 3D anahtarı |

Banka 3D dönüş adresi (OkUrl/FailUrl) her istekte otomatik gönderilir:
`https://alanadiniz.com/wc-api/qnb_3dpay_callback/`

## Banka Tarafında Yapılması Gerekenler

- Ödeme isteği sunucunuzdan bankaya gönderildiği için **sunucunuzun dış IP adresinin** QNB Finansbank'a tanımlatılması gerekir. Tanımlı değilse banka `M047` (IP kısıtlaması) hatası döner.
- Üye işyerinin 3D Pay işlem tipine ve kullanılacak para birimine yetkili olduğundan emin olun.

## Test Modu

"Test Modu" açıkken tüm istekler `https://vpostest.qnbfinansbank.com/Gateway/Default.aspx` adresine gider ve gerçek tahsilat yapılmaz. Test alanları, QNB dokümanındaki **3D PAY BİLGİLERİ** demo hesabıyla önceden doldurulmuştur:

| Alan | Değer |
|---|---|
| MbrId | 5 |
| MerchantID | 085300000009704 |
| UserCode | QNB_API_KULLANICI_3DPAY |
| UserPass | UcBN0 |
| MerchantPass | 12345678 |

> Bu değerleri ve test kartı bilgilerini [Demo Ortam Test Bilgileri](https://vpos.qnbfinansbank.com/Help/testInformation) sayfasındaki "3D PAY BİLGİLERİ" başlığıyla karşılaştırın; banka değiştirdiyse ayarlardan güncelleyin.

Güvenlik notları:
- Test modunda başlatılmış bir ödemenin sonucu, test modu kapatıldıktan sonra gelirse **kabul edilmez** (test hesabı herkese açık olduğu için).
- Canlı sitede test modunu açık bırakmayın: test kartıyla yapılan "ödemeler" siparişi ödendi olarak işaretler (sipariş notunda "TEST — gerçek tahsilat yok" yazar).

## Ödeme Akışı

```
Müşteri kart bilgilerini girer → "Siparişi ver"
    │
Sunucu, kart + hash ile Default.aspx'e POST eder (SecureType=3DPay)
    │
Banka 3D yönlendirme sayfasını döndürür → müşterinin tarayıcısına basılır
    │
Müşteri bankasının 3D Secure (SMS) ekranında doğrulama yapar
    │
Banka sonucu OkUrl/FailUrl'e POST eder (/wc-api/qnb_3dpay_callback/)
    │
ResponseHash + sipariş no + tutar doğrulanır
    ├─ ProcReturnCode = 00 → sipariş ödendi (İşleniyor), teşekkür sayfası
    └─ diğer                → sipariş başarısız, sepet korunur, hata ödeme sayfasında gösterilir
```

## Sipariş Durumları

| Durum | Ne zaman |
|---|---|
| İşleniyor | Banka onayı (00) imzası, sipariş numarası ve tutarı doğrulandı |
| Beklemede | Banka geçerli bir onay gönderdi ama sipariş numarası, tutar, para birimi veya ödeme yöntemi eşleşmedi — **QNB panelinden kontrol etmeden siparişi göndermeyin** |
| Başarısız | Banka işlemi reddetti veya ödeme başlatılamadı |
| Ödeme bekleniyor | Müşteri 3D ekranından dönmedi; WooCommerce süre dolunca iptal eder ve sipariş notuna banka sipariş numarası düşülür |

Sipariş zaten ödenmişken bankadan farklı numaralı ikinci bir onay gelirse sipariş notuna **"Çift tahsilat olabilir"** uyarısı düşülür.

## Lisans Kontrolü

Lisans, `vakif-katilim-sanalpos` eklentisindeki Devasa lisans istemcisiyle aynı sunucuyu (`lisans.devasa.com.tr`) kullanır.

- **Ürün kodu:** `qnbfinansbank-sanalpos` — lisans sunucusunda bu ürün koduyla lisans tanımlanmalıdır.
- Doğrulama 24 saat önbelleklenir ve günlük cron ile yenilenir; ayarlar her kaydedildiğinde yeniden doğrulanır.
- Lisans sunucusuna ulaşılamazsa son başarılı doğrulamadan itibaren 7 gün geçerli sayılır; bu sürede de ulaşılamazsa 1 saat sonra tekrar denenir.
- Lisans geçersizse ödeme formu yerine uyarı gösterilir ve ödeme başlatılmaz; yöneticiye panelde uyarı çıkar.
- Sınıf, ayar ve cron adları bu eklentiye özeldir; Vakıf Katılım eklentisiyle aynı sitede sorunsuz çalışır.

## Dosya Yapısı

```
├── qnb-finansbank-sanalpos.php            # Ana eklenti dosyası
├── includes/
│   ├── class-wc-gateway-qnb-3dpay.php     # WooCommerce ödeme geçidi
│   ├── class-qnb-3dpay-api.php            # Hash, tutar, yanıt ayrıştırma (WordPress'ten bağımsız)
│   ├── class-qnb-3dpay-blocks.php         # Blok ödeme sayfası entegrasyonu
│   └── class-devasa-qnb-license-client.php
├── assets/
│   ├── css/qnb-card-form.css
│   └── js/qnb-card-form.js, qnb-blocks.js
├── tests/run-tests.php                    # php tests/run-tests.php
├── README.md
└── CHANGELOG.md
```

## Sık Karşılaşılan Hatalar

| Kod | Anlamı | Çözüm |
|---|---|---|
| M047 | IP kısıtlaması | Sunucu dış IP'sini bankaya tanımlatın |
| M030 | Hash uyuşmazlığı | MerchantPass (3D anahtarı) ve MbrId'yi kontrol edin |
| V004 / M049 | Kullanıcı doğrulanamadı | UserCode / UserPass'i kontrol edin |
| V008 / V001 | Üye işyeri bulunamadı | MerchantID'yi ve test/canlı modunu kontrol edin |
| 51 | Bakiye/limit yetersiz | Müşteri tarafı |

## Destek

Teknik destek: **info@devasa.com.tr** | **+90 554 816 85 16**

## Lisans

Bu eklenti Devasa Bilişim tarafından geliştirilmiştir. Tüm hakları saklıdır.
