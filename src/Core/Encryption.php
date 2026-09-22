<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

class Encryption
{
    private string $key;
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_LENGTH = 12;
    private const TAG_LENGTH = 16;

    public function __construct(?string $key = null)
    {
        if ($key === null) {
            // 驗證 APP_KEY：長度足夠且前 64 字元為合法 hex，避免 hex2bin 在非 hex
            // 輸入時回傳 false / 觸發警告，導致金鑰長度不足而靜默產生弱加密。
            $hexKey = (string) ($_ENV['APP_KEY'] ?? '');
            if (strlen($hexKey) < 64 || !ctype_xdigit(substr($hexKey, 0, 64))) {
                throw new \RuntimeException('APP_KEY 必須為至少 64 字元的合法 hex');
            }
            $bin = hex2bin(substr($hexKey, 0, 64));
            if ($bin === false || strlen($bin) !== 32) {
                throw new \RuntimeException('APP_KEY 解析失敗');
            }
            $this->key = $bin;
        } else {
            $this->key = $key;
        }
    }

    /**
     * 加密字串（AES-256-GCM）
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('加密失敗');
        }

        return base64_encode($nonce . $tag . $ciphertext);
    }

    /**
     * 解密字串
     */
    public function decrypt(string $encoded): string
    {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < self::NONCE_LENGTH + self::TAG_LENGTH + 1) {
            throw new \RuntimeException('無效的加密資料');
        }

        $nonce      = substr($data, 0, self::NONCE_LENGTH);
        $tag        = substr($data, self::NONCE_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($data, self::NONCE_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('解密失敗：金鑰不正確或資料已損壞');
        }

        return $plaintext;
    }

    /**
     * 產生隨機 APP_KEY（64 字元 hex = 32 bytes）
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
