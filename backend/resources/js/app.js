/*
 * Admin/Staff UI behavior. Alpine is bundled and started by Livewire, so the
 * components below register on `alpine:init` (this module is deferred, so the
 * listener is always attached before Livewire boots Alpine).
 *
 * Preferences persist in localStorage under the same keys the inline boot
 * script (components/theme-boot.blade.php) reads before first paint, so a
 * saved theme / font / sidebar state never flashes the wrong look on load.
 * Day/Night mode is saved per account and per portal on the server (the
 * layouts render it onto <html>), with a per-portal copy on the device for the
 * sign-in screen.
 */

const root = document.documentElement;
const portal = root.getAttribute('data-portal') === 'staff' ? 'staff' : 'admin';

const KEYS = {
    theme: 'mm_theme_color',
    font: 'mm_theme_font',
    sidebar: 'mm_sidebar_collapsed',
    mode: `mm_theme_mode:${portal}`,
};

const storage = {
    set(key, value) {
        try {
            localStorage.setItem(key, value);
        } catch (e) {}
    },
};

/*
 * Day / Night mode. data-mode-pref holds the choice (light, dark or system)
 * and data-mode what is painted; CSS keys everything off data-mode.
 */
const MODES = ['light', 'dark', 'system'];
const prefersLight = window.matchMedia('(prefers-color-scheme: light)');
let switchTimer = null;
let saveTimer = null;

const modePreference = () => {
    const pref = root.getAttribute('data-mode-pref');

    return MODES.includes(pref) ? pref : 'dark';
};

const resolveMode = (pref) => (pref === 'system' ? (prefersLight.matches ? 'light' : 'dark') : pref);

// Mobile browsers tint their toolbar with theme-color; keep it on the page's canvas.
const syncThemeColor = () => {
    const meta = document.querySelector('meta[name="theme-color"]');
    const canvas = document.querySelector('.mm-portal') ?? root;

    if (meta) {
        meta.setAttribute('content', getComputedStyle(canvas).getPropertyValue('--mm-bg').trim());
    }
};

const applyMode = (pref) => {
    const mode = resolveMode(pref);

    if (root.getAttribute('data-mode') !== mode) {
        // Colors ease across for the length of the switch only (see .mm-mode-switching in app.css).
        root.classList.add('mm-mode-switching');
        clearTimeout(switchTimer);
        switchTimer = setTimeout(() => root.classList.remove('mm-mode-switching'), 400);
    }

    root.setAttribute('data-mode-pref', pref);
    root.setAttribute('data-mode', mode);
    syncThemeColor();
    window.dispatchEvent(new CustomEvent('mm-mode-changed', { detail: { pref, mode } }));
};

// Signed-in pages carry the save URL; sign-in pages only remember the choice on this device.
const saveMode = (pref) => {
    const url = root.getAttribute('data-mode-url');

    if (!url) {
        return;
    }

    // Only the last choice of a quick run of toggles is sent, so saves cannot land out of order.
    clearTimeout(saveTimer);
    saveTimer = setTimeout(async () => {
        try {
            const response = await fetch(url, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ mode: pref }),
            });

            if (response.status === 419) {
                window.dispatchEvent(new CustomEvent('mm-session-expired'));
            } else if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
        } catch (e) {
            window.dispatchEvent(
                new CustomEvent('mm-toast', {
                    detail: { message: 'Your display mode could not be saved. It applies on this page until you reload.', variant: 'warning' },
                }),
            );
        }
    }, 350);
};

const setMode = (pref) => {
    if (!MODES.includes(pref)) {
        return;
    }

    applyMode(pref);
    storage.set(KEYS.mode, pref);
    saveMode(pref);
};

// "System default" follows the device as it changes.
prefersLight.addEventListener('change', () => {
    if (modePreference() === 'system') {
        applyMode('system');
    }
});

// Another tab of the same portal switched mode.
window.addEventListener('storage', (event) => {
    if (event.key === KEYS.mode && MODES.includes(event.newValue) && event.newValue !== modePreference()) {
        applyMode(event.newValue);
    }
});

document.addEventListener('DOMContentLoaded', syncThemeColor);

