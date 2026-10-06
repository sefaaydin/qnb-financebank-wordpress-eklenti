/* QNB Finansbank 3D Pay — Blok tabanlı ödeme sayfası (Cart & Checkout Blocks) kaydı.
 * Derleme adımı gerektirmez; wp.element ile yazılmıştır. */
(function () {
    'use strict';

    var wc = window.wc || {};
    var wp = window.wp || {};
    var registry = wc.wcBlocksRegistry;
    var settings = wc.wcSettings;
    var element = wp.element;

    if (!registry || !settings || !element) {
        return;
    }

    var h = element.createElement;
    var useState = element.useState;
    var useEffect = element.useEffect;
    var decode = (wp.htmlEntities && wp.htmlEntities.decodeEntities) || function (s) { return s; };

    var data = settings.getSetting('qnb_3dpay_data', {}) || {};
    var title = decode(data.title || 'Kredi / Banka Kartı');
    var description = decode(data.description || '');
    var LICENSE_MSG = 'Bu ödeme yöntemi şu anda kullanılamıyor. Lütfen site yöneticisiyle iletişime geçin.';

    function onlyDigits(value, max) {
        return String(value || '').replace(/\D+/g, '').slice(0, max);
    }

    function luhn(num) {
        var sum = 0, dbl = false;
        for (var i = num.length - 1; i >= 0; i--) {
            var d = parseInt(num.charAt(i), 10);
            if (dbl) { d *= 2; if (d > 9) { d -= 9; } }
            sum += d;
            dbl = !dbl;
        }
        return sum % 10 === 0;
    }

    function validate(card) {
        var num = onlyDigits(card.number, 19);
        var m = parseInt(card.month, 10);
        if (num.length < 13 || !luhn(num)) { return 'Kart numarası hatalı.'; }
        if (!(m >= 1 && m <= 12)) { return 'Son kullanma ayı hatalı.'; }
        if (!(card.year.length === 2 || card.year.length === 4)) { return 'Son kullanma yılı hatalı.'; }
        if (card.cvv.length < 3) { return 'CVV hatalı.'; }
        return '';
    }

    function Field(props) {
        return h('div', { className: 'qnb-field' },
            h('label', { htmlFor: props.id }, props.label),
            h('input', {
                id: props.id,
                type: props.type || 'text',
                inputMode: props.inputMode,
                autoComplete: props.autoComplete,
                placeholder: props.placeholder,
                maxLength: props.maxLength,
                className: props.className,
                value: props.value,
                onChange: function (e) { props.onChange(e.target.value); }
            })
        );
    }

    function Content(props) {
        var state = useState({ number: '', holder: '', month: '', year: '', cvv: '' });
        var card = state[0];
        var setCard = state[1];

        var reg = props.eventRegistration || {};
        var onPaymentSetup = reg.onPaymentSetup || reg.onPaymentProcessing;
        var responseTypes = props.emitResponse && props.emitResponse.responseTypes;

        useEffect(function () {
            if (!onPaymentSetup || !responseTypes) {
                return undefined;
            }
            return onPaymentSetup(function () {
                if (!data.licenseValid) {
                    return { type: responseTypes.ERROR, message: LICENSE_MSG };
                }
                var err = validate(card);
                if (err) {
                    return { type: responseTypes.ERROR, message: err };
                }
                return {
                    type: responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            qnb_card_number: onlyDigits(card.number, 19),
                            qnb_card_holder: card.holder,
                            qnb_exp_month: card.month,
                            qnb_exp_year: card.year,
                            qnb_cvv: card.cvv
                        }
                    }
                };
            });
        }, [onPaymentSetup, responseTypes, card]);

        if (!data.licenseValid) {
            return h('p', { className: 'qnb-license-error' }, LICENSE_MSG);
        }

        function set(key, transform) {
            return function (value) {
                var next = {};
                for (var k in card) { if (Object.prototype.hasOwnProperty.call(card, k)) { next[k] = card[k]; } }
                next[key] = transform ? transform(value) : value;
                setCard(next);
            };
        }

        return h('div', null,
            description ? h('p', null, description) : null,
            data.testmode ? h('p', { className: 'qnb-test-badge' }, 'TEST MODU — gerçek tahsilat yapılmaz.') : null,
            h('div', { className: 'qnb-card-form' },
                h('div', { className: 'qnb-card-top' },
                    h('span', { className: 'qnb-chip', 'aria-hidden': 'true' }),
                    h('span', { className: 'qnb-secure' }, '3D Secure')
                ),
                h(Field, {
                    id: 'qnb-block-card-number', label: 'Kart Numarası', inputMode: 'numeric',
                    autoComplete: 'cc-number', placeholder: '0000 0000 0000 0000', maxLength: 23,
                    value: card.number,
                    onChange: set('number', function (v) { return onlyDigits(v, 19).replace(/(\d{4})(?=\d)/g, '$1 '); })
                }),
                h(Field, {
                    id: 'qnb-block-card-holder', label: 'Kart Üzerindeki İsim', autoComplete: 'cc-name',
                    placeholder: 'Ad Soyad', maxLength: 64, className: 'qnb-holder',
                    value: card.holder, onChange: set('holder')
                }),
                h('div', { className: 'qnb-row' },
                    h(Field, {
                        id: 'qnb-block-exp-month', label: 'Ay', inputMode: 'numeric', autoComplete: 'cc-exp-month',
                        placeholder: 'AA', maxLength: 2, value: card.month,
                        onChange: set('month', function (v) { return onlyDigits(v, 2); })
                    }),
                    h(Field, {
                        id: 'qnb-block-exp-year', label: 'Yıl', inputMode: 'numeric', autoComplete: 'cc-exp-year',
                        placeholder: 'YY', maxLength: 4, value: card.year,
                        onChange: set('year', function (v) { return onlyDigits(v, 4); })
                    }),
                    h(Field, {
                        id: 'qnb-block-cvv', label: 'CVV', type: 'password', inputMode: 'numeric', autoComplete: 'cc-csc',
                        placeholder: '***', maxLength: 4, value: card.cvv,
                        onChange: set('cvv', function (v) { return onlyDigits(v, 4); })
                    })
                ),
                h('p', { className: 'qnb-note' }, 'Kart bilgileriniz saklanmaz; doğrulama bankanızın 3D Secure ekranında yapılır.')
            )
        );
    }

    function Preview() {
        return h('p', null, description || title);
    }

    registry.registerPaymentMethod({
        name: 'qnb_3dpay',
        label: h('span', null, title),
        ariaLabel: title,
        content: h(Content, null),
        edit: h(Preview, null),
        canMakePayment: function () { return true; },
        supports: { features: data.supports || ['products'] }
    });
})();
