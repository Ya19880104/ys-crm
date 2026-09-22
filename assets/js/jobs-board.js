/**
 * 工作看板拖拉（對應架構設計 §7.6）。
 *
 * 使用 SortableJS 實現卡片跨欄 / 同欄拖拉，拖放後以 AJAX + CSRF header
 * 呼叫 POST /admin/jobs/{id}/move 更新後端 column_id 與 sort。
 * 失敗時還原卡片位置並提示。無 JS 時可改用卡片內「變更狀態」下拉（後備）。
 *
 * 安全：CSRF token 取自 <meta name="csrf-token">，隨 body 一併送出；
 * 後端 move() 仍會驗證 CSRF + jobs.edit 權限。
 */
(function () {
    'use strict';

    var CSRF_TOKEN = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var SORTABLE_CDN = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js';

    function showToast(message, type) {
        var toast = document.createElement('div');
        var bg = type === 'error' ? 'bg-red-500' : (type === 'info' ? 'bg-blue-500' : 'bg-emerald-500');
        toast.className = 'fixed bottom-6 right-6 z-50 ' + bg + ' text-white px-5 py-3 rounded-lg shadow-lg text-sm transition-all transform translate-y-2 opacity-0';
        toast.textContent = message;
        document.body.appendChild(toast);
        requestAnimationFrame(function () { toast.classList.remove('translate-y-2', 'opacity-0'); });
        setTimeout(function () {
            toast.classList.add('translate-y-2', 'opacity-0');
            setTimeout(function () { toast.remove(); }, 300);
        }, 2500);
    }

    function loadSortable() {
        return new Promise(function (resolve, reject) {
            if (typeof Sortable !== 'undefined') { resolve(); return; }
            var s = document.createElement('script');
            s.src = SORTABLE_CDN;
            s.onload = resolve;
            s.onerror = function () { reject(new Error('SortableJS 載入失敗')); };
            document.head.appendChild(s);
        });
    }

    function moveJob(jobId, columnId, sort) {
        return fetch('/admin/jobs/' + jobId + '/move', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ _csrf_token: CSRF_TOKEN, column_id: columnId, sort: sort })
        }).then(function (resp) {
            return resp.json().then(function (data) {
                if (!resp.ok || !data.success) {
                    throw new Error((data && data.message) || '移動失敗');
                }
                return data;
            });
        });
    }

    function updateCounts() {
        document.querySelectorAll('.jobs-column').forEach(function (col) {
            var body = col.querySelector('.jobs-column-body');
            var countEl = col.querySelector('.jobs-count');
            if (body && countEl) {
                countEl.textContent = body.querySelectorAll('.jobs-card').length;
            }
        });
    }

    function init() {
        var board = document.getElementById('jobs-board');
        if (!board || board.dataset.canEdit !== '1') { return; }

        var bodies = document.querySelectorAll('.jobs-column-body');
        if (!bodies.length) { return; }

        bodies.forEach(function (body) {
            new Sortable(body, {
                group: 'jobs',
                animation: 180,
                ghostClass: 'opacity-40',
                chosenClass: 'ring-2',
                dragClass: 'shadow-lg',
                draggable: '.jobs-card',
                // 不以整卡為 handle 內的連結觸發拖拉時誤跳轉：SortableJS 對連結點擊預設可正常運作
                onEnd: function (evt) {
                    var card = evt.item;
                    var jobId = parseInt(card.dataset.jobId, 10);
                    var toBody = evt.to;
                    var fromBody = evt.from;
                    var columnId = parseInt(toBody.dataset.columnId, 10);
                    var newIndex = evt.newIndex;
                    var oldIndex = evt.oldIndex;

                    if (!jobId || !columnId) { return; }
                    // 同欄同位置：無變化不送
                    if (fromBody === toBody && newIndex === oldIndex) { return; }

                    moveJob(jobId, columnId, newIndex).then(function () {
                        showToast('工作已移動');
                        updateCounts();
                    }).catch(function (err) {
                        showToast(err.message || '移動失敗', 'error');
                        // 還原
                        if (fromBody !== toBody) {
                            toBody.removeChild(card);
                            if (oldIndex < fromBody.children.length) {
                                fromBody.insertBefore(card, fromBody.children[oldIndex]);
                            } else {
                                fromBody.appendChild(card);
                            }
                        } else {
                            var items = Array.prototype.slice.call(fromBody.children);
                            if (oldIndex < items.length) {
                                fromBody.insertBefore(card, items[oldIndex]);
                            } else {
                                fromBody.appendChild(card);
                            }
                        }
                        updateCounts();
                    });
                }
            });
        });
    }

    function boot() {
        loadSortable().then(init).catch(function (err) {
            // 載入失敗：靜默退回「變更狀態」下拉後備，不阻斷頁面
            if (window.console) { console.warn('[JobsBoard]', err.message); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
