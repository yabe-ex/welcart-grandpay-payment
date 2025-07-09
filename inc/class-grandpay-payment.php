<?php

/**
 * GrandPay決済処理クラス - 完全版
 * Welcartとの統合、チェックアウトセッション作成、コールバック処理を実装
 */
class WelcartGrandpayPaymentProcessor {

    private $api;

    public function __construct() {
        $this->api = new WelcartGrandpayAPI();

        // Welcartの決済フックに登録
        add_action('usces_action_acting_processing', array($this, 'process_payment'), 10);
        add_action('init', array($this, 'handle_payment_callback'), 5);

        // Webhook処理
        add_action('wp_ajax_grandpay_webhook', array($this, 'handle_webhook'));
        add_action('wp_ajax_nopriv_grandpay_webhook', array($this, 'handle_webhook'));

        // REST API登録
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));

        error_log('GrandPay Payment Processor: Initialized');
    }

    /**
     * Webhook用REST APIエンドポイント登録
     */
    public function register_webhook_endpoint() {
        register_rest_route('grandpay/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook_rest'),
            'permission_callback' => '__return_true'
        ));

        error_log('GrandPay Payment: REST API webhook endpoint registered');
    }

    /**
     * メイン決済処理 - Welcart決済フロー統合
     */
    public function process_payment() {
        global $usces;

        error_log('GrandPay Payment: process_payment() called');

        // Welcartの決済設定を確認
        $acting_settings = $usces->options['acting_settings'] ?? array();
        $acting_flag = $acting_settings['acting_flag'] ?? '';

        error_log('GrandPay Payment: Current acting_flag: ' . $acting_flag);

        // フォームデータも確認
        $payment_method = $_POST['offer']['payment_method'] ?? '';
        error_log('GrandPay Payment: Posted payment method: ' . $payment_method);

        // GrandPayが選択されているかチェック
        $is_grandpay_selected = false;

        if ($acting_flag === 'grandpay') {
            $is_grandpay_selected = true;
            error_log('GrandPay Payment: Selected via acting_flag');
        }

        if (in_array($payment_method, array('acting_grandpay_card', 'grandpay'))) {
            $is_grandpay_selected = true;
            error_log('GrandPay Payment: Selected via payment_method');
        }

        // payment_nameでも確認
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

        // GrandPay設定確認
        $grandpay_options = $acting_settings['grandpay'] ?? array();
        if (($grandpay_options['activate'] ?? 'off') !== 'on') {
            error_log('GrandPay Payment: GrandPay not activated');
            $usces->error_message = 'GrandPay決済が有効になっていません。';
            $this->redirect_to_cart_with_error($usces->error_message);
            return;
        }

        // 注文データを取得・準備
        $order_data = $this->prepare_order_data();
        if (!$order_data) {
            error_log('GrandPay Payment: Failed to prepare order data');
            $usces->error_message = '注文データの準備に失敗しました';
            $this->redirect_to_cart_with_error($usces->error_message);
            return;
        }

        error_log('GrandPay Payment: Order data prepared: ' . print_r($order_data, true));

        // チェックアウトセッション作成
        $result = $this->api->create_checkout_session($order_data);

        if (!$result['success']) {
            error_log('GrandPay Payment: Checkout session creation failed: ' . $result['error']);
            $usces->error_message = $result['error'];
            $this->redirect_to_cart_with_error($usces->error_message);
            return;
        }

        if (isset($result['session_id']) && isset($result['checkout_url'])) {
            // 注文情報を保存
            $this->save_order_data($order_data['order_id'], $result, $order_data);

            error_log('GrandPay Payment: Redirecting to checkout URL: ' . $result['checkout_url']);

            // GrandPayの決済ページにリダイレクト
            wp_redirect($result['checkout_url']);
            exit;
        }

        // 予期しないエラー
        error_log('GrandPay Payment: Unexpected error in payment processing');
        $usces->error_message = '決済処理中にエラーが発生しました';
        $this->redirect_to_cart_with_error($usces->error_message);
    }

    /**
     * 注文データを準備（Welcart API修正版 v2）
     */
    private function prepare_order_data() {
        global $usces;

        try {
            // 基本データ取得
            $cart = $usces->cart;
            $member = $usces->get_member();
            $total_price = $usces->get_total_price();

            // 注文IDの取得 - Welcartの正しい方法で
            $order_id = null;

            // 1. セッションから注文IDを取得
            if (isset($_SESSION['usces_entry']['order_id'])) {
                $order_id = $_SESSION['usces_entry']['order_id'];
                error_log('GrandPay Payment: Order ID from session: ' . $order_id);
            }

            // 2. POSTデータから取得
            if (!$order_id && isset($_POST['order_id'])) {
                $order_id = intval($_POST['order_id']);
                error_log('GrandPay Payment: Order ID from POST: ' . $order_id);
            }

            // 3. Welcartの内部変数から取得
            if (!$order_id && isset($usces->current_order_id)) {
                $order_id = $usces->current_order_id;
                error_log('GrandPay Payment: Order ID from usces object: ' . $order_id);
            }

            // 4. 一時的な注文IDを生成（最後の手段）
            if (!$order_id) {
                $order_id = 'TEMP_' . time() . '_' . rand(1000, 9999);
                error_log('GrandPay Payment: Generated temporary order ID: ' . $order_id);
            }

            // 顧客情報の取得
            $customer_data = array();

            // 1. セッションのエントリーデータから取得
            if (isset($_SESSION['usces_entry']['customer'])) {
                $customer_data = $_SESSION['usces_entry']['customer'];
                error_log('GrandPay Payment: Customer data from session entry');
            }
            // 2. POSTデータから取得
            elseif (isset($_POST['customer'])) {
                $customer_data = $_POST['customer'];
                error_log('GrandPay Payment: Customer data from POST');
            }
            // 3. セッションのお客様情報から取得
            elseif (isset($_SESSION['usces_member'])) {
                $session_member = $_SESSION['usces_member'];
                $customer_data = array(
                    'name1' => $session_member['mem_name1'] ?? '',
                    'name2' => $session_member['mem_name2'] ?? '',
                    'mailaddress1' => $session_member['mem_email'] ?? '',
                    'tel' => $session_member['mem_tel'] ?? ''
                );
                error_log('GrandPay Payment: Customer data from session member');
            }
            // 4. 会員情報から取得
            elseif (!empty($member)) {
                $customer_data = array(
                    'name1' => $member['mem_name1'] ?? '',
                    'name2' => $member['mem_name2'] ?? '',
                    'mailaddress1' => $member['mem_email'] ?? '',
                    'tel' => $member['mem_tel'] ?? ''
                );
                error_log('GrandPay Payment: Customer data from member');
            }

            // デバッグ: 利用可能なセッションデータをログ出力
            error_log('GrandPay Payment: Available session keys: ' . print_r(array_keys($_SESSION), true));
            if (isset($_SESSION['usces_entry'])) {
                error_log('GrandPay Payment: usces_entry keys: ' . print_r(array_keys($_SESSION['usces_entry']), true));
            }

            // 顧客情報の統合
            $customer_name = trim(($customer_data['name1'] ?? '') . ' ' . ($customer_data['name2'] ?? ''));
            $customer_email = $customer_data['mailaddress1'] ?? $customer_data['email'] ?? '';
            $customer_phone = $customer_data['tel'] ?? $customer_data['phone'] ?? '';

            // デフォルト値の設定
            if (empty($customer_email)) {
                $customer_email = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'example.com');
                error_log('GrandPay Payment: Using default email: ' . $customer_email);
            }

            if (empty($customer_name)) {
                $customer_name = 'お客様';
                error_log('GrandPay Payment: Using default name');
            }

            // 金額の確認
            if (empty($total_price) || $total_price <= 0) {
                $total_price = 1000; // デフォルト金額
                error_log('GrandPay Payment: Using default amount: ' . $total_price);
            }

            // URL構築
            $base_url = home_url();

            // Welcartの標準的なURL構造
            $complete_url = $base_url . '/usces-member/?page=completionmember';
            $cart_url = $base_url . '/usces-cart/';

            // usces->urlが利用可能な場合はそれを使用
            if (isset($usces->url['complete_page'])) {
                $complete_url = $usces->url['complete_page'];
            }
            if (isset($usces->url['cart_page'])) {
                $cart_url = $usces->url['cart_page'];
            }

            // URLを簡略化（テスト用）
            $success_url = $complete_url . '?result=success&oid=' . $order_id;
            $failure_url = $cart_url . '?result=failure&oid=' . $order_id;

            error_log('GrandPay Payment: 簡略化されたURL');
            error_log('GrandPay Payment: Success URL: ' . $success_url);
            error_log('GrandPay Payment: Failure URL: ' . $failure_url);

            $order_data = array(
                'order_id' => $order_id,
                'amount' => intval($total_price),
                'name' => $customer_name,
                'email' => $customer_email,
                'phone' => $customer_phone,
                'success_url' => $success_url,
                'failure_url' => $failure_url
            );

            error_log('GrandPay Payment: Final order data prepared:');
            error_log('GrandPay Payment: - Order ID: ' . $order_id);
            error_log('GrandPay Payment: - Amount: ' . $total_price);
            error_log('GrandPay Payment: - Customer: ' . $customer_name . ' (' . $customer_email . ')');
            error_log('GrandPay Payment: - Success URL: ' . $success_url);
            error_log('GrandPay Payment: - Failure URL: ' . $failure_url);

            return $order_data;
        } catch (Exception $e) {
            error_log('GrandPay Payment: Exception in prepare_order_data: ' . $e->getMessage());
            error_log('GrandPay Payment: Exception trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * 注文データを保存
     */
    private function save_order_data($order_id, $payment_result, $order_data) {
        // GrandPayセッション情報を保存
        update_post_meta($order_id, '_grandpay_session_id', $payment_result['session_id']);
        update_post_meta($order_id, '_grandpay_checkout_url', $payment_result['checkout_url']);
        update_post_meta($order_id, '_payment_method', 'grandpay');
        update_post_meta($order_id, '_grandpay_payment_status', 'pending');
        update_post_meta($order_id, '_grandpay_created_at', current_time('mysql'));

        // 注文データも保存
        update_post_meta($order_id, '_customer_email', $order_data['email']);
        update_post_meta($order_id, '_customer_name', $order_data['name']);
        update_post_meta($order_id, '_customer_phone', $order_data['phone']);
        update_post_meta($order_id, '_order_total', $order_data['amount']);

        error_log('GrandPay Payment: Order metadata saved for order: ' . $order_id);
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
        $session_check = $_GET['session_check'] ?? '';

        // nonce検証
        if (!wp_verify_nonce($session_check, 'grandpay_callback_' . $order_id)) {
            error_log('GrandPay Payment: Invalid callback nonce for order: ' . $order_id);
            wp_die('Invalid session', 'Callback Error', array('response' => 403));
            return;
        }

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
            $status_result = $this->api->get_payment_status($session_id);

            error_log('GrandPay Payment: Payment status response: ' . print_r($status_result, true));

            if ($status_result['success'] && isset($status_result['data']['data'])) {
                $payment_data = $status_result['data']['data'];
                $payment_status = $payment_data['status'] ?? '';

                if ($payment_status === 'COMPLETED') {
                    // 注文完了処理
                    $this->complete_order($order_id, $payment_data);

                    // 完了ページにリダイレクト
                    global $usces;
                    wp_redirect(add_query_arg('order_id', $order_id, $usces->url['complete_page']));
                    exit;
                } else {
                    error_log('GrandPay Payment: Payment not completed, status: ' . $payment_status);
                }
            } else {
                error_log('GrandPay Payment: Failed to get payment status');
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

        // 注文を失敗状態に設定
        $this->fail_order($order_id);

        // エラーメッセージと共にカートページにリダイレクト
        global $usces;
        wp_redirect(add_query_arg('error', 'payment_failed', $usces->url['cart_page']));
        exit;
    }

    /**
     * 注文完了処理
     */
    private function complete_order($order_id, $payment_data) {
        global $usces;

        // 注文ステータスを完了に変更
        if (function_exists('usces_change_order_status')) {
            usces_change_order_status($order_id, 'ordercompletion');
        } else {
            // フォールバック
            update_post_meta($order_id, '_order_status', 'ordercompletion');
        }

        // 決済情報を保存
        update_post_meta($order_id, '_grandpay_payment_status', 'completed');
        update_post_meta($order_id, '_grandpay_transaction_id', $payment_data['id'] ?? '');
        update_post_meta($order_id, '_grandpay_completed_at', current_time('mysql'));
        update_post_meta($order_id, '_grandpay_payment_data', $payment_data);

        // カートをクリア
        if (isset($usces->cart)) {
            $usces->cart->empty_cart();
        }

        error_log('GrandPay Payment: Order completed successfully - ID: ' . $order_id);

        // 完了フックを実行
        do_action('grandpay_payment_completed', $order_id, $payment_data);
    }

    /**
     * 注文失敗処理
     */
    private function fail_order($order_id) {
        global $usces;

        // 注文ステータスを失敗に変更
        if (function_exists('usces_change_order_status')) {
            usces_change_order_status($order_id, 'cancel');
        } else {
            // フォールバック
            update_post_meta($order_id, '_order_status', 'cancel');
        }

        // 決済情報を更新
        update_post_meta($order_id, '_grandpay_payment_status', 'failed');
        update_post_meta($order_id, '_grandpay_failed_at', current_time('mysql'));

        error_log('GrandPay Payment: Order failed - ID: ' . $order_id);

        // 失敗フックを実行
        do_action('grandpay_payment_failed', $order_id);
    }

    /**
     * REST API Webhook処理
     */
    public function handle_webhook_rest($request) {
        error_log('GrandPay Payment: REST API Webhook received');

        $body = $request->get_body();
        $headers = $request->get_headers();

        // 署名検証（将来的に実装）
        $signature = $headers['x_grandpay_signature'][0] ?? '';

        error_log('GrandPay Payment: Webhook payload: ' . $body);
        error_log('GrandPay Payment: Webhook headers: ' . print_r($headers, true));

        $data = json_decode($body, true);

        if (!$data || !isset($data['type'])) {
            error_log('GrandPay Payment: Invalid webhook payload');
            return new WP_Error('invalid_payload', 'Invalid payload', array('status' => 400));
        }

        error_log('GrandPay Payment: Webhook event type: ' . $data['type']);

        // イベントタイプに応じて処理
        switch ($data['type']) {
            case 'PAYMENT_CHECKOUT':
            case 'checkout.session.completed':
            case 'payment.succeeded':
                $this->process_payment_webhook($data);
                break;

            case 'payment.failed':
                $this->process_payment_failure_webhook($data);
                break;

            default:
                error_log('GrandPay Payment: Unknown webhook event: ' . $data['type']);
                break;
        }

        return rest_ensure_response(array('status' => 'ok'));
    }

    /**
     * 旧形式のWebhook処理（後方互換性）
     */
    public function handle_webhook() {
        error_log('GrandPay Payment: Legacy webhook received');

        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_GRANDPAY_SIGNATURE'] ?? '';

        if (empty($payload)) {
            error_log('GrandPay Payment: Empty webhook payload');
            wp_die('Empty payload', 'Webhook Error', array('response' => 400));
        }

        $data = json_decode($payload, true);

        if (!$data || !isset($data['type'])) {
            error_log('GrandPay Payment: Invalid webhook payload');
            wp_die('Invalid payload', 'Webhook Error', array('response' => 400));
        }

        // REST API処理に転送
        $request = new WP_REST_Request('POST', '/grandpay/v1/webhook');
        $request->set_body($payload);
        $request->set_header('x-grandpay-signature', $signature);

        $response = $this->handle_webhook_rest($request);

        if (is_wp_error($response)) {
            wp_die($response->get_error_message(), 'Webhook Error', array('response' => 400));
        }

        wp_die('OK', 'Webhook Success', array('response' => 200));
    }

    /**
     * 決済成功Webhook処理
     */
    private function process_payment_webhook($data) {
        if (!isset($data['data']['object']['id'])) {
            error_log('GrandPay Payment: Webhook missing session ID');
            return;
        }

        $session_id = $data['data']['object']['id'];
        error_log('GrandPay Payment: Processing webhook for session: ' . $session_id);

        // セッションIDから注文IDを検索
        $order_id = $this->find_order_by_session_id($session_id);

        if (!$order_id) {
            error_log('GrandPay Payment: Order not found for session ID: ' . $session_id);
            return;
        }

        $payment_status = $data['data']['object']['status'] ?? '';
        error_log('GrandPay Payment: Webhook processing order: ' . $order_id . ', status: ' . $payment_status);

        switch ($payment_status) {
            case 'COMPLETED':
            case 'succeeded':
                $this->complete_order($order_id, $data['data']['object']);
                break;

            case 'REJECTED':
            case 'FAILED':
            case 'failed':
                $this->fail_order($order_id);
                break;

            default:
                error_log('GrandPay Payment: Unknown payment status via webhook: ' . $payment_status);
                break;
        }

        // Webhook受信ログ
        update_post_meta($order_id, '_grandpay_webhook_received', current_time('mysql'));
        update_post_meta($order_id, '_grandpay_webhook_data', $data);
    }

    /**
     * 決済失敗Webhook処理
     */
    private function process_payment_failure_webhook($data) {
        if (!isset($data['data']['object']['id'])) {
            error_log('GrandPay Payment: Failure webhook missing session ID');
            return;
        }

        $session_id = $data['data']['object']['id'];
        $order_id = $this->find_order_by_session_id($session_id);

        if ($order_id) {
            $this->fail_order($order_id);
            update_post_meta($order_id, '_grandpay_webhook_received', current_time('mysql'));
        }
    }

    /**
     * セッションIDから注文を検索
     */
    private function find_order_by_session_id($session_id) {
        $posts = get_posts(array(
            'post_type' => 'shop_order',
            'meta_key' => '_grandpay_session_id',
            'meta_value' => $session_id,
            'post_status' => 'any',
            'numberposts' => 1,
            'fields' => 'ids'
        ));

        return empty($posts) ? false : $posts[0];
    }

    /**
     * エラー時のリダイレクト
     */
    private function redirect_to_cart_with_error($error_message) {
        global $usces;

        $cart_url = $usces->url['cart_page'] ?? home_url('/cart/');
        $redirect_url = add_query_arg('grandpay_error', urlencode($error_message), $cart_url);

        wp_redirect($redirect_url);
        exit;
    }
}
