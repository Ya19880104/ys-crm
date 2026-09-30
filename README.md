# YS CRM 1.0.0

YANGSHEEP DESIGN 的客戶關係管理系統——獨立 PHP 應用，下載、放進主機、跑安裝精靈即可使用。

> **開發狀態：電子發票與 SLP 支付（SHOPLINE Payments）尚未開發完成。** 本專案目前規劃重構為一套通用的 CRM 系統。如有使用需求，歡迎發訊息與我聯繫。

- 客戶與聯絡人、客戶專區（客戶自行登入查看報價與付款）
- 報價單：線上簽署存證、列印／另存 PDF、公開連結（預設 30 天自動關閉，可就地調整或設為需密碼）
- 線上付款（PayUni）與週期帳務；電子發票及 SLP 支付（SHOPLINE Payments）尚未開發完成
- 工作看板、操作與登入稽核、管理者兩階段驗證、敏感操作再認證

## 系統需求

| 項目 | 需求 |
| --- | --- |
| PHP | 8.5 以上，需 `pdo_mysql`、`mbstring`、`openssl`、`json` 擴充（安裝精靈會逐項檢測） |
| 資料庫 | MySQL 8.4（Community Server） |
| Web 伺服器 | Nginx 或 Apache；所有不存在的路徑須導向 `index.php` |
| 連線 | HTTPS |
| 權限 | `storage/`、`uploads/` 可寫；網站根目錄的**上一層**可寫（安裝精靈會把程式搬到那裡，不對外公開） |

## 安裝

1. **上傳**：把 `ys-crm-1.0.0.zip` 解壓縮到網站根目錄（例如 `public_html/`）。壓縮檔內沒有外層資料夾，解開後 `index.php` 就在網站根目錄。`index.php` 與 `installer-move.php` 必須在同一層。
2. **網址改寫**：
   - Apache：套件內的 `.htaccess` 已處理，確認已啟用 `mod_rewrite` 與 `AllowOverride`。
   - Nginx：在站台設定加入 `try_files $uri $uri/ /index.php?$query_string;`。
3. **設定安裝用網域 `INSTALL_HOST`**：首次安裝時，系統只信任你明確指定的網域（避免有人偽造 Host 標頭搶先安裝）。未設定或不相符時，安裝頁會回 400。擇一設定：
   - Nginx＋PHP-FPM：`fastcgi_param INSTALL_HOST crm.example.com;`
   - PHP-FPM pool：`env[INSTALL_HOST] = crm.example.com`
   - Apache：`SetEnv INSTALL_HOST crm.example.com`

   非標準連接埠寫成 `crm.example.com:8443`，不要加 `https://` 或路徑。安裝精靈第 2 步會把它寫成正式的 `APP_URL`，之後可以移除。
4. **執行安裝精靈**：瀏覽 `https://你的網域/install`。
   1. 環境檢測：若出現「偵測到敏感檔案在 web root 內」，按「**搬移至安全位置**」，程式目錄會移到網站根目錄上一層，網站根目錄只留 `index.php`、`installer-move.php`、`.htaccess`、`robots.txt`、`assets/`、`uploads/`。
   2. 資料庫連線 → 建立資料表 → 初始資料 → 建立第一位管理員 → 網站設定 → 完成。
5. **設定排程**：在主機面板的 Cron Jobs 新增**一條**，每 5 分鐘執行一次（路徑請換成實際的程式目錄，也就是 `cli/` 所在位置）：

   ```bash
   php /path/to/ys-crm/cli/cron.php tick
   ```

   到期提醒、週期帳單與寄信由這一條排程驅動；後台「排程」頁可看到上次執行時間與結果。
6. **登入後台**完成系統設定：公司資料、寄信（SMTP）、金流。電子發票尚未開發完成，請勿用於正式開立。系統設定屬敏感頁面，進入時會要求再次輸入密碼。

## 安全注意事項

- **上傳目錄不可執行 PHP**：系統會在 `uploads/` 放置防護設定，但 Nginx 不讀 `.htaccess`，請在站台設定中禁止上傳目錄執行 PHP，並以安裝後的系統檢測結果為準。
- **機密檔不要放在網站根目錄**：`.env`、部署設定、資料庫傾印、憑證等若放在網站根目錄，Nginx 會直接把它們當靜態檔送出。
- **公開報價連結**：「任何取得連結者皆可檢視」的連結預設 30 天後自動關閉；關閉或到期後重新公開會產生新連結。報價頁已送出 `noindex`，但這只是給搜尋引擎的指示，不是存取控制——機密報價請改用「需密碼」或「須客戶登入」。
- 安裝完成後，系統以 `storage/install.lock` 封閉安裝精靈；請勿手動刪除 `.env`、`install.lock` 或安裝過程中的暫存檔來重跑精靈。

## 安裝中斷時

若安裝途中遺失瀏覽器 session（安裝頁回 503），不要刪檔重來。請在主機上於程式目錄執行：

```bash
php cli/recover-install.php reset-empty --confirm
```

依指示取得一次性復原碼後，到原站的 `/install/recover` 輸入即可接續。程式目錄已搬移時，加上 `--web-root=實際網站根目錄`。

## 著作權

© YANGSHEEP DESIGN。
