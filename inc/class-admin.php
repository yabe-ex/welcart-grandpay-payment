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
            error_log('GrandPay: Log test from admin page - ' . current_time('Y-m-d H:i:s'));
            $log_test_result = '<div class="notice notice-info"><p>📝 ログテストを実行しました。/wp-content/debug.log を確認してください。</p></div>';
        }

        // テスト接続処理
        if (isset($_POST['test_connection'])) {
            $token = $api->get_access_token();
            if ($token) {
                $test_result = '<div class="notice notice-success"><p>✓ API接続テスト成功</p></div>';
            } else {
                $test_result = '<div class="notice notice-error"><p>✗ API接続テスト失敗</p></div>';
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
            <h1><?php echo WELCART_GRANDPAY_PAYMENT_NAME; ?> - デバッグ設定</h1>

            <?php
            if (isset($test_result)) echo $test_result;
            if (isset($log_test_result)) echo $log_test_result;
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
                        <td><?php echo !empty($grandpay_settings['tenant_key']) ? '設定済み' : '未設定'; ?></td>
                    </tr>
                    <tr>
                        <th>Client ID</th>
                        <td><?php echo !empty($grandpay_settings['client_id']) ? '設定済み' : '未設定'; ?></td>
                    </tr>
                    <tr>
                        <th>Client Secret</th>
                        <td><?php echo !empty($grandpay_settings['client_secret']) ? '設定済み' : '未設定'; ?></td>
                    </tr>
                    <tr>
                        <th>Webhook URL</th>
                        <td><code><?php echo admin_url('admin-ajax.php?action=grandpay_webhook'); ?></code></td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <h2>🧪 テスト機能</h2>
                <form method="post" style="display: inline-block; margin-right: 10px;">
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
            }

            .card h2 {
                margin-top: 0;
            }
        </style>
<?php
    }
}
