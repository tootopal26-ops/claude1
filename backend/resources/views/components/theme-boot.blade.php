@props(['brandLocked' => false])
{{-- Applies the saved theme, font, sidebar state and Day/Night mode before first paint so a stored preference never flashes the wrong look.
     Sign-in pages pass brand-locked: they keep the portal's own theme and font, and follow only the mode.
     Mode: a signed-in page carries the account's preference for this portal in data-mode-pref (rendered by the server) and copies it
     to this device's per-portal key; a guest page reads that key, so the sign-in screen matches the last mode used in that portal. --}}
<script>
    (function () {
        var root = document.documentElement;
        var modeKey = 'mm_theme_mode:' + (root.getAttribute('data-portal') === 'staff' ? 'staff' : 'admin');
        var pref = root.getAttribute('data-mode-pref');
        try {
            @unless ($brandLocked)
            var theme = localStorage.getItem('mm_theme_color');
            var font = localStorage.getItem('mm_theme_font');
            if (theme && /^[a-z-]+$/.test(theme)) root.setAttribute('data-theme', theme);
            if (font && /^[a-z-]+$/.test(font)) root.setAttribute('data-font', font);
            root.setAttribute('data-sidebar', localStorage.getItem('mm_sidebar_collapsed') === '1' ? 'collapsed' : 'expanded');
            @endunless
            if (pref) {
                localStorage.setItem(modeKey, pref);
            } else {
                pref = localStorage.getItem(modeKey);
            }
        } catch (e) {
            @unless ($brandLocked)
            root.setAttribute('data-sidebar', 'expanded');
            @endunless
        }
        if (pref !== 'light' && pref !== 'dark' && pref !== 'system') pref = 'dark';
        var mode = pref;
        if (pref === 'system') {
            mode = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
        }
        root.setAttribute('data-mode-pref', pref);
        root.setAttribute('data-mode', mode);
    })();
</script>
