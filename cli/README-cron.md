# YS CRM 排程（Cron）設定說明

對應架構設計 §7.10（統一通知/到期引擎）、§7.8/§7.9（週期帳務）、§9 Phase 11。

## 統一入口

```
php cli/cron.php <command> [today]
```

| command | 作用 | 冪等 |
|---------|------|------|
| `tick` | 建議的單一主機排程入口；每日到期時跑 `run`，其餘每輪跑 `maintenance` | ✅ |
| `run` | 執行全部（expiry + recurring + reminders + invoice + mail + retention） | ✅ |
| `maintenance` | 高頻維護：先電子發票（回收 stale claim + 重試 due invoice），再寄信佇列 | ✅ |
| `expiry` | 掃 hosting/website：已到期翻 `status='expired'`；到期前 N 天 queue 到期提醒 | ✅ |
| `recurring` | 週期帳單：`generateDue`（產生到期帳單）+ `autoChargeDue`（自動扣款/待人工提醒） | ✅ |
| `reminders` | 報價待簽（送出逾期未簽）、款項待收（pending 付款逾期）→ queue 提醒 | ✅ |
| `invoice` | 僅電子發票維護：回收 stale claim + 重試 due invoice | ✅ |
| `mail` | 處理 `email_queue`：`claimBatch` → Mailer 寄送 → `markSent`/`markFailed`（含重試上限 3） | ✅ |

`[today]` 為選用的「今日」覆寫（`YYYY-MM-DD`），供測試或補跑：

```
php cli/cron.php run 2026-06-30
```

所有子命令皆**冪等、可重複執行不會重複出帳/重複寄信**，並輸出每步處理筆數。

## 主機面板 Cron Jobs 建議

> 路徑請用實際絕對路徑。若 docroot 為 `public_html/`，app 檔（`src/`、`cli/`）在其上層。
> 以 `which php` 或主機面板提供的 PHP 路徑為準。

| 頻率 | crontab | 指令 |
|------|---------|------|
| 每 5 分鐘（唯一一條） | `*/5 * * * *` | `php /home/<user>/ys-crm/cli/cron.php tick` |

說明：
- **每一輪 tick** 都會處理 due/stale 電子發票，接著處理寄信佇列；因此失敗發票不會等到下一次每日工作。
- **每日工作** 到時才執行一次 `run`。`run` 本身已包含 invoice 與 mail，因此該輪不會再執行 maintenance 或重複寄信。
- 到期掃描、週期帳單、提醒掃描與保留清理仍只在每日 `run` 進行；錯過執行時刻會在下一個 tick 補跑。
- 整個 tick 分派出的工作各以一個 cron named lock / cron run record 執行；mail 仍以 `queued→sending` 狀態轉移防止重複寄送。

## URL 觸發替代方案（CLI 為主）

若主機僅能以 URL 觸發排程，使用 token 保護的端點：

```
GET https://<your-domain>/cron/run?token=<CRON_TOKEN>&cmd=tick
```

- `cmd` 可為 `tick` | `run` | `maintenance` | `expiry` | `recurring` | `reminders` | `invoice` | `mail`（預設 `run`）。
- URL 端點不接受 `today` 覆寫；需補跑指定日期時請走有權限、step-up 與稽核的後台入口。
- token 也可改放 header：`X-Cron-Token: <CRON_TOKEN>`。

**設定 token**：於 `{prefix}settings` 寫入 group=`cron`, key=`cron_token`（建議 32+ 隨機字元）。
未設定、漏帶或錯誤 token 時端點**一律回 404（fail-closed）**，不洩漏端點是否存在。token 比對採 constant-time（`hash_equals`）。

範例（以 crontab + curl 觸發 URL，每 5 分鐘執行單一入口）：

```
*/5 * * * * curl -fsS "https://<your-domain>/cron/run?token=<CRON_TOKEN>&cmd=tick" > /dev/null
```

## 刻意延後的接點

- **真實 SMTP 寄送**：需 user 於「系統設定 → 通知設定」填寫 SMTP 主機/埠/帳號/密碼/加密方式。
  未填時 `mail` 不報致命錯，信件留在 `email_queue`（後台「通知記錄」可檢視/重送）。
- **綁卡自動扣款**：`autoChargeDue` 目前無真實綁卡（卡片 token 屬 P4-2 portal），
  到達扣款時點一律標「待人工」並 queue 提醒，不假裝扣款；P4-2 上線後於 `RecurringService::autoChargeDue`
  的接點呼叫 `provider->chargeWithToken` 真正扣款。
