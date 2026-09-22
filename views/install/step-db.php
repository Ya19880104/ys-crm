<?php $view->layout('install'); ?>

<h2 class="text-xl font-bold text-white mb-6">Step 2：資料庫設定</h2>
<p class="text-gray-400 mb-6">請填入 MySQL 資料庫連線資訊。若資料庫不存在，系統將自動建立。</p>

<?php if (\YangSheep\CRM\Core\Session::hasFlash('error')): ?>
<div class="bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-lg mb-6">
    <?= \YangSheep\CRM\Core\e(\YangSheep\CRM\Core\Session::getFlash('error')) ?>
</div>
<?php endif; ?>

<form id="dbForm" method="POST" action="/install/step-db" x-data="dbForm()" @submit.prevent="testDb()">
    <?= \YangSheep\CRM\Core\Csrf::field() ?>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm text-gray-300 mb-1">主機 (Host)</label>
            <input type="text" name="db_host" value="127.0.0.1" required
                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm text-gray-300 mb-1">連接埠 (Port)</label>
            <input type="text" name="db_port" value="3306" required
                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none">
        </div>
        <div class="col-span-2">
            <label class="block text-sm text-gray-300 mb-1">資料庫名稱</label>
            <input type="text" name="db_database" value="ys_crm" required
                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm text-gray-300 mb-1">使用者名稱</label>
            <input type="text" name="db_username" value="root" required
                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm text-gray-300 mb-1">密碼</label>
            <input type="password" name="db_password" value=""
                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm text-gray-300 mb-1">字元集</label>
            <input type="text" name="db_charset" value="utf8mb4" required
                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm text-gray-300 mb-1">資料表前綴</label>
            <input type="text" name="db_prefix" value="kb_" required
                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none">
        </div>
        <div class="col-span-2">
            <label class="block text-sm text-gray-300 mb-1">應用程式時區</label>
            <select name="site_timezone" required
                    class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none">
                <option value="Asia/Taipei" selected>Asia/Taipei (UTC+8)</option>
                <option value="Asia/Tokyo">Asia/Tokyo (UTC+9)</option>
                <option value="Asia/Shanghai">Asia/Shanghai (UTC+8)</option>
                <option value="Asia/Hong_Kong">Asia/Hong_Kong (UTC+8)</option>
                <option value="UTC">UTC</option>
            </select>
            <p class="text-xs text-gray-400 mt-1">
                此值會在建立第一筆資料前固定，讓 PHP 與資料庫的時間一致；安裝後請勿任意更改。
            </p>
        </div>
    </div>

    <!-- 測試結果 -->
    <div x-show="testResult !== null" class="mt-4 px-4 py-3 rounded-lg text-sm"
         :class="testResult?.success ? 'bg-emerald-900/50 border border-emerald-700 text-emerald-300' : 'bg-red-900/50 border border-red-700 text-red-300'">
        <span x-text="testResult?.message"></span>
        <!-- .env 寫入位置資訊 -->
        <template x-if="testResult?.success && testResult?.env_path">
            <div class="mt-2 pt-2 border-t border-emerald-700/50">
                <template x-if="testResult?.env_secure">
                    <p class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
                        </svg>
                        <span>.env 已安全存放在 web root 之外</span>
                    </p>
                </template>
                <template x-if="!testResult?.env_secure">
                    <p class="flex items-center gap-2 text-yellow-300">
                        <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        <span>.env 存放在專案目錄內，安裝完成後可手動搬移至更安全的位置</span>
                    </p>
                </template>
                <p class="text-xs text-gray-400 mt-1" x-text="'路徑：' + testResult.env_path + '/.env'"></p>
            </div>
        </template>
    </div>

    <div class="mt-8 flex items-center justify-between">
        <a href="/install" class="text-gray-400 hover:text-white transition">&larr; 返回</a>
        <button type="submit" :disabled="testing"
                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg font-medium transition disabled:opacity-50 disabled:cursor-not-allowed">
            <span x-show="!testing">測試連線並繼續 &rarr;</span>
            <span x-show="testing" class="flex items-center gap-2">
                <span class="spinner w-4 h-4"></span> 測試中...
            </span>
        </button>
    </div>
</form>

<script>
function dbForm() {
    return {
        testing: false,
        testResult: null,
        async testDb() {
            if (this.testing) return;
            this.testing = true;
            this.testResult = null;
            const form = document.getElementById('dbForm');
            const formData = new FormData(form);

            try {
                const response = await fetch('/install/step-db', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                });
                const data = await response.json();
                this.testResult = data;
                if (data.success) {
                    window.location.assign('/install/step-migrate');
                }
            } catch (e) {
                this.testResult = { success: false, message: '連線測試失敗' };
            } finally {
                this.testing = false;
            }
        }
    };
}
</script>
