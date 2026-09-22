<?php

declare(strict_types=1);

namespace YangSheep\CRM\Card;

class CardCommentService
{
    private CardCommentRepository $repo;

    public function __construct()
    {
        $this->repo = new CardCommentRepository();
    }

    public function findByCardId(int $cardId): array
    {
        return $this->repo->findByCardId($cardId);
    }

    public function findById(int $id): ?array
    {
        return $this->repo->findById($id);
    }

    public function create(array $data): int
    {
        return $this->repo->create($data);
    }

    public function delete(int $id): void
    {
        $this->repo->delete($id);
    }

    /** 允許的圖片 MIME */
    private const IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    /** 允許的影片 MIME */
    private const VIDEO_MIMES = [
        'video/mp4'          => 'mp4',
        'video/webm'         => 'webm',
        'video/quicktime'    => 'mov',
        'video/x-matroska'   => 'mkv',
        'video/x-msvideo'    => 'avi',
        'video/x-ms-wmv'     => 'wmv',
    ];

    /**
     * 處理媒體上傳（圖片或影片）
     */
    public function handleMediaUpload(array $file): ?array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        // PHP 8.1 起 finfo 已是物件（finfo class），由 GC 釋放；
        // finfo_close() 在 8.5 為 deprecated 的 no-op。

        $allMimes = self::IMAGE_MIMES + self::VIDEO_MIMES;
        if (!isset($allMimes[$mimeType])) {
            return null;
        }

        $isVideo = isset(self::VIDEO_MIMES[$mimeType]);
        $maxSize = $isVideo ? 1024 * 1024 * 1024 : 5 * 1024 * 1024; // 影片 1GB，圖片 5MB

        if ($file['size'] > $maxSize) {
            return null;
        }

        // 圖片驗證
        if (!$isVideo && @getimagesize($file['tmp_name']) === false) {
            return null;
        }

        $ext = $allMimes[$mimeType];
        $filename = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

        $webRoot = defined('WEB_ROOT') ? WEB_ROOT : BASE_PATH;
        $uploadDir = \YangSheep\CRM\Core\UploadPath::subdir('comments', $webRoot);
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // 寫檔前自我修復 uploads/ 的執行防護，未備妥則中止上傳。
        if (!\YangSheep\CRM\Install\UploadGuard::ensure(\YangSheep\CRM\Core\UploadPath::absolute($webRoot))) {
            return null;
        }

        $destPath = $uploadDir . '/' . $filename;
        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            return [
                'path' => \YangSheep\CRM\Core\UploadPath::webSubdir('comments') . $filename,
                'type' => $isVideo ? 'video' : 'image',
                'mime' => $mimeType,
                'size' => $file['size'],
            ];
        }

        return null;
    }

    /**
     * 相容舊介面
     */
    public function handleImageUpload(array $file): ?string
    {
        $result = $this->handleMediaUpload($file);
        return $result['path'] ?? null;
    }
}
