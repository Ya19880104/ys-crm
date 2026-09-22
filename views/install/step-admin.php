<?php $view->layout('install'); ?>
<?php $old = \YangSheep\CRM\Core\Session::getFlash('old_input') ?? []; ?>

<h2 class="text-xl font-bold text-white mb-6">Step 5：建立管理員帳號</h2>
<p class="text-gray-400 mb-6">建立第一個超級管理員帳號。</p>

<?php if (\YangSheep\CRM\Core\Session::hasFlash('error')): ?>
<div class="bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-lg mb-6">
    <?= \YangSheep\CRM\Core\e(\YangSheep\CRM\Core\Session::getFlash('error')) ?>
</div>
<?php endif; ?>

<form method="POST" action="/install/step-admin" x-data="adminForm()">
    <?= \YangSheep\CRM\Core\Csrf::field() ?>

    <div class="space-y-4">
        <div>
            <label class="block text-sm text-gray-300 mb-1">使用者名稱</label>
            <input type="text" name="username" value="<?= \YangSheep\CRM\Core\e($old['username'] ?? 'admin') ?>" required
                   pattern="[a-zA-Z0-9_]{4,50}"
                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none">
            <p class="text-gray-500 text-xs mt-1">4-50 字元，僅允許英文、數字、底線</p>
        </div>

        <div>
            <label class="block text-sm text-gray-300 mb-1">Email</label>
            <input type="email" name="email" value="<?= \YangSheep\CRM\Core\e($old['email'] ?? '') ?>" required
                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none">
        </div>

        <div>
            <label class="block text-sm text-gray-300 mb-1">顯示名稱</label>
            <input type="text" name="display_name" value="<?= \YangSheep\CRM\Core\e($old['display_name'] ?? '管理員') ?>" required
                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none">
        </div>

        <div>
            <label class="block text-sm text-gray-300 mb-1">密碼</label>
            <input type="password" name="password" required minlength="10"
                   x-model="password" @input="checkStrength()"
                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none">
            <div class="strength-bar mt-2">
                <div class="strength-bar-fill" :class="strengthClass"></div>
            </div>
            <p class="text-xs mt-1" :class="strengthColor" x-text="strengthText"></p>
        </div>

        <div>
            <label class="block text-sm text-gray-300 mb-1">確認密碼</label>
            <input type="password" name="password_confirmation" required
                   class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-white focus:border-blue-500 focus:outline-none">
        </div>
    </div>

    <div class="mt-8 flex items-center justify-between">
        <a href="/install/step-seed" class="text-gray-400 hover:text-white transition">&larr; 返回</a>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg font-medium transition">
            建立帳號並繼續 &rarr;
        </button>
    </div>
</form>

<script>
function adminForm() {
    return {
        password: '',
        strengthClass: '',
        strengthText: '至少 10 字元，含大小寫、數字、特殊字元',
        strengthColor: 'text-gray-500',
        checkStrength() {
            const p = this.password;
            let score = 0;
            if (p.length >= 10) score++;
            if (p.length >= 14) score++;
            if (/[a-z]/.test(p) && /[A-Z]/.test(p)) score++;
            if (/\d/.test(p)) score++;
            if (/[^a-zA-Z0-9]/.test(p)) score++;

            if (score <= 1) {
                this.strengthClass = 'strength-weak';
                this.strengthText = '弱';
                this.strengthColor = 'text-red-400';
            } else if (score === 2) {
                this.strengthClass = 'strength-fair';
                this.strengthText = '尚可';
                this.strengthColor = 'text-orange-400';
            } else if (score === 3) {
                this.strengthClass = 'strength-good';
                this.strengthText = '良好';
                this.strengthColor = 'text-yellow-400';
            } else {
                this.strengthClass = 'strength-strong';
                this.strengthText = '強';
                this.strengthColor = 'text-emerald-400';
            }
        }
    };
}
</script>
