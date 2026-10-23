{{-- Applies the saved theme, font and sidebar state before first paint so a stored preference never flashes the wrong look. --}}
<script>
    (function () {
        var root = document.documentElement;
        try {
            var theme = localStorage.getItem('mm_theme_color');
            var font = localStorage.getItem('mm_theme_font');
            if (theme && /^[a-z-]+$/.test(theme)) root.setAttribute('data-theme', theme);
            if (font && /^[a-z-]+$/.test(font)) root.setAttribute('data-font', font);
            root.setAttribute('data-sidebar', localStorage.getItem('mm_sidebar_collapsed') === '1' ? 'collapsed' : 'expanded');
        } catch (e) {
            root.setAttribute('data-sidebar', 'expanded');
        }
    })();
</script>
