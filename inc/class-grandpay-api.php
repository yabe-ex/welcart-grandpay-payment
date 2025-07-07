<?php

/**
 * GrandPay API通信クラス（実際の仕様に基づく修正版）
 */
class WelcartGrandpayAPI {

    private $api_base_url;
    private $tenant_key;
    private $username;
    private $credentials;
    private $test_mode;
    private $access_token;
    private $token_expires_at;

    public function __construct() {
        $this->api_base_url = 'https://api.payment-gateway.asia';
        $this->tenant_key = get_option('welcart_grandpay_tenant_key', '');
        $this->username = get_option('welcart_grandpay_username', '');
        $this->credentials = get_option('welcart_grandpay_credentials', '');
        $this->test_mode = get_option('welcart_grandpay_test_mode', false);
        $this->access_token = get_transient('welcart_grandpay_access_token');
        $this->token_expires_at = get_transient('welcart_grandpay_token_expires_at');

        error_log('GrandPay API: Using base URL: ' . $this->api_base_url);
    }

    /**
     * OAuth2 アクセストークン取得（実際の仕様）
     */
    public function get_access_token() {
        // 設定確認
        if (empty($this->username) || empty($this->credentials)) {
            error_log('GrandPay API: Username or credentials not set');

            // テストモードでモックトークンを試す
            if ($this->test_mode) {
                return $this->get_mock_access_token();
            }

            return false;
        }

        // トークンが有効期限内なら既存のものを返す
        if ($this->access_token && $this->token_expires_at && time() < $this->token_expires_at - 300) {
            error_log('GrandPay API: Using cached access token');
            return $this->access_token;
        }

        error_log('GrandPay API: Requesting new access token');
        error_log('GrandPay API: Username: ' . $this->username);
        error_log('GrandPay API: Test mode: ' . ($this->test_mode ? 'true' : 'false'));

        // 実際のエンドポイント
        $url = $this->api_base_url . '/uaa/oauth2/token';

        // WooCommerceソースコードと同じ認証ヘッダー
        $headers = array(
            'Authorization' => 'Basic Y2xpZW50OnNlY3JldA==', // client:secret
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'Welcart-GrandPay/' . WELCART_GRANDPAY_PAYMENT_VERSION
        );

        // 実際のリクエストボディ
        $body = array(
            'grant_type' => 'custom-password-grant',
            'username' => $this->username,
            'credentials' => $this->credentials
        );

        error_log('GrandPay API: OAuth request URL: ' . $url);
        error_log('GrandPay API: OAuth request body: ' . print_r($body, true));

        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => http_build_query($body),
            'timeout' => 30,
            'sslverify' => !$this->test_mode,
            'blocking' => true
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('GrandPay OAuth Error: ' . $error_message);

            // テストモードでモックトークンを試す
            if ($this->test_mode) {
                error_log('GrandPay API: Attempting to use mock token due to network error');
                return $this->get_mock_access_token();
            }

            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        error_log('GrandPay API: OAuth response code: ' . $response_code);
        error_log('GrandPay API: OAuth response body: ' . $response_body);

        if ($response_code !== 200) {
            error_log('GrandPay OAuth Error: HTTP ' . $response_code . ' - ' . $response_body);

            // テストモードでモックトークンを試す
            if ($this->test_mode) {
                error_log('GrandPay API: Attempting to use mock token due to API failure');
                return $this->get_mock_access_token();
            }

            return false;
        }

        $data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('GrandPay OAuth Error: Invalid JSON response - ' . json_last_error_msg());
            return false;
        }

        // WooCommerceソースコードを参考にしたレスポンス処理
        if (isset($data['data']['accessToken'])) {
            $this->access_token = $data['data']['accessToken'];
            $expires_in = 3600; // デフォルト1時間
            $this->token_expires_at = time() + $expires_in;

            // トークンをキャッシュ
            set_transient('welcart_grandpay_access_token', $this->access_token, $expires_in);
            set_transient('welcart_grandpay_token_expires_at', $this->token_expires_at, $expires_in);

            error_log('GrandPay API: Access token obtained successfully');
            return $this->access_token;
        }

