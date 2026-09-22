(function (global) {
    'use strict';
    global.YsEinvoiceSettings = {
        selectTab: function (tabs, fallback, search, hash) {
            var query = new URLSearchParams(search).get('tab');
            if (tabs.includes(query)) return query;
            try {
                var fragment = decodeURIComponent((hash || '').replace(/^#/, ''));
                if (tabs.includes(fragment)) return fragment;
            } catch (_) { /* Invalid URL encoding uses the known default. */ }
            return tabs.includes(fallback) ? fallback : (tabs[0] || '');
        },
        test: async function (state, token, fetcher) {
            state.testing = true;
            state.result = null;
            try {
                var response = await (fetcher || global.fetch.bind(global))('/admin/settings/test-einvoice', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: '_csrf_token=' + encodeURIComponent(token)
                });
                var data = await response.json();
                if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error('Invalid response');
                if (response.status === 401) {
                    state.result = { success: false, message: '登入狀態已失效，請重新登入後再手動測試。', action: 'login', href: '/login' };
                } else if (response.status === 403 && typeof data.redirect === 'string' && /^\/admin\/reauth\?return=[^\s\\]*$/.test(data.redirect)) {
                    state.result = { success: false, message: '請重新驗證身分；完成後會回到電子發票設定，請再手動測試。', action: 'reauth', href: data.redirect };
                } else if (response.status === 403) {
                    state.result = { success: false, message: '驗證失敗，請重新整理頁面後再試；若仍無法操作，請確認權限。', action: 'refresh' };
                } else if (response.status >= 200 && response.status < 300 && typeof data.success === 'boolean' && typeof data.message === 'string') {
                    state.result = { success: data.success, message: data.message };
                } else {
                    throw new Error('Invalid response');
                }
            } catch (_) {
                state.result = { success: false, message: '無法讀取測試結果，請確認網路連線後再試。' };
            } finally {
                state.testing = false;
            }
        }
    };
})(window);
