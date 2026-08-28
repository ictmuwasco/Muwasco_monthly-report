/*
 * frontend/src/js/app.js — Shared frontend behaviour (sidebar toggle, etc.).
 * Page-specific scripts currently live inline in frontend/src/pages/*.php.
 */
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('sidebarToggle');
    var sidebar = document.getElementById('sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('-translate-x-full');
        });
    }
});