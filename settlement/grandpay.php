<?php

/**
 * GrandPay決済モジュール（デバッグ強化版）
 */

// 直接アクセスを防ぐ
if (!defined('ABSPATH')) {
    exit;
}

error_log('GrandPay Settlement Module: Debug-enhanced version loaded - ' . date('Y-m-d H:i:s'));

/**
 * Welcart標準: 決済モジュール情報取得関数
 */
if (!function_exists('usces_get_settlement_info_grandpay')) {
    function usces_get_settlement_info_grandpay() {
        return array(
            'name' => 'GrandPay',
            'company' => 'GrandPay Co., Ltd.',
            'version' => '1.0.0',
            'correspondence' => 'JPY',
            'settlement' => 'credit',
            'explanation' => 'GrandPayクレジットカード決済サービス',
            'note' => 'クレジットカードで安全にお支払いいただけます。',
            'country' => 'JP',
            'launch' => true
        );
    }
}

/**
 * GrandPayが選択されているかチェック（デバッグ強化版）
 */
function grandpay_is_selected() {
    $settlement_selected = get_option('usces_settlement_selected', array());

    // 強化デバッグログ
    error_log('=== GRANDPAY SELECTION CHECK ===');
    error_log('Settlement selected raw: ' . print_r($settlement_selected, true));
    error_log('Settlement selected type: ' . gettype($settlement_selected));

    if (is_array($settlement_selected)) {
        // 配列の場合
        $is_selected = in_array('grandpay', $settlement_selected);
        error_log('GrandPay: Array check - GrandPay is ' . ($is_selected ? 'SELECTED' : 'NOT SELECTED'));
        error_log('GrandPay: Array contents: ' . implode(', ', $settlement_selected));
        return $is_selected;
    } elseif (is_string($settlement_selected)) {
        // 文字列の場合（カンマ区切り）
        $selected_modules = explode(',', $settlement_selected);
        $selected_modules = array_map('trim', $selected_modules); // 空白を除去
        $is_selected = in_array('grandpay', $selected_modules);
        error_log('GrandPay: String check - GrandPay is ' . ($is_selected ? 'SELECTED' : 'NOT SELECTED'));
        error_log('GrandPay: Parsed modules: ' . implode(', ', $selected_modules));
        return $is_selected;
    }

    error_log('GrandPay: ERROR - Unknown selection data format: ' . gettype($settlement_selected));
    error_log('=== END GRANDPAY SELECTION CHECK ===');
    return false;
}

/**
 * 決済タブ関連（デバッグ強化版）
 */
add_filter('usces_filter_settlement_tab_title', 'grandpay_add_settlement_tab', 10);
add_filter('usces_filter_settlement_tab_body', 'grandpay_add_settlement_tab_content', 10);

function grandpay_add_settlement_tab($tabs) {
    error_log('GrandPay Settlement Module: grandpay_add_settlement_tab called');
    error_log('Incoming tabs: ' . print_r($tabs, true));

    // 選択状態をチェック
    $is_selected = grandpay_is_selected();

    if ($is_selected) {
        error_log('GrandPay Settlement Module: ✅ Adding tab (module is selected)');
        $tabs['grandpay'] = 'GrandPay';
    } else {
        error_log('GrandPay Settlement Module: ❌ NOT adding tab (module not selected)');
    }

    error_log('Outgoing tabs: ' . print_r($tabs, true));
    return $tabs;
}

