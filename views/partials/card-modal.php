<?php
use function YangSheep\CRM\Core\e;
?>

<!-- 卡片快速檢視 Modal（Alpine.js） -->
<div x-data="cardModal()" x-show="open" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center"
     @keydown.escape.window="close()">

    <!-- 遮罩 -->
    <div class="fixed inset-0 bg-black/50 transition-opacity" x-show="open"
         x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         @click="close()"></div>

    <!-- Modal 本體 -->
    <div class="relative bg-white rounded-xl shadow-2xl max-w-lg w-full mx-4 max-h-[80vh] overflow-y-auto"
         x-show="open"
         x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">

        <!-- 載入中 -->
        <template x-if="loading">
            <div class="p-8 text-center text-gray-400">
                <svg class="animate-spin h-8 w-8 mx-auto mb-3 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                載入中...
            </div>
        </template>

        <!-- 卡片內容 -->
        <template x-if="!loading && card">
            <div>
                <!-- 標題列 -->
                <div class="px-6 py-4 border-b flex items-start justify-between">
                    <div class="flex-1 pr-4">
                        <h3 class="text-lg font-bold text-gray-800" x-text="card.title"></h3>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="w-2 h-2 rounded-full" :style="'background-color:' + safeBrandColor(card.stage_color)"></span>
                            <span class="text-sm text-gray-500" x-text="card.stage_name"></span>
                        </div>
                    </div>
                    <button @click="close()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- 詳細資訊 -->
                <div class="px-6 py-4 space-y-4">
                    <!-- 優先級和負責人 -->
                    <div class="flex items-center gap-4">
                        <div>
                            <span class="text-xs text-gray-400">優先級</span>
                            <div class="mt-0.5">
                                <span class="text-xs px-2 py-0.5 rounded font-medium"
                                      :class="priorityClass(card.priority)"
                                      x-text="priorityLabel(card.priority)"></span>
                            </div>
                        </div>
                        <div>
                            <span class="text-xs text-gray-400">負責人</span>
                            <p class="text-sm mt-0.5" x-text="card.assignee_name || '未指定'"></p>
                        </div>
                    </div>

                    <!-- 描述 -->
                    <div x-show="card.description">
                        <span class="text-xs text-gray-400">描述</span>
                        <p class="text-sm text-gray-600 mt-1 whitespace-pre-wrap" x-text="card.description"></p>
                    </div>
                </div>

                <!-- 底部操作 -->
                <div class="px-6 py-3 border-t bg-gray-50 rounded-b-xl flex items-center justify-between">
                    <a :href="'/admin/cards/' + card.id" class="text-sm text-blue-600 hover:underline">
                        檢視完整詳情
                    </a>
                    <a :href="'/admin/cards/' + card.id + '/edit'" class="text-sm text-gray-600 hover:text-gray-800">
                        編輯
                    </a>
                </div>
            </div>
        </template>

        <!-- 錯誤 -->
        <template x-if="!loading && error">
            <div class="p-8 text-center text-red-600 dark:text-red-400">
                <p x-text="error"></p>
                <button @click="close()" class="mt-3 text-sm text-gray-500 hover:text-gray-700">關閉</button>
            </div>
        </template>
    </div>
</div>

<script>
function cardModal() {
    return {
        open: false,
        loading: false,
        card: null,
        error: null,

        async openCard(cardId) {
            this.open = true;
            this.loading = true;
            this.error = null;
            this.card = null;

            try {
                const resp = await fetch(`/api/cards/${cardId}`, {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await resp.json();
                if (data.success) {
                    this.card = data.data;
                } else {
                    this.error = data.message || '載入失敗';
                }
            } catch (e) {
                this.error = '網路錯誤，請稍後再試';
            } finally {
                this.loading = false;
            }
        },

        close() {
            this.open = false;
            this.card = null;
            this.error = null;
        },

        priorityLabel(p) {
            const map = { critical: '緊急', high: '高', medium: '中', low: '低' };
            return map[p] || '中';
        },

        priorityClass(p) {
            const map = {
                critical: 'bg-red-100 text-red-700',
                high: 'bg-orange-100 text-orange-700',
                medium: 'bg-blue-100 text-blue-700',
                low: 'bg-gray-100 text-gray-500',
            };
            return map[p] || map.medium;
        },

        safeBrandColor(color) {
            if (!/^#[0-9a-f]{6}$/i.test(color || '')) return '#1E40AF';

            const red = parseInt(color.slice(1, 3), 16) / 255;
            const green = parseInt(color.slice(3, 5), 16) / 255;
            const blue = parseInt(color.slice(5, 7), 16) / 255;
            const max = Math.max(red, green, blue);
            const min = Math.min(red, green, blue);
            const delta = max - min;
            if (delta === 0) return color;

            let hue;
            if (max === red) hue = 60 * (((green - blue) / delta) % 6);
            else if (max === green) hue = 60 * (((blue - red) / delta) + 2);
            else hue = 60 * (((red - green) / delta) + 4);
            if (hue < 0) hue += 360;

            return hue >= 60 && hue <= 180 ? '#46caeb' : color;
        }
    };
}
</script>
