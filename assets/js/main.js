/**
 * ============================================================
 * EQAS - Main JavaScript
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 */

'use strict';

// ============================================================
// 1. CSRF TOKEN MANAGEMENT
// ============================================================
const EQAS = {
    csrfToken: null,
    baseUrl: '',

    /**
     * Initialize EQAS
     */
    init() {
        this.csrfToken = this.getCsrfToken();
        this.baseUrl = document.querySelector('meta[name="base-url"]')?.content || '';
        if (!this.baseUrl) {
            var dir = window.location.pathname.replace(/\/[^\/]*$/, '');
            this.baseUrl = (dir === '/' || dir === '') ? '' : dir;
        }
        this.setupAjaxDefaults();
        this.initSidebar();
        this.initDropdowns();
        this.initNotifications();
        this.initTooltips();
        this.initFormValidation();
        this.initDataTables();
        this.initModals();
        this.initAlertDismiss();
        this.initSessionKeepAlive();
    },

    /**
     * Get CSRF token from meta tag
     */
    getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    },

    /**
     * Setup default AJAX configuration with CSRF
     */
    setupAjaxDefaults() {
        // Attach CSRF token to all fetch requests
        const originalFetch = window.fetch;
        window.fetch = function(url, options = {}) {
            if (!options.headers) {
                options.headers = {};
            }

            // Add CSRF token for non-GET requests
            if (options.method && options.method.toUpperCase() !== 'GET') {
                if (options.headers instanceof Headers) {
                    options.headers.set('X-CSRF-Token', EQAS.csrfToken);
                } else {
                    options.headers['X-CSRF-Token'] = EQAS.csrfToken;
                }
            }

            return originalFetch(url, options);
        };
    }
};

// ============================================================
// 2. AJAX HELPER
// ============================================================

/**
 * EQAS AJAX wrapper
 * @param {string} url - Request URL
 * @param {string} method - HTTP method (GET, POST, PUT, DELETE)
 * @param {object} data - Request body data
 * @param {function} callback - Success callback function
 * @param {function} errorCallback - Error callback function
 */
function eqasAjax(url, method = 'GET', data = null, callback = null, errorCallback = null) {
    const options = {
        method: method.toUpperCase(),
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': EQAS.csrfToken,
            'X-Requested-With': 'XMLHttpRequest'
        }
    };

    if (data && method.toUpperCase() !== 'GET') {
        options.body = JSON.stringify(data);
    }

    // Show loading
    const loadingEl = document.getElementById('ajax-loading');
    if (loadingEl) loadingEl.style.display = 'flex';

    fetch(url, options)
        .then(response => {
            if (response.status === 401) {
                if (!EQAS._isRedirectingToLogin) {
                    EQAS._isRedirectingToLogin = true;
                    window.location.href = EQAS.baseUrl + '/login.php';
                }
                return;
            }
            if (!response.ok) {
                throw new Error(`خطأ في الخادم: ${response.status}`);
            }
            return response.json();
        })
        .then(result => {
            if (loadingEl) loadingEl.style.display = 'none';

            if (result && result.logged_out) {
                return;
            }

            if (result && result.success === false) {
                const errorMsg = result.message || 'حدث خطأ غير متوقع';
                if (errorCallback) {
                    errorCallback(errorMsg);
                } else {
                    showToast(errorMsg, 'danger');
                }
                return;
            }

            if (callback && result) {
                callback(result);
            }
        })
        .catch(error => {
            if (loadingEl) loadingEl.style.display = 'none';

            console.error('EQAS AJAX Error:', error);
            const errorMsg = 'حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.';
            if (errorCallback) {
                errorCallback(errorMsg);
            } else {
                showToast(errorMsg, 'danger');
            }
        });
}

/**
 * Submit form via AJAX
 * @param {HTMLFormElement} form - Form element
 * @param {function} callback - Success callback
 * @param {function} errorCallback - Error callback
 */
function eqasSubmitForm(form, callback = null, errorCallback = null) {
    const formData = new FormData(form);
    const submitBtn = form.querySelector('[type="submit"]');

    if (submitBtn) {
        toggleLoading(submitBtn, true);
    }

    fetch(form.action, {
        method: form.method || 'POST',
        body: formData,
        headers: {
            'X-CSRF-Token': EQAS.csrfToken,
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(result => {
        if (submitBtn) toggleLoading(submitBtn, false);

        if (result.success === false) {
            const errorMsg = result.message || 'حدث خطأ في إرسال النموذج';
            if (errorCallback) {
                errorCallback(errorMsg);
            } else {
                showToast(errorMsg, 'danger');
            }
            return;
        }

        if (callback) {
            callback(result);
        }
    })
    .catch(error => {
        if (submitBtn) toggleLoading(submitBtn, false);
        console.error('Form Submit Error:', error);
        showToast('حدث خطأ في الاتصال بالخادم', 'danger');
    });
}

// ============================================================
// 3. SIDEBAR
// ============================================================

EQAS.initSidebar = function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const toggleBtn = document.getElementById('sidebar-toggle');

    // Toggle sidebar on mobile
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
            document.body.classList.toggle('sidebar-open');
        });
    }

    // Close sidebar when clicking overlay
    if (overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
            document.body.classList.remove('sidebar-open');
        });
    }

    // Accordion Menu - Professional expand/collapse (Single Open behavior)
    const menuToggles = document.querySelectorAll('.sidebar-menu-link[data-toggle="submenu"]');
    menuToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            const submenu = this.nextElementSibling;
            const isExpanded = this.getAttribute('aria-expanded') === 'true';

            // Close ALL other submenus (Single Open / Accordion behavior)
            document.querySelectorAll('.sidebar-submenu.show').forEach(sm => {
                if (sm !== submenu) {
                    sm.classList.remove('show');
                    const otherBtn = sm.previousElementSibling;
                    if (otherBtn) {
                        otherBtn.setAttribute('aria-expanded', 'false');
                    }
                }
            });

            // Toggle current submenu
            if (isExpanded) {
                submenu.classList.remove('show');
                this.setAttribute('aria-expanded', 'false');
            } else {
                submenu.classList.add('show');
                this.setAttribute('aria-expanded', 'true');
            }
        });

        // Keyboard Navigation: Arrow keys support
        toggle.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.click();
            } else if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
                // In RTL, ArrowLeft goes to next, ArrowDown goes down
                e.preventDefault();
                const submenu = this.nextElementSibling;
                if (submenu && submenu.classList.contains('show')) {
                    const firstLink = submenu.querySelector('.sidebar-menu-link');
                    if (firstLink) firstLink.focus();
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                const prevItem = this.previousElementSibling;
                if (prevItem) {
                    const prevLink = prevItem.querySelector('.sidebar-menu-link') || prevItem;
                    if (prevLink) prevLink.focus();
                }
            }
        });
    });

    // Keyboard navigation within submenus
    document.querySelectorAll('.sidebar-submenu').forEach(submenu => {
        submenu.addEventListener('keydown', function(e) {
            const links = Array.from(this.querySelectorAll('.sidebar-menu-link'));
            const currentIndex = links.indexOf(document.activeElement);

            if (e.key === 'ArrowDown' || e.key === 'ArrowLeft') {
                e.preventDefault();
                const nextIndex = currentIndex < links.length - 1 ? currentIndex + 1 : 0;
                links[nextIndex]?.focus();
            } else if (e.key === 'ArrowUp' || e.key === 'ArrowRight') {
                e.preventDefault();
                if (currentIndex <= 0) {
                    // Go back to parent button
                    const parentBtn = this.previousElementSibling;
                    if (parentBtn) parentBtn.focus();
                } else {
                    links[currentIndex - 1]?.focus();
                }
            } else if (e.key === 'Escape') {
                const parentBtn = this.previousElementSibling;
                if (parentBtn) {
                    parentBtn.focus();
                    parentBtn.setAttribute('aria-expanded', 'false');
                    this.classList.remove('show');
                }
            }
        });
    });

    // Set active menu based on current URL
    this.setActiveMenuItem();
};

EQAS.setActiveMenuItem = function() {
    const currentPath = window.location.pathname;
    const menuLinks = document.querySelectorAll('.sidebar-menu-link');

    menuLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href && currentPath.includes(href.replace('.php', ''))) {
            link.classList.add('active');

            // Expand parent submenu
            const submenu = link.closest('.sidebar-submenu');
            if (submenu) {
                submenu.classList.add('show');
            }
        }
    });
};

// ============================================================
// 4. DROPDOWNS
// ============================================================

EQAS.initDropdowns = function() {
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        const openDropdowns = document.querySelectorAll('.dropdown-menu.show, .user-dropdown-menu.show, .notification-dropdown.show');
        openDropdowns.forEach(dropdown => {
            if (!dropdown.parentElement.contains(e.target)) {
                dropdown.classList.remove('show');
            }
        });
    });

    // User dropdown
    const userToggle = document.getElementById('user-dropdown-toggle');
    const userMenu = document.getElementById('userMenu');
    if (userToggle && userMenu) {
        userToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            userMenu.classList.toggle('show');
            // Close notification dropdown
            const notifDropdown = document.querySelector('.notification-dropdown');
            if (notifDropdown) notifDropdown.classList.remove('show');
        });
    }

    // Notification dropdown
    const notifToggle = document.getElementById('notification-toggle');
    const notifDropdown = document.querySelector('.notification-dropdown');
    if (notifToggle && notifDropdown) {
        notifToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            notifDropdown.classList.toggle('show');
            // Close user dropdown
            if (userMenu) userMenu.classList.remove('show');
        });
    }
};

// ============================================================
// 5. FORM HANDLING
// ============================================================

EQAS.initFormValidation = function() {
    // Add validation on form submit
    const forms = document.querySelectorAll('form[data-validate]');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    });

    // Real-time validation on input
    const inputs = document.querySelectorAll('.form-control[required]');
    inputs.forEach(input => {
        input.addEventListener('blur', function() {
            validateInput(this);
        });
        input.addEventListener('input', function() {
            if (this.classList.contains('is-invalid')) {
                validateInput(this);
            }
        });
    });
};

/**
 * Validate a form
 * @param {HTMLFormElement} form
 * @returns {boolean}
 */
function validateForm(form) {
    let isValid = true;
    const inputs = form.querySelectorAll('.form-control[required], .form-control[data-rules]');

    inputs.forEach(input => {
        if (!validateInput(input)) {
            isValid = false;
        }
    });

    return isValid;
}

/**
 * Validate a single input
 * @param {HTMLElement} input
 * @returns {boolean}
 */
function validateInput(input) {
    const value = input.value.trim();
    const rules = input.dataset.rules ? input.dataset.rules.split('|') : [];
    let errorMsg = '';

    // Required check
    if (input.hasAttribute('required') && !value) {
        errorMsg = 'هذا الحقل مطلوب';
    }

    // Email validation
    if (value && rules.includes('email')) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(value)) {
            errorMsg = 'يرجى إدخال بريد إلكتروني صحيح';
        }
    }

    // Min length
    const minRule = rules.find(r => r.startsWith('min:'));
    if (minRule && value) {
        const minLen = parseInt(minRule.split(':')[1]);
        if (value.length < minLen) {
            errorMsg = `يجب أن يكون الحد الأدنى ${minLen} حرفاً`;
        }
    }

    // Max length
    const maxRule = rules.find(r => r.startsWith('max:'));
    if (maxRule && value) {
        const maxLen = parseInt(maxRule.split(':')[1]);
        if (value.length > maxLen) {
            errorMsg = `يجب ألا يتجاوز ${maxLen} حرفاً`;
        }
    }

    // Number validation
    if (value && rules.includes('numeric')) {
        if (isNaN(value)) {
            errorMsg = 'يرجى إدخال رقم صحيح';
        }
    }

    // Apply validation state
    const feedback = input.parentElement.querySelector('.invalid-feedback');
    if (errorMsg) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        if (feedback) feedback.textContent = errorMsg;
        return false;
    } else if (value) {
        input.classList.remove('is-invalid');
        input.classList.add('is-valid');
        if (feedback) feedback.textContent = '';
        return true;
    }

    input.classList.remove('is-invalid', 'is-valid');
    return true;
}

/**
 * Confirm dangerous action
 * @param {string} message - Confirmation message
 * @param {function} onConfirm - Callback on confirm
 */
