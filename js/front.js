jQuery(document).ready(function ($) {
    // GrandPay 決済処理のメイン関数
    const GrandPayPayment = {
        init: function () {
            console.log('🔧 GrandPay Front Script Loaded');
            this.bindEvents();
            this.checkUrlParams();
            this.monitorPaymentMethodSelection();
        },

        bindEvents: function () {
            // 決済ボタンのクリックイベント
            $(document).on('click', '#grandpay-payment-button, #grandpay-retry-button', this.handlePaymentClick);

            // 決済方法選択時の処理
            $(document).on('change', 'input[name*="payment"]', this.handlePaymentMethodChange);

            // フォーム送信時の処理（GrandPay選択時）
            $(document).on('submit', 'form[name="customer_form"], .usces_cart_form', this.handleFormSubmit);
        },

        handlePaymentClick: function (e) {
            e.preventDefault();

            const $button = $(this);
            const orderId = $button.data('order-id') || GrandPayPayment.getOrderIdFromPage();

            console.log('🔄 GrandPay payment button clicked, Order ID:', orderId);

            if (!orderId) {
                GrandPayPayment.showError('注文IDが見つかりません。');
                return;
            }

            GrandPayPayment.startPayment(orderId, $button);
        },

        startPayment: function (orderId, $button) {
            console.log('🚀 Starting GrandPay payment for order:', orderId);

            // ローディング状態を表示
            this.showLoading();
            if ($button) {
                $button.prop('disabled', true);
            }

            // AJAX で決済セッションを作成
            $.ajax({
                url: typeof grandpay_front !== 'undefined' ? grandpay_front.ajaxurl : '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: {
                    action: 'grandpay_start_payment',
                    order_id: orderId,
                    nonce: typeof grandpay_front !== 'undefined' ? grandpay_front.nonce : ''
                },
                timeout: 30000,
                success: function (response) {
                    console.log('✅ GrandPay AJAX response:', response);

                    if (response.success && response.data.checkout_url) {
                        // 成功メッセージを表示してからリダイレクト
                        GrandPayPayment.showRedirectMessage();
                        setTimeout(function () {
                            console.log('🔗 Redirecting to:', response.data.checkout_url);
                            window.location.href = response.data.checkout_url;
                        }, 1500);
                    } else {
                        const errorMessage = response.data && response.data.message ? response.data.message : '決済処理中にエラーが発生しました。';
                        console.error('❌ GrandPay error:', errorMessage);
                        GrandPayPayment.showError(errorMessage);
                        GrandPayPayment.hideLoading();
                        if ($button) {
                            $button.prop('disabled', false);
                        }
                    }
                },
                error: function (xhr, status, error) {
                    console.error('❌ GrandPay AJAX error:', status, error);

                    let errorMessage = '通信エラーが発生しました。';

                    if (status === 'timeout') {
                        errorMessage = 'リクエストがタイムアウトしました。';
                    } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMessage = xhr.responseJSON.data.message;
                    }

                    GrandPayPayment.showError(errorMessage);
                    GrandPayPayment.hideLoading();
                    if ($button) {
                        $button.prop('disabled', false);
                    }
                }
            });
        },

        handlePaymentMethodChange: function () {
            const selectedMethod = $(this).val();
            console.log('💳 Payment method changed:', selectedMethod);

            if (selectedMethod === 'acting_grandpay_card' || ($(this).is(':checked') && $(this).closest('label').text().indexOf('GrandPay') !== -1)) {
                // GrandPay選択時の処理
                console.log('✅ GrandPay selected');
                GrandPayPayment.showGrandPayInfo();
            } else {
                // 他の決済方法選択時
                GrandPayPayment.hideGrandPayInfo();
            }
        },

        handleFormSubmit: function (e) {
            // GrandPayが選択されているかチェック
            const isGrandPaySelected = GrandPayPayment.isGrandPaySelected();

            console.log('📋 Form submit detected, GrandPay selected:', isGrandPaySelected);

            if (!isGrandPaySelected) {
                return; // 通常の処理を続行
            }

            // GrandPay選択時は通常の決済フローに任せる
            console.log('🔄 GrandPay selected, letting Welcart handle the form submission');
        },

        isGrandPaySelected: function () {
            // 複数の方法でGrandPay選択状態を確認
            let isSelected = false;

            // 1. acting_grandpay_card という値をチェック
            $('input[type="radio"][name*="payment"]:checked').each(function () {
                if ($(this).val() === 'acting_grandpay_card') {
                    isSelected = true;
                }
            });

            // 2. labelテキストでGrandPayを含むものをチェック
            $('input[type="radio"]:checked').each(function () {
                if ($(this).closest('label').text().indexOf('GrandPay') !== -1) {
                    isSelected = true;
                }
            });

            // 3. nameやidにgrandpayを含むものをチェック
            $('input[type="radio"]:checked').each(function () {
                const name = $(this).attr('name') || '';
                const id = $(this).attr('id') || '';
                const value = $(this).val() || '';

                if (
                    name.toLowerCase().indexOf('grandpay') !== -1 ||
                    id.toLowerCase().indexOf('grandpay') !== -1 ||
                    value.toLowerCase().indexOf('grandpay') !== -1
                ) {
                    isSelected = true;
                }
            });

            return isSelected;
        },

        monitorPaymentMethodSelection: function () {
            // ページロード時の決済方法チェック
            setTimeout(() => {
                if (this.isGrandPaySelected()) {
                    this.showGrandPayInfo();
                }
            }, 1000);
        },

        showLoading: function () {
            const $loading = $('#grandpay-loading');
            if ($loading.length) {
                $loading.show();
            } else {
                // 動的にローディング要素を作成
                const loadingHtml = `
                    <div id="grandpay-loading" class="grandpay-loading">
                        <p>${
                            typeof grandpay_front !== 'undefined' && grandpay_front.messages
                                ? grandpay_front.messages.processing
                                : '決済処理中です...'
                        }</p>
                        <div class="grandpay-spinner"></div>
                    </div>
                `;

                // コンテナがあれば追加、なければbodyに追加
                if ($('.grandpay-payment-container').length) {
                    $('.grandpay-payment-container').append(loadingHtml);
                } else {
                    $('body').append(loadingHtml);
                }
            }
        },

        hideLoading: function () {
            $('#grandpay-loading').hide();
        },

        showRedirectMessage: function () {
            const $loading = $('#grandpay-loading');
            if ($loading.length) {
                $loading
                    .find('p')
                    .text(
                        typeof grandpay_front !== 'undefined' && grandpay_front.messages
                            ? grandpay_front.messages.redirecting
                            : 'GrandPayの決済ページにリダイレクトしています...'
                    );
            }
        },

        showError: function (message) {
            console.error('❌ GrandPay Error:', message);

            // エラーメッセージを表示
            const errorHtml = `
                <div class="grandpay-error-message" style="
                    background-color: #fef7f7;
                    border: 1px solid #dc3232;
                    color: #dc3232;
                    padding: 12px 16px;
                    border-radius: 4px;
                    margin: 16px 0;
                    font-weight: 500;
                    position: relative;
                    z-index: 9999;
                ">
                    ❌ ${message}
                </div>
            `;

            // 既存のエラーメッセージを削除
            $('.grandpay-error-message').remove();

            // 新しいエラーメッセージを追加
            if ($('.grandpay-payment-container').length) {
                $('.grandpay-payment-container').prepend(errorHtml);
            } else {
                $('body').prepend(errorHtml);
            }

            // 5秒後に自動で非表示
            setTimeout(function () {
                $('.grandpay-error-message').fadeOut();
            }, 5000);
        },

        showGrandPayInfo: function () {
            console.log('ℹ️ Showing GrandPay info');

            // GrandPay選択時の説明を表示
            const infoHtml = `
                <div class="grandpay-payment-info-box" style="
                    background-color: #f0f8ff;
                    border: 1px solid #0073aa;
                    padding: 16px;
                    border-radius: 4px;
                    margin-top: 12px;
                ">
                    <p style="margin: 0; color: #0073aa; font-size: 14px;">
                        <strong>💳 クレジットカード決済（GrandPay）</strong><br>
                        次のページで安全にクレジットカード情報を入力してお支払いいただけます。
                    </p>
                </div>
            `;

            // 既存の情報ボックスを削除
            $('.grandpay-payment-info-box').remove();

            // GrandPay関連の要素を探して情報を追加
            $('input[value="acting_grandpay_card"], input[value*="grandpay"]').each(function () {
                $(this).closest('label').after(infoHtml);
            });

            // テキストでGrandPayを含むlabelの後に追加
            $('label:contains("GrandPay")').each(function () {
                $(this).after(infoHtml);
            });
        },

        hideGrandPayInfo: function () {
            $('.grandpay-payment-info-box').remove();
        },

        getOrderIdFromPage: function () {
            // URLパラメータから注文IDを取得
            const urlParams = new URLSearchParams(window.location.search);
            const orderId = urlParams.get('order_id');

            if (orderId) {
                return orderId;
            }

            // ページ内のdata属性から取得を試行
            const dataOrderId = $('.grandpay-payment-container').data('order-id');
            if (dataOrderId) {
                return dataOrderId;
            }

            // hidden inputから取得を試行
            const hiddenOrderId = $('input[name="order_id"]').val();
            if (hiddenOrderId) {
                return hiddenOrderId;
            }

            return null;
        },

        checkUrlParams: function () {
            // URLパラメータをチェックして決済結果を処理
            const urlParams = new URLSearchParams(window.location.search);
            const result = urlParams.get('grandpay_result');

            if (result === 'success') {
                this.showSuccessMessage();
            } else if (result === 'failure') {
                this.showFailureMessage();
            }

            // エラーパラメータもチェック
            const error = urlParams.get('error');
            if (error) {
                let errorMessage = '決済処理中にエラーが発生しました。';

                switch (error) {
                    case 'payment_failed':
                        errorMessage = '決済に失敗しました。再度お試しください。';
                        break;
                    case 'payment_verification_failed':
                        errorMessage = '決済の確認に失敗しました。サポートまでお問い合わせください。';
                        break;
                }

                this.showError(errorMessage);
            }
        },

        showSuccessMessage: function () {
            const messageHtml = `
                <div class="grandpay-result-message grandpay-success" style="
                    background-color: #f0fff4;
                    border: 1px solid #46b450;
                    color: #46b450;
                    padding: 16px;
                    border-radius: 4px;
                    margin: 20px 0;
                    text-align: center;
                    font-weight: 600;
                ">
                    ✅ 決済が完了しました
                </div>
            `;

            $('body').prepend(messageHtml);
        },

        showFailureMessage: function () {
            const messageHtml = `
                <div class="grandpay-result-message grandpay-failure" style="
                    background-color: #fef7f7;
                    border: 1px solid #dc3232;
                    color: #dc3232;
                    padding: 16px;
                    border-radius: 4px;
                    margin: 20px 0;
                    text-align: center;
                    font-weight: 600;
                ">
                    ❌ 決済に失敗しました
                </div>
            `;

            $('body').prepend(messageHtml);
        }
    };

    // 初期化実行
    GrandPayPayment.init();

    // グローバルに公開（デバッグ用）
    if (typeof window !== 'undefined') {
        window.GrandPayPayment = GrandPayPayment;
    }

    // デバッグ用ログ
    if (typeof grandpay_front !== 'undefined' && grandpay_front.debug) {
        console.log('🔧 GrandPay debug mode enabled');
        console.log('📊 GrandPay front config:', grandpay_front);
    }
});
