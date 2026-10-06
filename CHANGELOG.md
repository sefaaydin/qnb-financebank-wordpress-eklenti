# Değişiklik Günlüğü

Tüm önemli değişiklikler bu dosyada belgelenir.

---

## [1.0.0] - 2026-10-06

### Eklenen
- QNB Finansbank Sanal POS **3D Pay (3D Payment)** ödeme geçidi (`SecureType=3DPay`, `TxnType=Auth`, tek çekim)
- İstek hash'i: `Base64(SHA1(MbrId + OrderId + PurchAmount + OkUrl + FailUrl + TxnType + InstallmentCount + Rnd + MerchantPass))`
- Yanıt imza doğrulaması: `Base64(SHA1(MerchantId + MerchantPass + OrderId + AuthCode + ProcReturnCode + 3DStatus + ResponseRnd + UserCode))`
- Her denemede benzersiz banka sipariş numarası (`WC{sipariş}T{zaman}{rastgele}`); aynı sipariş tekrar denenebilir
- Tutar float kullanılmadan, string üzerinden 2 ondalık yarım-yukarı yuvarlanır
- Para birimleri: TRY (949), USD (840), EUR (978), GBP (826)
- Klasik ödeme sayfası, Blok ödeme sayfası (Store API) ve "Siparişi öde" sayfası desteği
- Test modu: ayrı test hesap bilgileri (QNB demo 3D Pay hesabıyla önceden dolu), `vpostest.qnbfinansbank.com`
- Gelişmiş: canlı/test gateway adresini değiştirme (yalnızca https)
- Banka 3D sayfası tek kullanımlık, 15 dakika geçerli ve sipariş anahtarına bağlı saklanır; göreli adresler için `<base>` eklenir
- Ödeme uç noktalarında `no-cache` ve `X-LiteSpeed-Cache-Control: no-cache` başlıkları
- Başarısız ödemede sepetin geri yüklenmesi ve hata mesajının ödeme sayfasında gösterilmesi
- QNB dönüş kodları için Türkçe açıklamalar (ISO kodları + M/V kodları)
- Windows-1254 / ISO-8859-9 gelen banka mesajlarının UTF-8'e çevrilmesi
- HPOS ve Cart & Checkout Blocks uyumluluk bildirimi

### Güvenlik
- Kart numarası, CVV, son kullanma, şifreler, hash ve sipariş anahtarı loglarda maskelenir; banka sayfası loga ham yazılmaz
- Klasik ödeme sayfasında görünür kart alanları isimsizdir; kart bilgisi yalnızca "Siparişi ver" anında gönderilir (adres/kargo güncellemelerine gitmez)
- İmzası doğrulanamayan onay yanıtları yok sayılır, sipariş durumu değişmez
- İmzası geçerli ancak sipariş no / tutar / para birimi / ödeme yöntemi eşleşmeyen onaylar siparişi **Beklemede**ye alır ve kaydedilir
- Ödenmiş siparişe gelen ikinci onay ve önceki eşleşmeyen onaylar için "çift tahsilat olabilir" uyarı notu
- Test modunda başlatılmış denemenin sonucu, test modu kapatıldıktan sonra kabul edilmez
- Eski denemeye ait geç gelen hata yanıtı güncel denemeyi bozmaz
- Eşzamanlı callback'lere karşı veritabanı kilidi; kilit sonrası sipariş önbellek atlanarak yeniden okunur
- Banka alanları sipariş notlarına düz metin olarak yazılır
- Müşteri 3D'den dönmeden iptal edilen siparişlere banka sipariş numaralı kontrol notu

### Lisans Sistemi
- Devasa lisans istemcisi (`Devasa_QNB_License_Client`), ürün kodu `qnbfinansbank-sanalpos`, sunucu `lisans.devasa.com.tr`
- Vakıf Katılım eklentisindeki istemciyle aynı mantık; sınıf/ayar/cron adları ayrı olduğu için iki eklenti birlikte çalışır
- Farklar: ayarlar her kaydedildiğinde yeniden doğrulama; sunucuya ulaşılamazsa geçersiz sonuç 24 saat yerine 1 saat önbelleklenir; grace period Unix zamanıyla hesaplanır (saat dilimi kayması yok)