function confirmAction(message, onConfirm) {
    const overlay = document.createElement('div');
    overlay.className = 'confirm-overlay show';
    overlay.innerHTML = `
        <div class="confirm-dialog">
            <div class="confirm-icon">⚠️</div>
            <div class="confirm-title">تأكيد العملية</div>
            <div class="confirm-message">${message}</div>
            <div class="confirm-actions">
                <button class="btn btn-danger confirm-yes">نعم، تأكيد</button>
                <button class="btn btn-light confirm-no">إلغاء</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    overlay.querySelector('.confirm-yes').addEventListener('click', function() {
        document.body.removeChild(overlay);
        if (onConfirm) onConfirm();
    });

    overlay.querySelector('.confirm-no').addEventListener('click', function() {
        document.body.removeChild(overlay);
    });

    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            document.body.removeChild(overlay);
        }
    });
}

/**
 * Confirm delete action
 * @param {string} message
 * @returns {Promise}
 */
function confirmDelete(message = 'هل أنت متأكد من الحذف؟ لا يمكن التراجع عن هذه العملية.') {
    return new Promise((resolve) => {
        confirmAction(message, () => resolve(true));
    });
}

// ============================================================
// 6. TABLE FEATURES
// ============================================================

EQAS.initDataTables = function() {
    // Sortable columns
    const sortHeaders = document.querySelectorAll('.data-table th.sortable');
    sortHeaders.forEach(th => {
        th.addEventListener('click', function() {
            const table = this.closest('.data-table');
            const colIndex = Array.from(this.parentElement.children).indexOf(this);
            const isAsc = this.classList.contains('sort-asc');

            // Remove sort class from all headers
            table.querySelectorAll('th').forEach(h => {
                h.classList.remove('sort-asc', 'sort-desc');
            });

            // Set sort direction
            this.classList.add(isAsc ? 'sort-desc' : 'sort-asc');

            // Sort table rows
            sortTable(table, colIndex, !isAsc);
        });
    });

    // Search/filter
    const searchInput = document.getElementById('table-search');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(function() {
            filterTable(searchInput.value);
        }, 300));
    }

    // Row selection
    const selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.row-checkbox input[type="checkbox"]');
            checkboxes.forEach(cb => { cb.checked = selectAll.checked; });
            updateBulkActionBar();
        });
    }

    // Individual row selection
    const rowCheckboxes = document.querySelectorAll('.row-checkbox input[type="checkbox"]');
    rowCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkActionBar);
    });
};

/**
 * Sort table by column
 */
function sortTable(table, colIndex, asc = true) {
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));

    rows.sort((a, b) => {
        const aVal = a.children[colIndex]?.textContent.trim() || '';
        const bVal = b.children[colIndex]?.textContent.trim() || '';

        // Try numeric sort
        const aNum = parseFloat(aVal);
        const bNum = parseFloat(bVal);
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return asc ? aNum - bNum : bNum - aNum;
        }

        // String sort
        return asc ? aVal.localeCompare(bVal, 'ar') : bVal.localeCompare(aVal, 'ar');
    });

    rows.forEach(row => tbody.appendChild(row));
}

/**
 * Filter table rows by search term
 */
function filterTable(searchTerm) {
    const rows = document.querySelectorAll('.data-table tbody tr');
    const term = searchTerm.trim().toLowerCase();

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
    });
}

/**
 * Update bulk action bar visibility
 */
function updateBulkActionBar() {
    const checked = document.querySelectorAll('.row-checkbox input[type="checkbox"]:checked');
    const bar = document.querySelector('.bulk-action-bar');
    const countEl = bar?.querySelector('.bulk-action-count');

    if (bar) {
        if (checked.length > 0) {
            bar.classList.add('show');
            if (countEl) countEl.textContent = checked.length;
        } else {
            bar.classList.remove('show');
        }
    }

    // Update select all checkbox
    const selectAll = document.getElementById('select-all');
    const totalCheckboxes = document.querySelectorAll('.row-checkbox input[type="checkbox"]');
    if (selectAll) {
        selectAll.checked = totalCheckboxes.length > 0 && checked.length === totalCheckboxes.length;
    }
}

// ============================================================
// 7. NOTIFICATIONS
// ============================================================

EQAS.initNotifications = function() {
    // Check for new notifications periodically
    setInterval(checkNotifications, 60000); // every minute

    // Load preview when notification dropdown opens
    const toggle = document.getElementById('notification-toggle');
    const dropdown = document.getElementById('notifDropdown');
    if (toggle && dropdown) {
        var previewLoaded = false;
        toggle.addEventListener('click', function() {
            if (!previewLoaded || dropdown.classList.contains('show')) {
                loadNotifPreview();
                previewLoaded = true;
            }
        });
    }
};

function checkNotifications() {
    eqasAjax(EQAS.baseUrl + '/api/notifications/check.php', 'GET', null, function(result) {
        if (result.count !== undefined) {
            const badge = document.getElementById('notifBadge');
            if (badge) {
                badge.textContent = result.count > 99 ? '99+' : result.count;
                badge.style.display = result.count > 0 ? '' : 'none';
            }
            const footer = document.getElementById('notifPreviewCount');
            if (footer) {
                footer.textContent = result.count + ' إشعار غير مقروء';
            }
        }
    });
}

/**
 * Load notification preview via AJAX
 */
function loadNotifPreview() {
    const list = document.getElementById('notifPreviewList');
    if (!list) return;

    list.innerHTML = '<div class="notification-loading" style="padding:20px;text-align:center;color:#888;font-size:13px;"><i class="fas fa-spinner fa-spin"></i> جاري التحميل...</div>';

    eqasAjax(EQAS.baseUrl + '/api/notifications/preview.php', 'GET', null, function(result) {
        if (!result || !result.items) {
            list.innerHTML = '<div style="padding:20px;text-align:center;color:#888;font-size:13px;">لا توجد إشعارات</div>';
            return;
        }

        if (result.items.length === 0) {
            list.innerHTML = '<div style="padding:20px;text-align:center;color:#888;font-size:13px;">🎉 لا توجد إشعارات جديدة</div>';
            return;
        }

        var html = '';
        var typeIcons = {
            'alert': '🚨', 'announcement': '📢', 'reminder': '⏰',
            'system': '⚙️', 'acknowledgment': '✅', 'info': 'ℹ️',
            'ack_request': '📋', 'parent_link': '🔗'
        };

        result.items.forEach(function(item) {
            var icon = typeIcons[item.type] || '📨';
            var created = item.created_at || '';
            var timeLabel = created.substring(11, 16);

            html += '<div class="notification-item unread" id="notif-preview-' + item.id + '">';
            html += '  <div class="notification-icon" style="font-size:20px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">' + icon + '</div>';
            html += '  <div class="notification-content" style="flex:1;min-width:0;">';
            html += '    <div class="notification-text" style="font-size:13px;color:#1a1a2e;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(item.title_ar) + '</div>';
            html += '    <div class="notification-text" style="font-size:11px;color:#888;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(item.message_ar) + '</div>';
            html += '    <div class="notification-time" style="font-size:10px;color:#adb5bd;">' + timeLabel + '</div>';
            html += '  </div>';
            html += '  <button type="button" class="notif-mark-read" data-id="' + item.id + '"';
            html += '          style="background:none;border:none;color:#28a745;cursor:pointer;padding:4px;font-size:13px;flex-shrink:0;"';
            html += '          title="تحديد كمقروء"><i class="fas fa-check-circle"></i></button>';
            html += '</div>';
        });

        list.innerHTML = html;

        // Bind mark-as-read buttons
        list.querySelectorAll('.notif-mark-read').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var id = this.getAttribute('data-id');
                markNotifAsRead(id);
            });
        });
    });
}

/**
 * Mark a single notification as read via AJAX
 */
function markNotifAsRead(id) {
    eqasAjax(EQAS.baseUrl + '/api/notifications/mark_read.php?id=' + id, 'GET', null, function(result) {
        if (result && result.success) {
            // Remove from preview list
            var el = document.getElementById('notif-preview-' + id);
            if (el) {
                el.style.transition = 'opacity 0.3s';
                el.style.opacity = '0';
                setTimeout(function() { el.remove(); checkNotifications(); }, 300);
            } else {
                checkNotifications();
            }
        }
    });
}

/**
 * Simple HTML escape
 */
function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// ============================================================
// 7b. SESSION KEEP-ALIVE & EXPIRY WARNING
// ============================================================

/**
 * Initialize session keep-alive
 * Pings the server every 5 minutes to maintain the session
 * Shows a warning toast 5 minutes before session expiry
 */
EQAS.initSessionKeepAlive = function() {
    if (!EQAS.baseUrl) return;

    setInterval(function() {
        fetch(EQAS.baseUrl + '/api/session/ping.php')
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.expires_in !== undefined && result.expires_in < 300) {
                    var msg = 'جلستك على وشك الانتهاء خلال أقل من 5 دقائق. ';
                    msg += '<button onclick="EQAS.extendSession()" style="background:var(--warning);color:#000;border:none;padding:4px 12px;border-radius:4px;cursor:pointer;margin-right:8px;font-weight:bold;">تمديد الجلسة</button>';
                    showToast(msg, 'warning', 10000);
                }
            })
            .catch(function() {});
    }, 120000); // every 2 minutes check expiry
};

/**
 * Extend the session by pinging server with extend=1
 */
EQAS.extendSession = function() {
    fetch(EQAS.baseUrl + '/api/session/ping.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ extend: true })
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        if (result.ok) {
            showToast('تم تمديد الجلسة بنجاح', 'success', 3000);
        }
    })
    .catch(function() {
        showToast('فشل تمديد الجلسة. يرجى تحديث الصفحة.', 'danger');
    });
};

// ============================================================
// 8. MODALS
// ============================================================

EQAS.initModals = function() {
    // Close modal on overlay click
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay')) {
            closeModal(e.target.id);
        }
    });

    // Close modal on close button click
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-close') || e.target.closest('.modal-close')) {
            const modal = e.target.closest('.modal-overlay');
            if (modal) closeModal(modal.id);
        }
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const openModal = document.querySelector('.modal-overlay.show');
            if (openModal) closeModal(openModal.id);
        }
    });
};

/**
 * Open modal
 * @param {string} modalId - Modal element ID
 * @param {object} data - Optional data to populate modal
 */
function openModal(modalId, data = null) {
    const modal = document.getElementById(modalId);
    if (!modal) return;

    // If data provided, populate modal fields
    if (data) {
        Object.keys(data).forEach(key => {
            const field = modal.querySelector(`[name="${key}"]`);
            if (field) {
                if (field.type === 'checkbox') {
                    field.checked = data[key];
                } else {
                    field.value = data[key];
                }
            }
            const display = modal.querySelector(`[data-field="${key}"]`);
            if (display) display.textContent = data[key];
        });
    }

    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

/**
 * Close modal
 * @param {string} modalId
 */
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;

    modal.classList.remove('show');
    document.body.style.overflow = '';
}

/**
 * Load content into modal via AJAX
 * @param {string} modalId
 * @param {string} url
 * @param {object} params
 */
function loadModalContent(modalId, url, params = {}) {
    const modal = document.getElementById(modalId);
    if (!modal) return;

    const body = modal.querySelector('.modal-body');
    if (body) {
        body.innerHTML = '<div class="text-center p-8"><div class="animate-spin" style="font-size:24px">⏳</div><p class="mt-3 text-secondary">جاري التحميل...</p></div>';
    }

    openModal(modalId);

    eqasAjax(url, 'GET', params, function(result) {
        if (body && result.html) {
            body.innerHTML = result.html;
        }
    });
}

/**
 * Submit form inside modal
 * @param {string} formId
 * @param {string} modalId
 */
function submitModalForm(formId, modalId) {
    const form = document.getElementById(formId);
    if (!form) return;

    eqasSubmitForm(form, function(result) {
        closeModal(modalId);
        showToast(result.message || 'تمت العملية بنجاح', 'success');
        // Optionally reload page or update data
        if (result.reload) {
            setTimeout(() => window.location.reload(), 1000);
        }
    });
}

// ============================================================
// 9. CHARTS
// ============================================================

/**
 * Initialize Chart.js with RTL defaults
 * @param {string} canvasId
 * @param {string} type
 * @param {object} data
 * @param {object} options
 * @returns {Chart}
 */
function initChart(canvasId, type, data, options = {}) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    // Default RTL options
    const defaultOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                rtl: true,
                textDirection: 'rtl',
                labels: {
                    font: {
                        family: "'Segoe UI', Tahoma, 'Noto Sans Arabic', Arial, sans-serif",
                        size: 12
                    },
                    padding: 16,
                    usePointStyle: true
                }
            },
            tooltip: {
                rtl: true,
                textDirection: 'rtl',
                titleFont: {
                    family: "'Segoe UI', Tahoma, 'Noto Sans Arabic', Arial, sans-serif"
                },
                bodyFont: {
                    family: "'Segoe UI', Tahoma, 'Noto Sans Arabic', Arial, sans-serif"
                }
            }
        },
        scales: type === 'bar' || type === 'line' ? {
            x: {
                reverse: true,
                ticks: {
                    font: {
                        family: "'Segoe UI', Tahoma, 'Noto Sans Arabic', Arial, sans-serif",
                        size: 11
                    }
                },
                grid: {
                    display: false
                }
            },
            y: {
                ticks: {
                    font: {
                        family: "'Segoe UI', Tahoma, 'Noto Sans Arabic', Arial, sans-serif",
                        size: 11
                    }
                },
                grid: {
                    color: 'rgba(0,0,0,0.05)'
                }
            }
        } : undefined
    };

    // Deep merge options
    const mergedOptions = deepMerge(defaultOptions, options);

    return new Chart(ctx, {
        type: type,
        data: data,
        options: mergedOptions
    });
}

/**
 * Deep merge two objects
 */
function deepMerge(target, source) {
    const output = Object.assign({}, target);
    if (isObject(target) && isObject(source)) {
        Object.keys(source).forEach(key => {
            if (isObject(source[key])) {
                if (!(key in target)) Object.assign(output, { [key]: source[key] });
                else output[key] = deepMerge(target[key], source[key]);
            } else {
                Object.assign(output, { [key]: source[key] });
            }
        });
    }
    return output;
}

function isObject(item) {
    return (item && typeof item === 'object' && !Array.isArray(item));
}

// Chart color palette
const CHART_COLORS = {
    primary: '#1a5632',
    secondary: '#c8a951',
    accent: '#e63946',
    info: '#17a2b8',
    purple: '#6f42c1',
    orange: '#fd7e14',
    teal: '#20c997',
    pink: '#e83e8c',
    primaryLight: 'rgba(26, 86, 50, 0.15)',
    secondaryLight: 'rgba(200, 169, 81, 0.15)',
    accentLight: 'rgba(230, 57, 70, 0.15)',
    infoLight: 'rgba(23, 162, 184, 0.15)'
};

// ============================================================
// 10. DATA EXPORT
// ============================================================

/**
 * Trigger data export
 * @param {string} format - Export format (csv, excel, pdf)
 * @param {string} url - Export endpoint
 * @param {object} params - Additional parameters
 */
function exportData(format, url, params = {}) {
    params.format = format;
    params._token = EQAS.csrfToken;

    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    form.style.display = 'none';

    Object.keys(params).forEach(key => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = params[key];
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);

    showToast('جاري تصدير البيانات...', 'info');
}

// ============================================================
// 11. TOAST NOTIFICATIONS
// ============================================================

/**
 * Show toast notification
 * @param {string} message - Toast message
 * @param {string} type - Toast type (success, danger, warning, info)
 * @param {number} duration - Duration in ms (default 5000)
 */
function showToast(message, type = 'info', duration = 5000) {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    const icons = {
        success: '✅',
        danger: '❌',
        warning: '⚠️',
        info: 'ℹ️'
    };

    const titles = {
        success: 'نجاح',
        danger: 'خطأ',
        warning: 'تنبيه',
        info: 'معلومة'
    };

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.style.position = 'relative';
    toast.innerHTML = `
        <span class="toast-icon">${icons[type] || icons.info}</span>
        <div class="toast-content">
            <div class="toast-title">${titles[type] || titles.info}</div>
            <div class="toast-message">${message}</div>
        </div>
        <button class="toast-close" onclick="this.closest('.toast').remove()">✕</button>
        <div class="toast-progress" style="animation-duration: ${duration}ms"></div>
    `;

    container.appendChild(toast);

    // Auto-remove after duration
    setTimeout(() => {
        if (toast.parentElement) {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(-30px)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }
    }, duration);
}

// ============================================================
// 12. ALERT DISMISS
// ============================================================

EQAS.initAlertDismiss = function() {
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('alert-dismiss') || e.target.closest('.alert-dismiss')) {
            const alert = e.target.closest('.alert');
            if (alert) {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                alert.style.transition = 'all 0.3s ease';
                setTimeout(() => alert.remove(), 300);
            }
        }
    });
};

// ============================================================
// 13. TOOLTIPS
// ============================================================

EQAS.initTooltips = function() {
    // Simple tooltip initialization using data attributes
    const tooltipElements = document.querySelectorAll('[data-tooltip]');
    tooltipElements.forEach(el => {
        el.classList.add('tooltip-wrapper');
        const tooltip = document.createElement('span');
        tooltip.className = 'tooltip';
        tooltip.textContent = el.dataset.tooltip;
        el.appendChild(tooltip);
    });
};

// ============================================================
// 14. UTILITY FUNCTIONS
// ============================================================

/**
 * Format number with Arabic-Indic or standard numerals
 * @param {number} num
 * @param {boolean} useArabicNumerals - Use ٠-٩ instead of 0-9
 * @returns {string}
 */
function formatNumber(num, useArabicNumerals = false) {
    if (num === null || num === undefined) return '٠';

    const formatted = Number(num).toLocaleString('ar-YE', {
        maximumFractionDigits: 2
    });

    if (useArabicNumerals) {
        return formatted.replace(/[0-9]/g, function(d) {
            return '٠١٢٣٤٥٦٧٨٩'[d];
        });
    }

    return formatted;
}

/**
 * Format date in Arabic format
 * @param {string|Date} date
 * @param {string} format - 'full', 'short', 'relative'
 * @returns {string}
 */
function formatDate(date, format = 'short') {
    if (!date) return '';

    const d = new Date(date);
    if (isNaN(d.getTime())) return '';

    const months = [
        'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
        'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'
    ];

    const days = [
        'الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'
    ];

    if (format === 'full') {
        return `${days[d.getDay()]} ${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}`;
    }

    if (format === 'short') {
        return `${d.getDate()}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
    }

    if (format === 'relative') {
        const now = new Date();
        const diff = now - d;
        const seconds = Math.floor(diff / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const daysDiff = Math.floor(hours / 24);

        if (seconds < 60) return 'الآن';
        if (minutes < 60) return `منذ ${minutes} دقيقة`;
        if (hours < 24) return `منذ ${hours} ساعة`;
        if (daysDiff < 7) return `منذ ${daysDiff} يوم`;
        if (daysDiff < 30) return `منذ ${Math.floor(daysDiff / 7)} أسبوع`;
        return `${d.getDate()} ${months[d.getMonth()]}`;
    }

    return d.toLocaleDateString('ar-YE');
}

