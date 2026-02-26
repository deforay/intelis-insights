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

  <!-- App JS (Alpine component) -->
  <script src="<?= asset('/assets/js/app.js') ?>"></script>
</head>

<body class="min-h-screen" x-data="chatApp" x-init="init()">

  <!-- ================================================================== -->
  <!--  OVERLAY (for drawers)                                             -->
  <!-- ================================================================== -->
  <div class="drawer-overlay" :class="{ visible: anyDrawerOpen() }" @click="closeDrawers()"></div>

  <!-- ================================================================== -->
  <!--  LEFT SIDEBAR -- Chat History                                      -->
  <!-- ================================================================== -->
  <aside class="sidebar" :class="{ open: sidebarOpen }">
    <div class="flex items-center justify-between p-4 border-b border-[var(--color-border)]">
      <h2 class="text-sm font-semibold tracking-wide uppercase text-[var(--color-text-muted)]">History</h2>
      <button @click="sidebarOpen = false" class="p-1 rounded-lg hover:bg-[var(--color-surface-alt)] transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <div class="p-3 space-y-1">
      <template x-for="conv in conversationHistory()" :key="conv.id">
        <button @click="scrollToConversation(conv.id)" class="w-full text-left px-3 py-2.5 rounded-xl text-sm transition
                 hover:bg-[var(--color-accent-soft)]"
          :class="{ 'bg-[var(--color-accent-soft)] text-accent font-medium': activeConversationId === conv.id }">
          <div class="truncate" x-text="conv.question"></div>
          <div class="text-xs text-[var(--color-text-muted)] mt-0.5" x-text="Fmt.date(conv.timestamp)"></div>
        </button>
      </template>

      <!-- Empty state -->
      <div x-show="conversations.length === 0" class="px-3 py-8 text-center text-sm text-[var(--color-text-muted)]">
        No conversations yet. Ask a question to get started.
      </div>
    </div>
  </aside>

  <!-- ================================================================== -->
  <!--  RIGHT DRAWER -- Saved Reports                                     -->
  <!-- ================================================================== -->
  <aside class="reports-drawer" :class="{ open: reportsDrawerOpen }">
    <div class="flex items-center justify-between p-4 border-b border-[var(--color-border)]">
      <h2 class="text-sm font-semibold tracking-wide uppercase text-[var(--color-text-muted)]">Saved Reports</h2>
      <button @click="reportsDrawerOpen = false" class="p-1 rounded-lg hover:bg-[var(--color-surface-alt)] transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Reports list -->
    <div class="p-3 space-y-2">
      <!-- Loading -->
      <template x-if="reportsLoading">
        <div class="space-y-3 p-2">
          <div class="skeleton skeleton-line"></div>
          <div class="skeleton skeleton-line"></div>
          <div class="skeleton skeleton-line"></div>
        </div>
      </template>

      <!-- Reports -->
      <template x-for="report in reports" :key="report.id">
        <div
          class="p-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] hover:border-accent transition group">
          <div class="flex items-start justify-between gap-2">
            <button @click="loadReport(report)" class="text-left flex-1 min-w-0">
              <div class="text-sm font-medium truncate" x-text="report.title"></div>
              <div class="text-xs text-[var(--color-text-muted)] mt-1" x-text="Fmt.date(report.created_at)"></div>
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

          <!-- Report meta chips -->
          <div class="flex flex-wrap gap-1.5 mt-2" x-show="report.plan?.metric">
            <span class="badge badge-accent" x-text="Fmt.label(report.plan?.metric || '')"></span>
            <template x-for="dim in (report.plan?.dimensions || [])" :key="dim">
              <span class="badge badge-secondary" x-text="Fmt.label(dim)"></span>
            </template>
          </div>
        </div>
      </template>

      <!-- Empty -->
      <div x-show="!reportsLoading && reports.length === 0"
        class="px-3 py-8 text-center text-sm text-[var(--color-text-muted)]">
        No saved reports yet. Run a query and save it as a report.
      </div>
    </div>
  </aside>

  <!-- ================================================================== -->
  <!--  APP SHELL                                                          -->
  <!-- ================================================================== -->
  <div class="app-shell">

    <!-- ============================================================== -->
    <!--  TOP NAV                                                        -->
    <!-- ============================================================== -->
    <nav class="top-nav">
      <div class="max-w-[1400px] mx-auto flex items-center justify-between px-4 h-14">
        <!-- Left: menu + logo -->
        <div class="flex items-center gap-3">
          <button @click="toggleSidebar()" class="p-2 rounded-xl hover:bg-[var(--color-surface-alt)] transition"
            title="Chat history">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>

          <a href="/chat.php" class="flex items-center gap-2.5 select-none">
            <!-- Logo mark -->
            <div
              class="w-8 h-8 rounded-lg bg-gradient-to-br from-teal-600 to-sky-500 flex items-center justify-center shadow-sm">
              <svg class="w-4.5 h-4.5 text-white" fill="none" stroke="currentColor" stroke-width="2.5"
                viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
              </svg>
            </div>
            <span class="text-base font-semibold tracking-tight">
              Intelis <span class="text-accent">Insights</span>
            </span>
          </a>
        </div>

        <!-- Right: actions -->
        <div class="flex items-center gap-1.5">
          <!-- Dark mode toggle -->
          <button @click="toggleDarkMode()" class="p-2 rounded-xl hover:bg-[var(--color-surface-alt)] transition"
            title="Toggle dark mode">
            <!-- Sun -->
            <svg x-show="darkMode" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
              viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round"
                d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
            </svg>
            <!-- Moon -->
            <svg x-show="!darkMode" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
              viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round"
                d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
            </svg>
          </button>

          <!-- Reports drawer -->
          <button @click="toggleReportsDrawer()" class="p-2 rounded-xl hover:bg-[var(--color-surface-alt)] transition"
            title="Saved reports">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round"
                d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
            </svg>
          </button>
        </div>
      </div>
    </nav>

    <!-- ============================================================== -->
    <!--  MAIN CONTENT                                                   -->
    <!-- ============================================================== -->
    <main class="overflow-y-auto" x-ref="bentoContainer">

      <!-- Welcome state (shown when no conversations) -->
      <div x-show="conversations.length === 0 && !loading" class="welcome-state min-h-[60vh]">
        <div class="welcome-icon">
          <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 011.037-.443 48.282 48.282 0 005.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
          </svg>
        </div>

        <div>
          <h1 class="text-2xl font-bold tracking-tight">Ask a question about your data</h1>
          <p class="text-[var(--color-text-muted)] mt-2 text-sm max-w-md mx-auto leading-relaxed">
            Explore viral load testing metrics, turnaround times, suppression rates, and more through natural language.
          </p>
        </div>

        <div class="suggestion-chips">
          <button @click="useSuggestion('What is the VL suppression rate by district?')" class="suggestion-chip">
            VL suppression rate by district
          </button>
          <button @click="useSuggestion('Show test volume trends for the last 12 months')" class="suggestion-chip">
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
                Q
              </div>
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

            <!-- Card 1: Explanation + SQL (collapsible) -->
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
                <!-- Query details -->
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

            <!-- Card 2: Data table (full width) -->
            <div x-show="hasTable(conv)" class="bento-card-full glass-card card-table card-enter">
              <div class="card-header flex items-center justify-between">
                <div class="flex items-center gap-2">
                  <svg class="w-4 h-4 text-accent flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2"
                    viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                      d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 0v.375" />
                  </svg>
                  <h3 class="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-muted)]">Results</h3>
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
                  <button x-show="!conv.response?.showChart && (conv.response?.chartTypes?.length > 0)"
                    @click="showChartForConv(conv)" class="flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-accent
                                 hover:bg-[var(--color-accent-soft)] rounded-lg transition">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round"
                        d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                    </svg>
                    Chart
                  </button>
                  <button @click="saveAsReport(conv)" class="flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-[var(--color-text-muted)]
                                 hover:text-accent hover:bg-[var(--color-accent-soft)] rounded-lg transition">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round"
                        d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" />
                    </svg>
                    Save
                  </button>
                </div>
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

          </div><!-- end bento-grid -->
        </div><!-- end conv wrapper -->
      </template>

      <!-- Pipeline steps now shown inline with each conversation above -->

    </main>

    <!-- Floating scroll-to-bottom button -->
    <button x-show="showScrollBtn" x-transition:enter="transition ease-out duration-200"
      x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
      x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
      x-transition:leave-end="opacity-0 translate-y-2" @click="scrollToBottom()"
      class="fixed bottom-24 right-6 z-30 w-10 h-10 rounded-full bg-[var(--color-surface)] border border-[var(--color-border)] shadow-lg flex items-center justify-center hover:bg-[var(--color-surface-alt)] transition cursor-pointer">
      <svg class="w-5 h-5 text-[var(--color-text-muted)]" fill="none" stroke="currentColor" stroke-width="2"
        viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
      </svg>
    </button>

    <!-- ============================================================== -->
    <!--  CHAT INPUT                                                     -->
    <!-- ============================================================== -->
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
          <textarea x-ref="chatInput" x-model="question" @keydown="handleKeydown($event)" @input="autoResize($event)"
            rows="1" placeholder="Ask about viral load data, suppression rates, turnaround times..." :disabled="loading"
            class="disabled:opacity-50"></textarea>

          <button @click="sendQuestion()" :disabled="loading || !question.trim()" class="send-btn"
            title="Send question">
            <!-- Arrow up icon -->
            <svg x-show="!loading" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5"
              viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5L12 3m0 0l7.5 7.5M12 3v18" />
            </svg>
            <!-- Spinner -->
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

  </div><!-- end app-shell -->

  <!-- ================================================================== -->
  <!--  TOAST NOTIFICATION                                                 -->
  <!-- ================================================================== -->
  <div x-show="_toast" x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-2" class="fixed bottom-24 left-1/2 -translate-x-1/2 z-[60] px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg
              border border-[var(--color-border)] bg-[var(--color-surface)]" :class="{
         'text-green-600 dark:text-green-400': _toast?.type === 'success',
         'text-red-600 dark:text-red-400': _toast?.type === 'error',
       }" x-text="_toast?.msg">
  </div>

</body>

</html>