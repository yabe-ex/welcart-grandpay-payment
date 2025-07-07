<?php

class WelcartGrandpayPaymentAdmin {

    public function __construct() {
        // 強制ログ（WP_DEBUG関係なく出力）
        error_log('GrandPay Admin: Constructor called - FORCED LOG');

        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue'));

        // プラグイン独自の設定ページ（デバッグ用）
        add_action('admin_menu', array($this, 'create_menu'));
        error_log('GrandPay Admin: admin_menu hook added - FORCED LOG');

        add_filter('plugin_action_links_' . plugin_basename(WELCART_GRANDPAY_PAYMENT_PATH . '/welcart-grandpay-payment.php'), array($this, 'plugin_action_links'));

        // インストール案内の表示
        add_action('admin_notices', array($this, 'show_installation_guide'));

        // **決済モジュール管理（Welcartの標準仕組みに準拠）**
        add_action('admin_init', array($this, 'ensure_settlement_module_registration'), 20);

        error_log('GrandPay Admin: Constructor completed - FORCED LOG');
    }

    /**
     * 決済モジュールが正しく登録されているかを確認・修正
     */
    public function ensure_settlement_module_registration() {
        // Welcartが利用可能かチェック
        if (!function_exists('usces_get_system_option')) {
            error_log('GrandPay Admin: Welcart not available for settlement module registration');
            return;
        }

        // 決済モジュールファイルの存在確認
        $settlement_file = WP_PLUGIN_DIR . '/usc-e-shop/settlement/grandpay.php';
        if (!file_exists($settlement_file)) {
            error_log('GrandPay Admin: Settlement module file not found: ' . $settlement_file);
            return;
        }

        // 利用可能決済モジュール一覧を取得
        $available_settlement = get_option('usces_available_settlement', array());

        // GrandPayが登録されていない場合は追加
        if (!isset($available_settlement['grandpay'])) {
            $available_settlement['grandpay'] = 'GrandPay';
            update_option('usces_available_settlement', $available_settlement);
            error_log('GrandPay Admin: Added to available settlement modules');
        }

        // 決済モジュール情報を確認
        if (file_exists($settlement_file)) {
            require_once($settlement_file);

            // モジュール情報取得関数の存在確認
            if (function_exists('usces_get_settlement_info_grandpay')) {
                $info = usces_get_settlement_info_grandpay();
                error_log('GrandPay Admin: Settlement module info: ' . print_r($info, true));
            } else {
                error_log('GrandPay Admin: usces_get_settlement_info_grandpay function not found in module file');
            }
        }

        error_log('GrandPay Admin: Settlement module registration check completed');
    }