/**
 * Toggle loading state on a button
 * @param {HTMLElement} button
 * @param {boolean} loading
 */
function toggleLoading(button, loading = true) {
    if (!button) return;

    if (loading) {
        button.dataset.originalText = button.innerHTML;
        button.classList.add('loading');
        button.disabled = true;
    } else {
        button.classList.remove('loading');
        button.disabled = false;
        if (button.dataset.originalText) {
            button.innerHTML = button.dataset.originalText;
        }
    }
}

/**
 * Debounce function
 * @param {function} func
 * @param {number} wait - Wait time in ms
 * @returns {function}
 */
function debounce(func, wait = 300) {
    let timeout;
    return function(...args) {
        const context = this;
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(context, args), wait);
    };
}

/**
 * Throttle function
 * @param {function} func
 * @param {number} limit - Limit in ms
 * @returns {function}
 */
function throttle(func, limit = 300) {
    let inThrottle;
    return function(...args) {
        const context = this;
        if (!inThrottle) {
            func.apply(context, args);
            inThrottle = true;
            setTimeout(() => { inThrottle = false; }, limit);
        }
    };
}

/**
 * Auto-save draft functionality
 * @param {string} formId
 * @param {string} storageKey
 * @param {number} interval - Save interval in ms
 */
function autoSaveDraft(formId, storageKey, interval = 30000) {
    const form = document.getElementById(formId);
    if (!form) return;

    // Load saved draft
    const saved = localStorage.getItem(storageKey);
    if (saved) {
        try {
            const data = JSON.parse(saved);
            Object.keys(data).forEach(key => {
                const field = form.querySelector(`[name="${key}"]`);
                if (field) {
                    if (field.type === 'checkbox') field.checked = data[key];
                    else field.value = data[key];
                }
            });
            showToast('تم استعادة المسودة المحفوظة', 'info');
        } catch (e) {
            console.error('Draft restore error:', e);
        }
    }

    // Save draft periodically
    setInterval(() => {
        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => { data[key] = value; });
        localStorage.setItem(storageKey, JSON.stringify(data));
    }, interval);
}

/**
 * Clear saved draft
 * @param {string} storageKey
 */
function clearDraft(storageKey) {
    localStorage.removeItem(storageKey);
}

// ============================================================
// 15. PASSWORD TOGGLE
// ============================================================

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('password-toggle') || e.target.closest('.password-toggle')) {
        const toggle = e.target.classList.contains('password-toggle') ? e.target : e.target.closest('.password-toggle');
        const input = toggle.parentElement.querySelector('input');
        if (input) {
            input.type = input.type === 'password' ? 'text' : 'password';
            toggle.textContent = input.type === 'password' ? '👁️' : '🙈';
        }
    }
});

// ============================================================
// 16. PRINT HELPER
// ============================================================

/**
 * Print specific content
 * @param {string} elementId - Element to print
 */
function printContent(elementId) {
    const content = document.getElementById(elementId);
    if (!content) return;

    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html dir="rtl" lang="ar">
        <head>
            <meta charset="UTF-8">
            <title>طباعة - نظام التقييم الشامل</title>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; direction: rtl; text-align: right; padding: 20px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #999; padding: 6px 10px; text-align: right; }
                th { background: #f0f0f0; font-weight: bold; }
            </style>
        </head>
        <body>${content.innerHTML}</body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
    printWindow.close();
}

// ============================================================
// 17. INITIALIZE ON DOM READY
// ============================================================

document.addEventListener('DOMContentLoaded', function() {
    EQAS.init();

    // Flash message auto-dismiss
    const flashMessages = document.querySelectorAll('.flash-message');
    flashMessages.forEach(msg => {
        setTimeout(() => {
            msg.style.opacity = '0';
            msg.style.transform = 'translateY(-10px)';
            msg.style.transition = 'all 0.3s ease';
            setTimeout(() => msg.remove(), 300);
        }, 5000);
    });
});

// ============================================================================
// EQAS - Chart.js Integration Helpers
// ============================================================================

/**
 * إنشاء رسم بياني دائري (Pie/Doughnut)
 * @param {string} canvasId معرف عنصر Canvas
 * @param {string} title العنوان
 * @param {Array} labels التسميات
 * @param {Array} data البيانات
 * @param {Array} colors الألوان
 * @param {boolean} isDoughnut هل هو دائري مجوف
 * @returns {Chart} كائن الرسم البياني
 */
function eqasPieChart(canvasId, title, labels, data, colors, isDoughnut = true) {
    var ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    var defaultColors = [
        '#1a5632', '#c8a951', '#dc3545', '#17a2b8', '#ffc107',
        '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#6610f2'
    ];

    return new Chart(ctx, {
        type: isDoughnut ? 'doughnut' : 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors || defaultColors.slice(0, data.length),
                borderWidth: 2,
                borderColor: '#ffffff',
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    rtl: true,
                    labels: {
                        font: { family: 'Tajawal, Arial, sans-serif', size: 12 },
                        padding: 16,
                        usePointStyle: true
                    }
                },
                title: {
                    display: !!title,
                    text: title,
                    font: { family: 'Tajawal, Arial, sans-serif', size: 16, weight: 'bold' },
                    rtl: true
                }
            }
        }
    });
}

