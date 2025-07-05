<?php

/**
 * Plugin Name: Welcart Grandpay payment
 * Plugin URI:
 * Description: Welcartで、Grandpayの決済ゲートウェイを利用するためのプラグインです。
 * Version: 1.0.0
 * Author: kirason
 * Author URI:
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 7.4
 * Requires at least: 5.0
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) exit();

// 強制ログテスト
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('GrandPay: Main plugin file loaded at ' . current_time('Y-m-d H:i:s'));
}

// Welcartがアクティブでない場合は処理を停止
if (!in_array('usc-e-shop/usc-e-shop.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('GrandPay: Welcart not active');
    }
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>GrandPay Payment プラグインを使用するには Welcart e-Commerce が必要です。</p></div>';
    });
    return;
}

$info = get_file_data(__FILE__, array('plugin_name' => 'Plugin Name', 'version' => 'Version'));

define('WELCART_GRANDPAY_PAYMENT_URL', plugins_url('', __FILE__));  // http(s)://〜/wp-content/plugins/welcart-grandpay-payment（URL）
define('WELCART_GRANDPAY_PAYMENT_PATH', dirname(__FILE__));         // /home/〜/wp-content/plugins/welcart-grandpay-payment (パス)
define('WELCART_GRANDPAY_PAYMENT_NAME', $info['plugin_name']);
define('WELCART_GRANDPAY_PAYMENT_SLUG', 'welcart-grandpay-payment');
define('WELCART_GRANDPAY_PAYMENT_PREFIX', 'welcart_grandpay_payment_');
define('WELCART_GRANDPAY_PAYMENT_VERSION', $info['version']);
define('WELCART_GRANDPAY_PAYMENT_DEVELOP', true);

class WelcartGrandpayPayment {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'), 20); // 優先度を下げてWelcart読み込み後に実行
        register_activation_hook(__FILE__, array($this, 'on_activation'));
        register_deactivation_hook(__FILE__, array($this, 'on_deactivation'));
    }

    public function init() {
        // 強制ログテスト
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GrandPay: init() method called');
        }

        // Welcartが読み込まれていることを確認
        if (!function_exists('usces_get_system_option')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GrandPay: Welcart functions not available');
            }
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GrandPay: Welcart functions available, proceeding with init');
        }

        try {
            $this->load_dependencies();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GrandPay: load_dependencies() completed successfully');
            }

            $this->init_hooks();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GrandPay: init_hooks() completed successfully');
            }

            $this->init_early_hooks();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GrandPay: init_early_hooks() completed successfully');
            }
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GrandPay: Exception in init() - ' . $e->getMessage());
            }
        }
    }

    /**
     * 早期フック初期化（Welcart読み込み直後）
     */
    private function init_early_hooks() {
        // 決済処理（管理画面・フロント両方で必要、早期に登録）
        $payment_processor = new WelcartGrandpayPaymentProcessor();
    }

    /**
     * 依存ファイルの読み込み
     */
    private function load_dependencies() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GrandPay: load_dependencies() called');
        }

        // API通信クラス
        if (!class_exists('WelcartGrandpayAPI')) {
            require_once WELCART_GRANDPAY_PAYMENT_PATH . '/inc/class-grandpay-api.php';
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GrandPay: Loaded WelcartGrandpayAPI');
            }
        }

        // 決済処理クラス
        if (!class_exists('WelcartGrandpayPaymentProcessor')) {
            require_once WELCART_GRANDPAY_PAYMENT_PATH . '/inc/class-grandpay-payment.php';
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GrandPay: Loaded WelcartGrandpayPaymentProcessor');
            }
        }

        // 管理画面クラス
        require_once WELCART_GRANDPAY_PAYMENT_PATH . '/inc/class-admin.php';
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GrandPay: Loaded admin class');
        }

        // フロントエンドクラス
        require_once WELCART_GRANDPAY_PAYMENT_PATH . '/inc/class-front.php';
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GrandPay: Loaded front class');
        }
    }

    /**
     * フックの初期化
     */
    private function init_hooks() {
        // デバッグログ
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GrandPay: init_hooks() called');
        }

        // **管理画面クラスをここで初期化**
        if (is_admin()) {
            error_log('GrandPay: Creating admin instance in init_hooks()');
            new WelcartGrandpayPaymentAdmin();
            error_log('GrandPay: Admin instance created in init_hooks()');
        }

        // フロントエンド側の処理
        if (!is_admin()) {
            new WelcartGrandpayPaymentFront();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GrandPay: Created front instance');
            }
        }

        // 国際化
        add_action('init', array($this, 'load_textdomain'));

        // アップデート処理
        add_action('upgrader_process_complete', array($this, 'on_update'), 10, 2);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GrandPay: init_hooks() completed');
        }
    }

    /**
     * プラグイン有効化時の処理
     */
    public function on_activation() {
        // バージョン情報を保存
        update_option('welcart_grandpay_payment_version', WELCART_GRANDPAY_PAYMENT_VERSION);

        // デフォルト設定を作成
        $options = get_option('usces_ex', array());
        if (!isset($options['grandpay'])) {
            $options['grandpay'] = array(
                'activate' => 'off',
                'test_mode' => 'on',
                'payment_name' => 'クレジットカード決済',
                'payment_description' => 'クレジットカードで安全にお支払いいただけます。'
            );
            update_option('usces_ex', $options);
        }

        // 決済モジュールファイルをコピー
        $this->copy_settlement_module();

        // 必要なテーブル作成やオプション初期化
        $this->create_database_tables();

        // 書き換えルールをフラッシュ
        flush_rewrite_rules();
    }

    /**
     * 決済モジュールファイルをWelcartのsettlementディレクトリにコピー
     */
    private function copy_settlement_module() {
        $source_file = WELCART_GRANDPAY_PAYMENT_PATH . '/settlement/grandpay.php';
        $welcart_settlement_dir = WP_PLUGIN_DIR . '/usc-e-shop/settlement/';
        $destination_file = $welcart_settlement_dir . 'grandpay.php';

        // デバッグログ
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("GrandPay Copy: Source - $source_file");
            error_log("GrandPay Copy: Destination - $destination_file");
            error_log("GrandPay Copy: Source exists - " . (file_exists($source_file) ? 'YES' : 'NO'));
        }

        // ソースファイルの存在確認
        if (!file_exists($source_file)) {
            add_action('admin_notices', function () use ($source_file) {
                echo '<div class="notice notice-error"><p>❌ <strong>GrandPay決済モジュール設定エラー</strong><br>
                ソースファイルが見つかりません: <code>' . basename($source_file) . '</code><br>
                プラグインファイルが正しく配置されているか確認してください。</p></div>';
            });
            return false;
        }

        // Welcartのsettlementディレクトリ確認・作成
        if (!$this->ensure_welcart_settlement_directory($welcart_settlement_dir)) {
            return false;
        }

        // ファイルコピー実行
        $copy_result = copy($source_file, $destination_file);

        if ($copy_result) {
            // 権限設定
            chmod($destination_file, 0644);

            // 成功通知
            add_action('admin_notices', function () use ($destination_file) {
                echo '<div class="notice notice-success is-dismissible">
                    <h4>🎉 GrandPay決済モジュールのインストール完了！</h4>
                    <p>ファイル配置先: <code>' . str_replace(ABSPATH, '', $destination_file) . '</code></p>
                    <h4>📋 次の設定手順:</h4>
                    <ol>
                        <li><strong>Welcart Shop → 基本設定 → 支払方法</strong> に移動</li>
                        <li><strong>「新規追加」</strong> をクリック</li>
                        <li>以下を設定:
                            <ul>
                                <li>支払方法名: <code>GrandPay決済</code></li>
                                <li>決済種別: <code>クレジット</code></li>
                                <li>決済モジュール: <code>grandpay.php</code></li>
                            </ul>
                        </li>
                        <li><strong>「この内容で設定する」</strong> をクリック</li>
                        <li><strong>Welcart Shop → 基本設定 → クレジット決済設定 → GrandPayタブ</strong> で設定</li>
                    </ol>
                </div>';
            });

            return true;
        } else {
            // コピー失敗
            add_action('admin_notices', function () use ($source_file, $destination_file) {
                echo '<div class="notice notice-error">
                    <h4>❌ GrandPay決済モジュールのコピーに失敗</h4>
                    <p>権限の問題の可能性があります。</p>
                    <h4>🔧 手動での対処法:</h4>
                    <pre style="background: #f0f0f0; padding: 10px; border-radius: 4px;">
# ディレクトリ作成
mkdir -p ' . dirname($destination_file) . '

# ファイルコピー
cp ' . $source_file . ' ' . $destination_file . '

# 権限設定
chmod 644 ' . $destination_file . '</pre>
                </div>';
            });

            return false;
        }
    }

    /**
     * Welcartのsettlementディレクトリの存在確認・作成
     */
    private function ensure_welcart_settlement_directory($welcart_settlement_dir) {
        // Welcartプラグインの存在確認
        $welcart_plugin_dir = WP_PLUGIN_DIR . '/usc-e-shop/';

        if (!is_dir($welcart_plugin_dir)) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error">
                    <h4>❌ Welcart e-Commerceプラグインが見つかりません</h4>
                    <p>GrandPay決済プラグインを使用するには、先に <strong>Welcart e-Commerce</strong> プラグインをインストール・有効化してください。</p>
                    <p><a href="https://ja.wordpress.org/plugins/usc-e-shop/" target="_blank" class="button button-primary">Welcart e-Commerceをダウンロード</a></p>
                </div>';
            });
            return false;
        }

        // settlementディレクトリの確認・作成
        if (!is_dir($welcart_settlement_dir)) {
            if (!wp_mkdir_p($welcart_settlement_dir)) {
                add_action('admin_notices', function () use ($welcart_settlement_dir) {
                    echo '<div class="notice notice-error">
                        <h4>❌ ディレクトリ作成に失敗</h4>
                        <p>以下のディレクトリを作成できませんでした:</p>
                        <code>' . $welcart_settlement_dir . '</code>
                        <h4>🔧 手動での対処法:</h4>
                        <pre style="background: #f0f0f0; padding: 10px; border-radius: 4px;">mkdir -p ' . $welcart_settlement_dir . '</pre>
                    </div>';
                });
                return false;
            }

            // ディレクトリ作成成功のログ
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("GrandPay: Created directory - $welcart_settlement_dir");
            }
        }

        return true;
    }

    /**
     * プラグイン無効化時の処理
     */
    public function on_deactivation() {
        // 決済モジュールファイルを削除（オプション）
        $this->remove_settlement_module();

        // 一時的なデータを削除
        delete_transient('welcart_grandpay_access_token');
        delete_transient('welcart_grandpay_token_expires_at');

        // 書き換えルールをフラッシュ
        flush_rewrite_rules();
    }

    /**
     * 決済モジュールファイルを削除（オプション）
     */
    private function remove_settlement_module() {
        $destination_file = WP_PLUGIN_DIR . '/usc-e-shop/settlement/grandpay.php';

        if (file_exists($destination_file)) {
            $delete_result = unlink($destination_file);

            if ($delete_result) {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-info is-dismissible">
                        <h4>🗑️ GrandPay決済モジュールファイルを削除しました</h4>
                        <p>Welcartの支払方法設定からも削除することをお勧めします。</p>
                    </div>';
                });

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("GrandPay: Removed settlement module file - $destination_file");
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("GrandPay: Failed to remove settlement module file - $destination_file");
                }
            }
        }
    }

    /**
     * アップデート時の処理
     */
    public function on_update($upgrader_object, $options) {
        $current_plugin_path_name = plugin_basename(__FILE__);

        if ($options['action'] == 'update' && $options['type'] == 'plugin') {
            if (isset($options['plugins'])) {
                foreach ($options['plugins'] as $each_plugin) {
                    if ($each_plugin == $current_plugin_path_name) {
                        // バージョン更新処理
                        $this->upgrade_database();
                        update_option('welcart_grandpay_payment_version', WELCART_GRANDPAY_PAYMENT_VERSION);
                    }
                }
            }
        }
    }

    /**
     * 言語ファイルの読み込み
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'welcart-grandpay-payment',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

    /**
     * データベーステーブル作成
     */
    private function create_database_tables() {
        global $wpdb;

        // 必要に応じてカスタムテーブルを作成
        // 現在は Welcart の既存テーブルと WordPress のメタテーブルを使用するため、特別な処理は不要

        // ログテーブルが必要な場合は以下のようなコードを追加
        /*
        $table_name = $wpdb->prefix . 'grandpay_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            event_type varchar(50) NOT NULL,
            event_data text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        */
    }

    /**
     * データベースアップグレード処理
     */
    private function upgrade_database() {
        $current_version = get_option('welcart_grandpay_payment_version', '0.0.0');

        // バージョン別のアップグレード処理
        if (version_compare($current_version, '1.0.0', '<')) {
            // 1.0.0 へのアップグレード処理
            $this->create_database_tables();
        }

        // 必要に応じて他のバージョンのアップグレード処理を追加
    }

    /**
     * ログ記録用のヘルパーメソッド
     */
    public static function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[GrandPay] ' . $message);
        }

        // より詳細なログが必要な場合はここに追加
    }

    /**
     * 設定値取得のヘルパーメソッド
     */
    public static function get_setting($key, $default = '') {
        $options = get_option('usces_ex', array());
        $grandpay_settings = $options['grandpay'] ?? array();
        return $grandpay_settings[$key] ?? $default;
    }

    /**
     * GrandPayが有効かどうかをチェック
     */
    public static function is_enabled() {
        return self::get_setting('activate') === 'on';
    }

    /**
     * テストモードかどうかをチェック
     */
    public static function is_test_mode() {
        return self::get_setting('test_mode') === 'on';
    }
}

// プラグインの初期化
WelcartGrandpayPayment::get_instance();

// 必要に応じてグローバル関数を定義
if (!function_exists('welcart_grandpay_log')) {
    function welcart_grandpay_log($message, $level = 'info') {
        WelcartGrandpayPayment::log($message, $level);
    }
}

if (!function_exists('welcart_grandpay_is_enabled')) {
    function welcart_grandpay_is_enabled() {
        return WelcartGrandpayPayment::is_enabled();
    }
}
