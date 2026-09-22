/**
 * Kanban 看板拖拉功能
 *
 * 使用 SortableJS 實現卡片拖拉，配合 AJAX 更新後端。
 */
(function () {
    'use strict';

    // ── 設定 ──────────────────────────────────────────
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const SORTABLE_CDN = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js';

    // ── 工具函式 ──────────────────────────────────────
    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        const bgClass = type === 'success'
            ? 'bg-green-500'
            : (type === 'error' ? 'bg-red-500' : 'bg-blue-500');

        toast.className = `fixed bottom-6 right-6 z-50 ${bgClass} text-white px-5 py-3 rounded-lg shadow-lg text-sm transition-all transform translate-y-2 opacity-0`;
        toast.textContent = message;
        document.body.appendChild(toast);

        // 淡入
        requestAnimationFrame(() => {
            toast.classList.remove('translate-y-2', 'opacity-0');
        });

        // 3 秒後淡出
        setTimeout(() => {
            toast.classList.add('translate-y-2', 'opacity-0');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // ── 載入 SortableJS ──────────────────────────────
    function loadSortableJS() {
        return new Promise((resolve, reject) => {
            if (typeof Sortable !== 'undefined') {
                resolve();
                return;
            }
            const script = document.createElement('script');
            script.src = SORTABLE_CDN;
            script.onload = resolve;
            script.onerror = () => reject(new Error('SortableJS 載入失敗'));
            document.head.appendChild(script);
        });
    }

    // ── AJAX 移動卡片 ────────────────────────────────
    async function moveCard(cardId, targetStageId, newSortOrder) {
        const resp = await fetch(`/api/cards/${cardId}/move`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                _csrf_token: CSRF_TOKEN,
                stage_id: targetStageId,
                sort_order: newSortOrder,
            }),
        });

        const data = await resp.json();

        if (!resp.ok || !data.success) {
            throw new Error(data.message || '移動失敗');
        }

        return data;
    }

    // ── 初始化 Sortable ──────────────────────────────
    function initSortable() {
        const columns = document.querySelectorAll('.kanban-column-body');
        if (!columns.length) return;

        columns.forEach(column => {
            const stageId = column.dataset.stageId;
            const parentCol = column.closest('.kanban-column');
            const allowDragIn = parentCol?.dataset.allowDragIn !== '0';
            const allowDragOut = parentCol?.dataset.allowDragOut !== '0';

            new Sortable(column, {
                group: {
                    name: 'kanban',
                    put: allowDragIn,
                    pull: allowDragOut,
                },
                animation: 200,
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                dragClass: 'sortable-drag',
                handle: '.kanban-card',
                draggable: '.kanban-card',

                onEnd: async function (evt) {
                    const cardEl = evt.item;
                    const cardId = parseInt(cardEl.dataset.cardId, 10);
                    const targetColumn = evt.to;
                    const targetStageId = parseInt(targetColumn.dataset.stageId, 10);
                    const newIndex = evt.newIndex;

                    // 記住原始位置（用於還原）
                    const fromColumn = evt.from;
                    const oldIndex = evt.oldIndex;

                    try {
                        await moveCard(cardId, targetStageId, newIndex);
                        showToast('卡片已移動', 'success');

                        // 更新 column header 的卡片數量
                        updateColumnCounts();
                    } catch (err) {
                        // 失敗：還原卡片位置
                        showToast(err.message || '移動失敗', 'error');

                        // 將卡片移回原位
                        if (fromColumn !== targetColumn) {
                            targetColumn.removeChild(cardEl);
                            if (oldIndex < fromColumn.children.length) {
                                fromColumn.insertBefore(cardEl, fromColumn.children[oldIndex]);
                            } else {
                                fromColumn.appendChild(cardEl);
                            }
                        } else {
                            // 同一 column 內排序還原
                            const items = Array.from(fromColumn.children);
                            if (oldIndex < items.length) {
                                fromColumn.insertBefore(cardEl, items[oldIndex]);
                            }
                        }
                    }
                },
            });
        });
    }

    // ── 更新每個 column 的卡片計數 ───────────────────
    function updateColumnCounts() {
        document.querySelectorAll('.kanban-column').forEach(col => {
            const body = col.querySelector('.kanban-column-body');
            const countEl = col.querySelector('.kanban-count');
            if (body && countEl) {
                countEl.textContent = body.querySelectorAll('.kanban-card').length;
            }
        });
    }

    // ── 卡片點擊開啟 Modal ──────────────────────────
    function initCardClickHandler() {
        // 委託事件到 board container
        const board = document.getElementById('kanban-board');
        if (!board) return;

        board.addEventListener('click', function (e) {
            const cardEl = e.target.closest('.kanban-card');
            if (!cardEl) return;

            // 排除拖拉中的點擊
            if (cardEl.classList.contains('sortable-drag')) return;

            const cardId = parseInt(cardEl.dataset.cardId, 10);
            if (!cardId) return;

            // 觸發 Alpine.js card-modal
            const modal = document.querySelector('[x-data*="cardModal"]');
            if (modal && modal.__x) {
                modal.__x.$data.openCard(cardId);
            } else if (window.kanbanApp) {
                window.kanbanApp.openModal(cardId);
            }
        });
    }

    // ── 公開 API ────────────────────────────────────
    window.kanbanApp = {
        openModal: function (cardId) {
            // 尋找 Alpine cardModal 實例
            const modalEls = document.querySelectorAll('[x-data]');
            modalEls.forEach(el => {
                if (el.__x && typeof el.__x.$data.openCard === 'function') {
                    el.__x.$data.openCard(cardId);
                }
            });
        }
    };

    // ── 啟動 ────────────────────────────────────────
    async function init() {
        try {
            await loadSortableJS();
            initSortable();
            initCardClickHandler();
        } catch (err) {
            console.error('[Kanban]', err.message);
            showToast('看板初始化失敗：' + err.message, 'error');
        }
    }

    // DOM Ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
