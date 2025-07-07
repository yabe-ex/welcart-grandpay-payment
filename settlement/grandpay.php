<?php

/**
 * GrandPay決済モジュール（Welcart標準準拠）
 * テレコムクレジットと同じ実装パターンを使用
 */

// 直接アクセスを防ぐ
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GrandPay決済クラス
 */
class GRANDPAY_SETTLEMENT {

    /**
     * Instance of this class.
     *
     * @var object
     */
    protected static $instance = null;

    protected $paymod_id;          // 決済代行会社ID
    protected $pay_method;         // 決済種別
    protected $acting_name;        // 決済代行会社略称
    protected $acting_formal_name; // 決済代行会社正式名称
    protected $acting_company_url; // 決済代行会社URL

    /**
     * エラーメッセージ
     *
     * @var string
     */
    protected $error_mes;

    /**
     * Construct.
     */
    public function __construct() {

        $this->paymod_id          = 'grandpay';
        $this->pay_method         = array('acting_grandpay_card');
        $this->acting_name        = 'GrandPay';
        $this->acting_formal_name = 'GrandPay Asia';
        $this->acting_company_url = 'https://payment-gateway.asia/';

        $this->initialize_data();

        if (is_admin()) {
            add_action('usces_action_admin_settlement_update', array($this, 'settlement_update'));
            add_action('usces_action_settlement_tab_title', array($this, 'settlement_tab_title'));
            add_action('usces_action_settlement_tab_body', array($this, 'settlement_tab_body'));
        }

        if ($this->is_activate_card()) {
            add_action('usces_action_reg_orderdata', array($this, 'register_orderdata'));
        }

        error_log('GrandPay Settlement Class: Initialized successfully');
    }

    /**
     * Return an instance of this class.
     */
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize
     */
    public function initialize_data() {
        $options = get_option('usces', array());
        if (!isset($options['acting_settings']) || !isset($options['acting_settings']['grandpay'])) {
            $options['acting_settings']['grandpay']['activate']            = 'off';
            $options['acting_settings']['grandpay']['test_mode']           = 'on';
            $options['acting_settings']['grandpay']['payment_name']        = 'クレジットカード決済';
            $options['acting_settings']['grandpay']['payment_description'] = 'クレジットカードで安全にお支払いいただけます。';
            $options['acting_settings']['grandpay']['tenant_key']          = '';
            $options['acting_settings']['grandpay']['client_id']           = '';
            $options['acting_settings']['grandpay']['client_secret']       = '';
            $options['acting_settings']['grandpay']['webhook_secret']      = '';
            $options['acting_settings']['grandpay']['card_activate']       = 'off';
            update_option('usces', $options);
        }
    }

    /**
     * 決済有効判定
     *
     * @param string $type Module type.
     * @return boolean
     */
    public function is_validity_acting($type = '') {
        $acting_opts = $this->get_acting_settings();
        if (empty($acting_opts)) {
            return false;
        }

        $payment_method = usces_get_system_option('usces_payment_method', 'sort');
        $method = false;

        switch ($type) {
            case 'card':
                foreach ($payment_method as $payment) {
                    if ('acting_grandpay_card' == $payment['settlement'] && 'activate' == $payment['use']) {
                        $method = true;
                        break;
                    }
                }
                if ($method && $this->is_activate_card()) {
                    return true;
                } else {
                    return false;
                }
                break;

            default:
                if ('on' == $acting_opts['activate']) {
                    return true;
                } else {
                    return false;
                }
        }
    }

    /**
     * クレジットカード決済有効判定
     *
     * @return boolean $res
     */
    public function is_activate_card() {
        $acting_opts = $this->get_acting_settings();
        if ((isset($acting_opts['activate']) && 'on' == $acting_opts['activate']) &&
            (isset($acting_opts['card_activate']) && ('on' == $acting_opts['card_activate']))
        ) {
            $res = true;
        } else {
            $res = false;
        }
        return $res;
    }

