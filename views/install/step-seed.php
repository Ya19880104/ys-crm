<?php $view->layout('install'); ?>

<h2 class="text-xl font-bold text-white mb-6">Step 4：匯入初始資料</h2>
<p class="text-gray-400 mb-6">系統將匯入預設角色、權限、看板、狀態等初始資料。</p>

<div x-data="seederRunner()">
    <div class="space-y-2">
        <template x-for="item in seeders" :key="item.name">
            <div class="flex items-center gap-3 py-2 px-3 rounded bg-slate-700/50 text-sm">
                <template x-if="item.status === 'pending'">
                    <span class="w-5 h-5 rounded-full bg-slate-600"></span>
                </template>
                <template x-if="item.status === 'running'">
                    <span class="migration-spinner"></span>
                </template>
                <template x-if="item.status === 'done'">
                    <svg class="w-5 h-5 text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                </template>
                <span class="text-gray-300" x-text="item.label"></span>
                <span x-show="item.count > 0" class="text-gray-500 text-xs" x-text="'(' + item.count + ' 筆)'"></span>
            </div>
        </template>
    </div>

    <div x-show="errorMessage" class="mt-4 bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-lg text-sm">
        <span x-text="errorMessage"></span>
    </div>

    <div class="mt-8 flex items-center justify-between">
        <a href="/install/step-migrate" class="text-gray-400 hover:text-white transition">&larr; 返回</a>
        <div class="flex gap-3">
            <button x-show="!running && !completed" @click="runSeeders()"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg font-medium transition">
                開始匯入
            </button>
            <a x-show="completed" href="/install/step-admin"
               class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg font-medium transition">
                下一步：建立管理員 &rarr;
            </a>
        </div>
    </div>
</div>

<script>
function seederRunner() {
    return {
        running: false,
        completed: false,
        errorMessage: null,
        seeders: [
            { name: 'roles', label: '角色資料', status: 'pending', count: 0 },
            { name: 'permissions', label: '權限資料', status: 'pending', count: 0 },
            { name: 'role_permissions', label: '角色權限對應', status: 'pending', count: 0 },
            { name: 'boards', label: '預設看板', status: 'pending', count: 0 },
            { name: 'stages', label: '預設狀態', status: 'pending', count: 0 },
            { name: 'settings', label: '系統設定', status: 'pending', count: 0 },
        ],
        async runSeeders() {
            this.running = true;
            this.errorMessage = null;
            this.seeders.forEach(s => s.status = 'running');

            try {
                const formData = new FormData();
                formData.append('_csrf_token', '<?= \YangSheep\CRM\Core\e($csrf) ?>');

                const response = await fetch('/install/step-seed', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                });
                const data = await response.json();

                if (data.success) {
                    const results = data.results || {};
                    this.seeders.forEach(s => {
                        s.status = 'done';
                        s.count = results[s.name] || 0;
                    });
                    this.completed = true;
                } else {
                    this.errorMessage = data.message;
                    this.seeders.forEach(s => s.status = 'pending');
                }
            } catch (e) {
                this.errorMessage = '匯入初始資料時發生錯誤';
                this.seeders.forEach(s => s.status = 'pending');
            } finally {
                this.running = false;
            }
        }
    };
}
</script>
