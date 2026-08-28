    <!-- ══════════ Sidebar ══════════ -->
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-primary-dark text-white transition-transform duration-200 lg:static lg:translate-x-0 print:hidden">
        <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4">
            <img src="public/assets/images/muwascologo.png" alt="MUWASCO" class="h-10 w-10 rounded-lg bg-white/95 object-contain p-0.5 ring-1 ring-white/30">
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