    /**
     * 決済オプション登録・更新
     * usces_action_admin_settlement_update
     */
    public function settlement_update() {
        global $usces;

        if ($this->paymod_id != $_POST['acting']) {
            return;
        }

        error_log('GrandPay Settlement: settlement_update() called');

        $this->error_mes = '';
        $options = get_option('usces', array());
        $payment_method = usces_get_system_option('usces_payment_method', 'settlement');

        unset($options['acting_settings']['grandpay']);
        $options['acting_settings']['grandpay']['activate']            = (isset($_POST['activate'])) ? $_POST['activate'] : 'off';
        $options['acting_settings']['grandpay']['test_mode']           = (isset($_POST['test_mode'])) ? $_POST['test_mode'] : 'on';
        $options['acting_settings']['grandpay']['payment_name']        = (isset($_POST['payment_name'])) ? sanitize_text_field($_POST['payment_name']) : 'クレジットカード決済';
        $options['acting_settings']['grandpay']['payment_description'] = (isset($_POST['payment_description'])) ? sanitize_textarea_field($_POST['payment_description']) : 'クレジットカードで安全にお支払いいただけます。';
        $options['acting_settings']['grandpay']['tenant_key']          = (isset($_POST['tenant_key'])) ? sanitize_text_field($_POST['tenant_key']) : '';
        $options['acting_settings']['grandpay']['client_id']           = (isset($_POST['client_id'])) ? sanitize_text_field($_POST['client_id']) : '';
        $options['acting_settings']['grandpay']['client_secret']       = (isset($_POST['client_secret'])) ? sanitize_text_field($_POST['client_secret']) : '';
        $options['acting_settings']['grandpay']['webhook_secret']      = (isset($_POST['webhook_secret'])) ? sanitize_text_field($_POST['webhook_secret']) : '';
        $options['acting_settings']['grandpay']['card_activate']       = (isset($_POST['activate']) && $_POST['activate'] == 'on') ? 'on' : 'off';

        // バリデーション
        if ('on' == $options['acting_settings']['grandpay']['activate']) {
            if (WCUtils::is_blank($_POST['tenant_key'])) {
                $this->error_mes .= '※Tenant Keyを入力してください<br />';
            }
            if (WCUtils::is_blank($_POST['client_id'])) {
                $this->error_mes .= '※Client IDを入力してください<br />';
            }
            if (WCUtils::is_blank($_POST['client_secret'])) {
                $this->error_mes .= '※Client Secretを入力してください<br />';
            }
        }

        if ('' == $this->error_mes) {
            $usces->action_status = 'success';
            $usces->action_message = __('Options are updated.', 'usces');

            if ('on' == $options['acting_settings']['grandpay']['activate']) {
                $toactive = array();

                // 決済処理の登録
                $usces->payment_structure['acting_grandpay_card'] = 'カード決済（' . $this->acting_name . '）';

                foreach ($payment_method as $settlement => $payment) {
                    if ('acting_grandpay_card' == $settlement && 'deactivate' == $payment['use']) {
                        $toactive[] = $payment['name'];
                    }
                }

                usces_admin_orderlist_show_wc_trans_id();
                if (0 < count($toactive)) {
                    $usces->action_message .= __("Please update the payment method to \"Activate\". <a href=\"admin.php?page=usces_initial#payment_method_setting\">General Setting > Payment Methods</a>", 'usces');
                }
            } else {
                unset($usces->payment_structure['acting_grandpay_card']);
            }

            $deactivate = array();
            foreach ($payment_method as $settlement => $payment) {
                if (!array_key_exists($settlement, $usces->payment_structure)) {
                    if ('deactivate' != $payment['use']) {
                        $payment['use'] = 'deactivate';
                        $deactivate[] = $payment['name'];
                        usces_update_system_option('usces_payment_method', $payment['id'], $payment);
                    }
                }
            }
            if (0 < count($deactivate)) {
                $deactivate_message = sprintf(__("\"Deactivate\" %s of payment method.", 'usces'), implode(',', $deactivate));
                $usces->action_message .= $deactivate_message;
            }
        } else {
            $usces->action_status = 'error';
            $usces->action_message = __('Data have deficiency.', 'usces');
            $options['acting_settings']['grandpay']['activate'] = 'off';
            unset($usces->payment_structure['acting_grandpay_card']);

            $deactivate = array();
            foreach ($payment_method as $settlement => $payment) {
                if (in_array($settlement, $this->pay_method)) {
                    if ('deactivate' != $payment['use']) {
                        $payment['use'] = 'deactivate';
                        $deactivate[] = $payment['name'];
                        usces_update_system_option('usces_payment_method', $payment['id'], $payment);
                    }
                }
            }
            if (0 < count($deactivate)) {
                $deactivate_message = sprintf(__("\"Deactivate\" %s of payment method.", 'usces'), implode(',', $deactivate));
                $usces->action_message .= $deactivate_message . __("Please complete the setup and update the payment method to \"Activate\".", 'usces');
            }
        }

        ksort($usces->payment_structure);
        update_option('usces', $options);
        update_option('usces_payment_structure', $usces->payment_structure);

        // 個別オプションとしても保存（API クラスで使用）
        update_option('welcart_grandpay_tenant_key', $options['acting_settings']['grandpay']['tenant_key']);
        update_option('welcart_grandpay_client_id', $options['acting_settings']['grandpay']['client_id']);
        update_option('welcart_grandpay_client_secret', $options['acting_settings']['grandpay']['client_secret']);
        update_option('welcart_grandpay_webhook_secret', $options['acting_settings']['grandpay']['webhook_secret']);
        update_option('welcart_grandpay_test_mode', $options['acting_settings']['grandpay']['test_mode'] === 'on');

        // アクセストークンキャッシュをクリア
        delete_transient('welcart_grandpay_access_token');
        delete_transient('welcart_grandpay_token_expires_at');

        error_log('GrandPay Settlement: Settings saved successfully');
    }

