<?php require_once __DIR__ . '/includes/helpers.php'; ?>
<!DOCTYPE html>
<html lang="en" class="">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Intelis Insights</title>

  <!-- Tailwind CSS (CDN / Play) -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            accent: { DEFAULT: '#0d9488', light: '#14b8a6', dark: '#0f766e', soft: 'rgba(13,148,136,0.10)' },
            secondary: { DEFAULT: '#0ea5e9', soft: 'rgba(14,165,233,0.10)' },
            surface: { DEFAULT: '#ffffff', dark: '#1a1a1e', alt: '#f0f0f3', 'alt-dark': '#222226' },
          },
          fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'sans-serif'] },
        },
      },
    };
  </script>

  <!-- Inter font -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>

  <!-- Alpine.js -->
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>

  <!-- App styles -->
  <link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">

  <!-- App JS (Alpine components) -->
  <script src="<?= asset('/assets/js/app.js') ?>"></script>
</head>

<body class="min-h-screen overflow-hidden" x-data="insightsApp" x-init="init()">

  <!-- ================================================================== -->
  <!--  MOBILE SIDEBAR OVERLAY                                            -->
  <!-- ================================================================== -->
  <div class="mobile-sidebar-overlay" :class="{ visible: mobileSidebarOpen }" @click="closeMobileSidebar()"></div>

  <!-- ================================================================== -->
  <!--  APP LAYOUT                                                        -->
  <!-- ================================================================== -->
  <div class="app-layout">

    <!-- ============================================================== -->
    <!--  LEFT SIDEBAR                                                   -->
    <!-- ============================================================== -->
    <aside class="app-sidebar" :class="{
             collapsed: sidebarCollapsed,
             'mobile-open': mobileSidebarOpen
           }">

      <!-- Logo -->
      <div class="sidebar-logo">
        <div
          class="w-8 h-8 rounded-lg bg-gradient-to-br from-teal-600 to-sky-500 flex items-center justify-center shadow-sm flex-shrink-0">
          <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
          </svg>
        </div>
        <span class="sidebar-logo-text text-sm font-semibold tracking-tight transition-all duration-200">
          Intelis <span class="text-accent">Insights</span>
        </span>
      </div>

      <!-- Navigation -->
      <nav class="sidebar-nav">
        <!-- Dashboard -->
        <button @click="navigate('dashboard')" class="sidebar-nav-item"
          :class="{ active: currentView === 'dashboard' }">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
          </svg>
          <span>Dashboard</span>
        </button>

        <!-- Chat -->
        <button @click="navigate('chat')" class="sidebar-nav-item" :class="{ active: currentView === 'chat' }">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 011.037-.443 48.282 48.282 0 005.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
          </svg>
          <span>Chat</span>
        </button>

        <!-- Reports -->
        <button @click="navigate('reports')" class="sidebar-nav-item" :class="{ active: currentView === 'reports' }">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
          </svg>
          <span>Reports</span>
        </button>

        <!-- Settings -->
        <button @click="navigate('settings')" class="sidebar-nav-item" :class="{ active: currentView === 'settings' }">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          <span>Settings</span>
        </button>

        <!-- Spacer -->
        <div class="flex-1"></div>

        <!-- Collapse toggle (desktop only) -->
        <button @click="toggleSidebarCollapse()" class="sidebar-nav-item hidden md:flex">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
            :class="{ 'rotate-180': sidebarCollapsed }" class="transition-transform duration-200">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5" />
          </svg>
          <span>Collapse</span>
        </button>
      </nav>

      <!-- Sidebar footer: user -->
      <div class="sidebar-footer">
        <button @click="logout()"
          class="sidebar-nav-item text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30"
          title="Sign out">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
          </svg>
          <span>Sign Out</span>
        </button>
      </div>
    </aside>

    <!-- ============================================================== -->
    <!--  TOP BAR                                                        -->
    <!-- ============================================================== -->
    <header class="app-topbar">
      <div class="app-topbar-left">
        <!-- Mobile menu button -->
        <button @click="toggleMobileSidebar()"
          class="md:hidden p-2 -ml-2 rounded-xl hover:bg-[var(--color-surface-alt)] transition">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>

        <!-- Chat history toggle (only in chat view) -->
        <button x-show="currentView === 'chat'" @click="toggleChatSidebar()"
          class="p-2 -ml-1 rounded-xl hover:bg-[var(--color-surface-alt)] transition" title="Chat history">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </button>

        <!-- View title -->
        <h1 class="text-base font-semibold capitalize" x-text="currentView"></h1>
      </div>

      <div class="app-topbar-right">
        <!-- Saved reports toggle (only in chat view) -->
        <button x-show="currentView === 'chat'" @click="toggleChatReportsDrawer()"
          class="p-2 rounded-xl hover:bg-[var(--color-surface-alt)] transition" title="Saved reports">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" />
          </svg>
        </button>
        <!-- Dark mode toggle -->
        <button @click="toggleDarkMode()" class="p-2 rounded-xl hover:bg-[var(--color-surface-alt)] transition"
          title="Toggle dark mode">
          <svg x-show="darkMode" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
          </svg>
          <svg x-show="!darkMode" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
            viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
          </svg>
        </button>

        <!-- User avatar -->
        <div class="flex items-center gap-2 pl-2 border-l border-[var(--color-border)]">
          <div
            class="w-8 h-8 rounded-full bg-gradient-to-br from-teal-600 to-sky-500 flex items-center justify-center text-white text-xs font-bold"
            x-text="user?.initials || 'U'"></div>
          <span class="text-sm font-medium hidden sm:inline" x-text="user?.name || 'User'"></span>
        </div>
      </div>
    </header>

    <!-- ============================================================== -->
    <!--  CONTENT AREA                                                   -->
    <!-- ============================================================== -->
    <div class="app-content">

      <!-- ============================================================ -->
      <!--  DASHBOARD VIEW                                               -->
      <!-- ============================================================ -->
      <div x-show="currentView === 'dashboard'" x-transition:enter="view-enter" class="h-full overflow-y-auto">
        <div class="dashboard-grid">
          <!-- Stat cards -->
          <template x-for="(stat, idx) in dashboardStats" :key="idx">
            <div class="stat-card">
              <div class="flex items-center justify-between">
                <span class="stat-label" x-text="stat.label"></span>
                <!-- Icon based on type -->
                <div class="w-8 h-8 rounded-lg flex items-center justify-center" :class="{
                       'bg-teal-50 dark:bg-teal-950/50 text-teal-600': stat.icon === 'chat',
                       'bg-sky-50 dark:bg-sky-950/50 text-sky-500': stat.icon === 'report',
                       'bg-cyan-50 dark:bg-cyan-950/50 text-cyan-500': stat.icon === 'metric',
                       'bg-green-50 dark:bg-green-950/50 text-green-500': stat.icon === 'health',
                     }">
                  <template x-if="stat.icon === 'chat'">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round"
                        d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 011.037-.443 48.282 48.282 0 005.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
                    </svg>
                  </template>
                  <template x-if="stat.icon === 'report'">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round"
                        d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                  </template>
                  <template x-if="stat.icon === 'metric'">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round"
                        d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                    </svg>
                  </template>
                  <template x-if="stat.icon === 'health'">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round"
                        d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                    </svg>
                  </template>
                </div>
              </div>
              <div class="stat-value" x-text="stat.value"></div>
              <div class="stat-change" :class="{
                     'text-green-500': stat.changeType === 'up',
                     'text-[var(--color-text-muted)]': stat.changeType === 'neutral',
                     'text-red-500': stat.changeType === 'down',
                   }" x-text="stat.change"></div>
            </div>
          </template>
        </div>

        <!-- Recent Activity + Quick Actions -->
        <div class="max-w-[1400px] mx-auto px-6 pb-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
          <!-- Recent Activity -->
          <div class="lg:col-span-2">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-[var(--color-text-muted)] mb-3">Recent
              Activity</h3>
            <div class="activity-list">
              <template x-for="(item, idx) in recentActivity" :key="idx">
                <div class="activity-item">
                  <div class="activity-dot" :class="item.color"></div>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm truncate" x-text="item.text"></p>
                  </div>
                  <span class="text-xs text-[var(--color-text-muted)] whitespace-nowrap" x-text="item.time"></span>
                </div>
              </template>
            </div>
          </div>

          <!-- Quick Actions -->
          <div>
            <h3 class="text-sm font-semibold uppercase tracking-wide text-[var(--color-text-muted)] mb-3">Quick Actions
            </h3>
            <div class="space-y-2">
              <button @click="navigate('chat')" class="quick-action-btn w-full">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                New Query
              </button>
              <button @click="navigate('reports')" class="quick-action-btn w-full">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round"
                    d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
                View Reports
              </button>
              <button @click="navigate('settings')" class="quick-action-btn w-full">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round"
                    d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                Settings
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ============================================================ -->
      <!--  CHAT VIEW                                                    -->
      <!-- ============================================================ -->
      <div x-show="currentView === 'chat'" x-transition:enter="view-enter" class="h-full overflow-hidden">
        <div class="chat-view-shell h-full overflow-hidden">

          <!-- Chat overlay (for drawers within chat) -->
          <div class="drawer-overlay" :class="{ visible: anyChatDrawerOpen() }" @click="closeChatDrawers()"></div>

          <!-- Chat history sidebar (slides in from left) -->
          <aside class="sidebar chat-history-sidebar" :class="{ open: chatSidebarOpen }">
            <div class="flex items-center justify-between p-4 border-b border-[var(--color-border)]">
              <h2 class="text-sm font-semibold tracking-wide uppercase text-[var(--color-text-muted)]">History</h2>
              <button @click="chatSidebarOpen = false"
                class="p-1 rounded-lg hover:bg-[var(--color-surface-alt)] transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <div class="p-3 space-y-1">
              <template x-for="conv in conversationHistory()" :key="conv.id">
                <button @click="scrollToConversation(conv.id)"
                  class="w-full text-left px-3 py-2.5 rounded-xl text-sm transition hover:bg-[var(--color-accent-soft)]"
                  :class="{ 'bg-[var(--color-accent-soft)] text-accent font-medium': activeConversationId === conv.id }">
                  <div class="truncate" x-text="conv.question"></div>
                  <div class="text-xs text-[var(--color-text-muted)] mt-0.5" x-text="Fmt.date(conv.timestamp)"></div>
                </button>
              </template>
              <div x-show="conversations.length === 0"
                class="px-3 py-8 text-center text-sm text-[var(--color-text-muted)]">
                No conversations yet. Ask a question to get started.
              </div>
            </div>
          </aside>

          <!-- Chat reports drawer (right) -->
          <aside class="reports-drawer" :class="{ open: chatReportsDrawerOpen }">
            <div class="flex items-center justify-between p-4 border-b border-[var(--color-border)]">
              <h2 class="text-sm font-semibold tracking-wide uppercase text-[var(--color-text-muted)]">Saved Reports
              </h2>
              <button @click="chatReportsDrawerOpen = false"
                class="p-1 rounded-lg hover:bg-[var(--color-surface-alt)] transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <div class="p-3 space-y-2">
              <template x-if="reportsLoading">
                <div class="space-y-3 p-2">
                  <div class="skeleton skeleton-line"></div>
                  <div class="skeleton skeleton-line"></div>
                  <div class="skeleton skeleton-line"></div>
                </div>
              </template>
              <template x-for="report in reports" :key="report.id">
                <div
                  class="p-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] hover:border-accent transition group">
                  <div class="flex items-start justify-between gap-2">
                    <button @click="loadReport(report)" class="text-left flex-1 min-w-0">
                      <div class="text-sm font-medium truncate" x-text="report.title"></div>
                      <div class="text-xs text-[var(--color-text-muted)] mt-1" x-text="Fmt.date(report.created_at)">
                      </div>
                    </button>
                    <button @click="deleteReport(report.id)"
                      class="p-1 rounded-lg opacity-0 group-hover:opacity-100 hover:bg-red-50 dark:hover:bg-red-950 text-red-500 transition"
                      title="Delete report">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                    </button>
                  </div>
                  <div class="flex flex-wrap gap-1.5 mt-2" x-show="report.plan?.metric">
                    <span class="badge badge-accent" x-text="Fmt.label(report.plan?.metric || '')"></span>
                    <template x-for="dim in (report.plan?.dimensions || [])" :key="dim">
                      <span class="badge badge-secondary" x-text="Fmt.label(dim)"></span>
                    </template>
                  </div>
                </div>
              </template>
              <div x-show="!reportsLoading && reports.length === 0"
                class="px-3 py-8 text-center text-sm text-[var(--color-text-muted)]">
                No saved reports yet.
              </div>
            </div>
          </aside>

          <!-- Chat main scrollable area -->
          <main class="overflow-y-auto" x-ref="chatContainer">

            <!-- Welcome state -->
            <div x-show="conversations.length === 0 && !loading" class="welcome-state min-h-[50vh]">
              <div class="welcome-icon">
                <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round"
                    d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 011.037-.443 48.282 48.282 0 005.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
                </svg>
              </div>
              <div>
                <h1 class="text-2xl font-bold tracking-tight">Ask a question about your data</h1>
                <p class="text-[var(--color-text-muted)] mt-2 text-sm max-w-md mx-auto leading-relaxed">
                  Explore viral load testing metrics, turnaround times, suppression rates, and more through natural
                  language.
                </p>
              </div>
              <div class="suggestion-chips">
                <button @click="useSuggestion('What is the VL suppression rate by district?')" class="suggestion-chip">
                  VL suppression rate by district
                </button>
                <button @click="useSuggestion('Show test volume trends for the last 12 months')"
                  class="suggestion-chip">
                  Test volume trends (12 months)
                </button>
                <button @click="useSuggestion('What is the average turnaround time by lab?')" class="suggestion-chip">
                  Turnaround time by lab
                </button>
                <button @click="useSuggestion('Show rejection rate by facility')" class="suggestion-chip">
                  Rejection rate by facility
                </button>
              </div>
            </div>

            <!-- Conversation results -->
            <template x-for="conv in conversations" :key="conv.id">
              <div :id="'conv-' + conv.id" class="mb-6">

                <!-- Question header -->
                <div class="max-w-[1400px] mx-auto px-4 pt-5 pb-2">
                  <div class="flex items-start gap-3">
                    <div
                      class="flex-shrink-0 w-8 h-8 rounded-full bg-gradient-to-br from-teal-600 to-sky-500 flex items-center justify-center text-white text-xs font-bold mt-0.5">
                      Q</div>
                    <div>
                      <p class="text-base font-medium leading-relaxed" x-text="conv.question"></p>
                      <p class="text-xs text-[var(--color-text-muted)] mt-1" x-text="Fmt.date(conv.timestamp)"></p>
                    </div>
                  </div>
                </div>


                <!-- Error state -->
                <div x-show="conv.error" class="bento-grid">
                  <div class="bento-card-full glass-card card-error card-enter">
                    <div class="flex items-start gap-3">
                      <div class="error-icon flex-shrink-0 mt-0.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                      </div>
                      <div>
                        <h3 class="text-sm font-semibold text-red-600 dark:text-red-400">Something went wrong</h3>
                        <p class="text-sm text-[var(--color-text-muted)] mt-1" x-text="conv.error"></p>
                        <button @click="question = conv.question; $nextTick(() => sendQuestion())"
                          class="mt-3 text-xs font-medium text-accent hover:text-accent-dark transition">
                          Retry this question
                        </button>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Result bento cards -->
                <div x-show="conv.response" class="bento-grid">

                  <!-- Explanation + SQL (collapsible) -->
                  <div x-show="hasExplanation(conv) || conv.response?.sql" x-data="{ expanded: false }" class="bento-card-full card-enter">
                    <button @click="expanded = !expanded"
                      class="flex items-center gap-2 text-xs text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition w-full">
                      <svg class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-90': expanded }" fill="none"
                        stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                      </svg>
                      <span class="font-medium uppercase tracking-wide">Explanation</span>
                      <span x-show="intentBadge(conv)" class="badge ml-1"
                        :class="intentBadge(conv)?.allowed ? 'badge-success' : 'badge-danger'"
                        x-text="intentBadge(conv)?.type"></span>
                    </button>
                    <div x-show="expanded" x-transition class="mt-2 pl-5.5 space-y-3">
                      <p x-show="conv.response?.explanation" class="text-sm leading-relaxed text-[var(--color-text-muted)]"
                         x-text="conv.response?.explanation"></p>
                      <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-[var(--color-text-muted)]">
                        <span x-show="conv.response?.debug?.tables_used?.length"
                              x-text="'Tables: ' + (conv.response?.debug?.tables_used || []).join(', ')"></span>
                        <span x-show="conv.response?.meta?.sql_execution_time_ms"
                              x-text="'SQL time: ' + conv.response?.meta?.sql_execution_time_ms + 'ms'"></span>
                      </div>
                      <div x-show="conv.response?.sql">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-[var(--color-text-muted)] mb-1">Generated SQL</p>
                        <pre class="text-xs bg-[var(--color-surface-alt)] rounded-lg p-3 overflow-x-auto text-[var(--color-text-muted)] font-mono leading-relaxed"><code x-text="conv.response?.sql"></code></pre>
                      </div>
                      <div x-show="conv.response?.citations?.length > 0">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-[var(--color-text-muted)] mb-1">Citations</p>
                        <div class="flex flex-wrap gap-1.5">
                          <template x-for="cit in (conv.response?.citations || [])" :key="cit">
                            <span class="badge badge-secondary text-[11px]" x-text="cit"></span>
                          </template>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Data table (full width) -->
                  <div x-show="hasTable(conv)" class="bento-card-full glass-card card-table card-enter">
                    <div class="card-header flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-accent flex-shrink-0" fill="none" stroke="currentColor"
                          stroke-width="2" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 0v.375" />
                        </svg>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-muted)]">Results
                        </h3>
                      </div>
                      <div class="flex items-center gap-3 text-xs text-[var(--color-text-muted)]">
                        <span x-show="totalRows(conv) > 0" x-text="Fmt.number(totalRows(conv)) + ' rows'"></span>
                        <span x-show="executionTime(conv)" x-text="Fmt.duration(executionTime(conv))"></span>
                      </div>
                    </div>
                    <div class="data-table-wrapper max-h-[320px]">
                      <table class="data-table">
                        <thead>
                          <tr>
                            <template x-for="col in tableColumns(conv)" :key="col">
                              <th :class="{ 'numeric': isNumericColumn(col) }" x-text="Fmt.label(col)"></th>
                            </template>
                          </tr>
                        </thead>
                        <tbody>
                          <template x-for="(row, rowIdx) in tableRowsLimited(conv)" :key="rowIdx">
                            <tr>
                              <template x-for="(col, colIdx) in tableColumns(conv)" :key="colIdx">
                                <td :class="{ 'numeric': isNumericColumn(col) }"
                                  x-text="formatCellValue(Array.isArray(row) ? row[colIdx] : row[col], col)"></td>
                              </template>
                            </tr>
                          </template>
                        </tbody>
                      </table>
                    </div>
                    <!-- Footer: pagination + actions -->
                    <div class="flex items-center justify-between px-4 py-2 border-t border-[var(--color-border)]">
                      <div class="flex items-center gap-3 text-xs text-[var(--color-text-muted)]">
                        <span x-text="Fmt.number(totalRows(conv)) + ' rows'"></span>
                        <template x-if="totalPages(conv) > 1">
                          <div class="flex items-center gap-1.5">
                            <button @click="prevPage(conv)" :disabled="tablePage(conv) <= 1"
                              class="p-1 rounded hover:bg-[var(--color-surface-alt)] transition disabled:opacity-30">
                              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                              </svg>
                            </button>
                            <span x-text="tablePage(conv) + ' / ' + totalPages(conv)"></span>
                            <button @click="nextPage(conv)" :disabled="tablePage(conv) >= totalPages(conv)"
                              class="p-1 rounded hover:bg-[var(--color-surface-alt)] transition disabled:opacity-30">
                              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                              </svg>
                            </button>
                          </div>
                        </template>
                      </div>
                      <div class="flex items-center gap-2">
                        <button
                          x-show="hasTable(conv) && !conv.response?.showChart && (conv.response?.chartTypes?.length > 0)"
                          @click="showChartForConv(conv)" class="flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-accent
                                       hover:bg-[var(--color-accent-soft)] rounded-lg transition">
                          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                              d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                          </svg>
                          Chart
                        </button>
                    </div>
                  </div>

                  <!-- Chart (on-demand, full width) -->
                  <div x-show="conv.response?.showChart" class="bento-card-full glass-card card-chart card-enter">
                    <div class="flex items-center justify-between mb-3 px-0.5">
                      <h3 class="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-muted)]">Chart</h3>
                      <div class="flex items-center gap-1.5">
                        <template x-for="ct in (conv.response?.chartTypes || [])" :key="ct">
                          <button @click="switchChartType(conv, ct)"
                            class="px-2 py-0.5 text-[11px] font-medium rounded-md border transition"
                            :class="conv.response?._activeChartType === ct
                                    ? 'border-accent text-accent bg-[var(--color-accent-soft)]'
                                    : 'border-[var(--color-border)] text-[var(--color-text-muted)] hover:border-accent hover:text-accent'" x-text="ct">
                          </button>
                        </template>
                        <button @click="conv.response.showChart = false"
                          class="p-1 rounded-md text-[var(--color-text-muted)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-alt)] transition ml-1"
                          title="Close chart">
                          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                          </svg>
                        </button>
                      </div>
                    </div>
                    <div class="chart-container">
                      <canvas :id="chartCanvasId(conv)"></canvas>
                    </div>
                  </div>

                  <!-- Plan details -->
                  <div x-show="hasPlan(conv)" class="bento-card-sm glass-card card-plan card-enter">
                    <div class="flex items-center gap-2 mb-3">
                      <svg class="w-4 h-4 text-secondary flex-shrink-0" fill="none" stroke="currentColor"
                        stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                      </svg>
                      <h3 class="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-muted)]">Query
                        Plan</h3>
                    </div>
                    <div class="space-y-2">
                      <template x-for="item in planDetails(conv)" :key="item.label">
                        <div class="chip">
                          <span class="chip-label" x-text="item.label"></span>
                          <span x-text="item.value"></span>
                        </div>
                      </template>
                    </div>
                    <div class="mt-4 pt-3 border-t border-[var(--color-border)]">
                      <button @click="saveAsReport(conv)" class="w-full flex items-center justify-center gap-2 px-3 py-2 text-xs font-medium
                                     rounded-lg border border-[var(--color-border)]
                                     hover:border-accent hover:text-accent hover:bg-[var(--color-accent-soft)]
                                     transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round"
                            d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" />
                        </svg>
                        Save as Report
                      </button>
                    </div>
                  </div>

                </div>
              </div>
            </template>

            <!-- Pipeline step indicator removed — now inline with loading state above -->

          </main>

          <!-- Chat input -->
          <footer class="chat-input-area">
            <!-- Thinking indicator (above input, Claude-style) -->
            <div x-show="loading" x-transition:enter="transition ease-out duration-200"
              x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
              class="flex items-center gap-2.5 px-5 py-2 max-w-3xl mx-auto w-full">
              <div class="thinking-shimmer flex-shrink-0"></div>
              <span class="text-sm text-[var(--color-text-muted)]" x-text="thinkingMessage"></span>
            </div>

            <div class="chat-input-wrapper">
              <div class="chat-input-box">
                <textarea x-ref="chatInput" x-model="question" @keydown="handleKeydown($event)"
                  @input="autoResize($event)" rows="1"
                  placeholder="Ask about viral load data, suppression rates, turnaround times..." :disabled="loading"
                  class="disabled:opacity-50"></textarea>
                <button @click="sendQuestion()" :disabled="loading || !question.trim()" class="send-btn"
                  title="Send question">
                  <svg x-show="!loading" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5"
                    viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5L12 3m0 0l7.5 7.5M12 3v18" />
                  </svg>
                  <svg x-show="loading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                  </svg>
                </button>
              </div>
              <p class="text-center text-[10px] text-[var(--color-text-muted)] mt-1.5 select-none">
                Intelis Insights &mdash; AI-powered analytics for laboratory data. The AI assistant never accesses the database directly.
              </p>
            </div>
          </footer>

        </div>
      </div>

      <!-- ============================================================ -->
      <!--  REPORTS VIEW                                                 -->
      <!-- ============================================================ -->
      <div x-show="currentView === 'reports'" x-transition:enter="view-enter" class="h-full overflow-y-auto">
        <div class="max-w-[1400px] mx-auto p-6">

          <div class="flex items-center justify-between mb-6">
            <div>
              <h2 class="text-lg font-semibold">Saved Reports</h2>
              <p class="text-sm text-[var(--color-text-muted)] mt-0.5">View and manage your saved analytics reports</p>
            </div>
            <button @click="fetchReports()" class="btn-secondary text-sm">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182" />
              </svg>
              Refresh
            </button>
          </div>

          <!-- Loading -->
          <div x-show="reportsLoading" class="reports-grid">
            <template x-for="i in 6" :key="i">
              <div class="glass-card">
                <div class="skeleton skeleton-line w-3/4"></div>
                <div class="skeleton skeleton-line w-full mt-3"></div>
                <div class="skeleton skeleton-line w-1/2 mt-2"></div>
              </div>
            </template>
          </div>

          <!-- Reports grid -->
          <div x-show="!reportsLoading && reports.length > 0" class="reports-grid">
            <template x-for="report in reports" :key="report.id">
              <div class="glass-card group cursor-pointer" @click="loadReport(report)">
                <div class="flex items-start justify-between gap-2 mb-3">
                  <div class="flex-1 min-w-0">
                    <h3 class="text-sm font-semibold truncate" x-text="report.title"></h3>
                    <p class="text-xs text-[var(--color-text-muted)] mt-1" x-text="Fmt.date(report.created_at)"></p>
                  </div>
                  <button @click.stop="deleteReport(report.id)"
                    class="p-1.5 rounded-lg opacity-0 group-hover:opacity-100 hover:bg-red-50 dark:hover:bg-red-950 text-red-500 transition"
                    title="Delete report">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round"
                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>

                <p class="text-xs text-[var(--color-text-muted)] truncate mb-3" x-text="report.question || ''"></p>

                <div class="flex flex-wrap gap-1.5" x-show="report.plan?.metric">
                  <span class="badge badge-accent" x-text="Fmt.label(report.plan?.metric || '')"></span>
                  <template x-for="dim in (report.plan?.dimensions || []).slice(0, 3)" :key="dim">
                    <span class="badge badge-secondary" x-text="Fmt.label(dim)"></span>
                  </template>
                </div>
              </div>
            </template>
          </div>

          <!-- Empty state -->
          <div x-show="!reportsLoading && reports.length === 0" class="text-center py-16">
            <div
              class="w-16 h-16 rounded-xl bg-[var(--color-accent-soft)] flex items-center justify-center mx-auto mb-4">
              <svg class="w-8 h-8 text-accent" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
              </svg>
            </div>
            <h3 class="text-base font-semibold mb-1">No reports yet</h3>
            <p class="text-sm text-[var(--color-text-muted)] mb-4">Run a query in Chat and save it as a report to see it
              here.</p>
            <button @click="navigate('chat')" class="btn-primary text-sm !py-2.5 !px-5">
              Go to Chat
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
              </svg>
            </button>
          </div>

        </div>
      </div>

      <!-- ============================================================ -->
      <!--  SETTINGS VIEW                                                -->
      <!-- ============================================================ -->
      <div x-show="currentView === 'settings'" x-transition:enter="view-enter" class="h-full overflow-y-auto">
        <div class="max-w-[700px] mx-auto p-6">

          <div class="mb-6">
            <h2 class="text-lg font-semibold">Settings</h2>
            <p class="text-sm text-[var(--color-text-muted)] mt-0.5">Configure your analytics workspace preferences</p>
          </div>

          <!-- Appearance -->
          <div class="settings-section mb-6">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-[var(--color-text-muted)] mb-2">Appearance
            </h3>

            <div class="settings-row">
              <div>
                <p class="text-sm font-medium">Dark Mode</p>
                <p class="text-xs text-[var(--color-text-muted)] mt-0.5">Toggle between light and dark theme</p>
              </div>
              <button @click="toggleDarkMode()" class="toggle-switch" :class="{ active: darkMode }"></button>
            </div>

            <div class="settings-row">
              <div>
                <p class="text-sm font-medium">Compact View</p>
                <p class="text-xs text-[var(--color-text-muted)] mt-0.5">Reduce padding and spacing in results</p>
              </div>
              <button @click="settings.compactView = !settings.compactView" class="toggle-switch"
                :class="{ active: settings.compactView }"></button>
            </div>
          </div>

          <!-- Data preferences -->
          <div class="settings-section mb-6">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-[var(--color-text-muted)] mb-2">Data
              Preferences</h3>

            <div class="settings-row">
              <div>
                <p class="text-sm font-medium">Default Time Range</p>
                <p class="text-xs text-[var(--color-text-muted)] mt-0.5">Default period for time-based queries</p>
              </div>
              <select x-model="settings.defaultTimeRange"
                class="text-sm bg-[var(--color-surface)] border border-[var(--color-border)] rounded-lg px-3 py-1.5 outline-none focus:border-accent transition">
                <option value="3months">Last 3 months</option>
                <option value="6months">Last 6 months</option>
                <option value="12months">Last 12 months</option>
                <option value="ytd">Year to date</option>
                <option value="all">All time</option>
              </select>
            </div>
          </div>

          <!-- Notifications -->
          <div class="settings-section mb-6">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-[var(--color-text-muted)] mb-2">Notifications
            </h3>

            <div class="settings-row">
              <div>
                <p class="text-sm font-medium">Email Notifications</p>
                <p class="text-xs text-[var(--color-text-muted)] mt-0.5">Receive email alerts for scheduled reports</p>
              </div>
              <button @click="settings.notifications = !settings.notifications" class="toggle-switch"
                :class="{ active: settings.notifications }"></button>
            </div>
          </div>

          <!-- Save button -->
          <div class="flex justify-end">
            <button @click="saveSettings()" class="btn-primary text-sm !py-2.5 !px-6">
              Save Settings
            </button>
          </div>

          <!-- Account section -->
          <div class="settings-section mt-8">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-[var(--color-text-muted)] mb-2">Account</h3>

            <div class="settings-row">
              <div>
                <p class="text-sm font-medium" x-text="user?.name || 'User'"></p>
                <p class="text-xs text-[var(--color-text-muted)] mt-0.5" x-text="user?.email || ''"></p>
              </div>
              <button @click="logout()" class="text-sm font-medium text-red-500 hover:text-red-600 transition">
                Sign Out
              </button>
            </div>
          </div>

        </div>
      </div>

    </div><!-- end app-content -->
  </div><!-- end app-layout -->

  <!-- ================================================================== -->
  <!--  TOAST NOTIFICATION                                                 -->
  <!-- ================================================================== -->
  <div x-show="_toast" x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-2" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg
              border border-[var(--color-border)] bg-[var(--color-surface)]" :class="{
         'text-green-600 dark:text-green-400': _toast?.type === 'success',
         'text-red-600 dark:text-red-400': _toast?.type === 'error',
       }" x-text="_toast?.msg">
  </div>

</body>

</html>