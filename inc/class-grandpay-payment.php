<?php

/**
 * GrandPay決済処理クラス
 */
class WelcartGrandpayPaymentProcessor {

    private $api;

    public function __construct() {
        $this->api = new WelcartGrandpayAPI();

        // Welcartの決済方法フィルターに登録
        add_filter('usces_filter_acting_processing', array($this, 'add_acting_processing'));
        add_filter('usces_filter_the_payment_method', array($this, 'add_payment_method'));

        // 決済処理
        add_action('usces_action_acting_processing', array($this, 'process_payment'));
        add_action('init', array($this, 'handle_payment_callback'));

        // Webhook処理
        add_action('wp_ajax_grandpay_webhook', array($this, 'handle_webhook'));
        add_action('wp_ajax_nopriv_grandpay_webhook', array($this, 'handle_webhook'));

        // フロント側での決済方法表示
        add_filter('usces_filter_available_payment_method', array($this, 'filter_available_payment_method'));
    }

    /**
     * Welcartのacting_processing一覧にGrandPayを追加
     */
    public function add_acting_processing($acting_flg) {
        $options = get_option('usces_ex', array());
        $grandpay_settings = $options['grandpay'] ?? array();

        if (($grandpay_settings['activate'] ?? '') === 'on') {
            $acting_flg['grandpay'] = 'GrandPay';
        }

        return $acting_flg;
    }

    /**
     * 利用可能な決済方法フィルター
     */
    public function filter_available_payment_method($payment_method) {
        $options = get_option('usces_ex', array());
        $grandpay_settings = $options['grandpay'] ?? array();

        if (($grandpay_settings['activate'] ?? '') === 'on') {
            $payment_method['grandpay'] = array(
                'name' => $grandpay_settings['payment_name'] ?? 'GrandPay',
                'explanation' => $grandpay_settings['payment_description'] ?? 'クレジットカード決済',
                'settlement' => 'credit',
                'sort' => 10
            );
        }

        return $payment_method;
    }

    /**
     * Welcartの決済方法リストにGrandPayを追加
     */
    public function add_payment_method($payment_methods) {
        $options = get_option('usces_ex', array());
        $grandpay_settings = $options['grandpay'] ?? array();

        if (($grandpay_settings['activate'] ?? '') === 'on') {
            $payment_methods['grandpay'] = array(
                'name' => $grandpay_settings['payment_name'] ?? 'GrandPay',
                'explanation' => $grandpay_settings['payment_description'] ?? 'クレジットカード決済',
                'settlement' => 'credit',
                'module' => 'grandpay'
            );
        }

        return $payment_methods;
    }

    /**
     * 決済処理
     */
    public function process_payment() {
        global $usces;

        // GrandPayが選択されているかチェック
        $acting_flag = $usces->options['acting_settings']['acting_flag'] ?? '';
        if ($acting_flag !== 'grandpay') {
            return;
        }

        // 追加の決済方法チェック
        if (isset($_POST['offer']['payment_method']) && $_POST['offer']['payment_method'] !== 'grandpay') {
            return;
        }

        $cart = $usces->cart->get_cart();
        $order_id = $usces->get_order_id();

        if (!$order_id) {
            $usces->error_message = '注文IDの取得に失敗しました';
            wp_redirect(home_url('/checkout/'));
            exit;
        }

        // 注文データを準備
        $member = $usces->get_member();
        $customer = $usces->get_customer();
        $total_price = $usces->get_total_price();

        $order_data = array(
            'order_id' => $order_id,
            'amount' => $total_price,
            'email' => $customer['mailaddress1'] ?? $member['mem_email'] ?? '',
            'phone' => $customer['tel'] ?? $member['mem_tel'] ?? '',
            'state' => $customer['pref'] ?? $member['mem_pref'] ?? '',
            'success_url' => add_query_arg(array(
                'grandpay_result' => 'success',
                'order_id' => $order_id
            ), $usces->url['complete_page']),
            'failure_url' => add_query_arg(array(
                'grandpay_result' => 'failure',
                'order_id' => $order_id
            ), $usces->url['cart_page'])
        );

        // 決済セッション作成
        $result = $this->api->create_checkout_session($order_data);

        if (isset($result['error'])) {
            // エラー処理
            $usces->error_message = $result['error'];
            wp_redirect($usces->url['cart_page']);
            exit;
        }

        if (isset($result['checkout_url'])) {
            // セッションIDを注文に保存
            update_post_meta($order_id, '_grandpay_session_id', $result['session_id']);
            update_post_meta($order_id, '_grandpay_checkout_url', $result['checkout_url']);
            update_post_meta($order_id, '_payment_method', 'grandpay');

            // GrandPayの決済ページにリダイレクト
            wp_redirect($result['checkout_url']);
            exit;
        }

        // 予期しないエラー
        $usces->error_message = '決済処理中にエラーが発生しました';
        wp_redirect($usces->url['cart_page']);
        exit;
    }