    /**
     * クレジット決済設定画面タブ
     * usces_action_settlement_tab_title
     */
    public function settlement_tab_title() {
        $settlement_selected = get_option('usces_settlement_selected');
        if (in_array($this->paymod_id, (array) $settlement_selected)) {
            echo '<li><a href="#uscestabs_' . $this->paymod_id . '">' . $this->acting_name . '</a></li>';
            error_log('GrandPay Settlement: Tab title added');
        } else {
            error_log('GrandPay Settlement: Not in selected settlements - tab not added');
            error_log('GrandPay Settlement: Selected settlements: ' . print_r($settlement_selected, true));
        }
    }

    /**
     * クレジット決済設定画面フォーム
     * usces_action_settlement_tab_body
     */
    public function settlement_tab_body() {
        global $usces;

        $acting_opts = $this->get_acting_settings();
        $settlement_selected = get_option('usces_settlement_selected');

        if (in_array($this->paymod_id, (array) $settlement_selected)) :
            error_log('GrandPay Settlement: Displaying tab body');
?>
            <div id="uscestabs_grandpay">
                <div class="settlement_service">
                    <span class="service_title"><?php echo esc_html($this->acting_formal_name); ?></span>
                </div>

                <?php if (isset($_POST['acting']) && 'grandpay' == $_POST['acting']) : ?>
                    <?php if ('' != $this->error_mes) : ?>
                        <div class="error_message"><?php wel_esc_script_e($this->error_mes); ?></div>
                    <?php elseif (isset($acting_opts['activate']) && 'on' == $acting_opts['activate']) : ?>
                        <div class="message">十分にテストを行ってから運用してください。</div>
                    <?php endif; ?>
                <?php endif; ?>

                <form action="" method="post" name="grandpay_form" id="grandpay_form">
                    <table class="settle_table">
                        <tr>
                            <th><a class="explanation-label" id="label_ex_activate_grandpay">GrandPay を利用する</a></th>
                            <td>
                                <label><input name="activate" type="radio" id="activate_grandpay_1" value="on" <?php checked(isset($acting_opts['activate']) && 'on' == $acting_opts['activate'], true); ?> /><span>利用する</span></label><br />
                                <label><input name="activate" type="radio" id="activate_grandpay_2" value="off" <?php checked(isset($acting_opts['activate']) && 'off' == $acting_opts['activate'], true); ?> /><span>利用しない</span></label>
                            </td>
                        </tr>
                        <tr id="ex_activate_grandpay" class="explanation">
                            <td colspan="2">GrandPay決済サービスを利用するかどうかを選択してください。</td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_payment_name_grandpay">決済方法名</a></th>
                            <td><input name="payment_name" type="text" id="payment_name_grandpay" value="<?php echo esc_attr(isset($acting_opts['payment_name']) ? $acting_opts['payment_name'] : 'クレジットカード決済'); ?>" class="regular-text" /></td>
                        </tr>
                        <tr id="ex_payment_name_grandpay" class="explanation">
                            <td colspan="2">フロント画面に表示される決済方法名を設定してください。</td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_payment_description_grandpay">決済説明文</a></th>
                            <td><textarea name="payment_description" id="payment_description_grandpay" rows="3" cols="50" class="regular-text"><?php echo esc_textarea(isset($acting_opts['payment_description']) ? $acting_opts['payment_description'] : 'クレジットカードで安全にお支払いいただけます。'); ?></textarea></td>
                        </tr>
                        <tr id="ex_payment_description_grandpay" class="explanation">
                            <td colspan="2">フロント画面に表示される決済方法の説明文を設定してください。</td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_tenant_key_grandpay">Tenant Key</a></th>
                            <td><input name="tenant_key" type="text" id="tenant_key_grandpay" value="<?php echo esc_attr(isset($acting_opts['tenant_key']) ? $acting_opts['tenant_key'] : ''); ?>" class="regular-text" placeholder="test_tenant_12345" /></td>
                        </tr>
                        <tr id="ex_tenant_key_grandpay" class="explanation">
                            <td colspan="2">GrandPayから提供されたTenant Keyを入力してください。<br>
                                <strong>テスト用:</strong> test_tenant_12345
                            </td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_client_id_grandpay">Client ID</a></th>
                            <td><input name="client_id" type="text" id="client_id_grandpay" value="<?php echo esc_attr(isset($acting_opts['client_id']) ? $acting_opts['client_id'] : ''); ?>" class="regular-text" placeholder="grandpay_test_client_id_67890" /></td>
                        </tr>
                        <tr id="ex_client_id_grandpay" class="explanation">
                            <td colspan="2">GrandPayから提供されたOAuth2 Client IDを入力してください。<br>
                                <strong>テスト用:</strong> grandpay_test_client_id_67890
                            </td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_client_secret_grandpay">Client Secret</a></th>
                            <td><input name="client_secret" type="password" id="client_secret_grandpay" value="<?php echo esc_attr(isset($acting_opts['client_secret']) ? $acting_opts['client_secret'] : ''); ?>" class="regular-text" placeholder="不明（API文書に記載なし）" /></td>
                        </tr>
                        <tr id="ex_client_secret_grandpay" class="explanation">
                            <td colspan="2">OAuth2認証で使用される可能性がありますが、APIドキュメントに具体的な値の記載がありません。<br>
                                <strong>まずは空欄のままテストしてください</strong>
                            </td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_webhook_secret_grandpay">Webhook Secret</a></th>
                            <td><input name="webhook_secret" type="password" id="webhook_secret_grandpay" value="<?php echo esc_attr(isset($acting_opts['webhook_secret']) ? $acting_opts['webhook_secret'] : ''); ?>" class="regular-text" placeholder="不明（API文書に記載なし）" /></td>
                        </tr>
                        <tr id="ex_webhook_secret_grandpay" class="explanation">
                            <td colspan="2">Webhook署名検証で使用される可能性がありますが、APIドキュメントに具体的な設定方法の記載がありません。<br>
                                <strong>まずは空欄のままテストしてください</strong>
                            </td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label" id="label_ex_test_mode_grandpay">動作環境</a></th>
                            <td>
                                <label><input name="test_mode" type="radio" id="test_mode_grandpay_1" value="on" <?php checked(isset($acting_opts['test_mode']) && 'on' == $acting_opts['test_mode'], true); ?> /><span>テスト環境</span></label><br />
                                <label><input name="test_mode" type="radio" id="test_mode_grandpay_2" value="off" <?php checked(isset($acting_opts['test_mode']) && 'off' == $acting_opts['test_mode'], true); ?> /><span>本番環境</span></label>
                            </td>
                        </tr>
                        <tr id="ex_test_mode_grandpay" class="explanation">
                            <td colspan="2">動作環境を切り替えます。テスト時は必ずテスト環境を選択してください。</td>
                        </tr>
                    </table>

                    <input name="acting" type="hidden" value="grandpay" />
                    <input name="usces_option_update" type="submit" class="button button-primary" value="<?php echo esc_html($this->acting_name); ?>の設定を更新する" />
                    <?php wp_nonce_field('admin_settlement', 'wc_nonce'); ?>
                </form>

                <div class="settle_exp">
                    <p><strong><?php echo esc_html($this->acting_formal_name); ?></strong></p>
                    <a href="<?php echo esc_url($this->acting_company_url); ?>" target="_blank"><?php echo esc_html($this->acting_name); ?>の詳細はこちら »</a>
                    <p>　</p>

                    <p><strong>🧪 開発・テスト用設定値</strong></p>
                    <p>実際のGrandPayサービスが利用できない場合は、以下のテスト用設定値をご利用ください：</p>
                    <ul>
                        <li><strong>Tenant Key:</strong> test_tenant_12345</li>
                        <li><strong>Client ID:</strong> grandpay_test_client_id_67890</li>
                        <li><strong>Client Secret:</strong> gp_test_secret_abcdef123456789</li>
                        <li><strong>Webhook Secret:</strong> webhook_secret_xyz789abc123</li>
                    </ul>

                    <p><strong>⚠️ 重要な注意事項</strong></p>
                    <ul>
                        <li><strong>テスト環境でのみ使用</strong>してください</li>
                        <li>実際の決済処理は行われません</li>
                        <li>本番運用前に実際のGrandPay契約が必要です</li>
                    </ul>

                    <p><strong>🔧 実際のGrandPayサービス利用時</strong></p>
                    <ol>
                        <li>GrandPayと正式契約を行う</li>
                        <li>GrandPay管理画面で認証情報を取得</li>
                        <li>上記テスト値を実際の値に置き換え</li>
                        <li>動作環境を「本番環境」に切り替え</li>
                        <li>十分なテストを実施</li>
                    </ol>

                    <p><strong>Webhook URL:</strong><br>
                        <code><?php echo admin_url('admin-ajax.php?action=grandpay_webhook'); ?></code><br>
                        この URLを GrandPay の管理画面で Webhook URL として設定してください。
                    </p>
                </div>
                <style>
                    .settle_exp ul,
                    .settle_exp ol {
                        margin-left: 20px;
                        margin-bottom: 15px;
                    }

                    .settle_exp li {
                        margin-bottom: 5px;
                    }

                    .settle_exp strong {
                        color: #0073aa;
                    }

                    .settle_exp p {
                        margin-bottom: 10px;
                    }
                </style>
    <?php
        else :
            error_log('GrandPay Settlement: Not in selected settlements - tab body not displayed');
        endif;
    }

