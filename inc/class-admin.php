<?php

class WelcartGrandpayPaymentAdmin {

    public function __construct() {
        // 強制ログ（WP_DEBUG関係なく出力）
        error_log('GrandPay Admin: Constructor called - FORCED LOG');

        // Welcart が読み込まれた後にフィルターを登録
        add_action('init', array($this, 'register_settlement_filters'), 15);

        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue'));

        // プラグイン独自の設定ページ（デバッグ用）
        add_action('admin_menu', array($this, 'create_menu'));
        error_log('GrandPay Admin: admin_menu hook added - FORCED LOG');

        add_filter('plugin_action_links_' . plugin_basename(WELCART_GRANDPAY_PAYMENT_PATH . '/welcart-grandpay-payment.php'), array($this, 'plugin_action_links'));

        // インストール案内の表示
        add_action('admin_notices', array($this, 'show_installation_guide'));

        // **フィルターフックの強制テスト**
        add_action('admin_init', array($this, 'test_filter_hooks'));

        // デバッグ：すべてのフィルターフックをログ出力
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('init', array($this, 'debug_all_hooks'), 99);
        }

        error_log('GrandPay Admin: Constructor completed - FORCED LOG');
    }

    /**
     * Welcart の決済関連フィルターを登録
     */
    public function register_settlement_filters() {
        // Welcart が利用可能かチェック
        if (!function_exists('usces_get_system_option')) {
            error_log('GrandPay Admin: Welcart not available, skipping filter registration');
            return;
        }

        error_log('GrandPay Admin: Registering settlement filters');

        // 複数のフィルター名でタブ追加を試行（Welcart バージョンによる違いに対応）
        add_filter('usces_filter_settlement_tab_title', array($this, 'add_settlement_tab'), 10, 1);
        add_filter('usces_filter_settlement_tab_body', array($this, 'add_settlement_tab_body'), 10, 1);
        add_filter('usces_settlement_tab_title', array($this, 'add_settlement_tab'), 10, 1);
        add_filter('usces_settlement_tab_body', array($this, 'add_settlement_tab_body'), 10, 1);
        add_filter('usces_filter_settlement_tabs', array($this, 'add_settlement_tab'), 10, 1);
        add_filter('usces_settlement_tabs', array($this, 'add_settlement_tab'), 10, 1);

        // 設定保存処理
        add_action('usces_action_admin_settlement_update', array($this, 'save_settlement_settings'));
        add_action('usces_admin_settlement_update', array($this, 'save_settlement_settings'));

        error_log('GrandPay Admin: Settlement filters registered');
    }

    /**
     * フィルターフックの強制テスト
     */
    public function test_filter_hooks() {
        // 現在のページがWelcartの設定ページかチェック
        if (!isset($_GET['page']) || $_GET['page'] !== 'usces_settlement') {
            return;
        }

        error_log('GrandPay Admin: test_filter_hooks() called on settlement page');

        // フィルターフックの手動実行テスト
        $test_tabs = array('existing_tab' => 'Existing Tab');

        // フィルターを手動で実行
        $result_tabs = apply_filters('usces_filter_settlement_tab_title', $test_tabs);
        error_log('GrandPay Admin: Manual filter test result - ' . print_r($result_tabs, true));

        // 直接メソッドを呼び出してテスト
        $direct_result = $this->add_settlement_tab($test_tabs);
        error_log('GrandPay Admin: Direct method call result - ' . print_r($direct_result, true));

        // グローバルフィルター一覧を確認
        global $wp_filter;
        if (isset($wp_filter['usces_filter_settlement_tab_title'])) {
            $callback_count = count($wp_filter['usces_filter_settlement_tab_title']->callbacks);
            error_log("GrandPay Admin: usces_filter_settlement_tab_title filter exists with $callback_count callbacks");

            // 登録されているコールバックを確認
            foreach ($wp_filter['usces_filter_settlement_tab_title']->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $callback_id => $callback_data) {
                    error_log("GrandPay Admin: Callback found - Priority: $priority, ID: $callback_id");
                }
            }
        } else {
            error_log('GrandPay Admin: usces_filter_settlement_tab_title filter does NOT exist');
        }
    }

    public function debug_all_hooks() {
        global $wp_filter;

        // Welcart関連のフィルター・アクション名をすべて記録
        $welcart_hooks = array();
        foreach ($wp_filter as $hook_name => $hook_data) {
            if (strpos($hook_name, 'usces') !== false) {
                $welcart_hooks[] = $hook_name;
            }
        }

        if (!empty($welcart_hooks)) {
            error_log('GrandPay Debug: Available Welcart hooks: ' . implode(', ', $welcart_hooks));
        }

        // 現在のページ確認
        if (isset($_GET['page']) && strpos($_GET['page'], 'usces_settlement') !== false) {
            error_log('GrandPay Debug: On Welcart settlement page: ' . $_GET['page']);
        }
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
                    <li><strong>支払方法を追加</strong><br>
                        Welcart Shop → 基本設定 → 支払方法 → 新規追加<br>
                        • 支払方法名：クレジットカード決済<br>
                        • 決済種別：クレジット<br>
                        • 決済モジュール：<code>grandpay.php</code>
                    </li>
                    <li><strong>このページでGrandPay設定を行う</strong></li>
                </ol>
            </div>
        <?php
        } else {
        ?>
            <div class="notice notice-success">
                <p><strong>✅ GrandPay決済モジュールが正常に配置されています。</strong><br>
                    支払方法の追加がまだの場合は、Welcart Shop → 基本設定 → 支払方法で設定してください。</p>
            </div>
        <?php
        }
    }

    /**
     * Welcartのクレジット決済設定タブにGrandPayを追加
     */
    public function add_settlement_tab($tabs) {
        // どのフィルターから呼ばれたかを確認
        $backtrace = debug_backtrace();
        $filter_name = 'unknown';
        foreach ($backtrace as $trace) {
            if (isset($trace['function']) && $trace['function'] === 'apply_filters') {
                $filter_name = isset($trace['args'][0]) ? $trace['args'][0] : 'unknown';
                break;
            }
        }

        // デバッグログ - 配列を文字列変換の警告を修正
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("GrandPay: add_settlement_tab called via filter: " . (is_array($filter_name) ? 'Array' : $filter_name));
            error_log('GrandPay: existing tabs - ' . print_r($tabs, true));
        }

        $tabs['grandpay'] = 'GrandPay';

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GrandPay: tabs after adding grandpay - ' . print_r($tabs, true));
        }

        return $tabs;
    }

    /**
     * GrandPay設定タブの内容
     */
    public function add_settlement_tab_body($settlement_selected) {
        if ($settlement_selected !== 'grandpay') {
            return;
        }

        $options = get_option('usces_ex');
        $grandpay_settings = $options['grandpay'] ?? array();

        ?>
        <table class="settle_table">
            <tr>
                <th>GrandPay を利用する</th>
                <td colspan="2">
                    <label>
                        <input name="grandpay[activate]" type="radio" id="grandpay_activate_1" value="on" <?php checked($grandpay_settings['activate'] ?? '', 'on'); ?> />
                        利用する
                    </label>
                    <br />
                    <label>
                        <input name="grandpay[activate]" type="radio" id="grandpay_activate_2" value="off" <?php checked($grandpay_settings['activate'] ?? '', 'off'); ?> />
                        利用しない
                    </label>
                </td>
            </tr>
            <tr>
                <th>Tenant Key</th>
                <td>
                    <input name="grandpay[tenant_key]" type="text" id="grandpay_tenant_key" value="<?php echo esc_attr($grandpay_settings['tenant_key'] ?? ''); ?>" size="50" />
                </td>
                <td>GrandPayから提供されたTenant Keyを入力してください</td>
            </tr>
            <tr>
                <th>Client ID</th>
                <td>
                    <input name="grandpay[client_id]" type="text" id="grandpay_client_id" value="<?php echo esc_attr($grandpay_settings['client_id'] ?? ''); ?>" size="50" />
                </td>
                <td>OAuth2認証用のClient IDを入力してください</td>
            </tr>
            <tr>
                <th>Client Secret</th>
                <td>
                    <input name="grandpay[client_secret]" type="password" id="grandpay_client_secret" value="<?php echo esc_attr($grandpay_settings['client_secret'] ?? ''); ?>" size="50" />
                </td>
                <td>OAuth2認証用のClient Secretを入力してください</td>
            </tr>
            <tr>
                <th>Webhook Secret</th>
                <td>
                    <input name="grandpay[webhook_secret]" type="password" id="grandpay_webhook_secret" value="<?php echo esc_attr($grandpay_settings['webhook_secret'] ?? ''); ?>" size="50" />
                </td>
                <td>Webhook署名検証用のSecretを入力してください</td>
            </tr>
            <tr>
                <th>テストモード</th>
                <td colspan="2">
                    <label>
                        <input name="grandpay[test_mode]" type="radio" id="grandpay_test_mode_1" value="on" <?php checked($grandpay_settings['test_mode'] ?? '', 'on'); ?> />
                        テストモード
                    </label>
                    <br />
                    <label>
                        <input name="grandpay[test_mode]" type="radio" id="grandpay_test_mode_2" value="off" <?php checked($grandpay_settings['test_mode'] ?? '', 'off'); ?> />
                        本番モード
                    </label>
                </td>
            </tr>
            <tr>
                <th>決済方法名</th>
                <td>
                    <input name="grandpay[payment_name]" type="text" id="grandpay_payment_name" value="<?php echo esc_attr($grandpay_settings['payment_name'] ?? 'クレジットカード決済'); ?>" size="30" />
                </td>
                <td>フロント画面に表示される決済方法名</td>
            </tr>
            <tr>
                <th>決済説明文</th>
                <td>
                    <textarea name="grandpay[payment_description]" id="grandpay_payment_description" rows="3" cols="50"><?php echo esc_textarea($grandpay_settings['payment_description'] ?? 'クレジットカードで安全にお支払いいただけます。'); ?></textarea>
                </td>
                <td>フロント画面に表示される説明文</td>
            </tr>
            <tr>
                <th>Webhook URL</th>
                <td colspan="2">
                    <code><?php echo admin_url('admin-ajax.php?action=grandpay_webhook'); ?></code>
                    <p class="description">この URLを GrandPay の管理画面で Webhook URL として設定してください。</p>
                </td>
            </tr>
        </table>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // テスト接続ボタンの処理（今後実装予定）
                $('#grandpay_test_connection').click(function() {
                    // Ajax でテスト接続を実行
                });
            });
        </script>

        <style>
            .settle_table th {
                background-color: #f9f9f9;
                padding: 10px;
                border: 1px solid #ddd;
                width: 180px;
            }

            .settle_table td {
                padding: 10px;
                border: 1px solid #ddd;
            }
        </style>
    <?php
    }

    /**
     * GrandPay設定の保存
     */
    public function save_settlement_settings() {
        if (!isset($_POST['grandpay'])) {
            return;
        }

        $grandpay_settings = $_POST['grandpay'];

        // バリデーション
        $grandpay_settings['tenant_key'] = sanitize_text_field($grandpay_settings['tenant_key'] ?? '');
        $grandpay_settings['client_id'] = sanitize_text_field($grandpay_settings['client_id'] ?? '');
        $grandpay_settings['client_secret'] = sanitize_text_field($grandpay_settings['client_secret'] ?? '');
        $grandpay_settings['webhook_secret'] = sanitize_text_field($grandpay_settings['webhook_secret'] ?? '');
        $grandpay_settings['activate'] = in_array($grandpay_settings['activate'] ?? '', array('on', 'off')) ? $grandpay_settings['activate'] : 'off';
        $grandpay_settings['test_mode'] = in_array($grandpay_settings['test_mode'] ?? '', array('on', 'off')) ? $grandpay_settings['test_mode'] : 'off';
        $grandpay_settings['payment_name'] = sanitize_text_field($grandpay_settings['payment_name'] ?? 'クレジットカード決済');
        $grandpay_settings['payment_description'] = sanitize_textarea_field($grandpay_settings['payment_description'] ?? '');

        // Welcartの設定に保存
        $options = get_option('usces_ex', array());
        $options['grandpay'] = $grandpay_settings;
        update_option('usces_ex', $options);

        // 個別オプションとしても保存（API クラスで使用）
        update_option('welcart_grandpay_tenant_key', $grandpay_settings['tenant_key']);
        update_option('welcart_grandpay_client_id', $grandpay_settings['client_id']);
        update_option('welcart_grandpay_client_secret', $grandpay_settings['client_secret']);
        update_option('welcart_grandpay_webhook_secret', $grandpay_settings['webhook_secret']);
        update_option('welcart_grandpay_test_mode', $grandpay_settings['test_mode'] === 'on');

        // アクセストークンキャッシュをクリア
        delete_transient('welcart_grandpay_access_token');
        delete_transient('welcart_grandpay_token_expires_at');
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
                <h2>設定状況</h2>
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
                <h2>テスト機能</h2>
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
                <h2>設定方法</h2>
                <ol>
                    <li>Welcart Shop → <strong>基本設定 → 支払方法</strong> に移動</li>
                    <li><strong>GrandPay決済</strong>が設定されているか確認</li>
                    <li>Welcart Shop → <strong>基本設定 → クレジット決済設定</strong> に移動</li>
                    <li><strong>GrandPay</strong>タブを選択</li>
                    <li>GrandPayから提供された情報を入力</li>
                    <li>設定を保存</li>
                    <li>このページで接続テストを実行</li>
                </ol>
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
