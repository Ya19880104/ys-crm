<?php

declare(strict_types=1);

namespace YangSheep\CRM\Install;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\RequestOrigin;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;
use YangSheep\CRM\Database\Seeders\DatabaseSeeder;

class InstallController extends Controller
{
    /**
     * 驗證安裝步驟順序（防止跳步攻擊）
     */
    private function requireStep(int $minStep): void
    {
        $currentStep = Session::get('install_step', 1);
        if ($currentStep < $minStep) {
            $this->redirect('/install');
        }
    }

    /**
     * Mutation 必須只在它所屬的當前步驟執行。
     *
     * requireStep() 適合 GET 頁面（允許回看已完成步驟），但 POST 若只檢查下限，
     * Step 6 的 session 仍可重播 Step 2 並覆寫 .env。副作用端點因此使用精確比對，
     * 同時拒絕跳步與重播。
     */
    private function requireMutationStep(int $expectedStep): void
    {
        if ((int) Session::get('install_step', 1) !== $expectedStep) {
            Session::flash('error', '安裝步驟已失效，請從目前流程重新繼續。');
            $this->redirect('/install');
        }
    }

    /**
     * Step 1：環境檢測（GET — 唯讀偵測，不做任何檔案系統變更）
     */
    public function index(): void
    {
        $checker = new EnvironmentChecker();
        $results = $checker->check();
        // 沿用同一份結果：check() 會建立 uploads/ 並寫入防護設定，不必跑第二次。
        $allPassed = $checker->allPassed($results);
        $structureMoved = Session::getFlash('structure_moved') ?? false;

        $this->render('install/index', [
            'title'          => '環境檢測',
            'currentStep'    => 1,
            'results'        => $results,
            'allPassed'      => $allPassed,
            'structureMoved' => $structureMoved,
            'csrf'           => Csrf::token(),
        ]);
    }

    /**
     * Step 1：搬移檔案結構（POST — 需 CSRF 驗證）
     */
    public function moveStructure(): void
    {
        $this->requireMutationStep(1);

        if (!defined('IS_FLAT_STRUCTURE') || !IS_FLAT_STRUCTURE) {
            Session::flash('error', '目錄結構已是安全模式，無需搬移。');
            $this->redirect('/install');
            return;
        }

        $checker = new EnvironmentChecker();
        $result = $checker->performMove();

        if ($result === true) {
            Session::flash('structure_moved', true);
        } else {
            Session::flash('error', '搬移失敗：' . $result);
        }

        $this->redirect('/install');
    }

    /**
     * Step 2：資料庫設定頁面
     */
    public function stepDb(): void
    {
        $this->render('install/step-db', [
            'title'       => '資料庫設定',
            'currentStep' => 2,
            'csrf'        => Csrf::token(),
        ]);
    }

    /**
     * Step 2：測試連線並寫入 .env
     */
    public function testConnection(): void
    {
        $this->requireMutationStep(1);

        $data = $this->request->only([
            'db_host', 'db_port', 'db_database', 'db_username', 'db_password', 'db_charset', 'db_prefix',
            'site_timezone',
        ]);

        $config = [
            'host'     => $data['db_host'] ?? '127.0.0.1',
            'port'     => $data['db_port'] ?? '3306',
            'database' => $data['db_database'] ?? 'ys_crm',
            'username' => $data['db_username'] ?? 'root',
            'password' => $data['db_password'] ?? '',
            'charset'  => $data['db_charset'] ?? 'utf8mb4',
            'prefix'   => $data['db_prefix'] ?? 'ys_crm_',
        ];

        try {
            // 時區必須在第一個 migration/admin timestamp 之前固定；Step 6 才選會讓
            // 同一資料庫混入兩種牆上時間。
            $appTimezone = EnvWriter::normalizeAppTimeZone($data['site_timezone'] ?? null);

            // 測試連線（同時建立資料庫若不存在）
            Database::testConnection($config, $appTimezone);

            // 先以 atomic marker 取得此 installer session 的 ownership，避免兩個匿名
            // request 同時進入 Step 2、互相覆寫 .env。
            $markers = new InstallationMarkers();
            $priorToken = (string) Session::get(InstallationMarkers::SESSION_KEY, '');
            $markerToken = $markers->begin($priorToken !== '' ? $priorToken : null);
            Session::set(InstallationMarkers::SESSION_KEY, $markerToken);

            try {
                // 初次 .env 必須立刻保存已通過 bootstrap Host gate 的站台 URL；
                // 否則下一個 request 會把真實網域當成 unknown Host 擋掉。
                $envValues = EnvWriter::generateDefaults($config, RequestOrigin::baseUrl(), $appTimezone);
                EnvWriter::write($envValues);
            } catch (\Throwable $e) {
                $markers->abandon($markerToken);
                Session::remove(InstallationMarkers::SESSION_KEY);
                throw $e;
            }
            $envInfo = EnvWriter::getEnvPath();

            // 儲存安裝進度
            Session::set('install_step', 3);
            Session::set('install_db_config', $config);
            Session::set('install_env_path', $envInfo['path']);
            Session::set('install_env_secure', $envInfo['secure']);

            // AJAX 回應
            if ($this->request->isAjax()) {
                $this->json([
                    'success'    => true,
                    'message'    => '連線成功！',
                    'env_path'   => $envInfo['display'],
                    'env_secure' => $envInfo['secure'],
                ]);
                return;
            }

            $this->redirect('/install/step-migrate');
        } catch (\Throwable $e) {
            if ($this->request->isAjax()) {
                $this->json(['success' => false, 'message' => '連線失敗：' . $e->getMessage()], 422);
                return;
            }

            Session::flash('error', '資料庫連線失敗：' . $e->getMessage());
            $this->redirect('/install/step-db');
        }
    }

