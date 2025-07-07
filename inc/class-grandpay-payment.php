<?php

/**
 * GrandPay決済処理クラス
 */
class WelcartGrandpayPaymentProcessor {

    private $api;

    public function __construct() {
        $this->api = new WelcartGrandpayAPI();

        // Welcartの決済フックに登録
        add_action('usces_action_acting_processing', array($this, 'process_payment'));
        add_action('init', array($this, 'handle_payment_callback'));

        // Webhook処理
        add_action('wp_ajax_grandpay_webhook', array($this, 'handle_webhook'));
        add_action('wp_ajax_nopriv_grandpay_webhook', array($this, 'handle_webhook'));

        error_log('GrandPay Payment Processor: Initialized');
    }

    /**
     * メイン決済処理
     * usces_action_acting_processing
     */
    public function process_payment() {
        global $usces;

        error_log('GrandPay Payment: process_payment() called');

        // Welcartの決済設定を確認
        $acting_settings = $usces->options['acting_settings'] ?? array();
        $acting_flag = $acting_settings['acting_flag'] ?? '';

        error_log('GrandPay Payment: Current acting_flag: ' . $acting_flag);
        error_log('GrandPay Payment: Acting settings: ' . print_r($acting_settings, true));

        // フォームから送信された決済方法もチェック
        $payment_method = $_POST['offer']['payment_method'] ?? '';
        error_log('GrandPay Payment: Posted payment method: ' . $payment_method);

        // GrandPayが選択されているかチェック（複数の方法で確認）
        $is_grandpay_selected = false;

        // 1. acting_flagでチェック
        if ($acting_flag === 'grandpay') {
            $is_grandpay_selected = true;
            error_log('GrandPay Payment: Selected via acting_flag');
        }

        // 2. payment_methodでチェック
        if (in_array($payment_method, array('acting_grandpay_card', 'grandpay'))) {
            $is_grandpay_selected = true;
            error_log('GrandPay Payment: Selected via payment_method');
        }

        // 3. POSTデータの詳細確認
        if (
            isset($_POST['offer']['payment_name']) &&
            strpos($_POST['offer']['payment_name'], 'GrandPay') !== false
        ) {
            $is_grandpay_selected = true;
            error_log('GrandPay Payment: Selected via payment_name');
        }

        if (!$is_grandpay_selected) {
            error_log('GrandPay Payment: Not GrandPay payment, skipping');
            return;
        }

        error_log('GrandPay Payment: GrandPay payment detected, proceeding');

        // GrandPayが有効かチェック
        $grandpay_options = $acting_settings['grandpay'] ?? array();
        if (($grandpay_options['activate'] ?? 'off') !== 'on') {
            error_log('GrandPay Payment: GrandPay not activated');
            $usces->error_message = 'GrandPay決済が有効になっていません。';
            wp_redirect($usces->url['cart_page']);
            exit;
        }

        error_log('GrandPay Payment: Starting GrandPay payment process');

        // 注文データを取得
        $cart = $usces->cart->get_cart();
        $order_id = $usces->get_order_id();

        if (!$order_id) {
            error_log('GrandPay Payment: Order ID not found');
            $usces->error_message = '注文IDの取得に失敗しました';
            wp_redirect($usces->url['cart_page']);
            exit;
        }

        error_log('GrandPay Payment: Processing order ID: ' . $order_id);

        // 顧客情報を取得
        $member = $usces->get_member();
        $customer = $usces->get_customer();
        $total_price = $usces->get_total_price();

        error_log('GrandPay Payment: Order total: ' . $total_price);
        error_log('GrandPay Payment: Customer data: ' . print_r($customer, true));

        // 注文データを準備
        $order_data = array(
            'order_id' => $order_id,
            'amount' => $total_price,
            'email' => $customer['mailaddress1'] ?? $member['mem_email'] ?? 'test@example.com',
            'phone' => $customer['tel'] ?? $member['mem_tel'] ?? '',
            'name' => trim(($customer['name1'] ?? $member['mem_name1'] ?? '') . ' ' . ($customer['name2'] ?? $member['mem_name2'] ?? '')),
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

        error_log('GrandPay Payment: Order data prepared: ' . print_r($order_data, true));

        // 決済セッション作成
        $result = $this->api->create_checkout_session($order_data);

        if (isset($result['error'])) {
            error_log('GrandPay Payment: Checkout session creation failed: ' . $result['error']);
            $usces->error_message = $result['error'];
            wp_redirect($usces->url['cart_page']);
            exit;
        }

        if (isset($result['checkout_url'])) {
            // セッションIDを注文に保存
            update_post_meta($order_id, '_grandpay_session_id', $result['session_id']);
            update_post_meta($order_id, '_grandpay_checkout_url', $result['checkout_url']);
            update_post_meta($order_id, '_payment_method', 'grandpay');
            update_post_meta($order_id, '_grandpay_payment_status', 'pending');

            error_log('GrandPay Payment: Redirecting to checkout URL: ' . $result['checkout_url']);

            // GrandPayの決済ページにリダイレクト
            wp_redirect($result['checkout_url']);
            exit;
        }

        // 予期しないエラー
        error_log('GrandPay Payment: Unexpected error in payment processing');
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

        error_log('GrandPay Payment: Callback received - Result: ' . $result . ', Order ID: ' . $order_id);

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
        error_log('GrandPay Payment: Processing success callback for order: ' . $order_id);

        $session_id = get_post_meta($order_id, '_grandpay_session_id', true);

        if ($session_id) {
            // 決済状況を確認
            $status = $this->api->get_payment_status($session_id);

            error_log('GrandPay Payment: Payment status response: ' . print_r($status, true));

            if (isset($status['status']) && $status['status'] === 'COMPLETED') {
                // 注文ステータスを更新
                global $usces;
                $usces->change_order_status($order_id, 'ordercompletion');

                // 決済情報を保存
                update_post_meta($order_id, '_grandpay_payment_status', 'completed');
                update_post_meta($order_id, '_grandpay_transaction_id', $status['id'] ?? '');
                update_post_meta($order_id, '_grandpay_callback_received', current_time('mysql'));

                error_log('GrandPay Payment: Order completed successfully');

                // 完了ページにリダイレクト
                wp_redirect(add_query_arg('order_id', $order_id, $usces->url['complete_page']));
                exit;
            } else {
                error_log('GrandPay Payment: Payment status not completed: ' . ($status['status'] ?? 'unknown'));
            }
        } else {
            error_log('GrandPay Payment: Session ID not found for order: ' . $order_id);
        }

        // 状況確認に失敗した場合
        wp_redirect(add_query_arg('error', 'payment_verification_failed', home_url('/checkout/')));
        exit;
    }

    /**
     * 失敗時のコールバック処理
     */
    private function handle_failure_callback($order_id) {
        error_log('GrandPay Payment: Processing failure callback for order: ' . $order_id);

        // 注文ステータスを更新
        global $usces;
        $usces->change_order_status($order_id, 'cancel');

        // 決済情報を保存
        update_post_meta($order_id, '_grandpay_payment_status', 'failed');
        update_post_meta($order_id, '_grandpay_callback_received', current_time('mysql'));

        // エラーメッセージと共にチェックアウトページにリダイレクト
        wp_redirect(add_query_arg('error', 'payment_failed', $usces->url['cart_page']));
        exit;
    }

    /**
     * Webhook処理
     */
    public function handle_webhook() {
        error_log('GrandPay Payment: Webhook received');

        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_GRANDPAY_SIGNATURE'] ?? '';

        error_log('GrandPay Payment: Webhook payload: ' . $payload);

        // 署名検証（現在は無効化）
        if (!$this->api->verify_webhook_signature($payload, $signature)) {
            error_log('GrandPay Payment: Webhook signature verification failed');
            wp_die('Unauthorized', 'Webhook Error', array('response' => 401));
        }

        $data = json_decode($payload, true);

        if (!$data || !isset($data['type'])) {
            error_log('GrandPay Payment: Invalid webhook payload');
            wp_die('Invalid payload', 'Webhook Error', array('response' => 400));
        }

        error_log('GrandPay Payment: Webhook event type: ' . $data['type']);

        // イベントタイプに応じて処理
        switch ($data['type']) {
            case 'PAYMENT_CHECKOUT':
                $this->process_payment_webhook($data);
                break;

            default:
                error_log('GrandPay Payment: Unknown webhook event: ' . $data['type']);
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
            error_log('GrandPay Payment: Webhook missing session ID');
            return;
        }

        $session_id = $data['data']['object']['id'];
        error_log('GrandPay Payment: Processing webhook for session: ' . $session_id);

        // セッションIDから注文IDを検索
        $orders = get_posts(array(
            'post_type' => 'shop_order',
            'meta_key' => '_grandpay_session_id',
            'meta_value' => $session_id,
            'post_status' => 'any',
            'numberposts' => 1
        ));

        if (empty($orders)) {
            error_log('GrandPay Payment: Order not found for session ID: ' . $session_id);
            return;
        }

        $order_id = $orders[0]->ID;
        $payment_status = $data['data']['object']['status'] ?? '';

        error_log('GrandPay Payment: Webhook processing order: ' . $order_id . ', status: ' . $payment_status);

        global $usces;

        switch ($payment_status) {
            case 'COMPLETED':
                $usces->change_order_status($order_id, 'ordercompletion');
                update_post_meta($order_id, '_grandpay_payment_status', 'completed');
                error_log('GrandPay Payment: Order completed via webhook');
                break;

            case 'REJECTED':
            case 'FAILED':
                $usces->change_order_status($order_id, 'cancel');
                update_post_meta($order_id, '_grandpay_payment_status', 'failed');
                error_log('GrandPay Payment: Order failed via webhook');
                break;

            default:
                error_log('GrandPay Payment: Unknown payment status: ' . $payment_status);
                break;
        }

        // Webhook受信ログ
        update_post_meta($order_id, '_grandpay_webhook_received', current_time('mysql'));
    }
}
