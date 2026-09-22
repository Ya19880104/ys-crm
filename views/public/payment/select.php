<?php
/**
 * 付款方式選擇頁（公開，§7.9；對齊 Node 版 views/quote/pay.ejs）。
 *
 * 流程：報價公開頁簽署後 → 點「前往付款」(GET /q/{token}/pay) → 本頁 → 選信用卡 / 虛擬 ATM
 *       → POST /q/{token}/pay（method=credit|atm，CSRF 群組內）→ PublicPaymentController::pay 發動付款。
 *
 * 米色棕色文件風（與公開報價頁一致），深藍主行動鈕，嚴禁綠色。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $quote     含 quote_number / total / currency / title
 * @var string $token     access_token
 * @var array  $company   我方公司資訊（logo/name）
 */

use function YangSheep\CRM\Core\e;

$token   = (string) ($token ?? ($quote['access_token'] ?? ''));
$cur     = (string) ($quote['currency'] ?? 'TWD');
$amount  = number_format((float) ($quote['total'] ?? 0), 0);
$quoteNo = (string) ($quote['quote_number'] ?? '');
$title   = (string) ($quote['title'] ?? '');
?>

<div class="pay-select-outer mx-auto">

    <!-- 返回 -->
    <div class="mb-4">
        <a href="/q/<?= e($token) ?>" class="pay-back">← 回報價單</a>
    </div>

    <article class="pay-card">
        <header class="pay-card-head">
            <h1>線上付款</h1>
            <p class="pay-card-sub">報價單 <?= e($quoteNo) ?><?= $title !== '' ? '・' . e($title) : '' ?></p>
        </header>

        <div class="pay-amount-wrap">
            <p class="pay-amount-label">應付金額</p>
            <p class="pay-amount"><?= e($cur) ?> <?= e($amount) ?></p>
        </div>

        <h2 class="pay-methods-heading">選擇付款方式</h2>

        <div class="pay-methods">
            <!-- 信用卡：POST /q/{token}/pay，method=credit + bind_card -->
            <form method="POST" action="/q/<?= e($token) ?>/pay" class="pay-method-form">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                <input type="hidden" name="method" value="credit">
                <label class="pay-bind-row">
                    <input type="checkbox" name="bind_card" value="1">
                    <span>儲存卡片以便日後自動扣款（訂閱型必勾）</span>
                </label>
                <button type="submit" class="pay-method-btn">
                    <span class="pm-icon" aria-hidden="true">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M3 10h18M7 15h2m-4 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    </span>
                    <span class="pm-title">信用卡</span>
                    <span class="pm-desc">支援 VISA / MasterCard / JCB</span>
                </button>
            </form>

            <!-- 虛擬 ATM：POST /q/{token}/pay，method=atm -->
            <form method="POST" action="/q/<?= e($token) ?>/pay" class="pay-method-form">
                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                <input type="hidden" name="method" value="atm">
                <button type="submit" class="pay-method-btn">
                    <span class="pm-icon" aria-hidden="true">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M4 10h16M4 6l8-3 8 3M6 10v7m4-7v7m4-7v7m4-7v7M4 21h16"/></svg>
                    </span>
                    <span class="pm-title">虛擬 ATM</span>
                    <span class="pm-desc">取得繳款帳號臨櫃／ATM 轉帳</span>
                </button>
            </form>
        </div>

        <p class="pay-secure">將透過金流商安全處理付款，本系統不會儲存您的卡號。</p>
    </article>
</div>

<style>
    :root {
        --doc-brand: #6b4f30; --doc-brand-2: #8a6d44;
        --doc-bg: #f7f0e6; --doc-line: #e3d8c7; --doc-muted: #8a7c6a; --doc-text: #3d3935;
    }
    .pay-select-outer { max-width: 720px; padding: 8px 0 40px; }
    .pay-back { color: var(--doc-muted); font-size: 14px; text-decoration: none; }
    .pay-back:hover { color: var(--doc-text); }
    .pay-card {
        background: #fff; border: 1px solid var(--doc-line); border-radius: 14px;
        box-shadow: 0 2px 10px rgba(0,0,0,.05); padding: 32px 32px 28px;
    }
    .pay-card-head h1 { margin: 0; font-size: 22px; font-weight: 700; color: var(--doc-text); letter-spacing: 2px; }
    .pay-card-sub { margin: 6px 0 0; color: var(--doc-muted); font-size: 13.5px; }
    .pay-amount-wrap {
        margin: 22px 0 8px; padding: 20px; text-align: center;
        background: var(--doc-bg); border-radius: 12px;
    }
    .pay-amount-label { margin: 0; color: var(--doc-muted); font-size: 13px; }
    .pay-amount { margin: 6px 0 0; font-size: 38px; font-weight: 800; color: var(--doc-text); letter-spacing: 1px; }
    .pay-methods-heading { margin: 24px 0 12px; font-size: 15px; font-weight: 700; color: var(--doc-brand); }
    .pay-methods { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: end; }
    .pay-method-form { display: flex; flex-direction: column; gap: 8px; }
    .pay-bind-row { display: flex; align-items: flex-start; gap: 8px; font-size: 12.5px; color: var(--doc-muted); cursor: pointer; }
    .pay-bind-row input { margin-top: 2px; }
    .pay-method-btn {
        display: flex; flex-direction: column; align-items: center; gap: 6px;
        width: 100%; padding: 24px 16px;
        background: #fff; border: 1.5px solid var(--doc-line); border-radius: 12px;
        cursor: pointer; transition: border-color .15s, box-shadow .15s, transform .15s;
    }
    .pay-method-btn:hover { border-color: var(--doc-brand-2); box-shadow: 0 4px 14px rgba(107,79,48,.12); transform: translateY(-1px); }
    .pay-method-btn:focus-visible { outline: 2px solid var(--doc-brand-2); outline-offset: 2px; }
    .pm-icon { color: var(--doc-brand); }
    .pm-title { font-size: 16px; font-weight: 700; color: var(--doc-text); }
    .pm-desc { font-size: 12.5px; color: var(--doc-muted); }
    .pay-secure { margin: 18px 0 0; text-align: center; color: var(--doc-muted); font-size: 12px; }
    @media (max-width: 560px) {
        .pay-methods { grid-template-columns: 1fr; }
        .pay-card { padding: 24px 18px; }
    }
</style>
