<?php

/**
 * GrandPay決済モジュール（最小テスト版）
 */

// 直接アクセスを防ぐ
if (!defined('ABSPATH')) {
    exit;
}

// 必ずログを出力
error_log('GrandPay Settlement Module: File loaded - ' . date('Y-m-d H:i:s'));

// 決済処理のテスト
if (isset($_REQUEST['acting']) && $_REQUEST['acting'] == 'grandpay') {
    error_log('GrandPay Settlement Module: Acting=grandpay detected');

    global $usces;

    // 簡単なテスト処理
    $order_id = $usces->get_order_id();
    error_log("GrandPay Settlement Module: Order ID = $order_id");

    // テスト用の成功処理
    if ($order_id) {
        $usces->change_order_status($order_id, 'ordercompletion');
        error_log('GrandPay Settlement Module: Order status changed to completion');
    }

    // 完了ページにリダイレクト
    wp_redirect($usces->url['complete_page'] . '?order_id=' . $order_id);
    exit;
}

error_log('GrandPay Settlement Module: File processing completed');