    /**
     * Step 3：Migration 頁面
     */
    public function stepMigrate(): void
    {
        $this->requireStep(3);
        $this->render('install/step-migrate', [
            'title'       => '建立資料表',
            'currentStep' => 3,
            'csrf'        => Csrf::token(),
        ]);
    }

    /**
     * Step 3：執行 Migration
     */
    public function runMigrations(): void
    {
        $this->requireMutationStep(3);

        try {
            // 重新載入 .env（從自動偵測的位置）
            $envFile = EnvWriter::findEnvFile();
            if ($envFile !== null) {
                \YangSheep\CRM\Core\DotEnv::load(dirname($envFile));
            }

            $config = require CONFIG_PATH . '/database.php';
            $pdo = Database::testConnection($config);
            $pdo->exec("USE `{$config['database']}`");

            $runner = new MigrationRunner($pdo, $config['prefix']);
            $results = $runner->runAll();

            Session::set('install_step', 4);

            if ($this->request->isAjax()) {
                $this->json(['success' => true, 'results' => $results]);
                return;
            }

            $this->redirect('/install/step-seed');
        } catch (\Throwable $e) {
            if ($this->request->isAjax()) {
                $this->json(['success' => false, 'message' => $e->getMessage()], 422);
                return;
            }

            Session::flash('error', 'Migration 失敗：' . $e->getMessage());
            $this->redirect('/install/step-migrate');
        }
    }

    /**
     * Step 4：Seeder 頁面
     */
    public function stepSeed(): void
    {
        $this->requireStep(4);
        $this->render('install/step-seed', [
            'title'       => '匯入初始資料',
            'currentStep' => 4,
            'csrf'        => Csrf::token(),
        ]);
    }

    /**
     * Step 4：執行 Seeder
     */
    public function runSeeders(): void
    {
        $this->requireMutationStep(4);

        try {
            // 重新載入 .env（從自動偵測的位置）
            $envFile = EnvWriter::findEnvFile();
            if ($envFile !== null) {
                \YangSheep\CRM\Core\DotEnv::load(dirname($envFile));
            }

            $config = require CONFIG_PATH . '/database.php';
            $pdo = Database::testConnection($config);
            $pdo->exec("USE `{$config['database']}`");

            $seeder = new DatabaseSeeder($pdo, $config['prefix']);
            $results = $seeder->run();

            Session::set('install_step', 5);

            if ($this->request->isAjax()) {
                $this->json(['success' => true, 'results' => $results]);
                return;
            }

            $this->redirect('/install/step-admin');
        } catch (\Throwable $e) {
            if ($this->request->isAjax()) {
                $this->json(['success' => false, 'message' => $e->getMessage()], 422);
                return;
            }

            Session::flash('error', 'Seeder 失敗：' . $e->getMessage());
            $this->redirect('/install/step-seed');
        }
    }

    /**
     * Step 5：管理員帳號頁面
     */
    public function stepAdmin(): void
    {
        $this->requireStep(5);
        $this->render('install/step-admin', [
            'title'       => '建立管理員帳號',
            'currentStep' => 5,
            'csrf'        => Csrf::token(),
        ]);
    }

    /**
     * Step 5：建立管理員
     */
    public function createAdmin(): void
    {
        $this->requireMutationStep(5);

        $data = $this->request->only([
            'username', 'email', 'display_name', 'password', 'password_confirmation'
        ]);

        $validator = Validator::make($data, [
            'username'     => 'required|string|min:4|max:50',
            'email'        => 'required|email',
            'display_name' => 'required|string|min:2|max:100',
            'password'     => 'required|string|min:10|confirmed',
        ]);

        if ($validator->fails()) {
            Session::flash('error', $validator->firstError());
            Session::flash('old_input', $data);
            $this->redirect('/install/step-admin');
            return;
        }

        try {
            $envFile = EnvWriter::findEnvFile();
            if ($envFile !== null) {
                \YangSheep\CRM\Core\DotEnv::load(dirname($envFile));
            }

            $config = require CONFIG_PATH . '/database.php';
            $pdo = Database::testConnection($config);
            $pdo->exec("USE `{$config['database']}`");
            $prefix = $config['prefix'];

            // 若 DB commit 後、file-session 寫入前 process 中斷，相同表單可精確
            // readback 後繼續；任何 identity/password 差異都明確拒絕。
            (new AdminBootstrapper())->createOrVerify($pdo, $prefix, [
                'username' => (string) $data['username'],
                'email' => (string) $data['email'],
                'display_name' => (string) $data['display_name'],
                'password' => (string) $data['password'],
            ]);

            Session::set('install_step', 6);
            Session::set('install_admin_username', $data['username']);
            Session::set('install_admin_email', $data['email']);

            $this->redirect('/install/step-config');
        } catch (\Throwable $e) {
            Session::flash('error', '建立管理員失敗：' . $e->getMessage());
            Session::flash('old_input', $data);
            $this->redirect('/install/step-admin');
        }
    }

