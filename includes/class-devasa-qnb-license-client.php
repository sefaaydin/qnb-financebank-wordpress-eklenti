<?php
/**
 * Devasa lisans istemcisi — QNB Finansbank Sanal POS eklentisi için.
 *
 * vakif-katilim-sanalpos eklentisindeki Devasa_License_Client ile aynı mantık ve aynı
 * lisans sunucusu (lisans.devasa.com.tr) kullanılır. İki eklenti aynı sitede birlikte
 * çalışabilsin diye sınıf adı, option/transient anahtarları ve cron kancası bu eklentiye
 * özeldir; ürün kodu PRODUCT sabitindedir.
 */

if (!defined('ABSPATH')) { exit; }

class Devasa_QNB_License_Client
{
    const API_URL     = 'https://lisans.devasa.com.tr/wp-json/devasa-license/v1/verify';
    const CACHE_KEY   = 'devasa_lm_qnb_status';
    const CACHE_TTL   = DAY_IN_SECONDS;
    const GRACE_DAYS  = 7;
    const OPTION_KEY  = 'devasa_lm_qnb_license_key';
    const PRODUCT     = 'qnbfinansbank-sanalpos';
    const CRON_HOOK   = 'devasa_lm_qnb_daily_check';

    /**
     * Lisans geçerli mi?
     * Önce cache'e bakar, cache yoksa veya süresi dolmuşsa API'ye sorar.
     */
    public static function is_valid()
    {
        $cached = get_transient(self::CACHE_KEY);

        if ($cached !== false) {
            return $cached === 'valid';
        }

        $result = self::check_remote();
        return $result['valid'];
    }

    /**
     * Cache'teki lisans bilgisini döndürür (admin bilgilendirme için).
     */
    public static function get_status()
    {
        return get_option(self::CACHE_KEY . '_detail', [
            'valid'   => false,
            'message' => 'Lisans henüz doğrulanmadı.',
            'expires' => null,
            'checked' => null,
        ]);
    }

    /**
     * Lisans anahtarını döndürür.
     */
    public static function get_key()
    {
        return trim((string) get_option(self::OPTION_KEY, ''));
    }

    /**
     * Lisans anahtarını kaydeder ve hemen doğrular.
     */
    public static function save_key($key)
    {
        update_option(self::OPTION_KEY, sanitize_text_field($key), false);
        delete_transient(self::CACHE_KEY);
        return self::check_remote();
    }

    /**
     * API sunucusuna lisans doğrulama isteği gönderir.
     */
    public static function check_remote()
    {
        $key = self::get_key();

        if (empty($key)) {
            $result = ['valid' => false, 'message' => 'Lisans anahtarı girilmemiş.', 'expires' => null];
            self::cache_result($result);
            return $result;
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => 15,
            'body'    => [
                'license_key' => $key,
                'site_url'    => home_url(),
                'product'     => self::PRODUCT,
            ],
        ]);

        if (is_wp_error($response)) {
            // Sunucuya ulaşılamadı - grace period uygula
            return self::handle_offline($response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body) || !isset($body['valid'])) {
            return self::handle_offline('Geçersiz API yanıtı');
        }

        $result = [
            'valid'   => (bool) $body['valid'],
            'message' => $body['message'] ?? '',
            'expires' => $body['expires'] ?? null,
        ];

        self::cache_result($result);
        return $result;
    }

    /**
     * Sunucuya ulaşılamadığında grace period uygular.
     * Son başarılı doğrulamadan 7 gün içinde geçerli sayar.
     */
    private static function handle_offline($error_msg)
    {
        $detail = get_option(self::CACHE_KEY . '_detail', []);
        // Son başarılı kontrolün Unix zamanı (eski kayıtlarda yalnızca yerel saatli 'checked' vardır).
        $last_ts = !empty($detail['checked_ts']) ? (int) $detail['checked_ts'] : (!empty($detail['checked']) ? (int) strtotime(get_gmt_from_date($detail['checked'])) : 0);

        if ($last_ts && !empty($detail['valid'])) {
            $grace_end = $last_ts + (self::GRACE_DAYS * DAY_IN_SECONDS);
            if (time() < $grace_end) {
                set_transient(self::CACHE_KEY, 'valid', HOUR_IN_SECONDS);
                return ['valid' => true, 'message' => 'Sunucuya ulaşılamadı, grace period aktif.', 'expires' => $detail['expires'] ?? null];
            }
        }

        // Sunucu geçici olarak erişilemezse ödeme 24 saat kapalı kalmasın: 1 saat sonra yeniden dene.
        $result = ['valid' => false, 'message' => 'Lisans sunucusuna ulaşılamadı: ' . $error_msg, 'expires' => null];
        self::cache_result($result, HOUR_IN_SECONDS, false);
        return $result;
    }

    /**
     * Sonucu cache ve option'a yazar.
     */
    private static function cache_result($result, $ttl = null, $is_server_answer = true)
    {
        $status = $result['valid'] ? 'valid' : 'invalid';
        set_transient(self::CACHE_KEY, $status, $ttl ?: self::CACHE_TTL);

        $detail            = $result;
        $detail['checked'] = current_time('mysql');
        // Grace period yalnızca sunucunun gerçekten verdiği son yanıta göre hesaplanır.
        $prev                 = get_option(self::CACHE_KEY . '_detail', []);
        $detail['checked_ts'] = $is_server_answer ? time() : (int) ($prev['checked_ts'] ?? 0);
        update_option(self::CACHE_KEY . '_detail', $detail, false);
    }

    /**
     * Günlük cron görevini kaydeder.
     */
    public static function schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Cron görevini siler.
     */
    public static function unschedule_cron()
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }
}
