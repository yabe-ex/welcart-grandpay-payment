<?php

/**
 * GrandPay決済モジュール（最小限テスト版）
 */

// 直接アクセスを防ぐ
if (!defined('ABSPATH')) {
    exit;
}

// 強制ログ出力（最優先）
error_log('🚀🚀🚀 MINIMAL GRANDPAY MODULE LOADED - ' . date('Y-m-d H:i:s'));
error_log('🚀🚀🚀 File path: ' . __FILE__);

/**
 * Welcart標準: 決済モジュール情報取得関数
 */
function usces_get_settlement_info_grandpay() {
    error_log('🚀🚀🚀 usces_get_settlement_info_grandpay() called');

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

/**
 * 最小限: タブ追加関数
 */
function grandpay_add_settlement_tab($tabs) {
    error_log('🚀🚀🚀 grandpay_add_settlement_tab() called');
    error_log('🚀🚀🚀 Input tabs: ' . print_r($tabs, true));

    $tabs['grandpay'] = 'GrandPay';

    error_log('🚀🚀🚀 Output tabs: ' . print_r($tabs, true));
    return $tabs;
}

/**
 * 最小限: タブ内容表示
 */
function grandpay_add_settlement_tab_content($settlement_selected) {
    error_log('🚀🚀🚀 grandpay_add_settlement_tab_content() called');
    error_log('🚀🚀🚀 settlement_selected: ' . $settlement_selected);

    if ($settlement_selected !== 'grandpay') {
        error_log('🚀🚀🚀 Not grandpay tab, skipping');
        return;
    }

    error_log('🚀🚀🚀 DISPLAYING GRANDPAY TAB CONTENT');

    $options = get_option('usces_ex', array());
    $grandpay_settings = $options['grandpay'] ?? array();

    echo '<div style="background: #d4edda; border: 2px solid #28a745; padding: 20px; margin: 20px 0; border-radius: 8px;">';
    echo '<h2 style="color: #155724; margin-top: 0;">🎉 GrandPay設定タブ表示成功！</h2>';
    echo '<p style="color: #155724; font-size: 16px;"><strong>最小限テスト版が正常に動作しています。</strong></p>';
    echo '</div>';

    echo '<table class="settle_table" style="width: 100%; border-collapse: collapse;">';
    echo '<tr>';
    echo '<th style="background: #f8f9fa; padding: 12px; border: 1px solid #ddd; width: 200px;">GrandPay を利用する</th>';
    echo '<td style="padding: 12px; border: 1px solid #ddd;" colspan="2">';
    echo '<label><input name="grandpay[activate]" type="radio" value="on"' . checked($grandpay_settings['activate'] ?? '', 'on', false) . ' /> 利用する</label><br>';
    echo '<label><input name="grandpay[activate]" type="radio" value="off"' . checked($grandpay_settings['activate'] ?? '', 'off', false) . ' /> 利用しない</label>';
    echo '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th style="background: #f8f9fa; padding: 12px; border: 1px solid #ddd;">決済方法名</th>';
    echo '<td style="padding: 12px; border: 1px solid #ddd;">';
    echo '<input name="grandpay[payment_name]" type="text" value="' . esc_attr($grandpay_settings['payment_name'] ?? 'GrandPay決済') . '" size="30" />';
    echo '</td>';
    echo '<td style="padding: 12px; border: 1px solid #ddd;">フロント画面に表示される決済方法名</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th style="background: #f8f9fa; padding: 12px; border: 1px solid #ddd;">テストモード</th>';
    echo '<td style="padding: 12px; border: 1px solid #ddd;" colspan="2">';
    echo '<label><input name="grandpay[test_mode]" type="radio" value="on"' . checked($grandpay_settings['test_mode'] ?? '', 'on', false) . ' /> テストモード</label><br>';
    echo '<label><input name="grandpay[test_mode]" type="radio" value="off"' . checked($grandpay_settings['test_mode'] ?? '', 'off', false) . ' /> 本番モード</label>';
    echo '</td>';
    echo '</tr>';
    echo '</table>';
}

/**
 * 設定保存処理
 */
function grandpay_save_settlement_settings() {
    error_log('🚀🚀🚀 grandpay_save_settlement_settings() called');

    if (!isset($_POST['grandpay'])) {
        error_log('🚀🚀🚀 No grandpay settings in POST');
        return;
    }

    $grandpay_settings = $_POST['grandpay'];

    // 簡単なバリデーション
    $grandpay_settings['activate'] = isset($grandpay_settings['activate']) ? $grandpay_settings['activate'] : 'off';
    $grandpay_settings['test_mode'] = isset($grandpay_settings['test_mode']) ? $grandpay_settings['test_mode'] : 'off';
    $grandpay_settings['payment_name'] = isset($grandpay_settings['payment_name']) ? sanitize_text_field($grandpay_settings['payment_name']) : 'GrandPay決済';

    // 設定保存
    $options = get_option('usces_ex', array());
    $options['grandpay'] = $grandpay_settings;
    $result = update_option('usces_ex', $options);

    error_log('🚀🚀🚀 Settings saved. Result: ' . ($result ? 'SUCCESS' : 'FAILED'));
    error_log('🚀🚀🚀 Saved settings: ' . print_r($grandpay_settings, true));
}

// フィルター登録
add_filter('usces_filter_settlement_tab_title', 'grandpay_add_settlement_tab', 10);
add_filter('usces_filter_settlement_tab_body', 'grandpay_add_settlement_tab_content', 10);
add_action('usces_action_admin_settlement_update', 'grandpay_save_settlement_settings', 10);

error_log('🚀🚀🚀 MINIMAL GRANDPAY MODULE INITIALIZATION COMPLETED');
error_log('🚀🚀🚀 Functions registered:');
error_log('🚀🚀🚀 - usces_get_settlement_info_grandpay: ' . (function_exists('usces_get_settlement_info_grandpay') ? 'YES' : 'NO'));
error_log('🚀🚀🚀 - grandpay_add_settlement_tab: ' . (function_exists('grandpay_add_settlement_tab') ? 'YES' : 'NO'));
