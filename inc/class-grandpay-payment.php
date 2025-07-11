<?php

/**
 * GrandPay決済処理クラス - 完全リセット版
 * 構文エラーなし、Welcart標準フロー準拠、シンプルで確実な実装
 */
class WelcartGrandpayPaymentProcessor {

    private $api;

    public function __construct() {
        $this->api = new WelcartGrandpayAPI();

        // Welcartが完全に読み込まれてから初期化
        add_action('init', array($this, 'init_payment_system'), 25);

        error_log('GrandPay Payment: Constructor completed');
    }

    /**
     * 決済システムの初期化
     */
    public function init_payment_system() {
        // Welcartの可用性確認
        if (!$this->is_welcart_available()) {
            error_log('GrandPay Payment: Welcart not available, deferring initialization');
            add_action('wp_loaded', array($this, 'init_payment_system'), 10);
            return;
        }

        // 決済フックの登録
        add_action('usces_action_acting_processing', array($this, 'process_payment'), 10);
        add_action('wp', array($this, 'handle_payment_callback'), 1);
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));

        error_log('GrandPay Payment: Payment system initialized successfully');
    }

    /**
     * Welcart可用性チェック
     */
    private function is_welcart_available() {
        global $usces;

        $required_functions = array(
            'usces_get_system_option',
            'usces_is_login',
            'usces_get_member',
            'usces_new_order_id'
        );

        foreach ($required_functions as $function) {
            if (!function_exists($function)) {
                error_log('GrandPay Payment: Required function not available: ' . $function);
                return false;
            }
        }

        if (!isset($usces) || !is_object($usces)) {
            error_log('GrandPay Payment: $usces global not available');
            return false;
        }

        return true;
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
    }

    /**
     * メイン決済処理
     */
    public function process_payment() {
        global $usces;

        error_log('GrandPay Payment: ========== PAYMENT PROCESSING START ==========');

        // GrandPay決済の判定
        if (!$this->is_grandpay_payment()) {
            error_log('GrandPay Payment: Not GrandPay payment method');
            return;
        }

        error_log('GrandPay Payment: GrandPay payment confirmed');

        // GrandPay設定確認
        if (!$this->validate_grandpay_settings()) {
            error_log('GrandPay Payment: Invalid GrandPay settings');
            $this->redirect_with_error('GrandPay決済設定に問題があります。');
            return;
        }

        // セッション情報の保存
        $this->preserve_session_data();

        // 一時的な注文データ準備
        $temp_order_data = $this->prepare_temp_order_data();
        if (!$temp_order_data) {
            error_log('GrandPay Payment: Failed to prepare temp order data');
            $this->redirect_with_error('注文データの準備に失敗しました。');
            return;
        }

        // チェックアウトセッション作成
        $result = $this->api->create_checkout_session($temp_order_data);
        if (!$result['success']) {
            error_log('GrandPay Payment: Checkout session failed: ' . $result['error']);
            $this->redirect_with_error($result['error']);
            return;
        }

        // セッション情報保存
        $this->save_temp_session_data($result, $temp_order_data);

        error_log('GrandPay Payment: Redirecting to GrandPay: ' . $result['checkout_url']);

        // GrandPayにリダイレクト
        wp_redirect($result['checkout_url']);
        exit;
    }

    /**
     * GrandPay決済かどうかの判定
     */
    private function is_grandpay_payment() {
        global $usces;

        // acting_flagの確認
        if (isset($usces->options['acting_settings']['acting_flag'])) {
            $acting_flag = $usces->options['acting_settings']['acting_flag'];
            if ($acting_flag === 'grandpay') {
                error_log('GrandPay Payment: Selected via acting_flag');
                return true;
            }
        }

        // POSTデータの確認
        if (isset($_POST['offer']['payment_method'])) {
            $payment_method = $_POST['offer']['payment_method'];
            if (in_array($payment_method, array('acting_grandpay_card', 'grandpay'))) {
                error_log('GrandPay Payment: Selected via POST payment_method');
                return true;
            }
        }

        // payment_nameの確認
        if (isset($_POST['offer']['payment_name'])) {
            $payment_name = $_POST['offer']['payment_name'];
            if (strpos($payment_name, 'GrandPay') !== false) {
                error_log('GrandPay Payment: Selected via payment_name');
                return true;
            }
        }

        return false;
    }

    /**
     * GrandPay設定の検証
     */
    private function validate_grandpay_settings() {
        global $usces;

        if (!isset($usces->options['acting_settings']['grandpay'])) {
            return false;
        }

        $grandpay_options = $usces->options['acting_settings']['grandpay'];

        if (!isset($grandpay_options['activate']) || $grandpay_options['activate'] !== 'on') {
            return false;
        }

        $required_fields = array('tenant_key', 'client_id', 'username', 'credentials');
        foreach ($required_fields as $field) {
            if (!isset($grandpay_options[$field]) || empty($grandpay_options[$field])) {
                error_log('GrandPay Payment: Missing required field: ' . $field);
                return false;
            }
        }

        return true;
    }

    /**
     * セッション情報の保存
     */
    private function preserve_session_data() {
        global $usces;

        error_log('GrandPay Payment: Preserving session data');

        // usces_entryの保存
        if (isset($_SESSION['usces_entry'])) {
            $_SESSION['grandpay_preserved_entry'] = $_SESSION['usces_entry'];
        }

        // カート情報の保存
        if (isset($usces->cart)) {
            $_SESSION['grandpay_preserved_cart'] = serialize($usces->cart);
        }

        // 会員情報の保存（安全に）
        $member_info = $this->get_member_info_safely();
        if ($member_info) {
            $_SESSION['grandpay_preserved_member'] = $member_info;
        }

        // POSTデータの保存
        $_SESSION['grandpay_preserved_post'] = $_POST;

        error_log('GrandPay Payment: Session data preserved');
    }

    /**
     * 安全な会員情報取得
     */
    private function get_member_info_safely() {
        try {
            if (!function_exists('usces_is_login') || !function_exists('usces_get_member')) {
                return null;
            }

            if (!usces_is_login()) {
                return null;
            }

            $member_info = usces_get_member();

            if (empty($member_info) || !is_array($member_info)) {
                return null;
            }

            return $member_info;
        } catch (Exception $e) {
            error_log('GrandPay Payment: Exception in get_member_info_safely: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 一時的な注文データの準備
     */
    private function prepare_temp_order_data() {
        global $usces;

        error_log('GrandPay Payment: Preparing temporary order data');

        try {
            // 一時的な注文ID生成
            $temp_order_id = 'TEMP_GP_' . time() . '_' . wp_generate_password(8, false);

            // 金額取得
            $total_amount = $this->get_order_total();

            // 顧客情報取得
            $customer_info = $this->get_customer_info();

            // コールバックURL作成
            $callback_nonce = wp_create_nonce('grandpay_temp_' . $temp_order_id);

            $success_url = add_query_arg(array(
                'grandpay_result' => 'success',
                'temp_id' => $temp_order_id,
                'nonce' => $callback_nonce
            ), home_url('/usces-member/?page=completionmember'));

            $failure_url = add_query_arg(array(
                'grandpay_result' => 'failure',
                'temp_id' => $temp_order_id,
                'nonce' => $callback_nonce
            ), home_url('/usces-cart/'));

            $temp_order_data = array(
                'order_id' => $temp_order_id,
                'amount' => intval($total_amount),
                'name' => $customer_info['name'],
                'email' => $customer_info['email'],
                'phone' => $customer_info['phone'],
                'success_url' => $success_url,
                'failure_url' => $failure_url
            );

            error_log('GrandPay Payment: Temp order data prepared successfully');
            return $temp_order_data;
        } catch (Exception $e) {
            error_log('GrandPay Payment: Exception in prepare_temp_order_data: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 注文金額取得
     */
    private function get_order_total() {
        global $usces;

        $total_amount = 0;

        // Welcartカートから取得
        if (isset($usces->cart) && method_exists($usces->cart, 'get_total_price')) {
            $total_amount = $usces->cart->get_total_price();
        }

        // セッションから取得
        if ($total_amount <= 0 && isset($_SESSION['usces_entry']['order']['total_full_price'])) {
            $total_amount = intval($_SESSION['usces_entry']['order']['total_full_price']);
        }

        // デフォルト値
        if ($total_amount <= 0) {
            $total_amount = 1000;
        }

        return $total_amount;
    }

    /**
     * 顧客情報取得
     */
    private function get_customer_info() {
        // 1. 会員情報を最優先
        $member_info = $this->get_member_info_safely();
        if ($member_info) {
            $name1 = isset($member_info['mem_name1']) ? $member_info['mem_name1'] : '';
            $name2 = isset($member_info['mem_name2']) ? $member_info['mem_name2'] : '';
            $email = isset($member_info['mem_email']) ? $member_info['mem_email'] : '';
            $phone = isset($member_info['mem_tel']) ? $member_info['mem_tel'] : '';

            $name = trim($name1 . ' ' . $name2);

            if (!empty($name) && !empty($email)) {
                return array(
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone
                );
            }
        }

        // 2. セッション情報
        if (isset($_SESSION['usces_entry']['customer'])) {
            $customer = $_SESSION['usces_entry']['customer'];
            $name1 = isset($customer['name1']) ? $customer['name1'] : '';
            $name2 = isset($customer['name2']) ? $customer['name2'] : '';
            $email = isset($customer['mailaddress1']) ? $customer['mailaddress1'] : '';
            $phone = isset($customer['tel']) ? $customer['tel'] : '';

            $name = trim($name1 . ' ' . $name2);

            if (!empty($name) && !empty($email)) {
                return array(
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone
                );
            }
        }

        // 3. POSTデータ
        if (isset($_POST['customer'])) {
            $customer = $_POST['customer'];
            $name1 = isset($customer['name1']) ? $customer['name1'] : '';
            $name2 = isset($customer['name2']) ? $customer['name2'] : '';
            $email = isset($customer['mailaddress1']) ? $customer['mailaddress1'] : '';
            $phone = isset($customer['tel']) ? $customer['tel'] : '';

            $name = trim($name1 . ' ' . $name2);

            if (!empty($name) && !empty($email)) {
                return array(
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone
                );
            }
        }

        // 4. デフォルト値
        return array(
            'name' => 'お客様',
            'email' => 'customer@example.com',
            'phone' => ''
        );
    }

    /**
     * 一時的なセッションデータ保存
     */
    private function save_temp_session_data($payment_result, $temp_order_data) {
        $_SESSION['grandpay_temp_session'] = array(
            'session_id' => $payment_result['session_id'],
            'checkout_url' => $payment_result['checkout_url'],
            'temp_order_id' => $temp_order_data['order_id'],
            'amount' => $temp_order_data['amount'],
            'customer_name' => $temp_order_data['name'],
            'customer_email' => $temp_order_data['email'],
            'created_at' => current_time('mysql')
        );

        error_log('GrandPay Payment: Temp session data saved');
    }

    /**
     * 決済完了後のコールバック処理
     */
    public function handle_payment_callback() {
        static $processed = false;
        if ($processed) {
            return;
        }

        if (!isset($_GET['grandpay_result']) || !isset($_GET['temp_id'])) {
            return;
        }

        $processed = true;

        $result = sanitize_text_field($_GET['grandpay_result']);
        $temp_id = sanitize_text_field($_GET['temp_id']);
        $nonce = isset($_GET['nonce']) ? $_GET['nonce'] : '';

        error_log('GrandPay Payment: ========== CALLBACK PROCESSING START ==========');

        // nonce検証
        if (!wp_verify_nonce($nonce, 'grandpay_temp_' . $temp_id)) {
            error_log('GrandPay Payment: Invalid callback nonce');
            wp_die('Invalid session', 'Payment Error', array('response' => 403));
            return;
        }

        // セッションデータ確認
        if (
            !isset($_SESSION['grandpay_temp_session']) ||
            $_SESSION['grandpay_temp_session']['temp_order_id'] !== $temp_id
        ) {
            error_log('GrandPay Payment: Session data mismatch');
            wp_die('Session data not found', 'Payment Error', array('response' => 404));
            return;
        }

        $session_data = $_SESSION['grandpay_temp_session'];

        if ($result === 'success') {
            $this->handle_successful_payment($session_data);
        } else {
            $this->handle_failed_payment($session_data);
        }

        error_log('GrandPay Payment: ========== CALLBACK PROCESSING END ==========');
    }

    /**
     * 決済成功時の処理
     */
    private function handle_successful_payment($session_data) {
        error_log('GrandPay Payment: ========== SUCCESSFUL PAYMENT PROCESSING ==========');

        try {
            // セッション情報復元
            $this->restore_session_data();

            // 決済ステータス確認
            $payment_status = $this->verify_payment_status($session_data['session_id']);
            if (!$payment_status['success']) {
                error_log('GrandPay Payment: Payment verification failed');
                $this->handle_failed_payment($session_data);
                return;
            }

            // Welcart標準フローで注文作成
            $order_id = $this->create_order_with_welcart();
            if (!$order_id) {
                error_log('GrandPay Payment: Failed to create order');
                $this->redirect_with_error('注文の作成に失敗しました。');
                return;
            }

            // GrandPay情報を注文に追加
            $this->add_grandpay_info($order_id, $session_data, $payment_status['data']);

            // 注文ステータス設定
            $this->set_order_status($order_id);

            // 完了ページにリダイレクト
            $this->redirect_to_completion($order_id);
        } catch (Exception $e) {
            error_log('GrandPay Payment: Exception in handle_successful_payment: ' . $e->getMessage());
            $this->redirect_with_error('決済処理中にエラーが発生しました。');
        }
    }

    /**
     * セッション情報復元
     */
    private function restore_session_data() {
        if (isset($_SESSION['grandpay_preserved_entry'])) {
            $_SESSION['usces_entry'] = $_SESSION['grandpay_preserved_entry'];
        }

        if (isset($_SESSION['grandpay_preserved_cart'])) {
            global $usces;
            $usces->cart = unserialize($_SESSION['grandpay_preserved_cart']);
        }

        if (isset($_SESSION['grandpay_preserved_post'])) {
            $_POST = $_SESSION['grandpay_preserved_post'];
        }

        error_log('GrandPay Payment: Session data restored');
    }

    /**
     * 決済ステータス確認
     */
    private function verify_payment_status($session_id) {
        $status_result = $this->api->get_payment_status($session_id);

        if (!$status_result['success']) {
            return array('success' => false, 'error' => 'API確認失敗');
        }

        $payment_data = array();
        if (isset($status_result['data']['data'])) {
            $payment_data = $status_result['data']['data'];
        }

        $session_status = isset($payment_data['status']) ? $payment_data['status'] : '';
        $actual_payment_status = '';

        if (isset($payment_data['payments']) && !empty($payment_data['payments'])) {
            $latest_payment = end($payment_data['payments']);
            if (isset($latest_payment['status'])) {
                $actual_payment_status = $latest_payment['status'];
            }
        }

        $final_status = !empty($actual_payment_status) ? $actual_payment_status : $session_status;
        $final_status_upper = strtoupper(trim($final_status));

        $success_statuses = array('COMPLETED', 'COMPLETE', 'SUCCESS', 'SUCCEEDED', 'PAID', 'AUTHORIZED');

        if (in_array($final_status_upper, $success_statuses)) {
            return array('success' => true, 'data' => $payment_data);
        }

        return array('success' => false, 'error' => 'Payment not completed: ' . $final_status);
    }

    /**
     * Welcart標準フローで注文作成
     */
    private function create_order_with_welcart() {
        global $usces;

        try {
            // 注文ID生成
            if (!function_exists('usces_new_order_id')) {
                return $this->create_fallback_order();
            }

            $order_id = usces_new_order_id();
            if (!$order_id) {
                return $this->create_fallback_order();
            }

            // Welcart標準の受注データ登録
            if (isset($_SESSION['usces_entry']) && isset($usces->cart)) {
                $entry = $_SESSION['usces_entry'];
                $cart = $usces->cart;

                $payments = array(
                    'settlement' => 'acting_grandpay_card',
                    'acting' => 'grandpay'
                );

                $args = array(
                    'order_id' => $order_id,
                    'cart' => $cart,
                    'entry' => $entry,
                    'payments' => $payments
                );

                do_action('usces_action_reg_orderdata', $args);
            }

            return $order_id;
        } catch (Exception $e) {
            error_log('GrandPay Payment: Exception in create_order_with_welcart: ' . $e->getMessage());
            return $this->create_fallback_order();
        }
    }

    /**
     * フォールバック注文作成
     */
    private function create_fallback_order() {
        $order_number = 'GP' . time();

        $order_post = array(
            'post_type' => 'shop_order',
            'post_status' => 'private',
            'post_title' => 'Order #' . $order_number,
            'post_content' => 'GrandPay Order',
            'post_author' => 1
        );

        $order_id = wp_insert_post($order_post);

        if (is_wp_error($order_id)) {
            return false;
        }

        // 基本メタデータ設定
        $this->set_fallback_metadata($order_id, $order_number);

        return $order_id;
    }

    /**
     * フォールバック用メタデータ設定
     */
    private function set_fallback_metadata($order_id, $order_number) {
        update_post_meta($order_id, '_order_number', $order_number);
        update_post_meta($order_id, '_order_date', current_time('mysql'));
        update_post_meta($order_id, '_order_status', 'new_order');
        update_post_meta($order_id, '_payment_method', 'acting_grandpay_card');
        update_post_meta($order_id, '_settlement', 'grandpay');

        // 金額情報
        if (isset($_SESSION['grandpay_temp_session']['amount'])) {
            $amount = $_SESSION['grandpay_temp_session']['amount'];
            update_post_meta($order_id, '_total_full_price', $amount);
        }

        // 顧客情報
        if (isset($_SESSION['grandpay_temp_session']['customer_name'])) {
            update_post_meta($order_id, '_customer_name', $_SESSION['grandpay_temp_session']['customer_name']);
        }

        if (isset($_SESSION['grandpay_temp_session']['customer_email'])) {
            update_post_meta($order_id, '_customer_email', $_SESSION['grandpay_temp_session']['customer_email']);
        }

        // 会員情報
        if (isset($_SESSION['grandpay_preserved_member'])) {
            $member = $_SESSION['grandpay_preserved_member'];
            if (isset($member['ID'])) {
                update_post_meta($order_id, '_member_id', $member['ID']);
            }
            if (isset($member['mem_name1'])) {
                update_post_meta($order_id, '_customer_name1', $member['mem_name1']);
            }
            if (isset($member['mem_name2'])) {
                update_post_meta($order_id, '_customer_name2', $member['mem_name2']);
            }
            if (isset($member['mem_pref'])) {
                update_post_meta($order_id, '_customer_pref', $member['mem_pref']);
            }
        }
    }

    /**
     * GrandPay情報を注文に追加
     */
    private function add_grandpay_info($order_id, $session_data, $payment_data) {
        update_post_meta($order_id, '_grandpay_session_id', $session_data['session_id']);
        update_post_meta($order_id, '_grandpay_completed_at', current_time('mysql'));
        update_post_meta($order_id, '_grandpay_payment_data', $payment_data);

        if (isset($payment_data['id'])) {
            update_post_meta($order_id, '_grandpay_transaction_id', $payment_data['id']);
            update_post_meta($order_id, '_wc_trans_id', $payment_data['id']);
        }
    }

    /**
     * 注文ステータス設定
     */
    private function set_order_status($order_id) {
        // 新規受付 + 入金済み状態
        if (function_exists('usces_change_order_status')) {
            try {
                usces_change_order_status($order_id, 'new_order');
            } catch (Exception $e) {
                update_post_meta($order_id, '_order_status', 'new_order');
            }
        } else {
            update_post_meta($order_id, '_order_status', 'new_order');
        }

        update_post_meta($order_id, '_payment_status', 'paid');
        update_post_meta($order_id, '_paid_date', current_time('mysql'));
        update_post_meta($order_id, '_acting_return', 'completion');
    }

    /**
     * 決済失敗時の処理
     */
    private function handle_failed_payment($session_data) {
        $this->clear_temp_data();
        $this->redirect_with_error('決済に失敗しました。再度お試しください。');
    }

    /**
     * 一時データクリア
     */
    private function clear_temp_data() {
        unset($_SESSION['grandpay_temp_session']);
        unset($_SESSION['grandpay_preserved_entry']);
        unset($_SESSION['grandpay_preserved_cart']);
        unset($_SESSION['grandpay_preserved_member']);
        unset($_SESSION['grandpay_preserved_post']);
    }

    /**
     * 完了ページリダイレクト
     */
    private function redirect_to_completion($order_id) {
        $complete_url = home_url('/usces-member/?page=completionmember');
        $redirect_url = add_query_arg('order_id', $order_id, $complete_url);

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * エラー時リダイレクト
     */
    private function redirect_with_error($error_message) {
        $cart_url = home_url('/usces-cart/');
        $redirect_url = add_query_arg('grandpay_error', urlencode($error_message), $cart_url);

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Webhook処理
     */
    public function handle_webhook_rest($request) {
        $body = $request->get_body();
        $data = json_decode($body, true);

        if (!$data) {
            return new WP_Error('invalid_payload', 'Invalid payload', array('status' => 400));
        }

        $event_type = '';
        if (isset($data['eventName'])) {
            $event_type = $data['eventName'];
        } elseif (isset($data['type'])) {
            $event_type = $data['type'];
        }

        switch ($event_type) {
            case 'payment.payment.done':
            case 'PAYMENT_CHECKOUT':
            case 'checkout.session.completed':
            case 'payment.succeeded':
                $this->update_order_from_webhook($data, 'completed');
                break;

            case 'payment.failed':
                $this->update_order_from_webhook($data, 'failed');
                break;
        }

        return rest_ensure_response(array('status' => 'ok'));
    }

    /**
     * Webhookからの注文更新
     */
    private function update_order_from_webhook($webhook_data, $status) {
        if (!isset($webhook_data['data']['metadata']['checkoutSessionId'])) {
            return;
        }

        $session_id = $webhook_data['data']['metadata']['checkoutSessionId'];
        $order_id = $this->find_order_by_session_id($session_id);

        if ($order_id) {
            update_post_meta($order_id, '_grandpay_webhook_updated', current_time('mysql'));
            if ($status === 'completed') {
                update_post_meta($order_id, '_grandpay_webhook_confirmed', true);
            }
        }
    }

    /**
     * セッションIDから注文検索
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
}
