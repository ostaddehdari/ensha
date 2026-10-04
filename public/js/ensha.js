(function () {
    'use strict';

    const toast = document.querySelector('[data-ensha-toast]');
    let toastTimer;

    function showToast(message) {
        if (!toast) return;
        toast.textContent = message;
        toast.hidden = false;
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => { toast.hidden = true; }, 2600);
    }

    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.closest('.ensha-password')?.querySelector('input');
            if (!input) return;
            const visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            button.textContent = visible ? 'نمایش' : 'پنهان';
        });
    });

    function updateUnreadCount() {
        const unread = document.querySelectorAll('[data-ensha-notification]:not(.ensha-notification-read)').length;
        document.querySelectorAll('[data-ensha-unread-count]').forEach((badge) => {
            badge.textContent = unread.toLocaleString('fa-IR');
            badge.hidden = unread === 0;
        });
        document.querySelectorAll('[data-ensha-unread-label]').forEach((label) => {
            label.textContent = unread ? `${unread.toLocaleString('fa-IR')} جدید` : 'همه خوانده شد';
            label.classList.toggle('kt-badge-primary', unread > 0);
            label.classList.toggle('kt-badge-light', unread === 0);
        });
    }

    document.querySelectorAll('[data-ensha-notification]').forEach((item) => {
        item.addEventListener('click', () => {
            item.classList.add('ensha-notification-read');
            updateUnreadCount();
            showToast('اعلان به‌عنوان خوانده‌شده ثبت شد.');
        });
    });

    document.querySelectorAll('[data-ensha-mark-all]').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelectorAll('[data-ensha-notification]').forEach((item) => item.classList.add('ensha-notification-read'));
            updateUnreadCount();
            showToast('همهٔ اعلان‌ها خوانده شد.');
        });
    });

    const chatStream = document.querySelector('#ensha-chat-stream');
    const chatForm = document.querySelector('[data-ensha-chat-form]');
    const chatInput = document.querySelector('#ensha_chat_input');
    if (chatForm && chatStream && chatInput) {
        chatForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const message = chatInput.value.trim();
            if (!message) return;
            const row = document.createElement('div');
            row.className = 'flex items-end justify-end gap-2';
            const bubble = document.createElement('div');
            bubble.className = 'ensha-chat-bubble outgoing';
            bubble.textContent = message;
            const time = document.createElement('small');
            time.textContent = 'اکنون';
            bubble.appendChild(time);
            row.appendChild(bubble);
            chatStream.appendChild(row);
            chatStream.scrollTop = chatStream.scrollHeight;
            chatInput.value = '';
            showToast('پیام در نسخه پایه به‌صورت محلی ثبت شد.');
        });
    }

    const searchInput = document.querySelector('[data-ensha-search-input]');
    const searchResult = document.querySelector('[data-ensha-search-result]');
    if (searchInput && searchResult) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.trim();
            if (!query) {
                searchResult.textContent = 'برای شروع جست‌وجو عبارت خود را وارد کنید.';
                return;
            }
            const links = Array.from(document.querySelectorAll('#sidebar_menu .kt-menu-title'));
            const matches = links.map((link) => link.textContent.trim()).filter((text) => text.includes(query));
            searchResult.textContent = matches.length ? `نتیجه: ${matches.join('، ')}` : 'نتیجه‌ای در منوی فعلی پیدا نشد.';
        });
    }

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm || 'از انجام این عملیات مطمئن هستید؟')) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-generate-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
            const random = new Uint32Array(12);
            window.crypto.getRandomValues(random);
            const required = ['A', 'a', '7', '!'];
            const characters = required.concat(Array.from(random, (number) => alphabet[number % alphabet.length]));
            const shuffle = new Uint32Array(characters.length);
            window.crypto.getRandomValues(shuffle);
            const password = characters.map((character, index) => ({character, order: shuffle[index]}))
                .sort((left, right) => left.order - right.order)
                .map((item) => item.character).join('');
            const form = button.closest('form');
            const passwordInput = button.closest('.ensha-input-action')?.querySelector('[data-password-input]');
            const confirmation = form?.querySelector('input[name="password_confirmation"]');
            if (passwordInput) {
                passwordInput.type = 'text';
                passwordInput.value = password;
            }
            if (confirmation) {
                confirmation.type = 'text';
                confirmation.value = password;
            }
            showToast('رمز قوی ساخته شد؛ آن را در محل امنی به کاربر تحویل دهید.');
        });
    });

    const roleSelect = document.querySelector('[data-role-select]');
    const centreField = document.querySelector('[data-centre-field]');
    if (roleSelect && centreField) {
        const updateCentreField = () => {
            const globalRole = roleSelect.selectedOptions[0]?.dataset.scope === 'global';
            const centreSelect = centreField.querySelector('select');
            centreField.hidden = globalRole;
            if (centreSelect) {
                centreSelect.required = !globalRole;
                if (globalRole) centreSelect.value = '';
            }
        };
        roleSelect.addEventListener('change', updateCentreField);
        updateCentreField();
    }

    document.querySelectorAll('.ensha-status-form').forEach((form) => {
        const status = form.querySelector('select[name="status"]');
        const reason = form.querySelector('input[name="status_reason"]');
        if (!status || !reason) return;
        const syncReason = () => {
            reason.required = status.value === 'blocked';
            reason.placeholder = status.value === 'blocked'
                ? 'دلیل مسدودسازی امنیتی را وارد کنید'
                : 'توضیح اختیاری درباره تغییر وضعیت';
        };
        status.addEventListener('change', syncReason);
        syncReason();
    });

    const permissionCheckboxes = () => Array.from(document.querySelectorAll('input[name="permission_ids[]"]:not(:disabled)'));
    document.querySelectorAll('[data-permission-group]').forEach((group) => {
        const toggle = group.querySelector('[data-group-toggle]');
        const checkboxes = Array.from(group.querySelectorAll('input[name="permission_ids[]"]:not(:disabled)'));
        if (!toggle || !checkboxes.length) return;
        const syncToggle = () => {
            const checked = checkboxes.filter((checkbox) => checkbox.checked).length;
            toggle.checked = checked === checkboxes.length;
            toggle.indeterminate = checked > 0 && checked < checkboxes.length;
        };
        toggle.addEventListener('change', () => {
            checkboxes.forEach((checkbox) => { checkbox.checked = toggle.checked; });
        });
        checkboxes.forEach((checkbox) => checkbox.addEventListener('change', syncToggle));
        syncToggle();
    });
    document.querySelectorAll('[data-select-all-permissions]').forEach((button) => button.addEventListener('click', () => {
        permissionCheckboxes().forEach((checkbox) => { checkbox.checked = true; checkbox.dispatchEvent(new Event('change')); });
    }));
    document.querySelectorAll('[data-clear-all-permissions]').forEach((button) => button.addEventListener('click', () => {
        permissionCheckboxes().forEach((checkbox) => { checkbox.checked = false; checkbox.dispatchEvent(new Event('change')); });
    }));

    updateUnreadCount();
}());