function grandpay_add_settlement_tab_content($settlement_selected) {
    error_log('GrandPay Settlement Module: grandpay_add_settlement_tab_content called with: ' . $settlement_selected);

    if ($settlement_selected !== 'grandpay') {
        error_log('GrandPay Settlement Module: Not displaying content (not grandpay tab)');
        return;
    }

    error_log('GrandPay Settlement Module: ✅ Displaying tab content');

    $options = get_option('usces_ex', array());
    $grandpay_settings = $options['grandpay'] ?? array();

?>
    <div style="background: #f0fff4; border: 2px solid #46b450; padding: 20px; border-radius: 8px; margin: 20px 0;">
        <h2 style="color: #46b450; margin-top: 0;">🎉 GrandPay設定タブが表示されました！</h2>
        <p style="font-size: 16px;"><strong>ドラッグ&ドロップとタブ表示が成功しました！</strong></p>
        <p>これで基本的なプラグイン構造が完成しています。</p>
    </div>

    <table class="settle_table" style="width: 100%; border-collapse: collapse;">
        <tr>
            <th style="background: #f9f9f9; padding: 12px; border: 1px solid #ddd; width: 200px;">GrandPay を利用する</th>
            <td style="padding: 12px; border: 1px solid #ddd;" colspan="2">
                <label>
                    <input name="grandpay[activate]" type="radio" value="on" <?php checked($grandpay_settings['activate'] ?? '', 'on'); ?> />
                    利用する
                </label><br>
                <label>
                    <input name="grandpay[activate]" type="radio" value="off" <?php checked($grandpay_settings['activate'] ?? '', 'off'); ?> />
                    利用しない
                </label>
            </td>
        </tr>
        <tr>
            <th style="background: #f9f9f9; padding: 12px; border: 1px solid #ddd;">決済方法名</th>
            <td style="padding: 12px; border: 1px solid #ddd;">
                <input name="grandpay[payment_name]" type="text" value="<?php echo esc_attr($grandpay_settings['payment_name'] ?? 'GrandPay決済'); ?>" size="30" />
            </td>
            <td style="padding: 12px; border: 1px solid #ddd;">フロント画面に表示される決済方法名</td>
        </tr>
        <tr>
            <th style="background: #f9f9f9; padding: 12px; border: 1px solid #ddd;">決済説明文</th>
            <td style="padding: 12px; border: 1px solid #ddd;">
                <textarea name="grandpay[payment_description]" rows="3" cols="50"><?php echo esc_textarea($grandpay_settings['payment_description'] ?? 'クレジットカードで安全にお支払いいただけます。'); ?></textarea>
            </td>
            <td style="padding: 12px; border: 1px solid #ddd;">フロント画面に表示される説明文</td>
        </tr>
        <tr>
            <th style="background: #f9f9f9; padding: 12px; border: 1px solid #ddd;">Tenant Key</th>
            <td style="padding: 12px; border: 1px solid #ddd;">
                <input name="grandpay[tenant_key]" type="text" value="<?php echo esc_attr($grandpay_settings['tenant_key'] ?? ''); ?>" size="50" placeholder="GrandPayから提供されたTenant Key" />
            </td>
            <td style="padding: 12px; border: 1px solid #ddd;">GrandPay API認証用</td>
        </tr>
        <tr>
            <th style="background: #f9f9f9; padding: 12px; border: 1px solid #ddd;">Client ID</th>
            <td style="padding: 12px; border: 1px solid #ddd;">
                <input name="grandpay[client_id]" type="text" value="<?php echo esc_attr($grandpay_settings['client_id'] ?? ''); ?>" size="50" placeholder="OAuth2 Client ID" />
            </td>
            <td style="padding: 12px; border: 1px solid #ddd;">OAuth2認証用のClient ID</td>
        </tr>
        <tr>
            <th style="background: #f9f9f9; padding: 12px; border: 1px solid #ddd;">Client Secret</th>
            <td style="padding: 12px; border: 1px solid #ddd;">
                <input name="grandpay[client_secret]" type="password" value="<?php echo esc_attr($grandpay_settings['client_secret'] ?? ''); ?>" size="50" placeholder="OAuth2 Client Secret" />
            </td>
            <td style="padding: 12px; border: 1px solid #ddd;">OAuth2認証用のClient Secret</td>
        </tr>
        <tr>
            <th style="background: #f9f9f9; padding: 12px; border: 1px solid #ddd;">Webhook Secret</th>
            <td style="padding: 12px; border: 1px solid #ddd;">
                <input name="grandpay[webhook_secret]" type="password" value="<?php echo esc_attr($grandpay_settings['webhook_secret'] ?? ''); ?>" size="50" placeholder="Webhook署名検証用Secret" />
            </td>
            <td style="padding: 12px; border: 1px solid #ddd;">Webhook署名検証用</td>
        </tr>
        <tr>
            <th style="background: #f9f9f9; padding: 12px; border: 1px solid #ddd;">テストモード</th>
            <td style="padding: 12px; border: 1px solid #ddd;" colspan="2">
                <label>
                    <input name="grandpay[test_mode]" type="radio" value="on" <?php checked($grandpay_settings['test_mode'] ?? '', 'on'); ?> />
                    テストモード（推奨）
                </label><br>
                <label>
                    <input name="grandpay[test_mode]" type="radio" value="off" <?php checked($grandpay_settings['test_mode'] ?? '', 'off'); ?> />
                    本番モード
                </label>
            </td>
        </tr>
        <tr>
            <th style="background: #f9f9f9; padding: 12px; border: 1px solid #ddd;">Webhook URL</th>
            <td style="padding: 12px; border: 1px solid #ddd;" colspan="2">
                <code style="background: #f0f0f0; padding: 8px; border-radius: 4px; display: block; word-break: break-all;">
                    <?php echo admin_url('admin-ajax.php?action=grandpay_webhook'); ?>
                </code>
                <p style="margin: 8px 0 0 0; color: #666; font-size: 14px;">
                    このURLをGrandPayの管理画面でWebhook URLとして設定してください。
                </p>
            </td>
        </tr>
    </table>

    <div style="background: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 6px; margin: 20px 0;">
        <h4 style="margin-top: 0; color: #0c5460;">🎉 基本実装完了！</h4>
        <p style="margin-bottom: 0;">
            ✅ プラグインの基本構造が完成<br>
            ✅ Welcartとの連携が正常に動作<br>
            ✅ 設定タブの表示・保存機能が実装<br>
            次は実際のGrandPay API連携の実装に進めます。
        </p>
    </div>
<?php
}

