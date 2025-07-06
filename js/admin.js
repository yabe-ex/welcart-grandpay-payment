jQuery(document).ready(function ($) {
    console.log('🔧 GrandPay Simple Admin Script Loaded');

    // 決済設定ページでのみ実行
    if (window.location.href.indexOf('usces_settlement') === -1) {
        console.log('ℹ️ Not on settlement page, skipping');
        return;
    }

    // 設定データを取得
    const settings = typeof grandpay_admin !== 'undefined' ? grandpay_admin : {};
    const isSelected = settings.is_selected == '1' || settings.is_selected === true;

    console.log('📊 Settings:', settings);
    console.log('📋 Is Selected:', isSelected);

    // GrandPayが選択されている場合のみタブを追加
    if (isSelected) {
        console.log('✅ GrandPay is selected, will add tab');
        addGrandPayTabSimple();
    } else {
        console.log('❌ GrandPay is not selected, skipping tab addition');
    }

    /**
     * シンプルなタブ追加（確実に動作する方式）
     */
    function addGrandPayTabSimple() {
        console.log('🚀 Adding GrandPay tab (simple method)');

        // まず、実際のタブ構造を調査
        debugTabStructure();

        // 方法1: WelcartPayタブを探してクローン
        if (addTabByCloning()) {
            console.log('✅ Tab added by cloning method');
            return;
        }

        // 方法2: 手動でタブ構造を作成
        if (addTabManually()) {
            console.log('✅ Tab added by manual method');
            return;
        }

        // 方法3: 最後の手段として、既存コンテンツの下に追加
        addContentDirectly();
        console.log('✅ Content added directly');
    }

    /**
     * 実際のタブ構造をデバッグ出力
     */
    function debugTabStructure() {
        console.log('=== 🔍 Tab Structure Debug ===');

        // 様々なセレクタで要素を検索
        const selectors = [
            '.ui-tabs',
            '.ui-tabs-nav',
            '.nav-tabs',
            '[role="tablist"]',
            'ul:has(li)',
            '*:contains("WelcartPay")',
            '*:contains("ペイジェント")'
        ];

        selectors.forEach((selector) => {
            const elements = $(selector);
            console.log(`${selector}: ${elements.length} found`);
            if (elements.length > 0) {
                console.log(`  First element:`, elements[0]);
                console.log(`  Classes:`, elements[0].className);
                console.log(`  Parent:`, elements.parent()[0]);
            }
        });

        // WelcartPayタブの詳細調査
        $('*:contains("WelcartPay")').each(function (i) {
            if ($(this).children().length === 0) {
                // テキストノードのみ
                console.log(`WelcartPay text ${i}:`, this);
                console.log(`  Tag:`, this.tagName);
                console.log(`  Parent:`, $(this).parent()[0]);
                console.log(`  Parent classes:`, $(this).parent()[0].className);
                console.log(`  Grandparent:`, $(this).parent().parent()[0]);
            }
        });

        console.log('=== End Tab Structure Debug ===');
    }

    /**
     * 方法1: 既存タブをクローンして追加
     */
    function addTabByCloning() {
        console.log('🔄 Trying tab cloning method...');

        // jQuery UIタブの構造を確認
        const $tabsContainer = $('#uscestabs_settlement');
        const $tabsList = $tabsContainer.find('.ui-tabs-nav');

        if ($tabsContainer.length === 0 || $tabsList.length === 0) {
            console.log('❌ jQuery UI tabs structure not found');
            return false;
        }

        console.log('✅ Found jQuery UI tabs structure');

        // 既存のGrandPayタブがあるかチェック
        if ($tabsList.find('a:contains("GrandPay")').length > 0) {
            console.log('ℹ️ GrandPay tab already exists');
            return true;
        }

        // WelcartPayタブを参考にして新しいタブを作成
        const $welcartTabLi = $tabsList.find('li').last(); // WelcartPayのli要素

        if ($welcartTabLi.length === 0) {
            console.log('❌ WelcartPay tab not found');
            return false;
        }

        console.log('✅ Found WelcartPay tab for reference');

        // 新しいGrandPay専用のタブとパネルIDを生成
        const grandpayTabId = 'uscestabs_grandpay';
        const grandpayAnchorId = 'ui-id-grandpay';

        // 新しいタブ（li要素）を作成
        const $newTabLi = $('<li></li>')
            .attr({
                role: 'tab',
                tabindex: '-1',
                'aria-controls': grandpayTabId,
                'aria-labelledby': grandpayAnchorId,
                'aria-selected': 'false',
                'aria-expanded': 'false'
            })
            .addClass('ui-tabs-tab ui-corner-top ui-state-default ui-tab grandpay-tab-li');

        // 新しいアンカー（a要素）を作成
        const $newAnchor = $('<a></a>')
            .attr({
                href: '#' + grandpayTabId,
                tabindex: '-1',
                id: grandpayAnchorId
            })
            .addClass('ui-tabs-anchor')
            .text('GrandPay');

        // アンカーをタブに追加
        $newTabLi.append($newAnchor);

        // タブリストに新しいタブを追加
        $tabsList.append($newTabLi);

        // 対応するタブパネルを作成
        const $newPanel = $('<div></div>')
            .attr({
                id: grandpayTabId,
                'aria-labelledby': grandpayAnchorId,
                role: 'tabpanel'
            })
            .addClass('ui-tabs-panel ui-corner-bottom ui-widget-content')
            .css({
                display: 'none',
                'aria-hidden': 'true'
            })
            .html(getGrandPayTabPanelHTML());

        // パネルをタブコンテナに追加
        $tabsContainer.append($newPanel);

        // jQuery UIタブシステムに新しいタブを登録
        try {
            $tabsContainer.tabs('refresh');
            console.log('✅ jQuery UI tabs refreshed successfully');
        } catch (e) {
            console.log('⚠️ jQuery UI refresh failed, using manual events:', e);

            // 手動でクリックイベントを設定
            $newAnchor.on('click', function (e) {
                e.preventDefault();
                console.log('🖱️ GrandPay tab clicked');

                // 全てのタブを非アクティブに
                $tabsList.find('li').removeClass('ui-tabs-active ui-state-active').attr('aria-selected', 'false').attr('aria-expanded', 'false');

                // 全てのパネルを非表示に
                $tabsContainer.find('.ui-tabs-panel').hide().attr('aria-hidden', 'true');

                // このタブとパネルをアクティブに
                $newTabLi.addClass('ui-tabs-active ui-state-active').attr('aria-selected', 'true').attr('aria-expanded', 'true');

                $newPanel.show().attr('aria-hidden', 'false');
            });
        }

        console.log('✅ GrandPay tab added as independent tab');
        return true;
    }

    /**
     * GrandPayタブパネル専用のHTML生成
     */
    function getGrandPayTabPanelHTML() {
        const savedSettings = settings.settings || {};

        return `
            <div class="settlement_service">
                <span class="service_title">GrandPay</span>
            </div>

            <form action="" method="post" name="grandpay_form" id="grandpay_form">
                <table class="settle_table">
                    <tbody>
                        <tr>
                            <th><a class="explanation-label">GrandPay を利用する</a></th>
                            <td>
                                <label>
                                    <input name="grandpay[activate]" type="radio" value="on" ${savedSettings.activate === 'on' ? 'checked' : ''}>
                                    <span>利用する</span>
                                </label><br>
                                <label>
                                    <input name="grandpay[activate]" type="radio" value="off" ${savedSettings.activate !== 'on' ? 'checked' : ''}>
                                    <span>利用しない</span>
                                </label>
                            </td>
                        </tr>
                        <tr class="explanation">
                            <td colspan="2">GrandPay決済サービスを利用するかどうかを選択してください。</td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label">決済方法名</a></th>
                            <td>
                                <input name="grandpay[payment_name]" type="text" value="${
                                    savedSettings.payment_name || 'クレジットカード決済'
                                }" class="regular-text">
                            </td>
                        </tr>
                        <tr class="explanation">
                            <td colspan="2">フロント画面に表示される決済方法名を設定してください。</td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label">決済説明文</a></th>
                            <td>
                                <textarea name="grandpay[payment_description]" rows="3" cols="50" class="regular-text">${
                                    savedSettings.payment_description || 'クレジットカードで安全にお支払いいただけます。'
                                }</textarea>
                            </td>
                        </tr>
                        <tr class="explanation">
                            <td colspan="2">フロント画面に表示される決済方法の説明文を設定してください。</td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label">Tenant Key</a></th>
                            <td>
                                <input name="grandpay[tenant_key]" type="text" value="${
                                    savedSettings.tenant_key || ''
                                }" class="regular-text" placeholder="GrandPayから提供されたTenant Key">
                            </td>
                        </tr>
                        <tr class="explanation">
                            <td colspan="2">GrandPayから提供されたTenant Keyを入力してください。</td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label">Client ID</a></th>
                            <td>
                                <input name="grandpay[client_id]" type="text" value="${
                                    savedSettings.client_id || ''
                                }" class="regular-text" placeholder="OAuth2 Client ID">
                            </td>
                        </tr>
                        <tr class="explanation">
                            <td colspan="2">GrandPayから提供されたOAuth2 Client IDを入力してください。</td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label">Client Secret</a></th>
                            <td>
                                <input name="grandpay[client_secret]" type="password" value="${
                                    savedSettings.client_secret || ''
                                }" class="regular-text" placeholder="OAuth2 Client Secret">
                            </td>
                        </tr>
                        <tr class="explanation">
                            <td colspan="2">GrandPayから提供されたOAuth2 Client Secretを入力してください。</td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label">Webhook Secret</a></th>
                            <td>
                                <input name="grandpay[webhook_secret]" type="password" value="${
                                    savedSettings.webhook_secret || ''
                                }" class="regular-text" placeholder="Webhook署名検証用Secret">
                            </td>
                        </tr>
                        <tr class="explanation">
                            <td colspan="2">GrandPayから提供されたWebhook署名検証用Secretを入力してください。</td>
                        </tr>

                        <tr>
                            <th><a class="explanation-label">動作環境</a></th>
                            <td>
                                <label>
                                    <input name="grandpay[test_mode]" type="radio" value="on" ${savedSettings.test_mode !== 'off' ? 'checked' : ''}>
                                    <span>テスト環境</span>
                                </label><br>
                                <label>
                                    <input name="grandpay[test_mode]" type="radio" value="off" ${savedSettings.test_mode === 'off' ? 'checked' : ''}>
                                    <span>本番環境</span>
                                </label>
                            </td>
                        </tr>
                        <tr class="explanation">
                            <td colspan="2">動作環境を切り替えます。テスト時は必ずテスト環境を選択してください。</td>
                        </tr>
                    </tbody>
                </table>

                <input type="hidden" name="acting" value="grandpay">
                <input name="usces_option_update" type="submit" class="button button-primary" value="GrandPay の設定を更新する">
                <input type="hidden" id="wc_nonce" name="wc_nonce" value="${$('#wc_nonce').val() || ''}">
                <input type="hidden" name="_wp_http_referer" value="/wp-admin/admin.php?page=usces_settlement">
            </form>

            <div class="settle_exp">
                <p><strong>GrandPay クレジットカード決済</strong><br>
                <a href="https://payment-gateway.asia/" target="_blank">GrandPay の詳細はこちら »</a></p>
                <p>GrandPayは、アジア圏でのクレジットカード決済に特化した決済サービスです。<br>
                セキュアで信頼性の高い決済処理により、安全なオンライン決済を提供します。</p>
                <p>本番環境で利用する場合は、必ず正規のSSL証明書を設置してください。</p>
                <p><strong>Webhook URL:</strong><br>
                <code>${window.location.origin}/wp-admin/admin-ajax.php?action=grandpay_webhook</code><br>
                この URL を GrandPay の管理画面で Webhook URL として設定してください。</p>
            </div>
        `;
    }

    /**
     * 方法2: 手動でタブ構造を作成
     */
    function addTabManually() {
        console.log('🔨 Trying manual tab creation...');

        // 適切な挿入場所を探す
        const $insertTarget = $('h1, h2, .wrap h1, .wrap h2')
            .filter(function () {
                const text = $(this).text();
                return text.includes('決済') || text.includes('クレジット') || text.includes('settlement');
            })
            .first();

        if ($insertTarget.length === 0) {
            console.log('❌ Insert target not found');
            return false;
        }

        console.log('📍 Insert target found:', $insertTarget[0]);

        // 手動タブ構造のHTML
        const tabHtml = `
            <div class="grandpay-manual-tabs" style="margin: 20px 0; border: 1px solid #ddd; border-radius: 6px; overflow: hidden;">
                <div class="tab-header" style="background: #f8f9fa; border-bottom: 1px solid #ddd; padding: 0;">
                    <button type="button" class="grandpay-manual-tab-btn" data-target="grandpay-content"
                            style="padding: 12px 20px; border: none; background: #007cba; color: white; cursor: pointer; font-weight: 500;">
                        GrandPay設定
                    </button>
                </div>
                <div id="grandpay-content" class="tab-content" style="padding: 20px; display: block;">
                    ${getGrandPayContentHTML()}
                </div>
            </div>
        `;

        // 挿入
        $insertTarget.after(tabHtml);

        // クリックイベント（念のため）
        $('.grandpay-manual-tab-btn').on('click', function () {
            console.log('🖱️ Manual GrandPay tab clicked');
            showGrandPayContent();
        });

        console.log('✅ Manual tab creation completed');
        return true;
    }

    /**
     * 方法3: 既存コンテンツの下に直接追加
     */
    function addContentDirectly() {
        console.log('📝 Adding content directly...');

        // フォームの最後に追加
        const $form = $('form').first();
        if ($form.length > 0) {
            const directHtml = `
                <div class="grandpay-direct-content" style="margin: 30px 0; padding: 20px; border: 2px solid #28a745; border-radius: 8px; background: #f8fff9;">
                    <h3 style="color: #28a745; margin-top: 0;">🚀 GrandPay設定</h3>
                    <p style="color: #155724; margin-bottom: 20px;">
                        <strong>タブとして統合できませんでしたが、設定は正常に機能します。</strong>
                    </p>
                    ${getGrandPayContentHTML()}
                </div>
            `;

            $form.append(directHtml);
            console.log('✅ Content added directly to form');
        }
    }

    /**
     * GrandPayコンテンツの表示
     */
    function showGrandPayContent() {
        console.log('📝 Showing GrandPay content');

        // 既存のコンテンツエリアを探す
        let $contentArea = $('#grandpay-content');

        if ($contentArea.length === 0) {
            // フォールバック: 適当な場所に作成
            $contentArea = $('<div id="grandpay-content" style="margin: 20px 0; padding: 20px; border: 1px solid #ddd; background: white;"></div>');
            $('form').first().append($contentArea);
        }

        $contentArea.html(getGrandPayContentHTML()).show();
    }

    /**
     * GrandPay設定フォームのHTML生成
     */
    function getGrandPayContentHTML() {
        const savedSettings = settings.settings || {};

        return `
            <form id="grandpay-settings-form" method="post" style="background: white; padding: 0;">
                <table class="form-table" style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <th scope="row" style="padding: 15px; border: 1px solid #ddd; background: #f9f9f9; width: 200px; font-weight: 600;">
                            GrandPay を利用する
                        </th>
                        <td style="padding: 15px; border: 1px solid #ddd;">
                            <fieldset>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input name="grandpay[activate]" type="radio" value="on" ${
                                        savedSettings.activate === 'on' ? 'checked' : ''
                                    } style="margin-right: 8px;" />
                                    利用する
                                </label>
                                <label style="display: block;">
                                    <input name="grandpay[activate]" type="radio" value="off" ${
                                        savedSettings.activate !== 'on' ? 'checked' : ''
                                    } style="margin-right: 8px;" />
                                    利用しない
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding: 15px; border: 1px solid #ddd; background: #f9f9f9; font-weight: 600;">
                            決済方法名
                        </th>
                        <td style="padding: 15px; border: 1px solid #ddd;">
                            <input name="grandpay[payment_name]" type="text" value="${savedSettings.payment_name || 'GrandPay決済'}"
                                   class="regular-text" style="padding: 6px 8px; width: 300px;" />
                            <p class="description">フロント画面に表示される決済方法名</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding: 15px; border: 1px solid #ddd; background: #f9f9f9; font-weight: 600;">
                            Tenant Key
                        </th>
                        <td style="padding: 15px; border: 1px solid #ddd;">
                            <input name="grandpay[tenant_key]" type="text" value="${savedSettings.tenant_key || ''}"
                                   class="regular-text" style="padding: 6px 8px; width: 400px;"
                                   placeholder="GrandPayから提供されたTenant Key" />
                            <p class="description">GrandPay API認証用のテナントキー</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding: 15px; border: 1px solid #ddd; background: #f9f9f9; font-weight: 600;">
                            テストモード
                        </th>
                        <td style="padding: 15px; border: 1px solid #ddd;">
                            <fieldset>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input name="grandpay[test_mode]" type="radio" value="on" ${
                                        savedSettings.test_mode !== 'off' ? 'checked' : ''
                                    } style="margin-right: 8px;" />
                                    <span style="color: #007cba;">テストモード（推奨）</span>
                                </label>
                                <label style="display: block;">
                                    <input name="grandpay[test_mode]" type="radio" value="off" ${
                                        savedSettings.test_mode === 'off' ? 'checked' : ''
                                    } style="margin-right: 8px;" />
                                    <span style="color: #d63638;">本番モード</span>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <div style="margin: 20px 0; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px;">
                    <h4 style="margin-top: 0; color: #856404;">💾 設定保存について</h4>
                    <p style="margin-bottom: 10px; color: #856404;">
                        設定を変更したら、ページ下部の「設定を更新する」ボタンをクリックして保存してください。
                    </p>
                    <button type="button" onclick="saveGrandPaySettings()"
                            style="background: #007cba; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
                        設定をメインフォームに反映
                    </button>
                </div>
            </form>
        `;
    }

    /**
     * 設定保存処理（グローバル関数として定義）
     */
    window.saveGrandPaySettings = function () {
        console.log('💾 Saving GrandPay settings...');

        const formData = {};
        $('#grandpay-settings-form')
            .find('input, textarea, select')
            .each(function () {
                const $field = $(this);
                const name = $field.attr('name');
                const value = $field.val();

                if (name) {
                    if ($field.attr('type') === 'radio' || $field.attr('type') === 'checkbox') {
                        if ($field.is(':checked')) {
                            formData[name] = value;
                        }
                    } else {
                        formData[name] = value;
                    }
                }
            });

        console.log('📊 Collected form data:', formData);

        // メインフォームに設定データを追加
        const $mainForm = $('form').not('#grandpay-settings-form').first();

        if ($mainForm.length === 0) {
            alert('メインフォームが見つかりません。ページを再読み込みしてください。');
            return;
        }

        // 既存のGrandPay inputsを削除
        $mainForm.find('input[name^="grandpay["]').remove();

        // 新しい設定を追加
        $.each(formData, function (name, value) {
            if (value) {
                $('<input type="hidden">').attr('name', name).val(value).appendTo($mainForm);
                console.log('➕ Added to main form:', name, '=', value);
            }
        });

        alert('設定をメインフォームに反映しました。「設定を更新する」ボタンで保存してください。');
        console.log('✅ Settings prepared for saving');
    };

    console.log('🎉 GrandPay Simple Admin initialization completed');
});
