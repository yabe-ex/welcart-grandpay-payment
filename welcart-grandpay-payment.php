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

define('WELCART_GRANDPAY_PAYMENT_URL', plugins_url('', __FILE__));
define('WELCART_GRANDPAY_PAYMENT_PATH', dirname(__FILE__));
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
        add_action('plugins_loaded', array($this, 'init'), 20);
        register_activation_hook(__FILE__, array($this, 'on_activation'));
        register_deactivation_hook(__FILE__, array($this, 'on_deactivation'));

        // 決済モジュール登録（最重要）
        add_action('admin_init', array($this, 'register_settlement_module'), 10);
    }

    /**
     * 決済モジュールの登録（functions.phpから移行）
     */
    public function register_settlement_module() {
        // 文字列形式での正しい登録
        $available_settlement = get_option('usces_available_settlement', array());

        if (!isset($available_settlement['grandpay'])) {
            // 他のモジュールと同じ文字列形式で登録
            $available_settlement['grandpay'] = 'GrandPay';

            update_option('usces_available_settlement', $available_settlement);
            error_log('🎉 GrandPay registered in settlement modules!');
        }
    }

    public function init() {
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
            $this->init_hooks();
            $this->init_early_hooks();
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GrandPay: Exception in init() - ' . $e->getMessage());
            }
        }
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
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GrandPay: init_hooks() called');
        }

        // 管理画面クラス
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

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GrandPay: init_hooks() completed');
        }
    }

    /**
     * 早期フック初期化
     */
    private function init_early_hooks() {
        // 決済処理（管理画面・フロント両方で必要、早期に登録）
        $payment_processor = new WelcartGrandpayPaymentProcessor();
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

        // 決済モジュールを利用可能リストに追加
        $this->register_settlement_module();

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

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("GrandPay Copy: Source - $source_file");
            error_log("GrandPay Copy: Destination - $destination_file");
        }

        // ソースファイルの存在確認
        if (!file_exists($source_file)) {
            add_action('admin_notices', function () use ($source_file) {
                echo '<div class="notice notice-error"><p>❌ <strong>GrandPay決済モジュール設定エラー</strong><br>
                ソースファイルが見つかりません: <code>' . basename($source_file) . '</code></p></div>';
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
            chmod($destination_file, 0644);

            add_action('admin_notices', function () use ($destination_file) {
                echo '<div class="notice notice-success is-dismissible">
                    <h4>🎉 GrandPay決済モジュールのインストール完了！</h4>
                    <p>ファイル配置先: <code>' . str_replace(ABSPATH, '', $destination_file) . '</code></p>
                    <h4>📋 次の設定手順:</h4>
                    <ol>
                        <li><strong>Welcart Shop → 基本設定 → クレジット決済設定</strong> に移動</li>
                        <li><strong>左側のリストからGrandPayを右側にドラッグ</strong></li>
                        <li><strong>「利用するモジュールを更新する」</strong> をクリック</li>
                        <li><strong>GrandPayタブ</strong> で設定を入力</li>
                    </ol>
                </div>';
            });

            return true;
        } else {
            add_action('admin_notices', function () use ($source_file, $destination_file) {
                echo '<div class="notice notice-error">
                    <h4>❌ GrandPay決済モジュールのコピーに失敗</h4>
                    <p>手動でファイルをコピーしてください：</p>
                    <pre>' . $source_file . ' → ' . $destination_file . '</pre>
                </div>';
            });

            return false;
        }
    }

    /**
     * Welcartのsettlementディレクトリの存在確認・作成
     */
    private function ensure_welcart_settlement_directory($welcart_settlement_dir) {
        $welcart_plugin_dir = WP_PLUGIN_DIR . '/usc-e-shop/';

        if (!is_dir($welcart_plugin_dir)) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error">
                    <h4>❌ Welcart e-Commerceプラグインが見つかりません</h4>
                    <p>GrandPay決済プラグインを使用するには、先に <strong>Welcart e-Commerce</strong> プラグインをインストール・有効化してください。</p>
                </div>';
            });
            return false;
        }

        if (!is_dir($welcart_settlement_dir)) {
            if (!wp_mkdir_p($welcart_settlement_dir)) {
                add_action('admin_notices', function () use ($welcart_settlement_dir) {
                    echo '<div class="notice notice-error">
                        <h4>❌ ディレクトリ作成に失敗</h4>
                        <p>手動でディレクトリを作成してください: <code>' . $welcart_settlement_dir . '</code></p>
                    </div>';
                });
                return false;
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

        flush_rewrite_rules();
    }

    /**
     * 決済モジュールファイルを削除
     */
    private function remove_settlement_module() {
        $destination_file = WP_PLUGIN_DIR . '/usc-e-shop/settlement/grandpay.php';

        if (file_exists($destination_file)) {
            $delete_result = unlink($destination_file);

            if ($delete_result && defined('WP_DEBUG') && WP_DEBUG) {
                error_log("GrandPay: Removed settlement module file - $destination_file");
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
     * ログ記録用のヘルパーメソッド
     */
    public static function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[GrandPay] ' . $message);
        }
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
