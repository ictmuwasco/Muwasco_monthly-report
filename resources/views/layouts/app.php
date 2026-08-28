<?php
/**
 * resources/views/layouts/app.php — Shared application shell (Tailwind).
 *
 * Expected variables before include:
 *   $pageTitle    string  Browser tab title
 *   $currentPage  string  basename of active PHP page (for nav highlight)
 *   $user_info    array   from getUserInfo(): full_name, role, etc.
 *   $content      string  rendered page HTML
 *
 * Usage:
 *   ob_start(); /* view HTML * / $content = ob_get_clean();
 *   require 'resources/views/layouts/app.php';
 */

if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
    header('Location: login.php');
    exit;
}
$currentPage = $currentPage ?? basename($_SERVER['PHP_SELF']);
$isAdmin     = ($_SESSION['role'] ?? '') === 'admin' || ($user_info['role'] ?? '') === 'admin';

/** Sidebar definition: [label, href, icon(SVG path), admin-only] */
$navItems = [
    ['Dashboard',   'index.php',    'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z', false],
    ['Data Entry',  'add_data.php', 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10', false],
    ['Reporting Months', 'months.php', 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5', false],
    ['Reports',     'reports.php',  'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z', false],
    ['Parameter Assignment', 'assignment.php', 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z', true],
    ['Users',       'user_management.php', 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z', true],
];

/** Friendly breadcrumb label for the current page */
$breadcrumbLabel = 'Dashboard';
foreach ($navItems as [$label, $href]) {
    if ($currentPage === $href) { $breadcrumbLabel = $label; break; }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'MUWASCO Monthly Report') ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💧</text></svg>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="public/assets/css/app.css">
</head>
<body class="h-full bg-[#F5F7FA] font-sans antialiased text-gray-800">

<div class="flex min-h-screen">

    <!-- ══════════ Sidebar ══════════ -->
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-primary-dark text-white transition-transform duration-200 lg:static lg:translate-x-0 print:hidden">
        <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4">
            <img src="muwascologo.png" alt="MUWASCO" class="h-10 w-10 rounded-lg bg-white/95 object-contain p-0.5 ring-1 ring-white/30">
            <div>
                <p class="text-sm font-bold leading-tight tracking-tight">MUWASCO</p>
                <p class="text-[11px] text-cyan-200/80">Monthly Report</p>
            </div>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Main navigation">
            <?php
            $mainItems  = array_filter($navItems, fn($i) => !$i[3]);
            $adminItems = array_filter($navItems, fn($i) => $i[3]);
            $renderNav = function (array $items, string $sectionLabel) use ($currentPage, $isAdmin) {
                if ($sectionLabel === 'Administration' && !$isAdmin) return;
                ?>
                <p class="px-3 pb-2 pt-4 text-[11px] font-semibold uppercase tracking-wider text-cyan-200/50 first:pt-0"><?= $sectionLabel ?></p>
                <ul class="space-y-1">
                    <?php foreach ($items as [$label, $href, $iconPath]): ?>
                        <li>
                            <a href="<?= $href ?>"
                               class="group relative flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-white/40
                                      <?= $currentPage === $href
                                         ? 'border-l-[3px] border-[#0F9D8A] bg-white/10 pl-[9px] text-white'
                                         : 'border-l-[3px] border-transparent pl-[9px] text-cyan-100/70 hover:bg-white/5 hover:text-white' ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7"
                                     stroke="currentColor" class="h-5 w-5 shrink-0 <?= $currentPage === $href ? 'opacity-100' : 'opacity-80 group-hover:opacity-100' ?>">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="<?= $iconPath ?>"/>
                                </svg>
                                <span><?= $label ?></span>
                                <?php if ($currentPage === $href): ?><span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">(current page)</span><?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php
            };
            $renderNav($mainItems, 'Main Navigation');
            $renderNav($adminItems, 'Administration');
            ?>
        </nav>

        <div class="border-t border-white/10 p-4">
            <div class="mb-3 flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-white/15 text-sm font-semibold uppercase">
                    <?= htmlspecialchars(strtoupper(substr($user_info['full_name'] ?? 'U', 0, 1))) ?>
                </span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium"><?= htmlspecialchars($user_info['full_name'] ?? 'User') ?></p>
                    <p class="truncate text-xs capitalize text-cyan-200/70"><?= htmlspecialchars($user_info['role'] ?? 'user') ?></p>
                </div>
            </div>
            <form method="POST" action="logout.php">
                <?= csrf_field() ?>
                <button type="submit"
                        class="flex w-full items-center justify-center gap-2 rounded-lg bg-white/10 px-3 py-2 text-sm font-medium text-red-200 transition-colors hover:bg-red-500/20 hover:text-red-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/40">
                    Sign out
                </button>
            </form>
        </div>
    </aside>

    <!-- ══════════ Main column ══════════ -->
    <div class="flex min-w-0 flex-1 flex-col">

        <!-- Topbar -->
        <header class="sticky top-0 z-20 flex h-14 items-center gap-4 border-b border-gray-200 bg-white/90 px-4 backdrop-blur md:px-6 print:hidden">
            <button id="sidebarToggle" aria-label="Toggle navigation"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary lg:hidden">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                </svg>
            </button>

            <nav class="hidden items-center gap-1.5 text-sm text-gray-400 sm:flex" aria-label="Breadcrumb">
                <a href="index.php" class="transition-colors hover:text-gray-600">Home</a>
                <span aria-hidden="true">/</span>
                <span class="font-medium text-gray-700" aria-current="page"><?= htmlspecialchars($breadcrumbLabel) ?></span>
            </nav>

            <div class="ml-auto flex items-center gap-3">
                <span class="badge-pending hidden sm:inline-flex">Role: <?= htmlspecialchars(ucwords(str_replace('_', ' ', $user_info['role'] ?? 'user'))) ?></span>
                <span class="hidden h-8 w-px bg-gray-200 sm:block"></span>
                <span class="truncate text-sm font-medium text-gray-600"><?= htmlspecialchars($user_info['full_name'] ?? '') ?></span>
            </div>
        </header>

        <!-- Page content -->
        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-6 md:px-6 md:py-8">
            <?= $content ?? '' ?>
        </main>

        <footer class="border-t border-gray-200 px-6 py-4 text-center text-xs text-gray-400 print:hidden">
            &copy; <?= date('Y') ?> Murang'a Water &amp; Sanitation Company · Monthly Reporting System
        </footer>
    </div>
</div>

<!-- Mobile overlay -->
<div id="sidebarOverlay" class="fixed inset-0 z-30 hidden bg-black/40 backdrop-blur-sm lg:hidden"></div>

<script>
    // Responsive sidebar: drawer on mobile, static on desktop
    (() => {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggle  = document.getElementById('sidebarToggle');
        const setOpen = open => {
            sidebar.classList.toggle('-translate-x-full', !open);
            overlay.classList.toggle('hidden', !open);
        };
        toggle?.addEventListener('click', () => setOpen(sidebar.classList.contains('-translate-x-full')));
        overlay?.addEventListener('click', () => setOpen(false));
        window.addEventListener('resize', () => { if (window.innerWidth >= 1024) setOpen(false); });
    })();
</script>
</body>
</html>