/**
 * إنشاء رسم بياني شريطي (Bar)
 * @param {string} canvasId معرف عنصر Canvas
 * @param {string} title العنوان
 * @param {Array} labels التسميات
 * @param {Array} datasets مجموعات البيانات [{label, data, backgroundColor}]
 * @param {Object} options خيارات إضافية
 * @returns {Chart} كائن الرسم البياني
 */
function eqasBarChart(canvasId, title, labels, datasets, options = {}) {
    var ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    var defaultColors = ['#1a5632', '#c8a951', '#17a2b8', '#dc3545', '#ffc107'];

    var chartDatasets = datasets.map(function(ds, i) {
        return {
            label: ds.label || '',
            data: ds.data,
            backgroundColor: ds.backgroundColor || defaultColors[i % defaultColors.length],
            borderColor: ds.borderColor || defaultColors[i % defaultColors.length],
            borderWidth: ds.borderWidth || 1,
            borderRadius: ds.borderRadius || 4,
            maxBarThickness: ds.maxBarThickness || 60
        };
    });

    return new Chart(ctx, {
        type: 'bar',
        data: { labels: labels, datasets: chartDatasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: options.horizontal ? 'y' : 'x',
            plugins: {
                legend: {
                    position: 'top',
                    rtl: true,
                    labels: {
                        font: { family: 'Tajawal, Arial, sans-serif', size: 12 },
                        usePointStyle: true
                    }
                },
                title: {
                    display: !!title,
                    text: title,
                    font: { family: 'Tajawal, Arial, sans-serif', size: 16, weight: 'bold' },
                    rtl: true
                }
            },
            scales: {
                x: {
                    ticks: { font: { family: 'Tajawal, Arial, sans-serif', size: 11 } },
                    grid: { display: false }
                },
                y: {
                    ticks: { font: { family: 'Tajawal, Arial, sans-serif', size: 11 } },
                    grid: { color: 'rgba(0,0,0,0.06)' },
                    beginAtZero: true
                }
            }
        }
    });
}

/**
 * إنشاء رسم بياني خطي (Line)
 * @param {string} canvasId معرف عنصر Canvas
 * @param {string} title العنوان
 * @param {Array} labels التسميات
 * @param {Array} datasets مجموعات البيانات [{label, data, borderColor, fill}]
 * @param {Object} options خيارات إضافية
 * @returns {Chart} كائن الرسم البياني
 */
function eqasLineChart(canvasId, title, labels, datasets, options = {}) {
    var ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    var defaultColors = ['#1a5632', '#c8a951', '#17a2b8', '#dc3545', '#ffc107'];

    var chartDatasets = datasets.map(function(ds, i) {
        var color = ds.borderColor || defaultColors[i % defaultColors.length];
        return {
            label: ds.label || '',
            data: ds.data,
            borderColor: color,
            backgroundColor: ds.fill ? (ds.fillColor || color + '20') : 'transparent',
            fill: ds.fill || false,
            tension: ds.tension !== undefined ? ds.tension : 0.3,
            pointRadius: ds.pointRadius || 4,
            pointHoverRadius: ds.pointHoverRadius || 6,
            borderWidth: ds.borderWidth || 2
        };
    });

    return new Chart(ctx, {
        type: 'line',
        data: { labels: labels, datasets: chartDatasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    rtl: true,
                    labels: {
                        font: { family: 'Tajawal, Arial, sans-serif', size: 12 },
                        usePointStyle: true
                    }
                },
                title: {
                    display: !!title,
                    text: title,
                    font: { family: 'Tajawal, Arial, sans-serif', size: 16, weight: 'bold' },
                    rtl: true
                }
            },
            scales: {
                x: {
                    ticks: { font: { family: 'Tajawal, Arial, sans-serif', size: 11 } },
                    grid: { display: false }
                },
                y: {
                    ticks: { font: { family: 'Tajawal, Arial, sans-serif', size: 11 } },
                    grid: { color: 'rgba(0,0,0,0.06)' },
                    beginAtZero: options.beginAtZero !== false
                }
            },
            interaction: {
                mode: 'index',
                intersect: false
            }
        }
    });
}

/**
 * إنشاء مقياس نصفي (Gauge) باستخدام Doughnut
 * @param {string} canvasId معرف عنصر Canvas
 * @param {string} label التسمية
 * @param {number} value القيمة (0-100)
 * @param {string} color اللون
 * @returns {Chart} كائن الرسم البياني
 */
function eqasGauge(canvasId, label, value, color) {
    var ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    value = Math.min(100, Math.max(0, value));
    var remaining = 100 - value;

    if (!color) {
        if (value >= 85) color = '#28a745';
        else if (value >= 70) color = '#1a5632';
        else if (value >= 50) color = '#ffc107';
        else color = '#dc3545';
    }

    return new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: [label, 'المتبقي'],
            datasets: [{
                data: [value, remaining],
                backgroundColor: [color, '#e9ecef'],
                borderWidth: 0,
                cutout: '78%'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false }
            }
        },
        plugins: [{
            id: 'gaugeCenter',
            afterDraw: function(chart) {
                var width = chart.width;
                var height = chart.height;
                var cctx = chart.ctx;
                cctx.restore();
                var fontSize = (height / 114).toFixed(2);
                cctx.font = 'bold ' + fontSize + 'em Tajawal, Arial, sans-serif';
                cctx.fillStyle = color;
                cctx.textBaseline = 'middle';
                cctx.textAlign = 'center';
                var text = value + '%';
                var textX = Math.round((width - chart.chartArea.left) / 2 + chart.chartArea.left);
                var textY = Math.round((height - chart.chartArea.top) / 2 + chart.chartArea.top);
                cctx.fillText(text, textX, textY);
                cctx.save();
            }
        }]
    });
}

// ============================================================================
// EQAS - AJAX Utility Functions
// ============================================================================

/**
 * إرسال طلب AJAX باستخدام Fetch API
 * @param {string} url عنوان الطلب
 * @param {Object} options الخيارات {method, data, headers, onSuccess, onError, onFinally}
 */
function eqasFetch(url, options = {}) {
    var method = options.method || 'GET';
    var headers = Object.assign({
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
    }, options.headers || {});

    var fetchOptions = {
        method: method,
        headers: headers,
        credentials: 'same-origin'
    };

    if (method === 'POST' && options.data) {
        if (options.data instanceof FormData) {
            fetchOptions.body = options.data;
        } else {
            headers['Content-Type'] = 'application/x-www-form-urlencoded';
            fetchOptions.body = new URLSearchParams(options.data).toString();
        }
    }

    fetch(url, fetchOptions)
        .then(function(response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function(data) {
            if (typeof options.onSuccess === 'function') options.onSuccess(data);
        })
        .catch(function(error) {
            console.error('EQAS AJAX Error:', error);
            if (typeof options.onError === 'function') options.onError(error);
        })
        .finally(function() {
            if (typeof options.onFinally === 'function') options.onFinally();
        });
}

/**
 * تحديث عنصر HTML عبر AJAX
 * @param {string} url عنوان الطلب
 * @param {string} targetSelector محدد العنصر المستهدف
 * @param {Object} params المعاملات
 */
function eqasLoadPartial(url, targetSelector, params = {}) {
    var target = document.querySelector(targetSelector);
    if (!target) return;

    target.style.opacity = '0.5';
    target.style.pointerEvents = 'none';

    var queryString = new URLSearchParams(params).toString();
    var fullUrl = queryString ? url + '?' + queryString : url;

    fetch(fullUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(response) { return response.text(); })
    .then(function(html) {
        target.innerHTML = html;
        target.style.opacity = '1';
        target.style.pointerEvents = 'auto';
    })
    .catch(function(error) {
        console.error('EQAS Load Partial Error:', error);
        target.style.opacity = '1';
        target.style.pointerEvents = 'auto';
        target.innerHTML = '<div class="alert alert-danger">خطأ في تحميل البيانات</div>';
    });
}

// ============================================================================
// EQAS - Form Validation Helpers
// ============================================================================

/**
 * التحقق من صلاحية نموذج
 * @param {string} formSelector محدد النموذج
 * @param {Object} rules قواعد التحقق {fieldName: {required, min, max, pattern, message}}
 * @returns {Object} {valid, errors}
 */
function eqasValidateForm(formSelector, rules) {
    var form = document.querySelector(formSelector);
    if (!form) return { valid: false, errors: ['النموذج غير موجود'] };

    var errors = [];
    var valid = true;

    Object.keys(rules).forEach(function(fieldName) {
        var field = form.querySelector('[name="' + fieldName + '"]');
        if (!field) return;

        var rule = rules[fieldName];
        var value = field.value.trim();

        // إزالة رسائل الخطأ السابقة
        var existingError = field.parentElement.querySelector('.field-error');
        if (existingError) existingError.remove();
        field.classList.remove('is-invalid');

        if (rule.required && !value) {
            errors.push(rule.message || fieldName + ' مطلوب');
            field.classList.add('is-invalid');
            valid = false;
            return;
        }

        if (value && rule.min && value.length < rule.min) {
            errors.push(rule.message || fieldName + ' يجب أن يكون ' + rule.min + ' أحرف على الأقل');
            field.classList.add('is-invalid');
            valid = false;
        }

        if (value && rule.max && value.length > rule.max) {
            errors.push(rule.message || fieldName + ' يجب أن لا يتجاوز ' + rule.max + ' حرف');
            field.classList.add('is-invalid');
            valid = false;
        }

        if (value && rule.pattern && !rule.pattern.test(value)) {
            errors.push(rule.message || fieldName + ' التنسيق غير صحيح');
            field.classList.add('is-invalid');
            valid = false;
        }

        if (value && rule.minValue && parseFloat(value) < rule.minValue) {
            errors.push(rule.message || fieldName + ' يجب أن يكون ' + rule.minValue + ' على الأقل');
            field.classList.add('is-invalid');
            valid = false;
        }

        if (value && rule.maxValue && parseFloat(value) > rule.maxValue) {
            errors.push(rule.message || fieldName + ' يجب أن لا يتجاوز ' + rule.maxValue);
            field.classList.add('is-invalid');
            valid = false;
        }

        // إضافة رسالة خطأ بجانب الحقل
        if (!valid && errors.length > 0) {
            var errorDiv = document.createElement('div');
            errorDiv.className = 'field-error';
            errorDiv.style.cssText = 'color:var(--danger);font-size:12px;margin-top:4px;';
            errorDiv.textContent = errors[errors.length - 1];
            field.parentElement.appendChild(errorDiv);
        }
    });

    return { valid: valid, errors: errors };
}

/**
 * التحقق من قوة كلمة المرور في الوقت الحقيقي
 * @param {HTMLInputElement} input عنصر إدخال كلمة المرور
 * @param {string} indicatorSelector محدد عنصر المؤشر
 */
function eqasPasswordStrength(input, indicatorSelector) {
    if (!input) return;
    var indicator = document.querySelector(indicatorSelector);
    if (!indicator) return;

    input.addEventListener('input', function() {
        var val = this.value;
        var score = 0;
        if (val.length >= 8) score++;
        if (val.length >= 12) score++;
        if (/[a-z]/.test(val)) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^a-zA-Z0-9]/.test(val)) score++;

        var labels = ['ضعيفة جداً', 'ضعيفة', 'متوسطة', 'جيدة', 'قوية', 'قوية جداً', 'ممتازة'];
        var colors = ['#dc3545', '#dc3545', '#ffc107', '#17a2b8', '#28a745', '#28a745', '#1a5632'];
        var widths = ['15%', '25%', '40%', '55%', '70%', '85%', '100%'];

        indicator.innerHTML = '<div style="height:4px;background:#e9ecef;border-radius:2px;margin-top:4px;">' +
            '<div style="height:100%;width:' + (widths[score] || '0%') + ';background:' + (colors[score] || '#dc3545') + ';border-radius:2px;transition:all 0.3s;"></div>' +
            '</div>' +
            '<span style="font-size:11px;color:' + (colors[score] || '#dc3545') + ';">' + (labels[score] || '') + '</span>';
    });
}

