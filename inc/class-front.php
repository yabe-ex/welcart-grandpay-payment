<?php

class WelcartGrandpayPaymentFront {

    public function __construct() {
        // Welcartのフロント側フィルターに登録
        add_filter('usces_filter_payment_list_credit', array($this, 'add_payment_option'));
        add_action('usces_action_reg_orderdata', array($this, 'save_payment_info'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // ショートコード登録
        add_shortcode('grandpay_payment_form', array($this, 'payment_form_shortcode'));
    }

    /**
     * 決済オプションリストにGrandPayを追加
     */
    public function add_payment_option($payment_list) {
        $options = get_option('usces_ex', array());
        $grandpay_settings = $options['grandpay'] ?? array();

        // GrandPayが有効になっている場合のみ追加
        if (($grandpay_settings['activate'] ?? '') === 'on') {
            $payment_name = $grandpay_settings['payment_name'] ?? 'クレジットカード決済（GrandPay）';
            $payment_description = $grandpay_settings['payment_description'] ?? 'クレジットカードで安全にお支払いいただけます。';

            $payment_list['grandpay'] = array(
                'name' => $payment_name,
                'explanation' => $payment_description,
                'settlement' => 'credit',
                'sort' => 1
            );
        }

        return $payment_list;
    }

    /**
     * 注文データ登録時に決済情報を保存
     */
    public function save_payment_info($order_id) {
        if (isset($_POST['offer']['payment_method']) && $_POST['offer']['payment_method'] === 'grandpay') {
            update_post_meta($order_id, '_payment_method', 'grandpay');
            update_post_meta($order_id, '_grandpay_payment_status', 'pending');
        }
    }

    /**
     * スクリプトとスタイルの読み込み
     */
    function front_enqueue() {
        // チェックアウトページでのみ読み込み
        if (!is_page() || !usces_is_cart_page()) {
            return;
        }

        $version = (defined('WELCART_GRANDPAY_PAYMENT_DEVELOP') && true === WELCART_GRANDPAY_PAYMENT_DEVELOP) ? time() : WELCART_GRANDPAY_PAYMENT_VERSION;
        $strategy = array('in_footer' => true, 'strategy' => 'defer');

        wp_register_style(WELCART_GRANDPAY_PAYMENT_SLUG . '-front', WELCART_GRANDPAY_PAYMENT_URL . '/css/front.css', array(), $version);
        wp_register_script(WELCART_GRANDPAY_PAYMENT_SLUG . '-front', WELCART_GRANDPAY_PAYMENT_URL . '/js/front.js', array('jquery'), $version, $strategy);

        wp_enqueue_style(WELCART_GRANDPAY_PAYMENT_SLUG . '-front');
        wp_enqueue_script(WELCART_GRANDPAY_PAYMENT_SLUG . '-front');

        $front = array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(WELCART_GRANDPAY_PAYMENT_SLUG),
            'messages' => array(
                'processing' => '決済処理中です...',
                'redirecting' => 'GrandPayの決済ページにリダイレクトしています...'
            )
        );
        wp_localize_script(WELCART_GRANDPAY_PAYMENT_SLUG . '-front', 'grandpay_front', $front);
    }

    /**
     * 決済フォームのショートコード
     */
    public function payment_form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'order_id' => 0,
            'style' => 'default'
        ), $atts);

        if (!$atts['order_id']) {
            return '<p>注文IDが指定されていません。</p>';
        }

        $order_id = intval($atts['order_id']);
        $payment_method = get_post_meta($order_id, '_payment_method', true);

        if ($payment_method !== 'grandpay') {
            return '<p>この注文はGrandPay決済ではありません。</p>';
        }

        $payment_status = get_post_meta($order_id, '_grandpay_payment_status', true);

        ob_start();
