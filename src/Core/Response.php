<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

class Response
{
    private int $statusCode = 200;
    private array $headers = [];

    public function status(int $code): static
    {
        $this->statusCode = $code;
        return $this;
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function html(string $content): void
    {
        $this->sendHeaders();
        http_response_code($this->statusCode);
        header('Content-Type: text/html; charset=utf-8');
        echo $content;
    }

    /**
     * 純文字回應。
     *
     * html() 會硬寫 Content-Type: text/html —— 排程觸發端點的輸出是給機器與
     * 維運人員看的執行摘要，用 text/plain 才不會被瀏覽器當 HTML 解讀
     * （輸出內含統計數字與錯誤訊息，以 HTML 呈現等於多開一條注入面）。
     */
    public function text(string $content, int $statusCode = 0): void
    {
        if ($statusCode > 0) {
            $this->statusCode = $statusCode;
        }
        $this->sendHeaders();
        http_response_code($this->statusCode);
        header('Content-Type: text/plain; charset=utf-8');
        echo $content;
    }

    public function json(mixed $data, int $statusCode = 0): void
    {
        if ($statusCode > 0) {
            $this->statusCode = $statusCode;
        }
        $this->sendHeaders();
        http_response_code($this->statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function redirect(string $url, int $statusCode = 302): never
    {
        http_response_code($statusCode);
        header("Location: {$url}");
        exit;
    }

    public function back(): never
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        $this->redirect($referer);
    }

    private function sendHeaders(): void
    {
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
    }
}