/**
 * 設定保存処理
 */
add_action('usces_action_admin_settlement_update', 'grandpay_save_settlement_settings');

function grandpay_save_settlement_settings() {
    if (!isset($_POST['grandpay'])) {
        return;
    }

    error_log('GrandPay Settlement Module: Saving settings');

    $grandpay_settings = $_POST['grandpay'];

    // バリデーション
    $grandpay_settings['activate'] = in_array($grandpay_settings['activate'] ?? '', array('on', 'off')) ? $grandpay_settings['activate'] : 'off';
    $grandpay_settings['test_mode'] = in_array($grandpay_settings['test_mode'] ?? '', array('on', 'off')) ? $grandpay_settings['test_mode'] : 'off';
    $grandpay_settings['payment_name'] = sanitize_text_field($grandpay_settings['payment_name'] ?? 'GrandPay決済');
    $grandpay_settings['payment_description'] = sanitize_textarea_field($grandpay_settings['payment_description'] ?? '');
    $grandpay_settings['tenant_key'] = sanitize_text_field($grandpay_settings['tenant_key'] ?? '');
    $grandpay_settings['client_id'] = sanitize_text_field($grandpay_settings['client_id'] ?? '');
    $grandpay_settings['client_secret'] = sanitize_text_field($grandpay_settings['client_secret'] ?? '');
    $grandpay_settings['webhook_secret'] = sanitize_text_field($grandpay_settings['webhook_secret'] ?? '');

    // 設定保存
    $options = get_option('usces_ex', array());
    $options['grandpay'] = $grandpay_settings;
    update_option('usces_ex', $options);

    // 個別オプションも保存
    update_option('welcart_grandpay_tenant_key', $grandpay_settings['tenant_key']);
    update_option('welcart_grandpay_client_id', $grandpay_settings['client_id']);
    update_option('welcart_grandpay_client_secret', $grandpay_settings['client_secret']);
    update_option('welcart_grandpay_webhook_secret', $grandpay_settings['webhook_secret']);
    update_option('welcart_grandpay_test_mode', $grandpay_settings['test_mode'] === 'on');

    // キャッシュクリア
    delete_transient('welcart_grandpay_access_token');
    delete_transient('welcart_grandpay_token_expires_at');

    error_log('GrandPay Settlement Module: Settings saved successfully');
    error_log('Saved settings: ' . print_r($grandpay_settings, true));
}

error_log('GrandPay Settlement Module: Debug-enhanced version initialization completed');