?>
        <div id="grandpay-payment-form" class="grandpay-payment-container">
            <?php if ($payment_status === 'pending'): ?>
                <div class="grandpay-payment-info">
                    <h3>クレジットカード決済</h3>
                    <p>以下のボタンをクリックして、安全な決済ページで決済を完了してください。</p>
                    <button type="button" id="grandpay-payment-button" class="grandpay-payment-btn">
                        決済ページへ進む
                    </button>
                </div>
                <div id="grandpay-loading" class="grandpay-loading" style="display: none;">
                    <p>決済ページに移動しています...</p>
                    <div class="grandpay-spinner"></div>
                </div>
            <?php elseif ($payment_status === 'completed'): ?>
                <div class="grandpay-payment-complete">
                    <h3>✓ 決済完了</h3>
                    <p>決済が正常に完了しました。</p>
                </div>
            <?php elseif ($payment_status === 'failed'): ?>
                <div class="grandpay-payment-failed">
                    <h3>✗ 決済失敗</h3>
                    <p>決済に失敗しました。お手数ですが、再度お試しください。</p>
                    <button type="button" id="grandpay-retry-button" class="grandpay-payment-btn">
                        再度決済する
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#grandpay-payment-button, #grandpay-retry-button').click(function() {
                    $('#grandpay-loading').show();
                    $(this).prop('disabled', true);

                    // GrandPayの決済処理開始
                    $.ajax({
                        url: grandpay_front.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'grandpay_start_payment',
                            order_id: <?php echo $order_id; ?>,
                            nonce: grandpay_front.nonce
                        },
                        success: function(response) {
                            if (response.success && response.data.checkout_url) {
                                window.location.href = response.data.checkout_url;
                            } else {
                                alert('決済処理中にエラーが発生しました: ' + (response.data.message || ''));
                                $('#grandpay-loading').hide();
                                $('.grandpay-payment-btn').prop('disabled', false);
                            }
                        },
                        error: function() {
                            alert('通信エラーが発生しました。');
                            $('#grandpay-loading').hide();
                            $('.grandpay-payment-btn').prop('disabled', false);
                        }
                    });
                });
            });
        </script>

        <style>
            .grandpay-payment-container {
                border: 1px solid #ddd;
                padding: 20px;
                border-radius: 5px;
                background-color: #f9f9f9;
                margin: 20px 0;
            }

            .grandpay-payment-btn {
                background-color: #0073aa;
                color: white;
                border: none;
                padding: 12px 24px;
                font-size: 16px;
                border-radius: 3px;
                cursor: pointer;
                transition: background-color 0.3s;
            }

            .grandpay-payment-btn:hover {
                background-color: #005a87;
            }

            .grandpay-payment-btn:disabled {
                background-color: #ccc;
                cursor: not-allowed;
            }

            .grandpay-loading {
                text-align: center;
                padding: 20px;
            }

            .grandpay-spinner {
                border: 4px solid #f3f3f3;
                border-top: 4px solid #3498db;
                border-radius: 50%;
                width: 30px;
                height: 30px;
                animation: spin 2s linear infinite;
                margin: 10px auto;
            }

            @keyframes spin {
                0% {
                    transform: rotate(0deg);
                }

                100% {
                    transform: rotate(360deg);
                }
            }

            .grandpay-payment-complete {
                color: #46b450;
            }

            .grandpay-payment-failed {
                color: #dc3232;
            }
        </style>
<?php
        return ob_get_clean();
    }

    /**
     * AJAX: 決済開始処理
     */
    public function ajax_start_payment() {
        check_ajax_referer(WELCART_GRANDPAY_PAYMENT_SLUG, 'nonce');

        $order_id = intval($_POST['order_id'] ?? 0);

        if (!$order_id) {
            wp_send_json_error(array('message' => '注文IDが無効です'));
        }

        // 注文データを取得
        $order = get_post($order_id);
        if (!$order || $order->post_type !== 'shop_order') {
            wp_send_json_error(array('message' => '注文が見つかりません'));
        }

        // 決済処理
        $api = new WelcartGrandpayAPI();

        // 注文情報を構築
        $order_data = array(
            'order_id' => $order_id,
            'amount' => get_post_meta($order_id, '_order_total', true),
            'email' => get_post_meta($order_id, '_customer_email', true),
            'phone' => get_post_meta($order_id, '_customer_tel', true),
            'success_url' => add_query_arg(array(
                'grandpay_result' => 'success',
                'order_id' => $order_id
            ), home_url('/checkout/complete/')),
            'failure_url' => add_query_arg(array(
                'grandpay_result' => 'failure',
                'order_id' => $order_id
            ), home_url('/checkout/'))
        );

        $result = $api->create_checkout_session($order_data);

        if (isset($result['error'])) {
            wp_send_json_error(array('message' => $result['error']));
        }

        if (isset($result['checkout_url'])) {
            // セッション情報を保存
            update_post_meta($order_id, '_grandpay_session_id', $result['session_id']);
            update_post_meta($order_id, '_grandpay_checkout_url', $result['checkout_url']);

            wp_send_json_success(array('checkout_url' => $result['checkout_url']));
        }

        wp_send_json_error(array('message' => '予期しないエラーが発生しました'));
    }

    /**
     * スクリプト読み込み（修正版）
     */
    public function enqueue_scripts() {
        // AJAX ハンドラーを登録
        add_action('wp_ajax_grandpay_start_payment', array($this, 'ajax_start_payment'));
        add_action('wp_ajax_nopriv_grandpay_start_payment', array($this, 'ajax_start_payment'));

        // フロント側のスクリプト読み込み
        $this->front_enqueue();
    }
}
