/**
 * 安裝頁面 JS 工具
 *
 * 提供密碼強度檢測、資料庫連線測試等輔助函式。
 */
(function () {
    'use strict';

    // ========== 密碼強度檢測 ==========

    /**
     * 評估密碼強度
     *
     * @param {string} password
     * @returns {{ score: number, label: string, color: string }}
     */
    function evaluatePasswordStrength(password) {
        let score = 0;

        if (!password || password.length === 0) {
            return { score: 0, label: '尚未輸入', color: 'gray' };
        }

        // 長度計分
        if (password.length >= 8) score++;
        if (password.length >= 12) score++;
        if (password.length >= 16) score++;

        // 複雜度計分
        if (/[a-z]/.test(password)) score++;
        if (/[A-Z]/.test(password)) score++;
        if (/[0-9]/.test(password)) score++;
        if (/[^a-zA-Z0-9]/.test(password)) score++;

        // 扣分：連續重複字元
        if (/(.)\1{2,}/.test(password)) score--;

        // 扣分：常見弱密碼模式
        const weakPatterns = ['password', '123456', 'qwerty', 'admin', 'letmein'];
        if (weakPatterns.some(p => password.toLowerCase().includes(p))) {
            score = Math.max(score - 2, 0);
        }

        score = Math.max(0, Math.min(score, 7));

        if (score <= 1) return { score, label: '非常弱', color: 'red' };
        if (score <= 2) return { score, label: '弱', color: 'orange' };
        if (score <= 4) return { score, label: '中等', color: 'yellow' };
        if (score <= 5) return { score, label: '強', color: 'blue' };
        return { score, label: '非常強', color: 'green' };
    }

    /**
     * 綁定密碼強度指示器到 input 元素
     *
     * @param {string} inputSelector  密碼輸入框 CSS 選擇器
     * @param {string} meterSelector  強度指示器容器 CSS 選擇器
     */
    function bindPasswordStrengthMeter(inputSelector, meterSelector) {
        const input = document.querySelector(inputSelector);
        const meter = document.querySelector(meterSelector);

        if (!input || !meter) return;

        input.addEventListener('input', function () {
            const result = evaluatePasswordStrength(this.value);

            const colorMap = {
                gray:   'bg-gray-200',
                red:    'bg-red-500',
                orange: 'bg-orange-500',
                yellow: 'bg-yellow-500',
                blue:   'bg-blue-500',
                green:  'bg-green-500',
            };

            const percentage = Math.round((result.score / 7) * 100);
            const barClass = colorMap[result.color] || 'bg-gray-200';

            meter.innerHTML = `
                <div class="flex items-center justify-between text-xs mb-1">
                    <span class="text-gray-500">密碼強度</span>
                    <span class="font-medium">${result.label}</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-1.5">
                    <div class="${barClass} h-1.5 rounded-full transition-all duration-300"
                         style="width: ${percentage}%"></div>
                </div>
            `;
        });
    }

    // ========== 資料庫連線測試 ==========

    /**
     * 測試資料庫連線
     *
     * @param {string} url     後端測試連線 API 路徑
     * @param {object} config  資料庫設定 { host, port, database, username, password, prefix }
     * @returns {Promise<{ success: boolean, message: string }>}
     */
    async function testDatabaseConnection(url, config) {
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(config),
            });

            const data = await response.json();
            return {
                success: data.success === true,
                message: data.message || (data.success ? '連線成功' : '連線失敗'),
            };
        } catch (err) {
            return {
                success: false,
                message: '無法連接伺服器：' + err.message,
            };
        }
    }

    /**
     * 綁定測試連線按鈕
     *
     * @param {string} btnSelector     按鈕 CSS 選擇器
     * @param {string} resultSelector  結果顯示容器 CSS 選擇器
     * @param {string} apiUrl          後端 API 路徑
     * @param {Function} getConfig     取得設定值的回呼函式，回傳 config object
     */
    function bindTestConnectionButton(btnSelector, resultSelector, apiUrl, getConfig) {
        const btn = document.querySelector(btnSelector);
        const result = document.querySelector(resultSelector);

        if (!btn || !result) return;

        btn.addEventListener('click', async function () {
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = '測試中...';
            result.innerHTML = '';

            const config = typeof getConfig === 'function' ? getConfig() : {};
            const response = await testDatabaseConnection(apiUrl, config);

            if (response.success) {
                result.innerHTML = `
                    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded-lg text-sm mt-2">
                        ${escapeHtml(response.message)}
                    </div>`;
            } else {
                result.innerHTML = `
                    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded-lg text-sm mt-2">
                        ${escapeHtml(response.message)}
                    </div>`;
            }

            btn.disabled = false;
            btn.textContent = originalText;
        });
    }

    // ========== 表單驗證輔助 ==========

    /**
     * 驗證必填欄位
     *
     * @param {string} formSelector 表單 CSS 選擇器
     * @returns {boolean}
     */
    function validateRequiredFields(formSelector) {
        const form = document.querySelector(formSelector);
        if (!form) return false;

        const requiredInputs = form.querySelectorAll('[required]');
        let valid = true;

        requiredInputs.forEach(function (input) {
            // 移除先前的錯誤樣式
            input.classList.remove('border-red-500');

            if (!input.value || input.value.trim() === '') {
                input.classList.add('border-red-500');
                valid = false;
            }
        });

        return valid;
    }

    /**
     * 切換密碼可見性
     *
     * @param {string} inputSelector  密碼欄位 CSS 選擇器
     * @param {string} toggleSelector 切換按鈕 CSS 選擇器
     */
    function bindPasswordToggle(inputSelector, toggleSelector) {
        const input = document.querySelector(inputSelector);
        const toggle = document.querySelector(toggleSelector);

        if (!input || !toggle) return;

        toggle.addEventListener('click', function () {
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            toggle.textContent = isPassword ? '隱藏' : '顯示';
        });
    }

    // ========== 工具函式 ==========

    /**
     * HTML 跳脫（防止 XSS）
     *
     * @param {string} str
     * @returns {string}
     */
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ========== 匯出到全域 ==========

    window.KanbanInstall = {
        evaluatePasswordStrength,
        bindPasswordStrengthMeter,
        testDatabaseConnection,
        bindTestConnectionButton,
        validateRequiredFields,
        bindPasswordToggle,
        escapeHtml,
    };
})();