// ============================================================================
// EQAS - Dashboard Data Loader
// ============================================================================

/**
 * تحميل بيانات لوحة التحكم عبر AJAX
 * @param {string} endpoint نقطة البيانات
 * @param {Function} callback دالة المعالجة
 */
function eqasLoadDashboardData(endpoint, callback) {
    eqasFetch(endpoint, {
        method: 'GET',
        onSuccess: function(data) {
            if (data.success !== false && typeof callback === 'function') {
                callback(data);
            }
        },
        onError: function(error) {
            console.warn('Dashboard data load error:', endpoint, error);
        }
    });
}

/**
 * تحديث إحصائيات لوحة التحكم بتأثير حركي
 * @param {string} selector محدد العنصر
 * @param {number} targetValue القيمة المستهدفة
 * @param {number} duration مدة الحركة بالمللي ثانية
 */
function eqasAnimateNumber(selector, targetValue, duration) {
    duration = duration || 1000;
    var element = document.querySelector(selector);
    if (!element) return;

    var startValue = parseInt(element.textContent) || 0;
    var startTime = null;

    function step(timestamp) {
        if (!startTime) startTime = timestamp;
        var progress = Math.min((timestamp - startTime) / duration, 1);
        var eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
        var current = Math.floor(startValue + (targetValue - startValue) * eased);
        element.textContent = current.toLocaleString('ar-SA');
        if (progress < 1) {
            requestAnimationFrame(step);
        }
    }

    requestAnimationFrame(step);
}

/**
 * إنشاء رسوم بيانية EQI للمدرسة
 * @param {string} canvasId معرف Canvas
 * @param {Object} eqiData بيانات المؤشر {dimensions: {name: score, ...}, overall: number}
 */
function eqasEqiRadar(canvasId, eqiData) {
    var ctx = document.getElementById(canvasId);
    if (!ctx || !eqiData || !eqiData.dimensions) return null;

    var labels = Object.keys(eqiData.dimensions);
    var data = Object.values(eqiData.dimensions);
    var labelsAr = labels.map(function(l) {
        var map = {
            'academic': 'أكاديمي',
            'teaching': 'تدريس',
            'climate': 'بيئة مدرسية',
            'behavior': 'سلوك',
            'skills': 'مهارات',
            'attendance': 'حضور'
        };
        return map[l] || l;
    });

    return new Chart(ctx, {
        type: 'radar',
        data: {
            labels: labelsAr,
            datasets: [{
                label: 'مؤشر جودة التعليم',
                data: data,
                backgroundColor: 'rgba(26,86,50,0.2)',
                borderColor: '#1a5632',
                borderWidth: 2,
                pointBackgroundColor: '#1a5632',
                pointBorderColor: '#fff',
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    rtl: true,
                    labels: {
                        font: { family: 'Tajawal, Arial, sans-serif', size: 12 }
                    }
                }
            },
            scales: {
                r: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        stepSize: 20,
                        font: { family: 'Tajawal, Arial, sans-serif', size: 10 }
                    },
                    pointLabels: {
                        font: { family: 'Tajawal, Arial, sans-serif', size: 13, weight: 'bold' }
                    }
                }
            }
        }
    });
}


// ============================================================
// 18. DELETE CONFIRMATION ENHANCEMENT
// ============================================================

/**
 * Global styled confirmation modal
 * Replaces browser default confirm() with a custom RTL overlay
 * @param {string} message - Confirmation message to display
 * @param {function} callback - Function to call on confirm
 */
function eqasConfirm(message, callback) {
    var overlay = document.createElement('div');
    overlay.className = 'eqas-confirm-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'eqas-confirm-title');
    overlay.innerHTML =
        '<div class="eqas-confirm-dialog">' +
            '<div class="eqas-confirm-icon">\u26A0\uFE0F</div>' +
            '<h3 id="eqas-confirm-title" class="eqas-confirm-title">\u062A\u0623\u0643\u064A\u062F \u0627\u0644\u0639\u0645\u0644\u064A\u0629</h3>' +
            '<p class="eqas-confirm-message">' + message + '</p>' +
            '<div class="eqas-confirm-actions">' +
                '<button class="btn btn-danger eqas-confirm-yes" autofocus>\u0646\u0639\u0645\u060C \u062A\u0623\u0643\u064A\u062F</button>' +
                '<button class="btn btn-light eqas-confirm-no">\u0625\u0644\u063A\u0627\u0621</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);
    overlay.querySelector('.eqas-confirm-yes').focus();

    overlay.querySelector('.eqas-confirm-yes').addEventListener('click', function() {
        overlay.remove();
        if (callback) callback();
    });
    overlay.querySelector('.eqas-confirm-no').addEventListener('click', function() {
        overlay.remove();
    });
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.remove();
    });
    overlay.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') overlay.remove();
    });
}

// Override .btn-danger with onclick confirm
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-danger[onclick*="confirm"]').forEach(function(btn) {
        var originalOnclick = btn.getAttribute('onclick');
        btn.removeAttribute('onclick');
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            eqasConfirm('\u0647\u0644 \u0623\u0646\u062A \u0645\u062A\u0623\u0643\u062F \u0645\u0646 \u0647\u0630\u0647 \u0627\u0644\u0639\u0645\u0644\u064A\u0629 \u0627\u0644\u062E\u0637\u064A\u0631\u0629\u061F', function() {
                var remaining = originalOnclick.replace(/^(?:return\s+)?confirm\s*\([^)]*\)\s*(?:&&\s*)?/, '');
                if (remaining.trim()) {
                    eval(remaining);
                } else if (btn.tagName === 'A' && btn.href) {
                    window.location.href = btn.href;
                }
            });
        });
    });
});

// ============================================================
// 19. AUTO-SAVE FORM DATA
// ============================================================

/**
 * Auto-save form data to localStorage with indicator
 * @param {string} formSelector - CSS selector for the form
 * @param {string} key - localStorage key for saving
 */
function eqasAutoSave(formSelector, key) {
    var form = document.querySelector(formSelector);
    if (!form) return;

    // Add auto-save indicator
    var indicator = form.querySelector('.auto-save-indicator');
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.className = 'auto-save-indicator';
        indicator.innerHTML = '\uD83D\uDCBE <span>\u0645\u0633\u0648\u062F\u0629 \u0645\u062D\u0641\u0648\u0638\u0629</span>';
        indicator.style.display = 'none';
        form.style.position = 'relative';
        form.appendChild(indicator);
    }

    // Restore saved data on page load
    var saved = localStorage.getItem(key);
    if (saved) {
        try {
            var data = JSON.parse(saved);
            Object.keys(data).forEach(function(k) {
                var field = form.querySelector('[name="' + k + '"]');
                if (field) {
                    if (field.type === 'checkbox') field.checked = data[k];
                    else if (field.type === 'radio') {
                        var radio = form.querySelector('[name="' + k + '"][value="' + data[k] + '"]');
                        if (radio) radio.checked = true;
                    } else field.value = data[k];
                }
            });
            indicator.style.display = 'flex';
            showToast('\u062A\u0645 \u0627\u0633\u062A\u0639\u0627\u062F\u0629 \u0627\u0644\u0645\u0633\u0648\u062F\u0629 \u0627\u0644\u0645\u062D\u0641\u0648\u0638\u0629', 'info', 3000);
        } catch (e) { /* ignore parse errors */ }
    }

    // Save periodically every 30 seconds
    var saveInterval = setInterval(function() {
        var formData = new FormData(form);
        var saveData = {};
        formData.forEach(function(v, k) { saveData[k] = v; });
        localStorage.setItem(key, JSON.stringify(saveData));
        indicator.style.display = 'flex';
        indicator.querySelector('span').textContent = '\u0645\u0633\u0648\u062F\u0629 \u0645\u062D\u0641\u0648\u0638\u0629 ' + new Date().toLocaleTimeString('ar-YE');
    }, 30000);

    // Clear saved data on successful submit
    form.addEventListener('submit', function() {
        localStorage.removeItem(key);
        indicator.style.display = 'none';
        clearInterval(saveInterval);
    });
}

// ============================================================
// 20. ACCESSIBILITY IMPROVEMENTS
// ============================================================

document.addEventListener('DOMContentLoaded', function() {
    // Add aria-label to icon-only buttons
    document.querySelectorAll('button, .btn, a.btn').forEach(function(btn) {
        var text = btn.textContent.trim();
        var hasIconOnly = btn.querySelector('i, svg, .icon, img') && text.length < 3;
        if (hasIconOnly && !btn.getAttribute('aria-label')) {
            var title = btn.getAttribute('title') || btn.dataset.tooltip || '';
            if (title) btn.setAttribute('aria-label', title);
            else btn.setAttribute('aria-label', '\u0632\u0631');
        }
    });

    // Keyboard navigation for dropdowns
    document.querySelectorAll('.dropdown-menu, .user-dropdown-menu, .notification-dropdown').forEach(function(dropdown) {
        dropdown.setAttribute('role', 'menu');
        dropdown.querySelectorAll('a, button, .dropdown-item, .user-dropdown-item').forEach(function(item) {
            item.setAttribute('role', 'menuitem');
            item.setAttribute('tabindex', '0');
            item.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); item.click(); }
            });
        });
    });

    // Focus trap for modals
    document.addEventListener('keydown', function(e) {
        var openModal = document.querySelector('.modal-overlay.show, .eqas-confirm-overlay');
        if (!openModal || e.key !== 'Tab') return;
        var focusable = openModal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable.length === 0) return;
        var first = focusable[0], last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
});

// ============================================================
// 21. PRINT FUNCTIONALITY
// ============================================================

/**
 * Print a specific page section with RTL support
 * @param {string} elementId - ID of the element to print
 */
function eqasPrint(elementId) {
    var content = document.getElementById(elementId);
    if (!content) return;

    var printWindow = window.open('', '_blank', 'width=800,height=600');
    printWindow.document.write(
        '<!DOCTYPE html><html dir="rtl" lang="ar"><head>' +
        '<meta charset="UTF-8"><title>\u0637\u0628\u0627\u0639\u0629 - \u0646\u0638\u0627\u0645 \u0627\u0644\u062A\u0642\u064A\u064A\u0645 \u0627\u0644\u0634\u0627\u0645\u0644</title>' +
        '<style>' +
        'body{font-family:Segoe UI,Tahoma,Arial,sans-serif;direction:rtl;text-align:right;padding:20px;color:#000;}' +
        'table{width:100%;border-collapse:collapse;}th,td{border:1px solid #999;padding:6px 10px;text-align:right;}' +
        'th{background:#f0f0f0;font-weight:bold;}.card{border:1px solid #ccc;padding:16px;margin:10px 0;}' +
        '.card-header{font-weight:bold;border-bottom:1px solid #ccc;padding-bottom:8px;margin-bottom:8px;}' +
        'h1,h2,h3{color:#1a5632;}' +
        '@page{margin:1cm;direction:rtl;size:A4;}' +
        '</style></head><body>' + content.innerHTML + '</body></html>'
    );
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() { printWindow.print(); printWindow.close(); }, 300);
}

// Auto-add print button to cards
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.card').forEach(function(card) {
        if (!card.id) card.id = 'card-' + Math.random().toString(36).substr(2, 9);
        var header = card.querySelector('.card-header');
        if (header && !header.querySelector('.eqas-print-btn')) {
            var printBtn = document.createElement('button');
            printBtn.className = 'btn btn-light btn-sm eqas-print-btn';
            printBtn.setAttribute('aria-label', '\u0637\u0628\u0627\u0639\u0629');
            printBtn.innerHTML = '\uD83D\uDDA8\uFE0F \u0637\u0628\u0627\u0639\u0629';
            printBtn.style.marginRight = 'auto';
            printBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                eqasPrint(card.id);
            });
            header.appendChild(printBtn);
        }
    });
});

// ============================================================
// 22. NOTIFICATION BADGE
// ============================================================

/**
 * Poll for unread notification count and update sidebar badge
 */
