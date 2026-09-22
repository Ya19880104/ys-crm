<?php $view->layout('install'); ?>

<h2 class="text-xl font-bold text-white mb-6">Step 3：建立資料表</h2>
<p class="text-gray-400 mb-6">系統將建立所需的 14 張資料表。</p>

<div x-data="migrationRunner()">
    <!-- 進度列表 -->
    <div class="space-y-2" id="migrationList">
        <template x-for="item in migrations" :key="item.name">
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
                <template x-if="item.status === 'error'">
                    <svg class="w-5 h-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                </template>
                <span class="text-gray-300" x-text="item.label"></span>
            </div>
        </template>
    </div>

    <!-- 錯誤訊息 -->
    <div x-show="errorMessage" class="mt-4 bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-lg text-sm">
        <span x-text="errorMessage"></span>
    </div>

    <div class="mt-8 flex items-center justify-between">
        <a href="/install/step-db" class="text-gray-400 hover:text-white transition">&larr; 返回</a>
        <div class="flex gap-3">
            <button x-show="!running && !completed" @click="runMigrations()"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg font-medium transition">
                開始建立資料表
            </button>
            <button x-show="errorMessage && !running" @click="runMigrations()"
                    class="bg-yellow-600 hover:bg-yellow-500 text-white px-6 py-2.5 rounded-lg font-medium transition">
                重試
            </button>
            <a x-show="completed" href="/install/step-seed"
               class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg font-medium transition">
                下一步：匯入初始資料 &rarr;
            </a>
        </div>
    </div>
</div>

<script>
function migrationRunner() {
    return {
        running: false,
        completed: false,
        errorMessage: null,
        migrations: [
            { name: '001', label: 'migrations 追蹤表', status: 'pending' },
            { name: '002', label: 'roles 角色表', status: 'pending' },
            { name: '003', label: 'permissions 權限表', status: 'pending' },
            { name: '004', label: 'role_permissions 角色權限對應表', status: 'pending' },
            { name: '005', label: 'users 使用者表', status: 'pending' },
            { name: '006', label: 'boards 看板表', status: 'pending' },
            { name: '007', label: 'stages 狀態表', status: 'pending' },
            { name: '008', label: 'cards 卡片表', status: 'pending' },
            { name: '009', label: 'card_logs 卡片紀錄表', status: 'pending' },
            { name: '010', label: 'card_comments 卡片留言表', status: 'pending' },
            { name: '011', label: 'public_wishes 公開提案表', status: 'pending' },
            { name: '012', label: 'settings 系統設定表', status: 'pending' },
            { name: '013', label: 'api_configs API 設定表', status: 'pending' },
            { name: '014', label: 'login_attempts 登入嘗試表', status: 'pending' },
            { name: '015', label: 'audit_logs 稽核紀錄表', status: 'pending' },
        ],
        async runMigrations() {
            this.running = true;
            this.errorMessage = null;

            // 動畫效果：逐一標記 running
            for (let i = 0; i < this.migrations.length; i++) {
                this.migrations[i].status = 'running';
                await new Promise(r => setTimeout(r, 100));
            }

            try {
                const formData = new FormData();
                formData.append('_csrf_token', '<?= \YangSheep\CRM\Core\e($csrf) ?>');

                const response = await fetch('/install/step-migrate', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                });
                const data = await response.json();

                if (data.success) {
                    this.migrations.forEach(m => m.status = 'done');
                    this.completed = true;
                } else {
                    this.errorMessage = data.message;
                    this.migrations.forEach(m => {
                        if (m.status === 'running') m.status = 'error';
                    });
                }
            } catch (e) {
                this.errorMessage = '執行 Migration 時發生錯誤';
                this.migrations.forEach(m => {
                    if (m.status === 'running') m.status = 'error';
                });
            } finally {
                this.running = false;
            }
        }
    };
}
</script>
