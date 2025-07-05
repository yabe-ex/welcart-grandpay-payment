<?php

/**
 * GrandPay API通信クラス
 */
class WelcartGrandpayAPI {

    private $api_base_url;
    private $tenant_key;
    private $client_id;
    private $client_secret;
    private $test_mode;
    private $access_token;
    private $token_expires_at;

    public function __construct() {
        $this->api_base_url = 'https://api.payment-gateway.asia';
        $this->tenant_key = get_option('welcart_grandpay_tenant_key', '');
        $this->client_id = get_option('welcart_grandpay_client_id', '');
        $this->client_secret = get_option('welcart_grandpay_client_secret', '');
        $this->test_mode = get_option('welcart_grandpay_test_mode', false);
        $this->access_token = get_transient('welcart_grandpay_access_token');
        $this->token_expires_at = get_transient('welcart_grandpay_token_expires_at');
    }

    /**
     * OAuth2 アクセストークン取得
     */
    public function get_access_token() {
        // トークンが有効期限内なら既存のものを返す
        if ($this->access_token && $this->token_expires_at && time() < $this->token_expires_at - 300) {
            return $this->access_token;
        }

        $url = $this->api_base_url . '/oauth2/token';

        $headers = array(
            'Authorization' => 'Basic ' . base64_encode($this->client_id . ':' . $this->client_secret),
            'Content-Type' => 'application/x-www-form-urlencoded'
        );

        $body = array(
            'grant_type' => 'client_credentials'
        );

        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => http_build_query($body),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            error_log('GrandPay OAuth Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['access_token'])) {
            $this->access_token = $data['access_token'];
            $this->token_expires_at = time() + $data['expires_in'];

            // トークンをキャッシュ
            set_transient('welcart_grandpay_access_token', $this->access_token, $data['expires_in']);
            set_transient('welcart_grandpay_token_expires_at', $this->token_expires_at, $data['expires_in']);

            return $this->access_token;
        }

        error_log('GrandPay OAuth Error: ' . $body);
        return false;
    }

    /**
     * 決済セッション作成
     */
    public function create_checkout_session($order_data) {
        $access_token = $this->get_access_token();
        if (!$access_token) {
            return array('error' => 'アクセストークンの取得に失敗しました');
        }

        $url = $this->api_base_url . '/checkout-sessions';

        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json',
            'x-tenant-key' => $this->tenant_key
        );

        // 決済データを構築
        $checkout_data = array(
            'type' => 'WEB_REDIRECT',
            'payer' => array(),
            'qty' => 'Tokyo',
            'name' => 'Test Man',
            'phone' => $order_data['phone'] ?? '',
            'email' => $order_data['email'] ?? '',
            'country' => 'JP',
            'state' => $order_data['state'] ?? '',
            'link' => array(
                'url' => $order_data['amount'],
                'value' => 'ONE_OFF',
                'status' => 'OPEN',
                'currency' => 'JPY',
                'metadata' => array(),
                'orderId' => $order_data['order_id'] ?? '',
                'paylimitdt' => array(),
                'online' => 'testing'
            ),
            'failureUrl' => $order_data['failure_url'] ?? '',
            'successUrl' => $order_data['success_url'] ?? ''
        );

        if ($this->test_mode) {
            $checkout_data['isTestMode'] = true;
        }

        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => json_encode($checkout_data),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return array('error' => 'API通信エラー: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['checkoutUrl'])) {
            return array(
                'success' => true,
                'checkout_url' => $data['checkoutUrl'],
                'session_id' => $data['id'] ?? ''
            );
        }

        return array('error' => 'セッション作成に失敗しました: ' . $body);
    }

    /**
     * 決済状況確認
     */
    public function get_payment_status($session_id) {
        $access_token = $this->get_access_token();
        if (!$access_token) {
            return array('error' => 'アクセストークンの取得に失敗しました');
        }

        $url = $this->api_base_url . '/checkout-sessions/' . $session_id;

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

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return $data;
    }

    /**
     * Webhook署名検証
     */
    public function verify_webhook_signature($payload, $signature) {
        $webhook_secret = get_option('welcart_grandpay_webhook_secret', '');
        if (empty($webhook_secret)) {
            return false;
        }

        $calculated_signature = hash_hmac('sha256', $payload, $webhook_secret);
        return hash_equals($calculated_signature, $signature);
    }
}