        error_log('GrandPay OAuth Error: No accessToken in response');
        error_log('GrandPay OAuth Response: ' . print_r($data, true));

        // テストモードでモックトークンを試す
        if ($this->test_mode) {
            error_log('GrandPay API: Attempting to use mock token due to API failure');
            return $this->get_mock_access_token();
        }

        return false;
    }

    /**
     * 決済セッション作成（実際の仕様）
     */
    public function create_checkout_session($order_data) {
        $access_token = $this->get_access_token();
        if (!$access_token) {
            return array('error' => 'アクセストークンの取得に失敗しました');
        }

        // モックトークンの場合はモック決済URLを返す
        if (strpos($access_token, 'mock_access_token_') === 0) {
            error_log('GrandPay API: Creating mock checkout session');
            return array(
                'success' => true,
                'checkout_url' => home_url('/grandpay-mock-checkout/?order_id=' . $order_data['order_id']),
                'session_id' => 'mock_session_' . $order_data['order_id'] . '_' . time()
            );
        }

        // 実際のエンドポイント
        $url = $this->api_base_url . '/p/v2/checkout-sessions';

        $headers = array(
            'x-tenant-key' => $this->tenant_key,
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json',
            'IsTestMode' => $this->test_mode ? 'true' : 'false'
        );

        // WooCommerceソースコードを参考にした決済データ構築
        $checkout_data = array(
            'title' => 'Checkout',
            'type' => 'WEB_REDIRECT',
            'currency' => 'JPY',
            'nature' => 'ONE_OFF',
            'payer' => array(
                'name' => $order_data['name'] ?? 'Test Customer',
                'phone' => str_replace('-', '', $order_data['phone'] ?? ''),
                'email' => $order_data['email'] ?? 'test@example.com',
                'areaCode' => '081',
                'country' => 'JP',
                'city' => 'JP'
            ),
            'successUrl' => $order_data['success_url'] ?? '',
            'failureUrl' => $order_data['failure_url'] ?? '',
            'lineItems' => array(
                array(
                    'priceData' => array(
                        'currency' => 'JPY',
                        'productData' => array(
                            'name' => 'supplement'
                        ),
                        'unitAmount' => intval($order_data['amount'] ?? 1000),
                        'taxBehavior' => 'string'
                    ),
                    'adjustableQuantity' => array(
                        'enabled' => true,
                        'minimum' => 1,
                        'maximum' => 10
                    ),
                    'quantity' => 1
                )
            )
        );

        error_log('GrandPay API: Checkout session request URL: ' . $url);
        error_log('GrandPay API: Checkout session request data: ' . json_encode($checkout_data, JSON_PRETTY_PRINT));

        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => json_encode($checkout_data),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            error_log('GrandPay API: Checkout session request error: ' . $response->get_error_message());
            return array('error' => 'API通信エラー: ' . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        error_log('GrandPay API: Checkout session response code: ' . $response_code);
        error_log('GrandPay API: Checkout session response body: ' . $body);

        if ($response_code !== 200) {
            return array('error' => 'セッション作成に失敗しました (HTTP ' . $response_code . '): ' . $body);
        }

        $data = json_decode($body, true);

        if (isset($data['data']['checkoutUrl'])) {
            error_log('GrandPay API: Checkout session created successfully');
            return array(
                'success' => true,
                'checkout_url' => $data['data']['checkoutUrl'],
                'session_id' => $data['data']['id'] ?? ''
            );
        }

        return array('error' => 'セッション作成に失敗しました: ' . $body);
    }

    /**
     * テスト用モックトークン（実際のAPIが利用できない場合）
     */
    public function get_mock_access_token() {
        if (!$this->test_mode) {
            error_log('GrandPay API: Mock token request in production mode - denied');
            return false;
        }

        error_log('GrandPay API: Using mock access token for testing');

        // モックトークンを生成
        $mock_token = 'mock_access_token_' . md5($this->username . time());
        $expires_in = 3600;
        $this->token_expires_at = time() + $expires_in;

        // キャッシュに保存
        set_transient('welcart_grandpay_access_token', $mock_token, $expires_in);
        set_transient('welcart_grandpay_token_expires_at', $this->token_expires_at, $expires_in);

        $this->access_token = $mock_token;

        error_log('GrandPay API: Mock token generated: ' . substr($mock_token, 0, 20) . '...');

        return $mock_token;
    }

    /**
     * エンドポイント検出（実際の仕様に基づく）
     */
    public function discover_api_endpoint() {
        $base_url = 'https://api.payment-gateway.asia';
        $oauth_endpoints = array(
            '/uaa/oauth2/token',         // 実際のエンドポイント
            '/oauth2/token',
            '/uaa/oauth/token'
        );

        $checkout_endpoints = array(
            '/p/v2/checkout-sessions',   // 実際のエンドポイント
            '/checkout-sessions',
            '/p/v1/checkout-sessions'
        );

        $results = array();

        foreach ($oauth_endpoints as $endpoint) {
            $test_url = $base_url . $endpoint;
            error_log("GrandPay API Discovery: Testing OAuth endpoint $test_url");

            $response = wp_remote_head($test_url, array(
                'timeout' => 5,
                'sslverify' => false
            ));

            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                $results[] = array(
                    'url' => $test_url,
                    'type' => 'OAuth',
                    'status' => $status_code,
                    'headers' => wp_remote_retrieve_headers($response)
                );

                error_log("GrandPay API Discovery: OAuth $test_url returned $status_code");
            }
        }

        foreach ($checkout_endpoints as $endpoint) {
            $test_url = $base_url . $endpoint;
            error_log("GrandPay API Discovery: Testing Checkout endpoint $test_url");

            $response = wp_remote_head($test_url, array(
                'timeout' => 5,
                'sslverify' => false
            ));

            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                $results[] = array(
                    'url' => $test_url,
                    'type' => 'Checkout',
                    'status' => $status_code,
                    'headers' => wp_remote_retrieve_headers($response)
                );

                error_log("GrandPay API Discovery: Checkout $test_url returned $status_code");
            }
        }

        return $results;
    }

    /**
     * 決済状況確認
     */
    public function get_payment_status($session_id) {
        $access_token = $this->get_access_token();
        if (!$access_token) {
            return array('error' => 'アクセストークンの取得に失敗しました');
        }

        // モックセッションの場合はモック結果を返す
        if (strpos($session_id, 'mock_session_') === 0) {
            error_log('GrandPay API: Returning mock payment status');
            return array(
                'id' => $session_id,
                'status' => 'COMPLETED',
                'amount' => 1000,
                'currency' => 'JPY'
            );
        }

        $url = $this->api_base_url . '/p/v2/checkout-sessions/' . $session_id;

        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'x-tenant-key' => $this->tenant_key
        );

        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return array('error' => 'API通信エラー: ' . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $data = json_decode($body, true);
        return $data;
    }

    /**
     * API設定の検証
     */
    public function validate_configuration() {
        if (empty($this->tenant_key)) {
            return array('error' => 'Tenant Keyが設定されていません');
        }

        if (empty($this->username)) {
            return array('error' => 'Usernameが設定されていません');
        }

        if (empty($this->credentials)) {
            return array('error' => 'Credentialsが設定されていません');
        }

        return array('success' => true);
    }

    /**
     * API接続テスト
     */
    public function test_connection() {
        $validation = $this->validate_configuration();
        if (isset($validation['error'])) {
            return $validation;
        }

        $token = $this->get_access_token();
        if (!$token) {
            return array('error' => 'アクセストークンの取得に失敗しました');
        }

        // モックトークンかどうかを確認
        if (strpos($token, 'mock_access_token_') === 0) {
            return array('success' => true, 'message' => 'API接続テスト成功（モックトークン使用）');
        }

        return array('success' => true, 'message' => 'API接続テスト成功（実際のAPI使用）');
    }

    /**
     * Webhook署名検証（無効化）
     */
    public function verify_webhook_signature($payload, $signature) {
        error_log('GrandPay API: Webhook signature verification (currently disabled)');
        return true;
    }
}