    public function show_installation_guide() {
        // クレジット決済設定ページでのみ表示
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'usces_settlement') === false) {
            return;
        }

        // 決済モジュールファイルが正しく配置されているかチェック
        $settlement_file = WP_PLUGIN_DIR . '/usc-e-shop/settlement/grandpay.php';

        if (!file_exists($settlement_file)) {
?>
            <div class="notice notice-warning">
                <h4>📋 GrandPay決済モジュールの設定手順</h4>
                <ol>
                    <li><strong>決済モジュールファイルを配置</strong><br>
                        <code><?php echo WELCART_GRANDPAY_PAYMENT_PATH; ?>/settlement/grandpay.php</code><br>
                        ↓ コピー ↓<br>
                        <code><?php echo WP_PLUGIN_DIR; ?>/usc-e-shop/settlement/grandpay.php</code>
                    </li>
                    <li><strong>利用できるモジュールリストに追加</strong><br>
                        このページの「利用できるクレジット決済モジュール」に「GrandPay」が表示されるので、
                        「利用中のクレジット決済モジュール」にドラッグ&ドロップで移動
                    </li>
                    <li><strong>「利用するモジュールを更新する」をクリック</strong></li>
                    <li><strong>「GrandPay」タブで詳細設定を行う</strong></li>
                </ol>
            </div>
            <?php
        } else {
            // 利用可能モジュールリストに登録されているかチェック
            $available_settlement = get_option('usces_available_settlement', array());

            if (!isset($available_settlement['grandpay'])) {
            ?>
                <div class="notice notice-info">
                    <h4>🔄 GrandPay決済モジュール 自動登録中</h4>
                    <p>決済モジュールファイルは配置済みです。利用可能モジュールリストに自動登録しています...</p>
                    <p>ページをリロードしてください。</p>
                </div>
                <?php
            } else {
                // 利用中モジュールリストに含まれているかチェック
                $selected_settlement = get_option('usces_settlement_selected', array());
                $is_selected = false;

                if (is_array($selected_settlement)) {
                    $is_selected = in_array('grandpay', $selected_settlement);
                } elseif (is_string($selected_settlement)) {
                    $is_selected = strpos($selected_settlement, 'grandpay') !== false;
                }

                if (!$is_selected) {
                ?>
                    <div class="notice notice-info">
                        <h4>✅ GrandPay決済モジュール設定可能</h4>
                        <p><strong>手順:</strong></p>
                        <ol>
                            <li>下記の「利用できるクレジット決済モジュール」から「<strong>GrandPay</strong>」を見つける</li>
                            <li>「<strong>GrandPay</strong>」を「利用中のクレジット決済モジュール」エリアにドラッグ&ドロップ</li>
                            <li>「<strong>利用するモジュールを更新する</strong>」ボタンをクリック</li>
                            <li>「<strong>GrandPay</strong>」タブが表示されるので、そこで詳細設定</li>
                        </ol>
                    </div>
                <?php
                } else {
                ?>
                    <div class="notice notice-success">
                        <h4>🎉 GrandPay決済モジュール利用準備完了</h4>
                        <p>「<strong>GrandPay</strong>」タブで詳細設定を行ってください。</p>
                    </div>
        <?php
                }
            }
        }
    }

    /**
     * プラグイン独自の管理メニュー（デバッグ用）
     */
    function create_menu() {
        add_submenu_page(
            'options-general.php',
            WELCART_GRANDPAY_PAYMENT_NAME . ' - デバッグ',
            WELCART_GRANDPAY_PAYMENT_NAME,
            'manage_options',
            'welcart-grandpay-payment',
            array($this, 'show_setting_page'),
            1
        );
    }

    function admin_enqueue($hook) {
        // Welcartの決済設定ページでのみ読み込み
        if (strpos($hook, 'usces_settlement') === false) {
            return;
        }

        $version = (defined('WELCART_GRANDPAY_PAYMENT_DEVELOP') && true === WELCART_GRANDPAY_PAYMENT_DEVELOP) ? time() : WELCART_GRANDPAY_PAYMENT_VERSION;

        wp_register_style(WELCART_GRANDPAY_PAYMENT_SLUG . '-admin',  WELCART_GRANDPAY_PAYMENT_URL . '/css/admin.css', array(), $version);
        wp_register_script(WELCART_GRANDPAY_PAYMENT_SLUG . '-admin', WELCART_GRANDPAY_PAYMENT_URL . '/js/admin.js', array('jquery'), $version);

        wp_enqueue_style(WELCART_GRANDPAY_PAYMENT_SLUG . '-admin');
        wp_enqueue_script(WELCART_GRANDPAY_PAYMENT_SLUG . '-admin');

        // GrandPay選択状態をJavaScriptに渡す
        $selected_settlements = get_option('usces_settlement_selected', array());
        $is_grandpay_selected = false;

        if (is_array($selected_settlements)) {
            $is_grandpay_selected = in_array('grandpay', $selected_settlements);
        } elseif (is_string($selected_settlements)) {
            $is_grandpay_selected = strpos($selected_settlements, 'grandpay') !== false;
        }

        // 設定データも渡す
        $options = get_option('usces_ex', array());
        $grandpay_settings = $options['grandpay'] ?? array();

        $admin_data = array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(WELCART_GRANDPAY_PAYMENT_SLUG),
            'is_selected' => $is_grandpay_selected,
            'settings' => $grandpay_settings,
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        );

        wp_localize_script(WELCART_GRANDPAY_PAYMENT_SLUG . '-admin', 'grandpay_admin', $admin_data);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GrandPay Admin: Scripts enqueued for settlement page');
            error_log('GrandPay Admin: is_selected = ' . ($is_grandpay_selected ? 'true' : 'false'));
        }
    }

    function plugin_action_links($links) {
        $url = '<a href="' . esc_url(admin_url("/options-general.php?page=welcart-grandpay-payment")) . '">デバッグ設定</a>';
        array_unshift($links, $url);
        return $links;
    }

    function show_setting_page() {
        $api = new WelcartGrandpayAPI();
        $options = get_option('usces_ex', array());
        $grandpay_settings = $options['grandpay'] ?? array();

        // ログテスト処理
        if (isset($_POST['test_log'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'grandpay_test_log')) {
                error_log('GrandPay: Log test from admin page - ' . current_time('Y-m-d H:i:s'));
                $log_test_result = '<div class="notice notice-info"><p>📝 ログテストを実行しました。/wp-content/debug.log を確認してください。</p></div>';
            }
        }

        // テスト接続処理
        if (isset($_POST['test_connection'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'grandpay_test_connection')) {
                $test_connection_result = $api->test_connection();
                if (isset($test_connection_result['success'])) {
                    $test_result = '<div class="notice notice-success"><p>✓ API接続テスト成功 - ' . $test_connection_result['message'] . '</p></div>';
                } else {
                    $test_result = '<div class="notice notice-error"><p>✗ API接続テスト失敗 - ' . $test_connection_result['error'] . '</p></div>';
                }
            }
        }

        // 詳細API診断処理
        if (isset($_POST['test_api_detailed'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'grandpay_test_api_detailed')) {
                $detailed_result = $this->run_detailed_api_test($api);
            }
        }

        // エンドポイント検出処理
        if (isset($_POST['discover_endpoints'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'grandpay_discover_endpoints')) {
                $discovery_result = $this->run_endpoint_discovery($api);
            }
        }

        // モックトークンテスト処理
        if (isset($_POST['test_mock_token'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'grandpay_test_mock_token')) {
                $mock_token = $api->get_mock_access_token();
                if ($mock_token) {
                    $mock_result = '<div class="notice notice-success"><p>✓ モックトークン生成成功: ' . substr($mock_token, 0, 20) . '...</p></div>';
                } else {
                    $mock_result = '<div class="notice notice-warning"><p>⚠️ モックトークンは本番モードでは無効です</p></div>';
                }
            }
        }

        // ファイル存在確認
        $settlement_file = WP_PLUGIN_DIR . '/usc-e-shop/settlement/grandpay.php';
        $settlement_file_exists = file_exists($settlement_file);

        // 決済モジュール登録状況確認
        $available_settlement = get_option('usces_available_settlement', array());
        $is_available = isset($available_settlement['grandpay']);

        $selected_settlement = get_option('usces_settlement_selected', array());
        $is_selected = false;
        if (is_array($selected_settlement)) {
            $is_selected = in_array('grandpay', $selected_settlement);
        } elseif (is_string($selected_settlement)) {
            $is_selected = strpos($selected_settlement, 'grandpay') !== false;
        }

        ?>
        <div class="wrap">
            <h1><?php echo WELCART_GRANDPAY_PAYMENT_NAME; ?> - デバッグ＆管理</h1>

            <?php
            if (isset($test_result)) echo $test_result;
            if (isset($log_test_result)) echo $log_test_result;
            if (isset($mock_result)) echo $mock_result;
            ?>

            <div class="card">
                <h2>🔍 システム状況</h2>
                <table class="form-table">
                    <tr>
                        <th>WordPress Debug</th>
                        <td><?php echo defined('WP_DEBUG') && WP_DEBUG ? '✓ 有効' : '✗ 無効'; ?></td>
                    </tr>
                    <tr>
                        <th>Debug Log</th>
                        <td><?php echo defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? '✓ 有効' : '✗ 無効'; ?></td>
                    </tr>
                    <tr>
                        <th>ログファイル</th>
                        <td>
                            <?php
                            $log_file = WP_CONTENT_DIR . '/debug.log';
                            if (file_exists($log_file)) {
                                echo '✓ 存在 - <code>' . $log_file . '</code><br>';
                                echo '最終更新: ' . date('Y-m-d H:i:s', filemtime($log_file));
                            } else {
                                echo '✗ 未作成 - <code>' . $log_file . '</code>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th>決済モジュールファイル</th>
                        <td>
                            <?php
                            if ($settlement_file_exists) {
                                echo '✓ 存在 - <code>' . $settlement_file . '</code>';
                            } else {
                                echo '✗ 未作成 - <code>' . $settlement_file . '</code>';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <h2>📦 決済モジュール登録状況</h2>
                <table class="form-table">
                    <tr>
                        <th>利用可能モジュールリスト</th>
                        <td>
                            <?php echo $is_available ? '✓ 登録済み' : '✗ 未登録'; ?>
                            <?php if ($is_available): ?>
                                <br><small>値: <?php echo esc_html($available_settlement['grandpay']); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>利用中モジュールリスト</th>
                        <td>
                            <?php echo $is_selected ? '✓ 選択済み' : '✗ 未選択'; ?>
                            <?php if ($is_selected): ?>
                                <br><small>GrandPayタブが表示されます</small>
                            <?php else: ?>
                                <br><small>決済設定ページでドラッグ&ドロップして追加してください</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>モジュール情報関数</th>
                        <td>
                            <?php
                            if ($settlement_file_exists) {
                                require_once($settlement_file);
                                if (function_exists('usces_get_settlement_info_grandpay')) {
                                    echo '✓ 正常';
                                    $info = usces_get_settlement_info_grandpay();
                                    echo '<br><small>名前: ' . esc_html($info['name'] ?? 'N/A') . '</small>';
                                    echo '<br><small>バージョン: ' . esc_html($info['version'] ?? 'N/A') . '</small>';
                                } else {
                                    echo '✗ 関数が見つかりません';
                                }
                            } else {
                                echo '✗ ファイルが存在しません';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <h2>⚙️ GrandPay設定状況</h2>
                <table class="form-table">
                    <tr>
                        <th>有効状態</th>
                        <td><?php echo ($grandpay_settings['activate'] ?? '') === 'on' ? '✓ 有効' : '✗ 無効'; ?></td>
                    </tr>
                    <tr>
                        <th>テストモード</th>
                        <td><?php echo ($grandpay_settings['test_mode'] ?? '') === 'on' ? '✓ テストモード' : '本番モード'; ?></td>
                    </tr>
                    <tr>
                        <th>Tenant Key</th>
                        <td><?php echo !empty($grandpay_settings['tenant_key']) ? '設定済み (' . substr($grandpay_settings['tenant_key'], 0, 10) . '...)' : '未設定'; ?></td>
                    </tr>
                    <tr>
                        <th>Client ID</th>
                        <td><?php echo !empty($grandpay_settings['client_id']) ? '設定済み (' . substr($grandpay_settings['client_id'], 0, 10) . '...)' : '未設定'; ?></td>
                    </tr>
                    <tr>
                        <th>Webhook URL</th>
                        <td><code><?php echo admin_url('admin-ajax.php?action=grandpay_webhook'); ?></code></td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <h2>🧪 テスト機能</h2>

                <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
                    <form method="post" style="display: inline-block;">
                        <?php wp_nonce_field('grandpay_test_log'); ?>
                        <p>
                            <input type="submit" name="test_log" class="button button-secondary" value="ログテスト" />
                        </p>
                        <p class="description">デバッグログにテストメッセージを出力します。</p>
                    </form>

                    <form method="post" style="display: inline-block;">
                        <?php wp_nonce_field('grandpay_test_connection'); ?>
                        <p>
                            <input type="submit" name="test_connection" class="button button-secondary" value="API接続テスト" />
                        </p>
                        <p class="description">設定されたAPI情報でGrandPayサーバーに接続できるかテストします。</p>
                    </form>

                    <form method="post" style="display: inline-block;">
                        <?php wp_nonce_field('grandpay_test_api_detailed'); ?>
                        <p>
                            <input type="submit" name="test_api_detailed" class="button button-secondary" value="詳細API診断" />
                        </p>
                        <p class="description">API接続の詳細な診断を行います。</p>
                    </form>

                    <form method="post" style="display: inline-block;">
                        <?php wp_nonce_field('grandpay_discover_endpoints'); ?>
                        <p>
                            <input type="submit" name="discover_endpoints" class="button button-primary" value="エンドポイント検出" />
                        </p>
                        <p class="description">利用可能なAPIエンドポイントを探します。</p>
                    </form>

                    <form method="post" style="display: inline-block;">
                        <?php wp_nonce_field('grandpay_test_mock_token'); ?>
                        <p>
                            <input type="submit" name="test_mock_token" class="button button-primary" value="モックトークンテスト" />
                        </p>
                        <p class="description">テスト用のモックトークンを生成します。</p>
                    </form>
                </div>

                <?php if (isset($detailed_result)): ?>
                    <div style="margin-top: 20px;">
                        <h4>🔍 詳細API診断結果</h4>
                        <pre style="background: #f1f1f1; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px;"><?php echo esc_html($detailed_result); ?></pre>
                    </div>
                <?php endif; ?>

                <?php if (isset($discovery_result)): ?>
                    <div style="margin-top: 20px;">
                        <h4>🔍 エンドポイント検出結果</h4>
                        <pre style="background: #f1f1f1; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px;"><?php echo esc_html($discovery_result); ?></pre>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>📋 設定手順（正しい順序）</h2>
                <ol style="line-height: 2;">
                    <li><strong>決済モジュールファイルの配置</strong> <?php echo $settlement_file_exists ? '✓' : '→ 必要'; ?></li>
                    <li><strong>利用可能モジュールリストに登録</strong> <?php echo $is_available ? '✓' : '→ 必要'; ?></li>
                    <li><strong>Welcart決済設定ページでドラッグ&ドロップ</strong> <?php echo $is_selected ? '✓' : '→ 必要'; ?></li>
                    <li><strong>GrandPayタブで詳細設定</strong> <?php echo !empty($grandpay_settings['tenant_key']) ? '✓' : '→ 必要'; ?></li>
                </ol>

                <p><strong>設定ページへのリンク:</strong></p>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=usces_settlement'); ?>" class="button button-primary">
                        Welcart クレジット決済設定ページ
                    </a>
                </p>
            </div>
        </div>

        <style>
            .card {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
                margin: 20px 0;
                padding: 20px;
                border-radius: 6px;
            }

            .card h2 {
                margin-top: 0;
                color: #23282d;
            }

            .card .form-table th {
                width: 200px;
                font-weight: 600;
            }

            .description {
                font-size: 13px;
                color: #666;
                margin: 5px 0;
            }
        </style>
<?php
    }

    /**
     * 詳細APIテストの実行
     */
    private function run_detailed_api_test($api) {
        $output = "=== GrandPay API 詳細診断 ===\n";
        $output .= "実行時刻: " . current_time('Y-m-d H:i:s') . "\n\n";

        // 1. 設定値確認
        $output .= "1. 設定値確認\n";
        $output .= "   Tenant Key: " . (get_option('welcart_grandpay_tenant_key') ? '設定済み (' . substr(get_option('welcart_grandpay_tenant_key'), 0, 10) . '...)' : '未設定') . "\n";
        $output .= "   Client ID: " . (get_option('welcart_grandpay_client_id') ? '設定済み (' . substr(get_option('welcart_grandpay_client_id'), 0, 10) . '...)' : '未設定') . "\n";
        $output .= "   Test Mode: " . (get_option('welcart_grandpay_test_mode') ? 'ON' : 'OFF') . "\n\n";

        // 2. ネットワーク接続確認
        $output .= "2. ネットワーク接続確認\n";
        $ping_url = 'https://api.payment-gateway.asia';
        $ping_response = wp_remote_get($ping_url, array('timeout' => 10));

        if (is_wp_error($ping_response)) {
            $output .= "   ❌ ベースURL接続失敗: " . $ping_response->get_error_message() . "\n";
        } else {
            $response_code = wp_remote_retrieve_response_code($ping_response);
            $output .= "   ✅ ベースURL接続成功 (HTTP $response_code)\n";
        }

        // 3. SSL証明書確認
        $output .= "\n3. SSL証明書確認\n";
        $ssl_response = wp_remote_get($ping_url, array(
            'timeout' => 10,
            'sslverify' => true
        ));

        if (is_wp_error($ssl_response)) {
            $output .= "   ⚠️  SSL証明書に問題: " . $ssl_response->get_error_message() . "\n";
        } else {
            $output .= "   ✅ SSL証明書正常\n";
        }

        // 4. OAuth2エンドポイント確認
        $output .= "\n4. OAuth2エンドポイント確認\n";
        $oauth_url = 'https://api.payment-gateway.asia/oauth2/token';

        // まず設定値チェック
        $client_id = get_option('welcart_grandpay_client_id');
        if (empty($client_id)) {
            $output .= "   ❌ Client IDが設定されていません\n";
        } else {
            // OAuth2リクエストを実行
            $auth_string = base64_encode($client_id . ':');
            $headers = array(
                'Authorization' => 'Basic ' . $auth_string,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'Welcart-GrandPay/' . WELCART_GRANDPAY_PAYMENT_VERSION,
                'Accept' => 'application/json'
            );

            $body = array('grant_type' => 'client_credentials');

            $oauth_response = wp_remote_post($oauth_url, array(
                'headers' => $headers,
                'body' => http_build_query($body),
                'timeout' => 30,
                'sslverify' => !get_option('welcart_grandpay_test_mode', false)
            ));

            if (is_wp_error($oauth_response)) {
                $output .= "   ❌ OAuth2リクエスト失敗: " . $oauth_response->get_error_message() . "\n";
            } else {
                $response_code = wp_remote_retrieve_response_code($oauth_response);
                $response_body = wp_remote_retrieve_body($oauth_response);
                $response_headers = wp_remote_retrieve_headers($oauth_response);

                $output .= "   レスポンスコード: $response_code\n";
                $output .= "   レスポンスヘッダー:\n";
                foreach ($response_headers as $header_name => $header_value) {
                    $output .= "     $header_name: $header_value\n";
                }

                $output .= "   レスポンスボディ: " . substr($response_body, 0, 500) . "\n";

                if ($response_code === 200) {
                    $data = json_decode($response_body, true);
                    if (isset($data['access_token'])) {
                        $output .= "   ✅ アクセストークン取得成功\n";
                        $output .= "   トークンタイプ: " . ($data['token_type'] ?? 'N/A') . "\n";
                        $output .= "   有効期限: " . ($data['expires_in'] ?? 'N/A') . " 秒\n";
                    } else {
                        $output .= "   ❌ レスポンスにaccess_tokenが含まれていません\n";
                    }
                } else {
                    $output .= "   ❌ OAuth2認証失敗 (HTTP $response_code)\n";

                    // エラーの詳細を確認
                    $error_data = json_decode($response_body, true);
                    if ($error_data && isset($error_data['error'])) {
                        $output .= "   エラータイプ: " . $error_data['error'] . "\n";
                        if (isset($error_data['error_description'])) {
                            $output .= "   エラー詳細: " . $error_data['error_description'] . "\n";
                        }
                    }
                }
            }
        }

        // 5. WordPress環境確認
        $output .= "\n5. WordPress環境確認\n";
        $output .= "   WordPress バージョン: " . get_bloginfo('version') . "\n";
        $output .= "   PHP バージョン: " . PHP_VERSION . "\n";
        $output .= "   cURL 有効: " . (function_exists('curl_version') ? 'YES' : 'NO') . "\n";
        $output .= "   OpenSSL 有効: " . (function_exists('openssl_get_cert_locations') ? 'YES' : 'NO') . "\n";
        $output .= "   allow_url_fopen: " . (ini_get('allow_url_fopen') ? 'ON' : 'OFF') . "\n";
        $output .= "   max_execution_time: " . ini_get('max_execution_time') . " 秒\n";
        $output .= "   memory_limit: " . ini_get('memory_limit') . "\n";

        // 6. Welcart統合確認
        $output .= "\n6. Welcart統合確認\n";
        $output .= "   Welcart 有効: " . (function_exists('usces_get_system_option') ? 'YES' : 'NO') . "\n";
        if (function_exists('usces_get_system_option')) {
            global $usces;
            $acting_flag = $usces->options['acting_settings']['acting_flag'] ?? '';
            $output .= "   現在のacting_flag: " . ($acting_flag ?: '未設定') . "\n";

            $payment_structure = get_option('usces_payment_structure', array());
            $grandpay_in_structure = isset($payment_structure['acting_grandpay_card']);
            $output .= "   GrandPay決済構造登録: " . ($grandpay_in_structure ? 'YES' : 'NO') . "\n";
        }

        $output .= "\n=== 診断完了 ===";

        return $output;
    }

    /**
     * エンドポイント検出の実行
     */
    private function run_endpoint_discovery($api) {
        $output = "=== GrandPay APIエンドポイント検出 ===\n";
        $output .= "実行時刻: " . current_time('Y-m-d H:i:s') . "\n\n";

        $discovery_results = $api->discover_api_endpoint();

        if (empty($discovery_results)) {
            $output .= "❌ 利用可能なエンドポイントが見つかりませんでした。\n\n";
        } else {
            $output .= "✅ エンドポイント検出結果:\n\n";

            foreach ($discovery_results as $result) {
                $status_icon = '❌';
                if ($result['status'] == 200) $status_icon = '✅';
                elseif (in_array($result['status'], array(400, 401, 405))) $status_icon = '⚠️';

                $output .= sprintf(
                    "%s %s (HTTP %d)\n",
                    $status_icon,
                    $result['url'],
                    $result['status']
                );

                // 401や400は認証が必要だが、エンドポイントは存在する
                if (in_array($result['status'], array(400, 401))) {
                    $output .= "   → 認証エラーですが、エンドポイントは存在します\n";
                } elseif ($result['status'] == 405) {
                    $output .= "   → POSTメソッドが必要かもしれません\n";
                }
            }
        }

        $output .= "\n=== 推奨事項 ===\n";
        $found_potential = false;
        foreach ($discovery_results as $result) {
            if (in_array($result['status'], array(400, 401, 405))) {
                $output .= "✓ 試してみる価値があるURL: " . $result['url'] . "\n";
                $found_potential = true;
            }
        }

        if (!$found_potential) {
            $output .= "• APIがまだ公開されていない可能性があります\n";
            $output .= "• ドキュメントのURLが古い可能性があります\n";
            $output .= "• テスト環境ではモックトークンを使用することを検討してください\n";
        }

        $output .= "\n=== 検出完了 ===";

        return $output;
    }
}