    /**
     * Step 6：基本設定頁面
     */
    public function stepConfig(): void
    {
        $this->requireStep(6);

        // 自動偵測站台網址。
        // Step 2 已把 process env 的 INSTALL_HOST 固化成 APP_URL；這裡只顯示
        // RequestOrigin 的可信設定值，不從 HTTP_HOST / SERVER_NAME 猜測。
        $detectedUrl = \YangSheep\CRM\Core\RequestOrigin::baseUrl();

        $this->render('install/step-config', [
            'title'       => '基本設定',
            'currentStep' => 6,
            'csrf'        => Csrf::token(),
            'detectedUrl' => $detectedUrl,
            'siteTimezone' => (require CONFIG_PATH . '/app.php')['timezone'],
        ]);
    }

    /**
     * Step 6：儲存設定
     */
    public function saveConfig(): void
    {
        $this->requireMutationStep(6);

        $data = $this->request->only([
            'site_name',
            'turnstile_site_key', 'turnstile_secret_key',
        ]);

        try {
            $envFile = EnvWriter::findEnvFile();
            if ($envFile !== null) {
                \YangSheep\CRM\Core\DotEnv::load(dirname($envFile));
            }

            $config = require CONFIG_PATH . '/database.php';
            $pdo = Database::testConnection($config);
            $pdo->exec("USE `{$config['database']}`");
            $prefix = $config['prefix'];
            $appConfig = require CONFIG_PATH . '/app.php';
            $appTimezone = (string) ($appConfig['timezone'] ?? 'Asia/Taipei');
            // Canonical APP_URL 已在 Step 2 由受信任的 INSTALL_HOST 固定。Step 6
            // 只顯示它，不接受 POST 改寫，避免跨資源中斷後 Host gate 鎖死 recovery。
            $siteUrl = rtrim(trim((string) ($_ENV['APP_URL'] ?? '')), '/');
            if (preg_match('#^https?://[a-z0-9.-]+(?::[1-9][0-9]{0,4})?$#i', $siteUrl) !== 1) {
                throw new \InvalidArgumentException('站台網址格式無效。');
            }

            // 更新 settings 表
            $updates = [
                ['site', 'site_name',      $data['site_name'] ?? 'YS CRM'],
                ['site', 'site_url',       $siteUrl],
                ['site', 'site_timezone',  $appTimezone],
                ['turnstile', 'site_key',  $data['turnstile_site_key'] ?? ''],
                ['turnstile', 'secret_key',$data['turnstile_secret_key'] ?? ''],
            ];

            if ($envFile === null) {
                throw new \RuntimeException('.env 檔案不存在');
            }
            (new InstallationConfigCommitter())->apply(
                $pdo,
                $prefix,
                $updates,
                $envFile,
                [
                    'TURNSTILE_SITE_KEY' => (string) ($data['turnstile_site_key'] ?? ''),
                    'TURNSTILE_SECRET_KEY' => (string) ($data['turnstile_secret_key'] ?? ''),
                ]
            );

            Session::set('install_step', 7);
            $this->redirect('/install/complete');
        } catch (\Throwable $e) {
            Session::flash('error', '儲存設定失敗：' . $e->getMessage());
            $this->redirect('/install/step-config');
        }
    }

    /**
     * Step 7：安裝完成
     */
    public function complete(): void
    {
        $this->requireStep(7);

        $markers = new InstallationMarkers();
        $markerToken = (string) Session::get(InstallationMarkers::SESSION_KEY, '');
        try {
            $markers->complete($markerToken);
        } catch (\Throwable $e) {
            Session::flash('error', '無法封閉安裝流程：' . $e->getMessage());
            $this->redirect('/install/step-config');
            return;
        }

        // 取得 .env 位置資訊
        $envInfo = EnvWriter::getEnvPath();

        $this->render('install/complete', [
            'title'         => '安裝完成',
            'currentStep'   => 7,
            'adminUsername'  => Session::get('install_admin_username', 'admin'),
            'adminEmail'    => Session::get('install_admin_email', ''),
            'dbName'        => $_ENV['DB_DATABASE'] ?? 'ys_crm',
            'envPath'       => $envInfo['display'],
            'envSecure'     => $envInfo['secure'],
            'basePath'      => BASE_PATH,
            'parentPath'    => dirname(BASE_PATH),
        ]);

        // 清除安裝相關 session
        Session::remove('install_step');
        Session::remove('install_db_config');
        Session::remove('install_admin_username');
        Session::remove('install_admin_email');
        Session::remove(InstallationMarkers::SESSION_KEY);
    }
}
