<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

abstract class Middleware
{
    /**
     * 處理請求
     *
     * @param Request $request 當前請求
     * @param callable $next 下一個 middleware 或 handler
     * @param mixed ...$params 額外參數（來自路由定義 Middleware:param）
     */
    abstract public function handle(Request $request, callable $next, mixed ...$params): void;
}