    /**
     * 受注データ登録
     * usces_action_reg_orderdata
     *
     * @param array $args
     */
    public function register_orderdata($args) {
        global $usces;
        extract($args);

        $acting_flg = $payments['settlement'];
        if (!in_array($acting_flg, $this->pay_method)) {
            return;
        }

        if (!$entry['order']['total_full_price']) {
            return;
        }

        // GrandPay固有の注文データ処理をここに追加
        error_log('GrandPay Settlement: Order data registered for order_id: ' . $order_id);
    }

    /**
     * 決済オプション取得
     *
     * @return array $acting_settings
     */
    protected function get_acting_settings() {
        global $usces;

        $acting_settings = (isset($usces->options['acting_settings'][$this->paymod_id])) ? $usces->options['acting_settings'][$this->paymod_id] : array();
        return $acting_settings;
    }
}

/**
 * 旧来の関数（後方互換性のため）
 */
if (!function_exists('usces_get_settlement_info_grandpay')) {
    function usces_get_settlement_info_grandpay() {
        return array(
            'name'           => 'GrandPay',
            'company'        => 'GrandPay Asia Co., Ltd.',
            'version'        => '1.0.0',
            'correspondence' => 'JPY',
            'settlement'     => 'credit',
            'explanation'    => 'GrandPayクレジットカード決済サービス',
            'note'           => 'アジア圏専用のセキュアなクレジットカード決済',
            'country'        => 'JP',
            'launch'         => true
        );
    }
}

// インスタンス作成
GRANDPAY_SETTLEMENT::get_instance();

error_log('GrandPay Settlement Module: Loaded and initialized');