    /**
     * 決済完了後のコールバック処理
     */
    public function handle_payment_callback() {
        if (!isset($_GET['grandpay_result']) || !isset($_GET['order_id'])) {
            return;
        }

        $order_id = intval($_GET['order_id']);
        $result = sanitize_text_field($_GET['grandpay_result']);

        if ($result === 'success') {
            $this->handle_success_callback($order_id);
        } elseif ($result === 'failure') {
            $this->handle_failure_callback($order_id);
        }
    }

    /**
     * 成功時のコールバック処理
     */
    private function handle_success_callback($order_id) {
        $session_id = get_post_meta($order_id, '_grandpay_session_id', true);

        if ($session_id) {
            // 決済状況を確認
            $status = $this->api->get_payment_status($session_id);

            if (isset($status['status']) && $status['status'] === 'COMPLETED') {
                // 注文ステータスを更新
                global $usces;
                $usces->change_order_status($order_id, 'ordercompletion');

                // 決済情報を保存
                update_post_meta($order_id, '_grandpay_payment_status', 'completed');
                update_post_meta($order_id, '_grandpay_transaction_id', $status['id'] ?? '');

                // 完了ページにリダイレクト
                wp_redirect(home_url('/checkout/complete/?order_id=' . $order_id));
                exit;
            }
        }

        // 状況確認に失敗した場合
        wp_redirect(home_url('/checkout/?error=payment_verification_failed'));
        exit;
    }

    /**
     * 失敗時のコールバック処理
     */
    private function handle_failure_callback($order_id) {
        // 注文ステータスを更新
        global $usces;
        $usces->change_order_status($order_id, 'cancel');

        // 決済情報を保存
        update_post_meta($order_id, '_grandpay_payment_status', 'failed');

        // エラーメッセージと共にチェックアウトページにリダイレクト
        wp_redirect(home_url('/checkout/?error=payment_failed'));
        exit;
    }

    /**
     * Webhook処理
     */
    public function handle_webhook() {
        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_GRANDPAY_SIGNATURE'] ?? '';

        // 署名検証
        if (!$this->api->verify_webhook_signature($payload, $signature)) {
            wp_die('Unauthorized', 'Webhook Error', array('response' => 401));
        }

        $data = json_decode($payload, true);

        if (!$data || !isset($data['type'])) {
            wp_die('Invalid payload', 'Webhook Error', array('response' => 400));
        }

        // イベントタイプに応じて処理
        switch ($data['type']) {
            case 'PAYMENT_CHECKOUT':
                $this->process_payment_webhook($data);
                break;

            default:
                error_log('Unknown webhook event: ' . $data['type']);
                break;
        }

        // 成功レスポンス
        wp_die('OK', 'Webhook Success', array('response' => 200));
    }

    /**
     * 決済Webhook処理
     */
    private function process_payment_webhook($data) {
        if (!isset($data['data']['object']['id'])) {
            return;
        }

        $session_id = $data['data']['object']['id'];

        // セッションIDから注文IDを検索
        $orders = get_posts(array(
            'post_type' => 'shop_order',
            'meta_key' => '_grandpay_session_id',
            'meta_value' => $session_id,
            'post_status' => 'any',
            'numberposts' => 1
        ));

        if (empty($orders)) {
            error_log('Order not found for session ID: ' . $session_id);
            return;
        }

        $order_id = $orders[0]->ID;
        $payment_status = $data['data']['object']['status'] ?? '';

        global $usces;

        switch ($payment_status) {
            case 'COMPLETED':
                $usces->change_order_status($order_id, 'ordercompletion');
                update_post_meta($order_id, '_grandpay_payment_status', 'completed');
                break;

            case 'REJECTED':
            case 'FAILED':
                $usces->change_order_status($order_id, 'cancel');
                update_post_meta($order_id, '_grandpay_payment_status', 'failed');
                break;

            default:
                error_log('Unknown payment status: ' . $payment_status);
                break;
        }

        // Webhook受信ログ
        update_post_meta($order_id, '_grandpay_webhook_received', current_time('mysql'));
    }
}