function eqasUpdateNotifBadge() {
    eqasAjax(EQAS.baseUrl + '/api/notifications/count.php', 'GET', null, function(result) {
        if (result.count === undefined) return;
        var badge = document.querySelector('#notification-toggle .header-action-badge, .sidebar-badge');
        if (!badge) return;
        var prevCount = parseInt(badge.textContent) || 0;
        badge.textContent = result.count;
        badge.style.display = result.count > 0 ? 'flex' : 'none';

        // Pulse animation when new notifications arrive
        if (result.count > prevCount) {
            badge.classList.add('badge-pulse');
            setTimeout(function() { badge.classList.remove('badge-pulse'); }, 2000);
        }
    });
}

// Poll every 60 seconds
setInterval(eqasUpdateNotifBadge, 60000);

// ============================================================
// 23. FORM VALIDATION ENHANCEMENT
// ============================================================

/**
 * Enhanced form validation with Arabic messages and error count badge
 * @param {string} formSelector - CSS selector for the form
 */
function eqasValidate(formSelector) {
    var form = document.querySelector(formSelector);
    if (!form) return;

    var arabicMessages = {
        required: '\u0647\u0630\u0627 \u0627\u0644\u062D\u0642\u0644 \u0645\u0637\u0644\u0648\u0628',
        email: '\u064A\u0631\u062C\u0649 \u0625\u062F\u062E\u0627\u0644 \u0628\u0631\u064A\u062F \u0625\u0644\u0643\u062A\u0631\u0648\u0646\u064A \u0635\u062D\u064A\u062D',
        numeric: '\u064A\u0631\u062C\u0649 \u0625\u062F\u062E\u0627\u0644 \u0631\u0642\u0645 \u0635\u062D\u064A\u062D',
        minlength: '\u0627\u0644\u0646\u0635 \u0642\u0635\u064A\u0631 \u062C\u062F\u0627\u064B',
        maxlength: '\u0627\u0644\u0646\u0635 \u0637\u0648\u064A\u0644 \u062C\u062F\u0627\u064B',
        pattern: '\u0627\u0644\u0635\u064A\u063A\u0629 \u063A\u064A\u0631 \u0635\u062D\u064A\u062D\u0629'
    };

    // Real-time validation on blur
    form.querySelectorAll('.form-control').forEach(function(input) {
        input.addEventListener('blur', function() {
            eqasValidateField(input, arabicMessages);
        });
    });

    // On form submit, count errors and show badge
    form.addEventListener('submit', function(e) {
        var errors = 0;
        form.querySelectorAll('.form-control').forEach(function(input) {
            if (!eqasValidateField(input, arabicMessages)) errors++;
        });
        updateErrorBadge(form, errors);
        if (errors > 0) { e.preventDefault(); e.stopPropagation(); }
    });
}

/**
 * Validate a single field and show Arabic error
 * @param {HTMLElement} input
 * @param {object} messages
 * @returns {boolean}
 */
function eqasValidateField(input, messages) {
    var val = input.value.trim();
    var error = '';
    if (input.hasAttribute('required') && !val) error = messages.required;
    else if (input.type === 'email' && val && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) error = messages.email;
    else if (input.dataset.numeric !== undefined && val && isNaN(val)) error = messages.numeric;
    else if (input.minLength > 0 && val.length < input.minLength) error = messages.minlength;
    else if (input.maxLength > 0 && val.length > input.maxLength) error = messages.maxlength;

    var existing = input.parentElement.querySelector('.field-error');
    if (error) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        if (!existing) {
            var errEl = document.createElement('div');
            errEl.className = 'field-error';
            errEl.textContent = error;
            input.parentElement.appendChild(errEl);
        } else { existing.textContent = error; }
        return false;
    } else {
        input.classList.remove('is-invalid');
        if (val) input.classList.add('is-valid');
        if (existing) existing.remove();
        return true;
    }
}

/**
 * Update or create error count badge on submit button
 */
function updateErrorBadge(form, count) {
    var btn = form.querySelector('[type="submit"]');
    if (!btn) return;
    var badge = btn.querySelector('.error-count-badge');
    if (count > 0) {
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'error-count-badge';
            btn.appendChild(badge);
        }
        badge.textContent = count;
    } else if (badge) {
        badge.remove();
    }
}

// ============================================================
// 24. MOBILE / TOUCH UX ENHANCEMENTS
// ============================================================

/**
 * EQAS Mobile UX Module
 * Comprehensive touch and mobile user experience enhancements
 * for the EQAS (نظام التقييم الشامل لجودة التعليم المدرسي )
 *
 * Includes:
 *   1. Swipe Gesture for Sidebar
 *   2. Scroll-to-Top Button
 *   3. Touch-Friendly Table Rows
 *   4. Mobile Dropdown Improvements
 *   5. Pull-to-Refresh Detection
 *   6. Mobile Search Enhancement
 *   7. Viewport Height Fix (iOS Safari)
 *   8. Orientation Change Handler
 *   9. Touch Ripple Effect
 *  10. Lazy Image Loading
 *  11. Form Auto-Save Indicator
 *  12. Network Status Detection
 */