document.addEventListener('alpine:init', () => {
    // Header / sign-in Day-Night switch (components/ui/mode-toggle.blade.php).
    Alpine.data('modeToggle', () => ({
        mode: root.getAttribute('data-mode') === 'light' ? 'light' : 'dark',

        init() {
            window.addEventListener('mm-mode-changed', (event) => (this.mode = event.detail.mode));
        },

        get label() {
            return this.mode === 'light' ? 'Switch to Dark Mode' : 'Switch to Light Mode';
        },

        toggle() {
            setMode(this.mode === 'light' ? 'dark' : 'light');
        },
    }));

    // Mode, color theme and font picker (header "Appearance" menu).
    Alpine.data('appearance', () => ({
        mode: modePreference(),
        theme: root.getAttribute('data-theme') || 'purple-glass',
        font: root.getAttribute('data-font') || 'default-sans',

        init() {
            window.addEventListener('mm-mode-changed', (event) => (this.mode = event.detail.pref));
        },

        setMode(pref) {
            setMode(pref);
        },

        setTheme(theme) {
            this.theme = theme;
            root.setAttribute('data-theme', theme);
            storage.set(KEYS.theme, theme);
            syncThemeColor();
        },

        setFont(font) {
            this.font = font;
            root.setAttribute('data-font', font);
            storage.set(KEYS.font, font);
        },
    }));

    // Application shell: collapsible sidebar (desktop), drawer (mobile),
    // collapsed-rail tooltip, session-expiry dialog.
    Alpine.data('shell', () => ({
        drawer: false,
        collapsed: root.getAttribute('data-sidebar') === 'collapsed',
        mobile: window.matchMedia('(max-width: 1023px)').matches,
        sessionExpired: false,
        tip: { show: false, label: '', x: 0, y: 0 },

        init() {
            const query = window.matchMedia('(max-width: 1023px)');

            query.addEventListener('change', (event) => {
                this.mobile = event.matches;

                if (!event.matches) {
                    this.drawer = false;
                }
            });
        },

        toggleCollapsed() {
            this.collapsed = !this.collapsed;
            root.setAttribute('data-sidebar', this.collapsed ? 'collapsed' : 'expanded');
            storage.set(KEYS.sidebar, this.collapsed ? '1' : '0');
            this.hideTip();
        },

        // One fixed-position tooltip for the collapsed rail, so the scrolling
        // nav list can never clip it.
        showTip(el, label) {
            if (!this.collapsed || this.mobile) {
                return;
            }

            const rect = el.getBoundingClientRect();
            this.tip = { show: true, label, x: rect.right + 12, y: rect.top + rect.height / 2 };
        },

        hideTip() {
            this.tip.show = false;
        },
    }));

    // Toast host. Anything can raise a toast with
    //   window.dispatchEvent(new CustomEvent('mm-toast', { detail: { message, variant } }))
    Alpine.data('toasts', () => ({
        items: [],
        seq: 0,

        push({ message, variant = 'success', duration }) {
            const id = ++this.seq;
            this.items.push({ id, message, variant });

            // Errors stay until dismissed; everything else clears itself.
            const ttl = duration ?? (variant === 'danger' ? 0 : 6000);
            if (ttl > 0) {
                setTimeout(() => this.dismiss(id), ttl);
            }
        },

        dismiss(id) {
            this.items = this.items.filter((item) => item.id !== id);
        },
    }));

    // "Copy" button for short strings such as a redeem code. Uses the async
    // clipboard where the browser allows it and a selection fallback elsewhere
    // (for example a plain-HTTP admin URL); flips to "Copied" for two seconds.
    Alpine.data('copyText', (text) => ({
        copied: false,

        async copy() {
            try {
                await navigator.clipboard.writeText(text);
            } catch (e) {
                const field = document.createElement('textarea');
                field.value = text;
                field.setAttribute('readonly', '');
                field.style.position = 'fixed';
                field.style.opacity = '0';
                document.body.appendChild(field);
                field.select();
                document.execCommand('copy');
                field.remove();
            }

            this.copied = true;
            setTimeout(() => (this.copied = false), 2000);
        },
    }));

    // Unsaved-changes guard for forms: mark the form dirty on the first edit and
    // let the browser ask before the page is closed, reloaded or navigated away
    // from. A successful save (or Cancel/Discard) clears it first.
    Alpine.data('unsavedGuard', () => ({
        dirty: false,

        init() {
            window.addEventListener('beforeunload', (event) => {
                if (this.dirty) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });

            // Cancel and other in-page links are deliberate; the browser prompt above
            // covers everything else, so only links that opt in stay guarded.
            this.$el.addEventListener('click', (event) => {
                const link = event.target instanceof Element ? event.target.closest('a[data-discard]') : null;

                if (link) {
                    this.dirty = false;
                }
            });
        },

        markDirty() {
            this.dirty = true;
        },

        clean() {
            this.dirty = false;
        },
    }));

    // Global confirmation dialog. Any button/link carrying data-confirm
    // (rendered by <x-ui.button confirm="...">) is intercepted in the capture
    // phase — before Livewire's own handlers — and replayed once the user
    // accepts, so wire:click and wire:submit both keep working unchanged.
    Alpine.data('confirmDialog', () => ({
        open: false,
        title: '',
        message: '',
        confirmLabel: 'Confirm',
        variant: 'primary',
        target: null,

        init() {
            document.addEventListener(
                'click',
                (event) => {
                    const el = event.target instanceof Element ? event.target.closest('[data-confirm]') : null;

                    if (!el || el.disabled) {
                        return;
                    }

                    if (el.__mmConfirmed) {
                        el.__mmConfirmed = false;

                        return;
                    }

                    event.preventDefault();
                    event.stopImmediatePropagation();
                    this.ask(el);
                },
                true,
            );
        },

        ask(el) {
            this.target = el;
            this.title = el.dataset.confirmTitle || 'Are you sure?';
            this.message = el.dataset.confirm;
            this.confirmLabel = el.dataset.confirmLabel || 'Confirm';
            this.variant = el.dataset.confirmVariant || 'primary';
            this.open = true;
        },

        accept() {
            const el = this.target;
            this.open = false;
            this.target = null;

            if (el) {
                el.__mmConfirmed = true;
                el.click();
            }
        },

        cancel() {
            const el = this.target;
            this.open = false;
            this.target = null;
            el?.focus();
        },
    }));
});

// An expired session (HTTP 419) on a Livewire request: show the shell's
// sign-in-again dialog instead of Livewire's native confirm() prompt.
document.addEventListener('livewire:init', () => {
    window.Livewire.interceptRequest(({ onError }) => {
        onError(({ response, preventDefault }) => {
            if (response.status === 419) {
                preventDefault();
                window.dispatchEvent(new CustomEvent('mm-session-expired'));
            }
        });
    });
});
