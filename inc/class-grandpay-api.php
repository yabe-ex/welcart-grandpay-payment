<?php

/**
 * GrandPay Payment Gateway Implementation for Welcart
 */

class WelcartGrandPayAPI {
    private $tenant_key;
    private $client_id;
    private $username;
    private $credentials;
    private $test_mode;
    private $base_url = 'https://api.payment-gateway.asia';

    public function __construct() {
        $this->tenant_key = get_option('welcart_grandpay_tenant_key', '');
        $this->client_id = get_option('welcart_grandpay_client_id', '');
        $this->username = get_option('welcart_grandpay_username', '');
        $this->credentials = get_option('welcart_grandpay_credentials', '');
        $this->test_mode = get_option('welcart_grandpay_test_mode', 'on');
    }

    /**
     * OAuth2アクセストークン取得
     */
    public function get_access_token() {
        // エンドポイント検出結果から、正しいエンドポイントを使用
        $endpoint = $this->base_url . '/uaa/oauth2/token';

        $body = array(
            'grant_type' => 'custom-password-grant',
            'username' => $this->username,
            'credentials' => $this->credentials
        );

        $headers = array(
            'Authorization' => 'Basic ' . base64_encode($this->client_id),
            'Content-Type' => 'application/x-www-form-urlencoded'
        );

        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => http_build_query($body),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 200) {
            $data = json_decode($response_body, true);
            if (isset($data['access_token'])) {
                return array('success' => true, 'access_token' => $data['access_token']);
            }
        }

        return array('success' => false, 'error' => 'Failed to get access token', 'response' => $response_body);
    }

    /**
     * チェックアウトセッション作成
     */
    public function create_checkout_session($order_data) {
        // まずアクセストークンを取得
        $token_result = $this->get_access_token();
        if (!$token_result['success']) {
            return $token_result;
        }

        $access_token = $token_result['access_token'];
        $endpoint = $this->base_url . '/p/v2/checkout-sessions';

        $checkout_data = array(
            'type' => 'WEB_REDIRECT',
            'payer' => array(
                'name' => $order_data['customer_name'],
                'email' => $order_data['customer_email']
            ),
            'link' => array(
                'value' => intval($order_data['total_amount']),
                'currency' => 'JPY'
            ),
            'metadata' => array(
                'orderId' => $order_data['order_id']
            ),
            'payItems' => array(
                array(
                    'name' => 'Post Man',
                    'email' => $order_data['customer_email'],
                    'mobile' => $order_data['phone'] ?? ''
                )
            ),
            'lastBehaviour' => 'string',
            'failureUrl' => home_url('/checkout/failure'),
            'successUrl' => home_url('/checkout/success'),
            'availableQuantity' => 1,
            'available' => true,
            'minimum' => 10,
            'maximum' => 1000000
        );

        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json',
            'x-tenant-key' => $this->tenant_key
        );

        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => json_encode($checkout_data),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 200 || $response_code === 201) {
            $data = json_decode($response_body, true);
            if (isset($data['checkoutUrl'])) {
                return array(
                    'success' => true,
                    'checkout_url' => $data['checkoutUrl'],
                    'session_id' => $data['id']
                );
            }
        }

        return array('success' => false, 'error' => 'Failed to create checkout session', 'response' => $response_body);
    }

    /**
     * 決済ステータス確認
     */
    public function get_payment_status($session_id) {
        $token_result = $this->get_access_token();
        if (!$token_result['success']) {
            return $token_result;
        }

        $access_token = $token_result['access_token'];
        $endpoint = $this->base_url . '/p/v2/checkout-sessions/' . $session_id;

        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'x-tenant-key' => $this->tenant_key
        );

        $response = wp_remote_get($endpoint, array(
            'headers' => $headers,
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 200) {
            $data = json_decode($response_body, true);
            return array('success' => true, 'data' => $data);
        }

        return array('success' => false, 'error' => 'Failed to get payment status', 'response' => $response_body);
    }

    /**
     * API接続テスト（改良版）
     */
    public function test_api_connection() {
        $results = array();

        // 設定値確認
        if (empty($this->tenant_key)) {
            $results['config_error'][] = 'Tenant Key が設定されていません';
        }
        if (empty($this->client_id)) {
            $results['config_error'][] = 'Client ID が設定されていません';
        }
        if (empty($this->username)) {
            $results['config_error'][] = 'Username が設定されていません';
        }
        if (empty($this->credentials)) {
            $results['config_error'][] = 'Credentials が設定されていません';
        }

        if (!empty($results['config_error'])) {
            return array('success' => false, 'errors' => $results['config_error']);
        }

        // OAuth2認証テスト
        $token_result = $this->get_access_token();
        if ($token_result['success']) {
            $results['oauth2'] = 'OK - アクセストークン取得成功';
        } else {
            $results['oauth2'] = 'NG - ' . $token_result['error'];
        }

        return array('success' => $token_result['success'], 'results' => $results);
    }
}