const EQASMobile = {

    // Internal state
    _swipeState: null,
    _pullState: null,
    _scrollToTopBtn: null,
    _searchOverlay: null,
    _autoSaveIndicator: null,
    _isOnline: navigator.onLine,
    _doubleTapTimers: {},

    /**
     * Initialize all mobile enhancements
     */
    init() {
        if (!this._isMobileCapable()) return;

        this.initViewportHeightFix();
        this.initSwipeGesture();
        this.initScrollToTop();
        this.initTouchTableRows();
        this.initMobileDropdowns();
        this.initPullToRefresh();
        this.initMobileSearch();
        this.initOrientationHandler();
        this.initTouchRipple();
        this.initLazyImages();
        this.initAutoSaveIndicator();
        this.initNetworkStatus();
    },

    /**
     * Check if the device is mobile-capable (touch or small screen)
     * @returns {boolean}
     */
    _isMobileCapable() {
        return ('ontouchstart' in window) || (window.innerWidth < 992) || (navigator.maxTouchPoints > 0);
    },

    /**
     * Check if currently in mobile viewport
     * @returns {boolean}
     */
    _isMobileViewport() {
        return window.innerWidth < 992;
    },

    // ============================================================
    // 24.1 SWIPE GESTURE FOR SIDEBAR
    // ============================================================

    /**
     * Add touch swipe gestures for sidebar open/close on mobile
     * - Swipe right (from right edge 30px) to open sidebar
     * - Swipe left to close sidebar
     * - Minimum swipe distance: 50px
     * - Only active on screen width < 992px
     * - Prevents swipe when scrolling vertically
     */
    initSwipeGesture() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        if (!sidebar) return;

        this._swipeState = {
            startX: 0,
            startY: 0,
            startTime: 0,
            isTracking: false,
            isVerticalScroll: false
        };

        const MIN_SWIPE_DISTANCE = 50;
        const EDGE_ZONE = 30; // px from right edge (RTL: right is the far edge)
        const MAX_TIME = 500; // ms

        document.addEventListener('touchstart', (e) => {
            if (!this._isMobileViewport()) return;

            const touch = e.touches[0];
            const startX = touch.clientX;
            const startY = touch.clientY;
            const screenWidth = window.innerWidth;

            // Only track swipes that start from the right edge zone (RTL layout)
            // or track when sidebar is already open (for closing swipe)
            const sidebarOpen = sidebar.classList.contains('show');

            if (sidebarOpen || startX >= (screenWidth - EDGE_ZONE)) {
                this._swipeState.startX = startX;
                this._swipeState.startY = startY;
                this._swipeState.startTime = Date.now();
                this._swipeState.isTracking = true;
                this._swipeState.isVerticalScroll = false;
            }
        }, { passive: true });

        document.addEventListener('touchmove', (e) => {
            if (!this._swipeState.isTracking) return;

            const touch = e.touches[0];
            const deltaX = touch.clientX - this._swipeState.startX;
            const deltaY = touch.clientY - this._swipeState.startY;

            // Detect vertical scroll intent — if Y movement dominates, abort swipe tracking
            if (Math.abs(deltaY) > Math.abs(deltaX) && Math.abs(deltaY) > 10) {
                this._swipeState.isVerticalScroll = true;
            }
        }, { passive: true });

        document.addEventListener('touchend', (e) => {
            if (!this._swipeState.isTracking || this._swipeState.isVerticalScroll) {
                this._swipeState.isTracking = false;
                return;
            }

            const touch = e.changedTouches[0];
            const deltaX = touch.clientX - this._swipeState.startX;
            const deltaTime = Date.now() - this._swipeState.startTime;
            const sidebarOpen = sidebar.classList.contains('show');

            // In RTL: swiping left means deltaX < 0, swiping right means deltaX > 0
            // Swipe left (deltaX < -MIN) = close sidebar
            // Swipe right (deltaX > MIN) from edge = open sidebar
            if (deltaTime <= MAX_TIME) {
                if (sidebarOpen && deltaX < -MIN_SWIPE_DISTANCE) {
                    // Close sidebar on swipe left
                    sidebar.classList.remove('show');
                    if (overlay) overlay.classList.remove('show');
                    document.body.classList.remove('sidebar-open');
                } else if (!sidebarOpen && deltaX > MIN_SWIPE_DISTANCE) {
                    // Open sidebar on swipe right (if started from right edge zone)
                    const startedFromEdge = this._swipeState.startX >= (window.innerWidth - EDGE_ZONE);
                    if (startedFromEdge) {
                        sidebar.classList.add('show');
                        if (overlay) overlay.classList.add('show');
                        document.body.classList.add('sidebar-open');
                    }
                }
            }

            this._swipeState.isTracking = false;
        }, { passive: true });
    },

    // ============================================================
    // 24.2 SCROLL-TO-TOP BUTTON
    // ============================================================

    /**
     * Create a floating scroll-to-top button
     * - Appears after scrolling 300px
     * - Positioned bottom-left (far side in RTL)
     * - Smooth scroll to top on click
     * - z-index above content, below modals
     * - Auto-hides when near top
     */
    initScrollToTop() {
        // Create the button element
        const btn = document.createElement('button');
        btn.id = 'eqas-scroll-top';
        btn.setAttribute('aria-label', 'العودة للأعلى');
        btn.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"></polyline></svg>';
        btn.style.cssText = [
            'position: fixed',
            'bottom: 24px',
            'left: 24px',  // In RTL, left is the far side
            'width: 44px',
            'height: 44px',
            'border-radius: 50%',
            'border: none',
            'background: var(--primary, #1a5632)',
            'color: #fff',
            'cursor: pointer',
            'display: none',
            'align-items: center',
            'justify-content: center',
            'z-index: 1040', // Below modals (1050+), above content
            'box-shadow: 0 2px 8px rgba(0,0,0,0.25)',
            'transition: opacity 0.3s ease, transform 0.3s ease',
            'opacity: 0',
            'transform: scale(0.8)',
            '-webkit-tap-highlight-color: transparent'
        ].join(';');

        document.body.appendChild(btn);
        this._scrollToTopBtn = btn;

        // Show/hide on scroll (throttled)
        const checkVisibility = throttle(() => {
            if (window.scrollY > 300) {
                btn.style.display = 'flex';
                requestAnimationFrame(() => {
                    btn.style.opacity = '1';
                    btn.style.transform = 'scale(1)';
                });
            } else {
                btn.style.opacity = '0';
                btn.style.transform = 'scale(0.8)';
                setTimeout(() => { if (window.scrollY <= 300) btn.style.display = 'none'; }, 300);
            }
        }, 150);

        window.addEventListener('scroll', checkVisibility, { passive: true });

        // Smooth scroll to top on click
        btn.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    },

    // ============================================================
    // 24.3 TOUCH-FRIENDLY TABLE ROWS
    // ============================================================

    /**
     * Add touch feedback (highlight) on table rows for mobile
     * Add double-tap to view details behavior
     */
    initTouchTableRows() {
        // Touch highlight via event delegation
        document.addEventListener('touchstart', (e) => {
            const row = e.target.closest('.data-table tbody tr');
            if (!row) return;

            // Add highlight class
            row.classList.add('eqas-row-touched');
        }, { passive: true });

        document.addEventListener('touchend', (e) => {
            // Remove highlight from all touched rows
            document.querySelectorAll('.eqas-row-touched').forEach(row => {
                row.classList.remove('eqas-row-touched');
            });

            const row = e.target.closest('.data-table tbody tr');
            if (!row) return;

            // Double-tap detection
            const rowId = row.dataset.id || row.rowIndex;
            const now = Date.now();

            if (this._doubleTapTimers[rowId] && (now - this._doubleTapTimers[rowId]) < 350) {
                // Double-tap detected — trigger detail view
                e.preventDefault();
                const detailLink = row.querySelector('[data-detail-url], .row-detail-link, a[href*="detail"], a[href*="view"]');
                if (detailLink) {
                    detailLink.click();
                } else if (row.dataset.detailUrl) {
                    window.location.href = row.dataset.detailUrl;
                }
                delete this._doubleTapTimers[rowId];
            } else {
                this._doubleTapTimers[rowId] = now;
                // Clear single-tap timer after timeout
                setTimeout(() => { delete this._doubleTapTimers[rowId]; }, 400);
            }
        }, { passive: false });

        // Also clean up on touchcancel
        document.addEventListener('touchcancel', () => {
            document.querySelectorAll('.eqas-row-touched').forEach(row => {
                row.classList.remove('eqas-row-touched');
            });
        }, { passive: true });

        // Inject touch-row highlight style
        this._injectStyle('eqas-table-touch-style', `
            .data-table tbody tr.eqas-row-touched {
                background-color: rgba(26, 86, 50, 0.08) !important;
                transition: background-color 0.15s ease;
            }
            @media (max-width: 991px) {
                .data-table tbody tr {
                    -webkit-tap-highlight-color: transparent;
                    cursor: pointer;
                }
            }
        `);
    },

    // ============================================================
    // 24.4 MOBILE DROPDOWN IMPROVEMENTS
    // ============================================================

    /**
     * Make notification and user dropdowns full-width on mobile
     * Enhanced outside-tap-to-close for all dropdowns
     */
    initMobileDropdowns() {
        // Inject mobile dropdown styles
        this._injectStyle('eqas-mobile-dropdown-style', `
            @media (max-width: 991px) {
                .notification-dropdown.show,
                .user-dropdown-menu.show {
                    position: fixed !important;
                    top: auto !important;
                    bottom: 0 !important;
                    left: 0 !important;
                    right: 0 !important;
                    width: 100% !important;
                    max-height: 70vh;
                    overflow-y: auto;
                    border-radius: 16px 16px 0 0 !important;
                    box-shadow: 0 -4px 20px rgba(0,0,0,0.15) !important;
                    z-index: 1050 !important;
                    animation: eqasDropdownSlideUp 0.3s ease;
                }
                @keyframes eqasDropdownSlideUp {
                    from { transform: translateY(100%); opacity: 0; }
                    to { transform: translateY(0); opacity: 1; }
                }
            }
        `);

        // Enhanced outside-tap-to-close for mobile
        document.addEventListener('touchstart', (e) => {
            if (!this._isMobileViewport()) return;

            const openDropdowns = document.querySelectorAll(
                '.notification-dropdown.show, .user-dropdown-menu.show, .dropdown-menu.show'
            );
            openDropdowns.forEach(dropdown => {
                const parent = dropdown.parentElement;
                if (parent && !parent.contains(e.target)) {
                    dropdown.classList.remove('show');
                    // Also remove any mobile overlay
                    const backdrop = document.querySelector('.eqas-dropdown-backdrop');
                    if (backdrop) backdrop.remove();
                }
            });
        }, { passive: true });

        // Add backdrop overlay when dropdown is shown on mobile
        const observer = new MutationObserver((mutations) => {
            if (!this._isMobileViewport()) return;

            mutations.forEach(mutation => {
                mutation.addedNodes.forEach(node => {
                    if (node.nodeType !== 1) return;
                });
                // Check for dropdown show
                const hasOpen = document.querySelector('.notification-dropdown.show, .user-dropdown-menu.show');
                const existingBackdrop = document.querySelector('.eqas-dropdown-backdrop');

                if (hasOpen && !existingBackdrop) {
                    const backdrop = document.createElement('div');
                    backdrop.className = 'eqas-dropdown-backdrop';
                    backdrop.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.3);z-index:1049;';
                    backdrop.addEventListener('click', () => {
                        document.querySelectorAll('.notification-dropdown.show, .user-dropdown-menu.show, .dropdown-menu.show')
                            .forEach(d => d.classList.remove('show'));
                        backdrop.remove();
                    });
                    document.body.appendChild(backdrop);
                } else if (!hasOpen && existingBackdrop) {
                    existingBackdrop.remove();
                }
            });
        });

        observer.observe(document.body, { subtree: true, attributes: true, attributeFilter: ['class'] });
    },

    // ============================================================
    // 24.5 PULL-TO-REFRESH DETECTION
    // ============================================================

    /**
     * Add pull-to-refresh detection (visual indicator only)
     * Dispatches custom 'eqas:pull-refresh' event
     * Does NOT actually refresh the page
     */
    initPullToRefresh() {
        this._pullState = {
            startY: 0,
            pulling: false,
            indicator: null
        };

        // Create pull indicator element (hidden by default)
        const indicator = document.createElement('div');
        indicator.id = 'eqas-pull-indicator';
        indicator.setAttribute('aria-hidden', 'true');
        indicator.innerHTML = '<div class="eqas-pull-arrow">&#8595;</div><span class="eqas-pull-text">اسحب للتحديث</span>';
        indicator.style.cssText = [
            'position: fixed',
            'top: -50px',
            'right: 0',
            'left: 0',
            'height: 50px',
            'display: flex',
            'align-items: center',
            'justify-content: center',
            'gap: 8px',
            'background: var(--primary, #1a5632)',
            'color: #fff',
            'font-family: inherit',
            'font-size: 14px',
            'z-index: 9999',
            'transition: top 0.3s ease',
            'border-radius: 0 0 12px 12px'
        ].join(';');
        document.body.appendChild(indicator);
        this._pullState.indicator = indicator;

        const PULL_THRESHOLD = 80;

        document.addEventListener('touchstart', (e) => {
            // Only activate when at the top of the page
            if (window.scrollY > 0) return;

            this._pullState.startY = e.touches[0].clientY;
            this._pullState.pulling = true;
        }, { passive: true });

        document.addEventListener('touchmove', (e) => {
            if (!this._pullState.pulling) return;
            if (window.scrollY > 0) {
                this._pullState.pulling = false;
                indicator.style.top = '-50px';
                return;
            }

            const pullDistance = e.touches[0].clientY - this._pullState.startY;
            if (pullDistance > 0) {
                // Show pull indicator
                const progress = Math.min(pullDistance, PULL_THRESHOLD * 1.5);
                indicator.style.top = (progress - 50) + 'px';

                if (pullDistance >= PULL_THRESHOLD) {
                    indicator.querySelector('.eqas-pull-text').textContent = 'افتح للتحديث';
                    indicator.querySelector('.eqas-pull-arrow').style.transform = 'rotate(180deg)';
                } else {
                    indicator.querySelector('.eqas-pull-text').textContent = 'اسحب للتحديث';
                    indicator.querySelector('.eqas-pull-arrow').style.transform = 'rotate(0deg)';
                }
            }
        }, { passive: true });

        document.addEventListener('touchend', () => {
            if (!this._pullState.pulling) return;

            const indicatorTop = parseInt(indicator.style.top) || -50;

            if (indicatorTop >= (PULL_THRESHOLD - 50)) {
                // Threshold reached — dispatch custom event
                indicator.querySelector('.eqas-pull-text').textContent = 'جاري التحديث...';
                indicator.querySelector('.eqas-pull-arrow').style.display = 'none';

                // Dispatch the custom event for other code to listen to
                const event = new CustomEvent('eqas:pull-refresh', {
                    bubbles: true,
                    detail: { timestamp: Date.now() }
                });
                document.dispatchEvent(event);

                // Animate indicator back up after a brief delay
                setTimeout(() => {
                    indicator.style.top = '-50px';
                    indicator.querySelector('.eqas-pull-arrow').style.display = '';
                    indicator.querySelector('.eqas-pull-arrow').style.transform = 'rotate(0deg)';
                }, 1200);
            } else {
                // Not enough pull — snap back
                indicator.style.top = '-50px';
            }

            this._pullState.pulling = false;
        }, { passive: true });
    },

    // ============================================================
    // 24.6 MOBILE SEARCH ENHANCEMENT
    // ============================================================

    /**
     * On mobile, expand search input when focused
     * Add search overlay on mobile for better UX
     */
    initMobileSearch() {
        const searchInput = document.querySelector('.header-search input, .search-input, #global-search');
        if (!searchInput) return;

        // Create mobile search overlay
        const overlay = document.createElement('div');
        overlay.id = 'eqas-search-overlay';
        overlay.style.cssText = [
            'position: fixed',
            'top: 0',
            'right: 0',
            'left: 0',
            'bottom: 0',
            'background: rgba(0,0,0,0.5)',
            'z-index: 1045',
            'display: none',
            'opacity: 0',
            'transition: opacity 0.25s ease'
        ].join(';');
        document.body.appendChild(overlay);
        this._searchOverlay = overlay;

        // Inject mobile search styles
        this._injectStyle('eqas-mobile-search-style', `
            @media (max-width: 991px) {
                .header-search,
                .search-box,
                .header-search input,
                .search-input {
                    transition: width 0.3s ease, max-width 0.3s ease;
                }
                .header-search.eqas-search-expanded input,
                .search-box.eqas-search-expanded input,
                .search-input.eqas-search-expanded {
                    width: 100% !important;
                    max-width: 100% !important;
                    position: relative;
                    z-index: 1050;
                    font-size: 16px !important; /* Prevent iOS zoom */
                }
                .header-search.eqas-search-expanded,
                .search-box.eqas-search-expanded {
                    position: fixed !important;
                    top: 8px !important;
                    right: 12px !important;
                    left: 12px !important;
                    z-index: 1050 !important;
                    width: auto !important;
                }
            }
        `);

        const searchParent = searchInput.closest('.header-search, .search-box') || searchInput;

        searchInput.addEventListener('focus', () => {
            if (!this._isMobileViewport()) return;

            searchParent.classList.add('eqas-search-expanded');
            overlay.style.display = 'block';
            requestAnimationFrame(() => { overlay.style.opacity = '1'; });
        });

        const closeSearch = () => {
            searchParent.classList.remove('eqas-search-expanded');
            overlay.style.opacity = '0';
            setTimeout(() => { overlay.style.display = 'none'; }, 250);
        };

        searchInput.addEventListener('blur', () => {
            // Small delay to allow overlay click to register
            setTimeout(closeSearch, 200);
        });

        overlay.addEventListener('click', closeSearch);
    },

    // ============================================================
    // 24.7 VIEWPORT HEIGHT FIX (iOS Safari)
    // ============================================================

    /**
     * Fix iOS Safari viewport height issue
     * 100vh includes the address bar on iOS Safari
     * Sets CSS custom property --vh for real viewport height
     * Usage in CSS: height: calc(var(--vh, 1vh) * 100)
     */
    initViewportHeightFix() {
        const setVh = () => {
            const vh = window.innerHeight * 0.01;
            document.documentElement.style.setProperty('--vh', vh + 'px');
        };

        setVh();

        // Recalculate on resize (debounced)
        window.addEventListener('resize', debounce(setVh, 150), { passive: true });

        // Also handle soft keyboard appearance/disappearance
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', debounce(setVh, 150));
        }
    },

    // ============================================================
    // 24.8 ORIENTATION CHANGE HANDLER
    // ============================================================

    /**
     * Detect orientation change
     * - Close sidebar on orientation change
     * - Recalculate viewport height
     */
    initOrientationHandler() {
        const handleOrientationChange = () => {
            // Close sidebar
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            if (sidebar && sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
                if (overlay) overlay.classList.remove('show');
                document.body.classList.remove('sidebar-open');
            }

            // Recalculate viewport height
            const vh = window.innerHeight * 0.01;
            document.documentElement.style.setProperty('--vh', vh + 'px');

            // Close any mobile dropdowns
            document.querySelectorAll('.notification-dropdown.show, .user-dropdown-menu.show')
                .forEach(d => d.classList.remove('show'));

            const backdrop = document.querySelector('.eqas-dropdown-backdrop');
            if (backdrop) backdrop.remove();
        };

        // Modern API
        if (screen.orientation) {
            screen.orientation.addEventListener('change', handleOrientationChange);
        }

        // Legacy support via resize (debounced to distinguish from regular resize)
        let lastOrientation = window.innerWidth > window.innerHeight ? 'landscape' : 'portrait';
        window.addEventListener('resize', debounce(() => {
            const currentOrientation = window.innerWidth > window.innerHeight ? 'landscape' : 'portrait';
            if (currentOrientation !== lastOrientation) {
                lastOrientation = currentOrientation;
                handleOrientationChange();
            }
        }, 250), { passive: true });
    },

    // ============================================================
    // 24.9 TOUCH RIPPLE EFFECT
    // ============================================================

    /**
     * Add material-design-like ripple effect on buttons and links on touch
     * Only active on touch devices
     */
    initTouchRipple() {
        if (!('ontouchstart' in window)) return;

        // Inject ripple styles
        this._injectStyle('eqas-ripple-style', `
            .eqas-ripple {
                position: relative;
                overflow: hidden;
                -webkit-tap-highlight-color: transparent;
            }
            .eqas-ripple-wave {
                position: absolute;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.35);
                transform: scale(0);
                animation: eqasRippleExpand 0.6s ease-out forwards;
                pointer-events: none;
            }
            .eqas-ripple-wave.eqas-ripple-dark {
                background: rgba(0, 0, 0, 0.1);
            }
            @keyframes eqasRippleExpand {
                to { transform: scale(4); opacity: 0; }
            }
        `);

        // Add ripple class to interactive elements
        const rippleTargets = 'button, .btn, a.btn, .card-clickable, [data-ripple]';
        document.querySelectorAll(rippleTargets).forEach(el => {
            el.classList.add('eqas-ripple');
        });

        // Event delegation for ripple creation
        document.addEventListener('touchstart', (e) => {
            const target = e.target.closest('.eqas-ripple');
            if (!target) return;

            const touch = e.touches[0];
            const rect = target.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height) * 2;
            const x = touch.clientX - rect.left - (size / 2);
            const y = touch.clientY - rect.top - (size / 2);

            const wave = document.createElement('span');
            wave.className = 'eqas-ripple-wave';

            // Use dark ripple on light backgrounds
            const bgColor = window.getComputedStyle(target).backgroundColor;
            if (bgColor && (bgColor === 'rgba(0, 0, 0, 0)' || bgColor === 'transparent')) {
                wave.classList.add('eqas-ripple-dark');
            }

            wave.style.cssText = `width:${size}px;height:${size}px;left:${x}px;top:${y}px;`;
            target.appendChild(wave);

            // Clean up after animation
            wave.addEventListener('animationend', () => wave.remove());
        }, { passive: true });
    },

    // ============================================================
    // 24.10 LAZY IMAGE LOADING
    // ============================================================

    /**
     * Add intersection observer for lazy loading images
     * Only for images with data-lazy attribute
     */
    initLazyImages() {
        const lazyImages = document.querySelectorAll('img[data-lazy]');
        if (lazyImages.length === 0) return;

        // Use native lazy loading as fallback if IntersectionObserver not available
        if (!('IntersectionObserver' in window)) {
            lazyImages.forEach(img => {
                if (img.dataset.lazy) {
                    img.src = img.dataset.lazy;
                    img.removeAttribute('data-lazy');
                }
            });
            return;
        }

        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    const lazySrc = img.dataset.lazy;

                    if (lazySrc) {
                        // Add loading placeholder if not already set
                        if (!img.src && !img.style.background) {
                            img.style.background = '#f0f0f0';
                        }

                        img.src = lazySrc;
                        img.removeAttribute('data-lazy');

                        img.addEventListener('load', () => {
                            img.style.background = '';
                            img.classList.add('eqas-lazy-loaded');
                        }, { once: true });

                        img.addEventListener('error', () => {
                            img.style.background = '';
                            img.classList.add('eqas-lazy-error');
                        }, { once: true });
                    }

                    obs.unobserve(img);
                }
            });
        }, {
            rootMargin: '100px 0px', // Start loading 100px before entering viewport
            threshold: 0.01
        });

        lazyImages.forEach(img => observer.observe(img));

        // Inject fade-in style for lazy loaded images
        this._injectStyle('eqas-lazy-image-style', `
            img[data-lazy] {
                opacity: 0.3;
                transition: opacity 0.4s ease;
            }
            img.eqas-lazy-loaded {
                opacity: 1;
            }
            img.eqas-lazy-error {
                opacity: 0.5;
            }
        `);
    },

    // ============================================================
    // 24.11 FORM AUTO-SAVE INDICATOR
    // ============================================================

    /**
     * Show a small saving indicator when auto-save is active
     * Positioned at the top of the viewport
     */
    initAutoSaveIndicator() {
        // Create indicator bar (hidden by default)
        const indicator = document.createElement('div');
        indicator.id = 'eqas-autosave-indicator';
        indicator.setAttribute('role', 'status');
        indicator.setAttribute('aria-live', 'polite');
        indicator.innerHTML = '<span class="eqas-autosave-icon">&#128190;</span> <span class="eqas-autosave-text">جاري الحفظ...</span>';
        indicator.style.cssText = [
            'position: fixed',
            'top: 0',
            'right: 0',
            'left: 0',
            'height: 3px',
            'background: var(--primary, #1a5632)',
            'z-index: 9999',
            'transform: scaleX(0)',
            'transform-origin: right', // RTL
            'transition: transform 0.6s ease',
            'pointer-events: none'
        ].join(';');
        document.body.appendChild(indicator);
        this._autoSaveIndicator = indicator;

        // Also add a text indicator that shows above content
        const textIndicator = document.createElement('div');
        textIndicator.id = 'eqas-autosave-text-indicator';
        textIndicator.setAttribute('role', 'status');
        textIndicator.setAttribute('aria-live', 'polite');
        textIndicator.style.cssText = [
            'position: fixed',
            'top: 4px',
            'right: 50%',
            'transform: translateX(50%)',
            'background: var(--primary, #1a5632)',
            'color: #fff',
            'padding: 6px 16px',
            'border-radius: 0 0 8px 8px',
            'font-size: 12px',
            'z-index: 9999',
            'display: none',
            'opacity: 0',
            'transition: opacity 0.3s ease',
            'font-family: inherit',
            'box-shadow: 0 2px 8px rgba(0,0,0,0.2)'
        ].join(';');
        textIndicator.innerHTML = '&#128190; جاري الحفظ التلقائي...';
        document.body.appendChild(textIndicator);

        // Listen for auto-save events from existing eqasAutoSave / autoSaveDraft
        const showSaveIndicator = () => {
            // Progress bar animation
            indicator.style.transform = 'scaleX(0.3)';
            setTimeout(() => { indicator.style.transform = 'scaleX(0.7)'; }, 200);
            setTimeout(() => { indicator.style.transform = 'scaleX(1)'; }, 500);
            setTimeout(() => { indicator.style.transform = 'scaleX(0)'; }, 1500);

            // Text indicator
            textIndicator.style.display = 'block';
            requestAnimationFrame(() => { textIndicator.style.opacity = '1'; });
            setTimeout(() => {
                textIndicator.style.opacity = '0';
                setTimeout(() => { textIndicator.style.display = 'none'; }, 300);
            }, 2000);
        };

        // Expose method for external auto-save functions to call
        EQASMobile.showAutoSaveIndicator = showSaveIndicator;

        // Hook into existing autoSaveDraft and eqasAutoSave
        // Override to add indicator when they save
        const origAutoSaveDraft = window.autoSaveDraft;
        if (origAutoSaveDraft) {
            window.autoSaveDraft = function(formId, storageKey, interval) {
                origAutoSaveDraft(formId, storageKey, interval);
                // Show indicator on each interval save
                setInterval(showSaveIndicator, interval || 30000);
            };
        }

        const origEqasAutoSave = window.eqasAutoSave;
        if (origEqasAutoSave) {
            window.eqasAutoSave = function(formSelector, key) {
                origEqasAutoSave(formSelector, key);
                setInterval(showSaveIndicator, 30000);
            };
        }
    },

    // ============================================================
    // 24.12 NETWORK STATUS DETECTION
    // ============================================================

    /**
     * Detect offline/online status
     * - Show a toast when going offline/online
     * - Disable certain actions when offline
     * - Add visual offline indicator
     */
    initNetworkStatus() {
        this._isOnline = navigator.onLine;

        // Create persistent offline bar (hidden by default)
        const offlineBar = document.createElement('div');
        offlineBar.id = 'eqas-offline-bar';
        offlineBar.setAttribute('role', 'alert');
        offlineBar.setAttribute('aria-live', 'assertive');
        offlineBar.innerHTML = '<span>&#9888;&#65039; أنت غير متصل بالإنترنت</span><span class="eqas-offline-sub">بعض الوظائف قد لا تعمل</span>';
        offlineBar.style.cssText = [
            'position: fixed',
            'top: 0',
            'right: 0',
            'left: 0',
            'background: #dc3545',
            'color: #fff',
            'padding: 8px 16px',
            'text-align: center',
            'font-size: 14px',
            'font-family: inherit',
            'z-index: 99999',
            'display: none',
            'flex-direction: column',
            'align-items: center',
            'gap: 2px',
            'box-shadow: 0 2px 8px rgba(0,0,0,0.2)',
            'direction: rtl'
        ].join(';');
        document.body.appendChild(offlineBar);

        // Inject offline sub-text style
        this._injectStyle('eqas-offline-style', `
            .eqas-offline-sub { font-size: 11px; opacity: 0.8; }
            body.eqas-offline .btn-primary:not(.eqas-offline-safe),
            body.eqas-offline .btn-danger:not(.eqas-offline-safe),
            body.eqas-offline [data-requires-online] {
                opacity: 0.5;
                pointer-events: none;
                cursor: not-allowed;
            }
            body.eqas-offline #eqas-offline-bar {
                display: flex !important;
            }
            body.eqas-offline {
                padding-top: 44px;
            }
        `);

        const goOffline = () => {
            this._isOnline = false;
            document.body.classList.add('eqas-offline');
            showToast('تم فقدان الاتصال بالإنترنت. بعض الوظائف قد لا تعمل.', 'warning', 5000);
        };

        const goOnline = () => {
            this._isOnline = true;
            document.body.classList.remove('eqas-offline');
            showToast('تم استعادة الاتصال بالإنترنت', 'success', 3000);
        };

        window.addEventListener('offline', goOffline);
        window.addEventListener('online', goOnline);

        // Initial state check
        if (!navigator.onLine) {
            document.body.classList.add('eqas-offline');
        }

        // Prevent form submission when offline
        document.addEventListener('submit', (e) => {
            if (!this._isOnline) {
                const form = e.target;
                // Allow forms marked as offline-safe
                if (form.dataset.offlineSafe !== undefined) return;
                e.preventDefault();
                showToast('لا يمكن إرسال النموذج بدون اتصال بالإنترنت', 'danger');
            }
        }, true);

        // Expose online status check
        EQASMobile.isOnline = () => this._isOnline;
    },

    // ============================================================
    // UTILITY: Inject CSS style block
    // ============================================================

    /**
     * Inject a <style> block into the document head
     * @param {string} id - Unique ID for the style element
     * @param {string} css - CSS content
     */
    _injectStyle(id, css) {
        if (document.getElementById(id)) return;
        const style = document.createElement('style');
        style.id = id;
        const nonce = document.querySelector('meta[name="csp-nonce"]')?.getAttribute('content');
        if (nonce) style.setAttribute('nonce', nonce);
        style.textContent = css;
        document.head.appendChild(style);
    }
};

// ============================================================
// INITIALIZE MOBILE ENHANCEMENTS ON DOM READY
// ============================================================

document.addEventListener('DOMContentLoaded', function() {
    EQASMobile.init();
});

// Re-initialize lazy images for dynamically added content
document.addEventListener('eqas:content-loaded', function() {
    EQASMobile.initLazyImages();
    // Also add ripple to new buttons
    if ('ontouchstart' in window) {
        document.querySelectorAll('button, .btn, a.btn, .card-clickable, [data-ripple]').forEach(el => {
            if (!el.classList.contains('eqas-ripple')) {
                el.classList.add('eqas-ripple');
            }
        });
    }
});
