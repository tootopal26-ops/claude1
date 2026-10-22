const THEME_COLOR_KEY = 'mm_theme_color';
const THEME_FONT_KEY = 'mm_theme_font';

function applyColorTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try {
        localStorage.setItem(THEME_COLOR_KEY, theme);
    } catch (e) {}
}

function applyFontTheme(font) {
    document.documentElement.setAttribute('data-font', font);
    try {
        localStorage.setItem(THEME_FONT_KEY, font);
    } catch (e) {}
}

function initThemeSwitcher() {
    const colorSelect = document.getElementById('mm-theme-color-select');
    const fontSelect = document.getElementById('mm-theme-font-select');

    if (colorSelect) {
        colorSelect.value = document.documentElement.getAttribute('data-theme') || 'purple-glass';
        colorSelect.addEventListener('change', (event) => applyColorTheme(event.target.value));
    }

    if (fontSelect) {
        fontSelect.value = document.documentElement.getAttribute('data-font') || 'default-sans';
        fontSelect.addEventListener('change', (event) => applyFontTheme(event.target.value));
    }
}

document.addEventListener('DOMContentLoaded', initThemeSwitcher);
document.addEventListener('livewire:navigated', initThemeSwitcher);
