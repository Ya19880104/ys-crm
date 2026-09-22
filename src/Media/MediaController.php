<?php

declare(strict_types=1);

namespace YangSheep\CRM\Media;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;

class MediaController extends Controller
{
    private Database $db;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->db = Database::getInstance();
    }

    /**
     * 媒體檔案列表
     */
    public function index(): void
    {
        $webRoot = defined('WEB_ROOT') ? WEB_ROOT : BASE_PATH;

        // 從 uploads 目錄掃描所有檔案
        $files = [];
        $uploadDirs = [];
        foreach (\YangSheep\CRM\Core\UploadPath::readableDirs() as $rootDir) {
            foreach (['comments', 'settings'] as $subdir) $uploadDirs[] = $rootDir . '/' . $subdir;
        }

        foreach ($uploadDirs as $dir) {
            $fullDir = $webRoot . '/' . $dir;
            if (!is_dir($fullDir)) {
                continue;
            }

            $items = scandir($fullDir);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..' || $item === '.htaccess' || $item === '.gitkeep') {
                    continue;
                }

                $filePath = \YangSheep\CRM\Core\UploadPath::resolveFile('/' . $dir . '/' . $item, $webRoot);
                if ($filePath === null) {
                    continue;
                }

                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
                $isVideo = in_array($ext, ['mp4', 'webm', 'mov'], true);

                if (!$isImage && !$isVideo) {
                    continue;
                }

                // 查詢是否有備註引用此檔案
                $urlPath = '/' . $dir . '/' . $item;
                $usedBy = $this->db->fetch(
                    "SELECT c.id as comment_id, c.card_id, k.title as card_title
                     FROM {prefix}card_comments c
                     LEFT JOIN {prefix}cards k ON k.id = c.card_id
                     WHERE c.image_path = ? LIMIT 1",
                    [$urlPath]
                );

                $files[] = [
                    'name'      => $item,
                    'path'      => $urlPath,
                    'dir'       => $dir,
                    'type'      => $isVideo ? 'video' : 'image',
                    'size'      => filesize($filePath),
                    'modified'  => filemtime($filePath),
                    'used_by'   => $usedBy,
                ];
            }
        }

        // 按修改時間倒序
        usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);

        $this->render('admin/media/index', [
            'title' => '媒體檔案管理',
            'files' => $files,
        ]);
    }

    /**
     * POST 刪除檔案
     */
    public function destroy(): void
    {
        // fail-closed：CSRF 驗證失敗即中止（與全專案一致；先前回傳值被丟棄屬 fail-open 死碼）。
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('驗證失敗，請重新操作。');
        }

        $filePath = $this->request->input('file_path', '');

        // 安全驗證：只允許刪除 uploads/ 下的檔案
        if (!\YangSheep\CRM\Core\UploadPath::isUploadWebPath($filePath)) {
            $this->backWithError('不允許刪除此檔案');
        }

        // 防止路徑穿越
        if (str_contains($filePath, '..') || str_contains($filePath, "\0")) {
            $this->backWithError('無效的檔案路徑');
        }

        $webRoot = defined('WEB_ROOT') ? WEB_ROOT : BASE_PATH;
        $fullPath = \YangSheep\CRM\Core\UploadPath::resolveFile($filePath, $webRoot);
        if ($fullPath === null) {
            $this->backWithError('檔案不存在或路徑不合法');
        }

        // 清除 DB 中的引用
        $this->db->execute(
            "UPDATE {prefix}card_comments SET image_path = NULL, media_type = NULL WHERE image_path = ?",
            [$filePath]
        );

        // 刪除檔案
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }

        $this->redirectWith('/admin/media', 'success', '檔案已刪除');
    }
}
