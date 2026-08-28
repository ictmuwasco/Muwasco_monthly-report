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
