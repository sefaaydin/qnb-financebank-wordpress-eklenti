/* QNB Finansbank 3D Pay — klasik ödeme sayfası kart alanları.
 *
 * Görünür kart alanlarının name'i yoktur; WooCommerce'in adres/kargo değişiminde gönderdiği
 * update_order_review isteğine kart bilgisi gitmesin diye değerler yalnızca sipariş verilirken
 * gizli alanlara kopyalanır. WooCommerce ödeme yöntemlerini AJAX ile yeniden çizdiği için
 * biçimlendirme olayları document'a bağlanır. */
(function () {
    'use strict';

    var FIELDS = ['qnb_card_number', 'qnb_card_holder', 'qnb_exp_month', 'qnb_exp_year', 'qnb_cvv'];

    function onlyDigits(value, max) {
        return String(value || '').replace(/\D+/g, '').slice(0, max);
    }

    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!el || !el.id) {
            return;
        }
        switch (el.id) {
            case 'qnb_card_number':
                el.value = onlyDigits(el.value, 19).replace(/(\d{4})(?=\d)/g, '$1 ');
                break;
            case 'qnb_exp_month':
                el.value = onlyDigits(el.value, 2);
                break;
            case 'qnb_exp_year':
                el.value = onlyDigits(el.value, 4);
                break;
            case 'qnb_cvv':
                el.value = onlyDigits(el.value, 4);
                break;
        }
    });

    function hiddenInputs() {
        return document.querySelectorAll('#qnb-card-form input[type="hidden"]');
    }

    function fill() {
        var box = document.getElementById('qnb-card-form');
        if (!box) {
            return;
        }
        FIELDS.forEach(function (name) {
            var visible = document.getElementById(name);
            var hidden = box.querySelector('input[type="hidden"][name="' + name + '"]');
            if (visible && hidden) {
                hidden.value = visible.value;
            }
        });
    }

    function clear() {
        Array.prototype.forEach.call(hiddenInputs(), function (h) {
            h.value = '';
        });
    }

    function selected() {
        var radio = document.getElementById('payment_method_qnb_3dpay');
        // Tek ödeme yöntemi varsa radyo gizli olabilir; form yine de bizimdir.
        return !radio || radio.checked;
    }

    function bind() {
        var $ = window.jQuery;
        if ($) {
            // Ödeme sayfası (checkout.js bu olayı form gönderilmeden hemen önce tetikler).
            $('form.checkout').on('checkout_place_order_qnb_3dpay', function () {
                fill();
                return true;
            });
            $(document.body).on('checkout_error update_checkout', clear);
        }

        // "Siparişi öde" (order-pay) sayfası klasik form gönderimi yapar.
        var payForm = document.getElementById('order_review');
        if (payForm && payForm.tagName === 'FORM') {
            payForm.addEventListener('submit', function () {
                if (selected()) {
                    fill();
                }
            });
        }
    }

    // Banka sayfasından "geri" ile dönüldüğünde tarayıcı sayfayı önbellekten (bfcache) geri yükleyebilir;
    // gizli alanlarda kart bilgisi kalmasın.
    window.addEventListener('pageshow', clear);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
