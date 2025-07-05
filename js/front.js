jQuery(document).ready(function ($) {
    // GrandPay 決済処理のメイン関数
    const GrandPayPayment = {
        init: function () {
            this.bindEvents();
            this.checkUrlParams();
        },

        bindEvents: function () {
            // 決済ボタンのクリックイベント
            $(document).on('click', '#grandpay-payment-button, #grandpay-retry-button', this.handlePaymentClick);

            // 決済方法選択時の処理
            $(document).on('change', 'input[name="offer[payment_method]"]', this.handlePaymentMethodChange);

            // フォーム送信時の処理
            $(document).on('submit', '.usces_cart_form', this.handleFormSubmit);
        },

        handlePaymentClick: function (e) {
            e.preventDefault();

            const $button = $(this);
            const orderId = $button.data('order-id') || GrandPayPayment.getOrderIdFromPage();

            if (!orderId) {
                GrandPayPayment.showError('注文IDが見つかりません。');
                return;
            }

            GrandPayPayment.startPayment(orderId, $button);
        },

        startPayment: function (orderId, $button) {
            // ローディング状態を表示
            this.showLoading();
            $button.prop('disabled', true);

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
                    if (response.success && response.data.checkout_url) {
                        // 成功メッセージを表示してからリダイレクト
                        GrandPayPayment.showRedirectMessage();
                        setTimeout(function () {
                            window.location.href = response.data.checkout_url;
                        }, 1500);
                    } else {
                        const errorMessage = response.data && response.data.message ? response.data.message : '決済処理中にエラーが発生しました。';
                        GrandPayPayment.showError(errorMessage);
                        GrandPayPayment.hideLoading();
                        $button.prop('disabled', false);
                    }
                },
                error: function (xhr, status, error) {
                    let errorMessage = '通信エラーが発生しました。';

                    if (status === 'timeout') {
                        errorMessage = 'リクエストがタイムアウトしました。';
                    } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMessage = xhr.responseJSON.data.message;
                    }

                    GrandPayPayment.showError(errorMessage);
                    GrandPayPayment.hideLoading();
                    $button.prop('disabled', false);
                }
            });
        },

        handlePaymentMethodChange: function () {
            const selectedMethod = $(this).val();

            if (selectedMethod === 'grandpay') {
                // GrandPay選択時の処理
                GrandPayPayment.showGrandPayInfo();
            } else {
                // 他の決済方法選択時
                GrandPayPayment.hideGrandPayInfo();
            }
        },

        handleFormSubmit: function (e) {
            const selectedMethod = $('input[name="offer[payment_method]"]:checked').val();

            if (selectedMethod === 'grandpay') {
                // フォーム送信を一時停止してGrandPay処理を開始
                e.preventDefault();

                // フォームデータを取得して注文を作成
                const formData = new FormData(this);

                $.ajax({
                    url: $(this).attr('action'),
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function (response) {
                        // 注文作成成功後、決済処理開始
                        const orderId = GrandPayPayment.extractOrderIdFromResponse(response);
                        if (orderId) {
                            GrandPayPayment.startPayment(orderId, $('.grandpay-payment-btn'));
                        } else {
                            GrandPayPayment.showError('注文の作成に失敗しました。');
                        }
                    },
                    error: function () {
                        GrandPayPayment.showError('注文の作成中にエラーが発生しました。');
                    }
                });
            }
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
                $('.grandpay-payment-container').append(loadingHtml);
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
                ">
                    ${message}
                </div>
            `;

            // 既存のエラーメッセージを削除
            $('.grandpay-error-message').remove();

            // 新しいエラーメッセージを追加
            $('.grandpay-payment-container').prepend(errorHtml);

            // 3秒後に自動で非表示
            setTimeout(function () {
                $('.grandpay-error-message').fadeOut();
            }, 5000);
        },

        showGrandPayInfo: function () {
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
                        <strong>💳 クレジットカード決済</strong><br>
                        次のページで安全にクレジットカード情報を入力してお支払いいただけます。
                    </p>
                </div>
            `;

            $('.grandpay-payment-info-box').remove();
            $('input[value="grandpay"]').closest('label').after(infoHtml);
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

            return null;
        },

        extractOrderIdFromResponse: function (response) {
            // レスポンスから注文IDを抽出（実装は実際のレスポンス形式に合わせて調整）
            try {
                if (typeof response === 'string') {
                    const match = response.match(/order_id[=:](\d+)/);
                    if (match) {
                        return match[1];
                    }
                }

                if (response && response.order_id) {
                    return response.order_id;
                }
            } catch (e) {
                console.error('Order ID extraction failed:', e);
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
                    ✓ 決済が完了しました
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
                    ✗ 決済に失敗しました
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
});
