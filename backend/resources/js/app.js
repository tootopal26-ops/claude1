/*
 * Admin/Staff UI behavior. Alpine is bundled and started by Livewire, so the
 * components below register on `alpine:init` (this module is deferred, so the
 * listener is always attached before Livewire boots Alpine).
 *
 * Preferences persist in localStorage under the same keys the inline boot
 * script (components/theme-boot.blade.php) reads before first paint, so a
 * saved theme / font / sidebar state never flashes the wrong look on load.
 */

const KEYS = {
    theme: 'mm_theme_color',
    font: 'mm_theme_font',
    sidebar: 'mm_sidebar_collapsed',
};

const storage = {
    set(key, value) {
        try {
            localStorage.setItem(key, value);
        } catch (e) {}
    },
};

document.addEventListener('alpine:init', () => {
    const root = document.documentElement;

    // Color theme + font theme picker (header "Appearance" menu).
    Alpine.data('appearance', () => ({
        theme: root.getAttribute('data-theme') || 'purple-glass',
        font: root.getAttribute('data-font') || 'default-sans',

        setTheme(theme) {
            this.theme = theme;
            root.setAttribute('data-theme', theme);
            storage.set(KEYS.theme, theme);
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
