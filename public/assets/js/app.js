/* ============================================================
   Intelis Insights -- app.js
   Alpine.js application: chat, bento results, charts, reports
   ============================================================ */

// ---------------------------------------------------------------------------
//  Helpers
// ---------------------------------------------------------------------------

const Fmt = {
  /** Format a number with locale-aware thousands separators. */
  number(val, decimals = 0) {
    if (val == null || isNaN(val)) return '--';
    return Number(val).toLocaleString(undefined, {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    });
  },

  /** Format a value as a percentage. */
  percent(val, decimals = 1) {
    if (val == null || isNaN(val)) return '--';
    const n = Number(val);
    // If the value is already 0-1, multiply by 100
    const pct = n > 1 ? n : n * 100;
    return pct.toFixed(decimals) + '%';
  },

  /** Format an ISO date string for display. */
  date(iso) {
    if (!iso) return '--';
    try {
      return new Date(iso).toLocaleDateString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric',
      });
    } catch { return iso; }
  },

  /** Sentence-case a snake_case or camelCase string. */
  label(str) {
    if (!str) return '';
    return str
      .replace(/_/g, ' ')
      .replace(/([a-z])([A-Z])/g, '$1 $2')
      .replace(/\b\w/g, c => c.toUpperCase());
  },

  /** Truncate a string with ellipsis. */
  truncate(str, len = 80) {
    if (!str) return '';
    return str.length > len ? str.slice(0, len) + '...' : str;
  },

  /** Duration in ms to human-readable. */
  duration(ms) {
    if (ms == null) return '--';
    if (ms < 1000) return ms + 'ms';
    return (ms / 1000).toFixed(2) + 's';
  },
};

// Expose globally for inline template use
window.Fmt = Fmt;

// ---------------------------------------------------------------------------
//  Chart colour palette
// ---------------------------------------------------------------------------

const CHART_COLORS = [
  '#6366f1', // indigo
  '#8b5cf6', // violet
  '#06b6d4', // cyan
  '#22c55e', // green
  '#f59e0b', // amber
  '#ef4444', // red
  '#ec4899', // pink
  '#14b8a6', // teal
  '#f97316', // orange
  '#64748b', // slate
];

function chartColor(index, alpha = 1) {
  const hex = CHART_COLORS[index % CHART_COLORS.length];
  if (alpha >= 1) return hex;
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return `rgba(${r},${g},${b},${alpha})`;
}

// ---------------------------------------------------------------------------
//  UUID helper
// ---------------------------------------------------------------------------

function uuid() {
  if (crypto.randomUUID) return crypto.randomUUID();
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

// ---------------------------------------------------------------------------
//  Alpine: chatApp component
// ---------------------------------------------------------------------------

document.addEventListener('alpine:init', () => {

  // =========================================================================
  //  Landing page component (dark mode only)
  // =========================================================================
  Alpine.data('landingApp', () => ({
    darkMode: false,

    init() {
      const stored = localStorage.getItem('ii-dark-mode');
      if (stored !== null) {
        this.darkMode = stored === 'true';
      } else {
        this.darkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
      }
      this.applyDarkMode();
    },

    toggleDarkMode() {
      this.darkMode = !this.darkMode;
      localStorage.setItem('ii-dark-mode', String(this.darkMode));
      this.applyDarkMode();
    },

    applyDarkMode() {
      document.documentElement.classList.toggle('dark', this.darkMode);
    },
  }));

  // =========================================================================
  //  Login page component
  // =========================================================================
  Alpine.data('loginApp', () => ({
    darkMode: false,
    email: '',
    password: '',
    error: '',
    loading: false,

    init() {
      // If already authenticated, redirect
      if (localStorage.getItem('ii-auth-token')) {
        window.location.href = '/app';
        return;
      }
      const stored = localStorage.getItem('ii-dark-mode');
      if (stored !== null) {
        this.darkMode = stored === 'true';
      } else {
        this.darkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
      }
      this.applyDarkMode();
    },

    toggleDarkMode() {
      this.darkMode = !this.darkMode;
      localStorage.setItem('ii-dark-mode', String(this.darkMode));
      this.applyDarkMode();
    },

    applyDarkMode() {
      document.documentElement.classList.toggle('dark', this.darkMode);
    },

    async signIn() {
      this.error = '';
      if (!this.email.trim() || !this.password.trim()) {
        this.error = 'Please enter both email and password.';
        return;
      }
      this.loading = true;

      // Simulate auth delay
      await new Promise(r => setTimeout(r, 800));

      // Mock auth: accept any email/password with basic validation
      if (!this.email.includes('@')) {
        this.error = 'Please enter a valid email address.';
        this.loading = false;
        return;
      }

      // Store mock token and user info
      const token = 'ii-mock-' + Date.now() + '-' + Math.random().toString(36).slice(2);
      const user = {
        email: this.email,
        name: this.email.split('@')[0].replace(/[._-]/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
        initials: this.email.slice(0, 2).toUpperCase(),
      };

      localStorage.setItem('ii-auth-token', token);
      localStorage.setItem('ii-user', JSON.stringify(user));

      this.loading = false;
      window.location.href = '/app';
    },
  }));

  // =========================================================================
  //  App shell component (authenticated area)
  // =========================================================================
  Alpine.data('insightsApp', () => ({
    // ---- State ----
    darkMode: false,
    currentView: 'dashboard',
    sidebarCollapsed: false,
    mobileSidebarOpen: false,
    user: null,

    // Chat state (mirrors chatApp but embedded)
    chatSidebarOpen: false,
    chatReportsDrawerOpen: false,
    question: '',
    loading: false,
    sessionId: null,
    conversations: [],
    activeConversationId: null,
    reports: [],
    reportsLoading: false,
    _charts: {},
    _toast: null,
    _toastTimeout: null,

    // Thinking message rotation
    thinkingMessage: '',
    _thinkingTimer: null,
    _thinkingMessages: [
      'Understanding your question...',
      'Analyzing the data...',
      'Looking through the records...',
      'Preparing your results...',
    ],

    // Dashboard placeholders
    dashboardStats: [
      { label: 'Total Queries', value: '1,247', change: '+12%', changeType: 'up', icon: 'chat' },
      { label: 'Active Reports', value: '23', change: '+3', changeType: 'up', icon: 'report' },
      { label: 'Available Metrics', value: '48', change: 'Stable', changeType: 'neutral', icon: 'metric' },
      { label: 'System Health', value: '99.9%', change: 'Operational', changeType: 'up', icon: 'health' },
    ],

    recentActivity: [
      { text: 'Query: VL suppression rate by district', time: '2 minutes ago', color: 'bg-teal-600' },
      { text: 'Report saved: Monthly TAT Analysis', time: '15 minutes ago', color: 'bg-sky-500' },
      { text: 'Query: Test volume trends Q4', time: '1 hour ago', color: 'bg-cyan-500' },
      { text: 'Report updated: Facility Performance', time: '3 hours ago', color: 'bg-green-500' },
      { text: 'Query: Rejection rate by sample type', time: '5 hours ago', color: 'bg-amber-500' },
    ],

    // Settings state
    settings: {
      darkMode: false,
      defaultTimeRange: '12months',
      notifications: true,
      compactView: false,
    },

    // ---- Lifecycle ----
    init() {
      // Auth check
      const token = localStorage.getItem('ii-auth-token');
      if (!token) {
        window.location.href = '/login';
        return;
      }

      // Load user
      try {
        this.user = JSON.parse(localStorage.getItem('ii-user') || '{}');
      } catch {
        this.user = { name: 'User', email: '', initials: 'U' };
      }

      // Dark mode
      const stored = localStorage.getItem('ii-dark-mode');
      if (stored !== null) {
        this.darkMode = stored === 'true';
      } else {
        this.darkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
      }
      this.settings.darkMode = this.darkMode;
      this.applyDarkMode();

      // Session
      this.sessionId = localStorage.getItem('ii-session-id') || null;

      // Check if we should open chat view (from /chat route)
      const params = new URLSearchParams(window.location.search);
      if (params.get('view') === 'chat') {
        this.currentView = 'chat';
      }

      // Load reports
      this.fetchReports();

      // Load settings
      try {
        const savedSettings = JSON.parse(localStorage.getItem('ii-settings') || '{}');
        this.settings = { ...this.settings, ...savedSettings };
      } catch {}
    },

    // ---- Dark mode ----
    toggleDarkMode() {
      this.darkMode = !this.darkMode;
      this.settings.darkMode = this.darkMode;
      localStorage.setItem('ii-dark-mode', String(this.darkMode));
      this.applyDarkMode();
    },

    applyDarkMode() {
      document.documentElement.classList.toggle('dark', this.darkMode);
    },

    // ---- Navigation ----
    navigate(view) {
      this.currentView = view;
      this.mobileSidebarOpen = false;
      // Update URL without reload
      const url = view === 'chat' ? '/chat' : '/app';
      window.history.replaceState({}, '', url);
    },

    toggleSidebarCollapse() {
      this.sidebarCollapsed = !this.sidebarCollapsed;
    },

    toggleMobileSidebar() {
      this.mobileSidebarOpen = !this.mobileSidebarOpen;
    },

    closeMobileSidebar() {
      this.mobileSidebarOpen = false;
    },

    logout() {
      localStorage.removeItem('ii-auth-token');
      localStorage.removeItem('ii-user');
      window.location.href = '/login';
    },

    // ---- Chat methods (same as chatApp) ----
    toggleChatSidebar() {
      this.chatSidebarOpen = !this.chatSidebarOpen;
      if (this.chatSidebarOpen) this.chatReportsDrawerOpen = false;
    },

    toggleChatReportsDrawer() {
      this.chatReportsDrawerOpen = !this.chatReportsDrawerOpen;
      if (this.chatReportsDrawerOpen) {
        this.chatSidebarOpen = false;
        this.fetchReports();
      }
    },

    closeChatDrawers() {
      this.chatSidebarOpen = false;
      this.chatReportsDrawerOpen = false;
    },

    anyChatDrawerOpen() {
      return this.chatSidebarOpen || this.chatReportsDrawerOpen;
    },

    autoResize(event) {
      const el = event.target;
      el.style.height = 'auto';
      el.style.height = Math.min(el.scrollHeight, 160) + 'px';
    },

    useSuggestion(text) {
      this.question = text;
      this.$nextTick(() => {
        const ta = this.$refs.chatInput;
        if (ta) {
          ta.focus();
          ta.style.height = 'auto';
          ta.style.height = Math.min(ta.scrollHeight, 160) + 'px';
        }
      });
    },


    // Thinking message rotation
    _startThinking() {
      let idx = 0;
      this.thinkingMessage = this._thinkingMessages[0];
      this._thinkingTimer = setInterval(() => {
        idx = (idx + 1) % this._thinkingMessages.length;
        this.thinkingMessage = this._thinkingMessages[idx];
      }, 3000);
    },

    _stopThinking() {
      if (this._thinkingTimer) {
        clearInterval(this._thinkingTimer);
        this._thinkingTimer = null;
      }
      this.thinkingMessage = '';
    },

    async sendQuestion() {
      const q = this.question.trim();
      if (!q || this.loading) return;

      this.loading = true;
      this.thinkingMessage = '';
      this.question = '';

      const ta = this.$refs.chatInput;
      if (ta) ta.style.height = 'auto';

      this.conversations.push({
        id: uuid(),
        question: q,
        response: null,
        error: null,
        timestamp: new Date().toISOString(),
      });

      const conv = this.conversations[this.conversations.length - 1];
      this.activeConversationId = conv.id;
      this.$nextTick(() => this.scrollChatToBottom());

      // Animate pipeline steps while request is in flight
      this._startThinking();

      try {
        const resp = await fetch('/api/v1/chat/ask', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            question: q,
            session_id: this.sessionId || '',
          }),
        });

        if (!resp.ok) {
          const err = await resp.json().catch(() => ({}));
          throw new Error(err.message || err.error || 'Query failed');
        }

        const result = await resp.json();

        if (result.meta?.session_id && !this.sessionId) {
          this.sessionId = result.meta.session_id;
          localStorage.setItem('ii-session-id', this.sessionId);
        }

        if (result.verification && !result.verification.matches_intent) {
          throw new Error(result.verification.reasoning || 'This question could not be answered.');
        }

        conv.response = {
          intent: {
            allowed: result.verification?.matches_intent ?? true,
            type: result.meta?.detected_intent || 'unknown',
          },
          explanation: result.verification?.reasoning || '',
          data: result.data || { columns: [], rows: [], count: 0 },
          chart: result.chart || null,
          showChart: false,
          _activeChartType: null,
          chartTypes: this.detectChartTypes(result.data),
          sql: result.sql,
          citations: result.citations || [],
          verification: result.verification,
          meta: {
            execution_time_ms: result.meta?.execution_time_ms,
            sql_execution_time_ms: result.meta?.sql_execution_time_ms,
            detected_intent: result.meta?.detected_intent,
            session_id: result.meta?.session_id,
          },
          debug: result.debug || {},
          _page: 1,
        };
      } catch (err) {
        conv.error = err.message || 'An unexpected error occurred.';
      } finally {
        this._stopThinking();
        this.thinkingMessage = '';
        this.loading = false;
        this.$nextTick(() => {
          setTimeout(() => this.scrollChatToBottom(), 80);
        });
      }
    },

    handleKeydown(event) {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        this.sendQuestion();
      }
    },

    scrollChatToBottom() {
      const container = this.$refs.chatContainer;
      if (container) {
        container.scrollTo({ top: container.scrollHeight, behavior: 'smooth' });
      }
    },

    // Detect what chart types are suitable for the data
    detectChartTypes(data) {
      if (!data?.rows?.length || !data?.columns?.length) return [];
      const cols = data.columns;
      const numericCols = cols.filter(c => {
        const lower = c.toLowerCase();
        return lower.includes('rate') || lower.includes('count') || lower.includes('total') ||
               lower.includes('avg') || lower.includes('sum') || lower.includes('percent') ||
               lower.includes('pct') || lower.includes('volume') || lower.includes('time') ||
               lower.includes('days') || lower.includes('suppressed') || lower.includes('tested') ||
               lower.includes('rejected') || lower.includes('pending');
      });
      if (numericCols.length === 0) return [];

      const rowCount = data.rows.length;
      const types = ['bar'];
      if (rowCount > 2) types.push('line');
      if (rowCount <= 10 && numericCols.length >= 1) types.push('pie');
      return types;
    },

    // Show chart for conversation (on-demand)
    showChartForConv(conv) {
      if (!conv.response) return;
      const types = conv.response.chartTypes || [];
      const recommended = conv.response.chart?.recommended;
      const chartType = (recommended && types.includes(recommended)) ? recommended : types[0];
      if (!chartType) return;
      this.showChartForFirst(conv, chartType);
    },

    // Switch chart type (synchronous — canvas already in DOM)
    switchChartType(conv, chartType) {
      if (!conv.response?.data) return;
      const canvasId = 'chart-' + conv.id;
      if (this._charts[canvasId]) {
        this._charts[canvasId].destroy();
        delete this._charts[canvasId];
      }
      conv.response.chart.recommended = chartType;
      conv.response._activeChartType = chartType;
      this.renderChart(conv);
    },

    // Show chart for first time (canvas not yet in DOM)
    showChartForFirst(conv, chartType) {
      if (!conv.response?.data) return;
      const cols = this.displayColumns(conv);
      const numericCols = cols.filter(c => this.isNumericColumn(c));
      const labelCol = cols.find(c => !this.isNumericColumn(c)) || cols[0];
      const valueCol = numericCols[0] || cols[1];
      if (!labelCol || !valueCol) return;

      conv.response.chart = {
        recommended: chartType,
        alternatives: [],
        config: { x_axis: labelCol, y_axis: valueCol },
      };
      conv.response.showChart = true;
      conv.response._activeChartType = chartType;

      this.$nextTick(() => {
        setTimeout(() => {
          this.renderChart(conv);
          this.scrollChatToBottom();
        }, 50);
      });
    },

    // Chart rendering
    chartCanvasId(conv) { return 'chart-' + conv.id; },

    renderChart(conv) {
      const resp = conv.response;
      if (!resp?.chart || !resp?.data?.rows?.length) return;

      const canvasId = this.chartCanvasId(conv);
      const canvas = document.getElementById(canvasId);
      if (!canvas) return;

      if (this._charts[canvasId]) {
        this._charts[canvasId].destroy();
        delete this._charts[canvasId];
      }

      const chartCfg = resp.chart;
      const result = resp.data;

      const xAxis = chartCfg.config?.x_axis || (result.columns && result.columns[0]);
      const yAxis = chartCfg.config?.y_axis || (result.columns && result.columns[1]);

      if (!xAxis || !yAxis) return;

      // Data rows may be objects (keyed) or arrays (indexed)
      const firstRow = result.rows[0];
      const isObjectRows = firstRow && !Array.isArray(firstRow) && typeof firstRow === 'object';

      let labels, values;
      if (isObjectRows) {
        labels = result.rows.map(r => r[xAxis]);
        values = result.rows.map(r => parseFloat(r[yAxis]) || 0);
      } else {
        const xIdx = result.columns.indexOf(xAxis);
        const yIdx = result.columns.indexOf(yAxis);
        if (xIdx === -1 || yIdx === -1) return;
        labels = result.rows.map(r => r[xIdx]);
        values = result.rows.map(r => parseFloat(r[yIdx]) || 0);
      }

      const isDark = this.darkMode;
      const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
      const tickColor = isDark ? '#8e8e93' : '#6e6e73';

      const chartType = this.mapChartType(chartCfg.recommended || 'bar');

      const datasets = [{
        label: Fmt.label(yAxis),
        data: values,
        backgroundColor: chartType === 'pie' || chartType === 'doughnut'
          ? values.map((_, i) => chartColor(i, 0.75))
          : chartColor(0, 0.2),
        borderColor: chartType === 'pie' || chartType === 'doughnut'
          ? values.map((_, i) => chartColor(i))
          : chartColor(0),
        borderWidth: chartType === 'pie' || chartType === 'doughnut' ? 1 : 2,
        borderRadius: chartType === 'bar' ? 6 : 0,
        tension: 0.3,
        fill: chartType === 'line',
        pointRadius: chartType === 'line' ? 3 : 0,
        pointHoverRadius: chartType === 'line' ? 5 : 0,
      }];

      const config = {
        type: chartType,
        data: { labels, datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { intersect: false, mode: 'index' },
          plugins: {
            legend: {
              display: chartType === 'pie' || chartType === 'doughnut',
              labels: { color: tickColor, font: { size: 11 } },
            },
            tooltip: {
              backgroundColor: isDark ? '#1a1a1e' : '#ffffff',
              titleColor: isDark ? '#f0f0f3' : '#1d1d1f',
              bodyColor: isDark ? '#f0f0f3' : '#1d1d1f',
              borderColor: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)',
              borderWidth: 1,
              cornerRadius: 8,
              padding: 10,
            },
          },
          scales: chartType === 'pie' || chartType === 'doughnut' ? {} : {
            x: {
              grid: { color: gridColor },
              ticks: { color: tickColor, font: { size: 11 }, maxRotation: 45 },
            },
            y: {
              grid: { color: gridColor },
              ticks: { color: tickColor, font: { size: 11 } },
              beginAtZero: true,
            },
          },
        },
      };

      this._charts[canvasId] = new Chart(canvas, config);
    },

    mapChartType(recommended) {
      const map = {
        bar: 'bar', horizontal_bar: 'bar', line: 'line', pie: 'pie',
        doughnut: 'doughnut', stacked_bar: 'bar', area: 'line', timeseries: 'line',
      };
      return map[recommended] || 'bar';
    },

    // Reports
    async fetchReports() {
      this.reportsLoading = true;
      try {
        const resp = await fetch('/api/v1/reports');
        if (resp.ok) {
          const data = await resp.json();
          this.reports = Array.isArray(data) ? data : (data.data || []);
        }
      } catch (err) {
        console.warn('Failed to load reports:', err);
      } finally {
        this.reportsLoading = false;
      }
    },

    async saveAsReport(conv) {
      if (!conv.response) return;
      const title = prompt('Report title:', conv.question);
      if (!title) return;
      try {
        const payload = {
          title,
          question: conv.question,
          plan: conv.response.plan || {},
          chart: conv.response.chart || {},
          result: conv.response.result || {},
          session_id: conv.response.session_id || this.sessionId,
        };
        const resp = await fetch('/api/v1/reports', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        if (!resp.ok) throw new Error('Failed to save report');
        this.fetchReports();
        this.showToast('Report saved successfully!');
      } catch (err) {
        this.showToast(err.message, 'error');
      }
    },

    async deleteReport(id) {
      if (!confirm('Delete this report?')) return;
      try {
        await fetch(`/api/v1/reports/${id}`, { method: 'DELETE' });
        this.reports = this.reports.filter(r => r.id !== id);
        this.showToast('Report deleted.');
      } catch (err) {
        this.showToast('Failed to delete report', 'error');
      }
    },

    loadReport(report) {
      this.currentView = 'chat';
      this.$nextTick(() => {
        this.question = report.question || report.title || '';
        if (this.question) {
          this.$nextTick(() => this.sendQuestion());
        }
      });
    },

    // Toast
    showToast(msg, type = 'success') {
      this._toast = { msg, type };
      clearTimeout(this._toastTimeout);
      this._toastTimeout = setTimeout(() => { this._toast = null; }, 3500);
    },

    // Result helpers
    hasExplanation(conv) { return !!(conv.response?.explanation); },
    hasTable(conv) { const r = conv.response?.data; return r && r.columns?.length && r.rows?.length; },
    hasChart(conv) { return !!(conv.response?.showChart && conv.response?.chart && conv.response?.data?.rows?.length); },
    hasChartTypes(conv) { return (conv.response?.chartTypes?.length > 0) && !conv.response?.showChart; },
    hasPlan(conv) {
      const resp = conv.response;
      return !!(resp?.meta?.detected_intent || resp?.debug?.tables_used?.length || resp?.sql);
    },
    displayColumns(conv) {
      const cols = conv.response?.data?.columns || [];
      return cols.filter(c => !c.toLowerCase().endsWith('_id'));
    },
    tableColumns(conv) { return this.displayColumns(conv); },
    tableRows(conv) { return conv.response?.data?.rows || []; },
    _pageSize: 50,
    tableRowsLimited(conv) {
      const rows = this.tableRows(conv);
      const page = conv.response?._page || 1;
      const start = (page - 1) * this._pageSize;
      return rows.slice(start, start + this._pageSize);
    },
    totalRows(conv) { return conv.response?.data?.count ?? this.tableRows(conv).length; },
    totalPages(conv) { return Math.max(1, Math.ceil(this.tableRows(conv).length / this._pageSize)); },
    tablePage(conv) { return conv.response?._page || 1; },
    prevPage(conv) { if (conv.response && conv.response._page > 1) conv.response._page--; },
    nextPage(conv) { if (conv.response && conv.response._page < this.totalPages(conv)) conv.response._page++; },
    executionTime(conv) { return conv.response?.meta?.execution_time_ms; },

    formatCellValue(val, col) {
      if (val == null) return '--';
      if (typeof val === 'number') {
        const lower = (col || '').toLowerCase();
        if (lower.includes('rate') || lower.includes('percent') || lower.includes('pct')) {
          return Fmt.percent(val);
        }
        return Fmt.number(val, val % 1 !== 0 ? 2 : 0);
      }
      return String(val);
    },

    isNumericColumn(col) {
      const lower = (col || '').toLowerCase();
      return (
        lower.includes('rate') || lower.includes('count') ||
        lower.includes('total') || lower.includes('avg') ||
        lower.includes('sum') || lower.includes('percent') ||
        lower.includes('pct') || lower.includes('volume') ||
        lower.includes('time') || lower.includes('days')
      );
    },

    planDetails(conv) {
      const resp = conv.response;
      if (!resp) return [];
      const items = [];
      if (resp.meta?.detected_intent) items.push({ label: 'Intent', value: Fmt.label(resp.meta.detected_intent) });
      if (resp.debug?.tables_used?.length) items.push({ label: 'Tables', value: resp.debug.tables_used.join(', ') });
      if (resp.verification?.conf != null) items.push({ label: 'Confidence', value: Math.round(resp.verification.conf * 100) + '%' });
      if (resp.sql) items.push({ label: 'SQL', value: Fmt.truncate(resp.sql, 80) });
      if (resp.citations?.length) items.push({ label: 'Citations', value: resp.citations.length + ' sources' });
      return items;
    },

    intentBadge(conv) {
      const resp = conv.response;
      if (!resp?.verification && !resp?.meta?.detected_intent) return null;
      return {
        allowed: resp.verification?.matches_intent ?? true,
        type: resp.meta?.detected_intent || 'unknown',
      };
    },

    conversationHistory() { return [...this.conversations].reverse(); },

    scrollToConversation(convId) {
      this.chatSidebarOpen = false;
      this.$nextTick(() => {
        const el = document.getElementById('conv-' + convId);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    },

    // Settings
    saveSettings() {
      localStorage.setItem('ii-settings', JSON.stringify(this.settings));
      this.showToast('Settings saved!');
    },
  }));

  // =========================================================================
  //  Original chat page component
  // =========================================================================
  Alpine.data('chatApp', () => ({
    // ---- State ----
    darkMode: false,
    sidebarOpen: false,
    reportsDrawerOpen: false,
    question: '',
    loading: false,
    sessionId: null,

    // Conversations (array of result groups)
    conversations: [],    // [{id, question, response, cards, timestamp}]
    activeConversationId: null,

    // Reports
    reports: [],
    reportsLoading: false,

    // Chart.js instance tracking (canvasId -> Chart)
    _charts: {},

    // Thinking message rotation
    thinkingMessage: '',
    _thinkingTimer: null,
    _thinkingMessages: [
      'Understanding your question...',
      'Analyzing the data...',
      'Looking through the records...',
      'Preparing your results...',
    ],

    // Scroll state
    showScrollBtn: false,

    // ---- Lifecycle ----
    init() {
      // Restore dark mode preference
      const stored = localStorage.getItem('ii-dark-mode');
      if (stored !== null) {
        this.darkMode = stored === 'true';
      } else {
        this.darkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
      }
      this.applyDarkMode();

      // Restore session
      this.sessionId = localStorage.getItem('ii-session-id') || null;

      // Load reports
      this.fetchReports();

      // Track scroll position for floating button
      this.$nextTick(() => {
        const container = this.$refs.bentoContainer;
        if (container) {
          container.addEventListener('scroll', () => {
            const { scrollTop, scrollHeight, clientHeight } = container;
            this.showScrollBtn = scrollHeight - scrollTop - clientHeight > 100;
          });
        }
      });
    },

    // ---- Dark mode ----
    toggleDarkMode() {
      this.darkMode = !this.darkMode;
      localStorage.setItem('ii-dark-mode', String(this.darkMode));
      this.applyDarkMode();
    },

    applyDarkMode() {
      document.documentElement.classList.toggle('dark', this.darkMode);
    },

    // ---- Sidebar / drawer helpers ----
    toggleSidebar() {
      this.sidebarOpen = !this.sidebarOpen;
      if (this.sidebarOpen) this.reportsDrawerOpen = false;
    },

    toggleReportsDrawer() {
      this.reportsDrawerOpen = !this.reportsDrawerOpen;
      if (this.reportsDrawerOpen) {
        this.sidebarOpen = false;
        this.fetchReports();
      }
    },

    closeDrawers() {
      this.sidebarOpen = false;
      this.reportsDrawerOpen = false;
    },

    anyDrawerOpen() {
      return this.sidebarOpen || this.reportsDrawerOpen;
    },

    // ---- Text-area auto-resize ----
    autoResize(event) {
      const el = event.target;
      el.style.height = 'auto';
      el.style.height = Math.min(el.scrollHeight, 160) + 'px';
    },

    // ---- Suggestion click ----
    useSuggestion(text) {
      this.question = text;
      this.$nextTick(() => {
        const ta = this.$refs.chatInput;
        if (ta) {
          ta.focus();
          ta.style.height = 'auto';
          ta.style.height = Math.min(ta.scrollHeight, 160) + 'px';
        }
      });
    },

    // Thinking message rotation
    _startThinking() {
      let idx = 0;
      this.thinkingMessage = this._thinkingMessages[0];
      this._thinkingTimer = setInterval(() => {
        idx = (idx + 1) % this._thinkingMessages.length;
        this.thinkingMessage = this._thinkingMessages[idx];
      }, 3000);
    },

    _stopThinking() {
      if (this._thinkingTimer) {
        clearInterval(this._thinkingTimer);
        this._thinkingTimer = null;
      }
      this.thinkingMessage = '';
    },

    // ---- Send question (single API call to /api/v1/chat/ask) ----
    async sendQuestion() {
      const q = this.question.trim();
      if (!q || this.loading) return;

      this.loading = true;
      this.thinkingMessage = '';
      this.question = '';

      const ta = this.$refs.chatInput;
      if (ta) ta.style.height = 'auto';

      this.conversations.push({
        id: uuid(),
        question: q,
        response: null,
        error: null,
        timestamp: new Date().toISOString(),
      });

      const conv = this.conversations[this.conversations.length - 1];
      this.activeConversationId = conv.id;
      this.$nextTick(() => this.scrollToBottom());

      // Animate pipeline steps while request is in flight
      this._startThinking();

      try {
        const resp = await fetch('/api/v1/chat/ask', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            question: q,
            session_id: this.sessionId || '',
          }),
        });

        if (!resp.ok) {
          const err = await resp.json().catch(() => ({}));
          throw new Error(err.message || err.error || 'Query failed');
        }

        const result = await resp.json();

        // Save session ID if returned
        if (result.meta?.session_id && !this.sessionId) {
          this.sessionId = result.meta.session_id;
          localStorage.setItem('ii-session-id', this.sessionId);
        }

        // Check if query was rejected
        if (result.verification && !result.verification.matches_intent) {
          throw new Error(result.verification.reasoning || 'This question could not be answered.');
        }

        conv.response = {
          intent: {
            allowed: result.verification?.matches_intent ?? true,
            type: result.meta?.detected_intent || 'unknown',
          },
          explanation: result.verification?.reasoning || '',
          data: result.data || { columns: [], rows: [], count: 0 },
          chart: result.chart || null,
          showChart: false,
          _activeChartType: null,
          chartTypes: this.detectChartTypes(result.data),
          sql: result.sql,
          citations: result.citations || [],
          verification: result.verification,
          meta: {
            execution_time_ms: result.meta?.execution_time_ms,
            sql_execution_time_ms: result.meta?.sql_execution_time_ms,
            detected_intent: result.meta?.detected_intent,
            session_id: result.meta?.session_id,
          },
          debug: result.debug || {},
          _page: 1,
        };
      } catch (err) {
        conv.error = err.message || 'An unexpected error occurred.';
      } finally {
        this._stopThinking();
        this.thinkingMessage = '';
        this.loading = false;
        this.$nextTick(() => {
          setTimeout(() => this.scrollToBottom(), 80);
        });
      }
    },

    // ---- Keyboard handler ----
    handleKeydown(event) {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        this.sendQuestion();
      }
    },

    // ---- Scroll helper ----
    scrollToBottom() {
      const container = this.$refs.bentoContainer;
      if (container) {
        container.scrollTo({ top: container.scrollHeight, behavior: 'smooth' });
      }
    },

    // Detect chart types suitable for the data
    detectChartTypes(data) {
      if (!data?.rows?.length || !data?.columns?.length) return [];
      const cols = data.columns;
      const numericCols = cols.filter(c => {
        const lower = c.toLowerCase();
        return lower.includes('rate') || lower.includes('count') || lower.includes('total') ||
               lower.includes('avg') || lower.includes('sum') || lower.includes('percent') ||
               lower.includes('pct') || lower.includes('volume') || lower.includes('time') ||
               lower.includes('days') || lower.includes('suppressed') || lower.includes('tested') ||
               lower.includes('rejected') || lower.includes('pending');
      });
      if (numericCols.length === 0) return [];

      const rowCount = data.rows.length;
      const types = ['bar'];
      if (rowCount > 2) types.push('line');
      if (rowCount <= 10 && numericCols.length >= 1) types.push('pie');
      return types;
    },

    // Show chart for conversation (on-demand)
    showChartForConv(conv) {
      if (!conv.response) return;
      const types = conv.response.chartTypes || [];
      const recommended = conv.response.chart?.recommended;
      const chartType = (recommended && types.includes(recommended)) ? recommended : types[0];
      if (!chartType) return;
      this.showChartForFirst(conv, chartType);
    },

    // Switch chart type (synchronous — canvas already in DOM)
    switchChartType(conv, chartType) {
      if (!conv.response?.data) return;
      const canvasId = 'chart-' + conv.id;

      // Destroy old chart
      if (this._charts[canvasId]) {
        this._charts[canvasId].destroy();
        delete this._charts[canvasId];
      }

      conv.response.chart.recommended = chartType;
      conv.response._activeChartType = chartType;

      // Re-render immediately — canvas is already visible
      this.renderChart(conv);
    },

    // Show chart for first time (canvas not yet in DOM)
    showChartForFirst(conv, chartType) {
      if (!conv.response?.data) return;
      const cols = this.displayColumns(conv);
      const numericCols = cols.filter(c => this.isNumericColumn(c));
      const labelCol = cols.find(c => !this.isNumericColumn(c)) || cols[0];
      const valueCol = numericCols[0] || cols[1];
      if (!labelCol || !valueCol) return;

      conv.response.chart = {
        recommended: chartType,
        alternatives: [],
        config: { x_axis: labelCol, y_axis: valueCol },
      };
      conv.response.showChart = true;
      conv.response._activeChartType = chartType;

      // Wait for Alpine to render the chart div + canvas
      this.$nextTick(() => {
        setTimeout(() => {
          this.renderChart(conv);
          this.scrollToBottom();
        }, 50);
      });
    },

    // ---- Chart rendering ----
    chartCanvasId(conv) {
      return 'chart-' + conv.id;
    },

    renderChart(conv) {
      const resp = conv.response;
      if (!resp?.chart || !resp?.data?.rows?.length) return;

      const canvasId = this.chartCanvasId(conv);
      const canvas = document.getElementById(canvasId);
      if (!canvas) return;

      // Destroy previous instance
      if (this._charts[canvasId]) {
        this._charts[canvasId].destroy();
        delete this._charts[canvasId];
      }

      const chartCfg = resp.chart;
      const result = resp.data;

      const xAxis = chartCfg.config?.x_axis || (result.columns && result.columns[0]);
      const yAxis = chartCfg.config?.y_axis || (result.columns && result.columns[1]);

      if (!xAxis || !yAxis) return;

      // Handle both object rows and array rows
      const firstRow = result.rows[0];
      const isObjectRows = firstRow && !Array.isArray(firstRow) && typeof firstRow === 'object';

      let labels, values;
      if (isObjectRows) {
        labels = result.rows.map(r => r[xAxis]);
        values = result.rows.map(r => parseFloat(r[yAxis]) || 0);
      } else {
        const xIdx = result.columns.indexOf(xAxis);
        const yIdx = result.columns.indexOf(yAxis);
        if (xIdx === -1 || yIdx === -1) return;
        labels = result.rows.map(r => r[xIdx]);
        values = result.rows.map(r => parseFloat(r[yIdx]) || 0);
      }

      const isDark = this.darkMode;
      const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
      const tickColor = isDark ? '#8e8e93' : '#6e6e73';

      const chartType = this.mapChartType(chartCfg.recommended || 'bar');

      const datasets = [{
        label: Fmt.label(yAxis),
        data: values,
        backgroundColor: chartType === 'pie' || chartType === 'doughnut'
          ? values.map((_, i) => chartColor(i, 0.75))
          : chartColor(0, 0.2),
        borderColor: chartType === 'pie' || chartType === 'doughnut'
          ? values.map((_, i) => chartColor(i))
          : chartColor(0),
        borderWidth: chartType === 'pie' || chartType === 'doughnut' ? 1 : 2,
        borderRadius: chartType === 'bar' ? 6 : 0,
        tension: 0.3,
        fill: chartType === 'line',
        pointRadius: chartType === 'line' ? 3 : 0,
        pointHoverRadius: chartType === 'line' ? 5 : 0,
      }];

      const config = {
        type: chartType,
        data: { labels, datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { intersect: false, mode: 'index' },
          plugins: {
            legend: {
              display: chartType === 'pie' || chartType === 'doughnut',
              labels: { color: tickColor, font: { size: 11 } },
            },
            tooltip: {
              backgroundColor: isDark ? '#1a1a1e' : '#ffffff',
              titleColor: isDark ? '#f0f0f3' : '#1d1d1f',
              bodyColor: isDark ? '#f0f0f3' : '#1d1d1f',
              borderColor: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)',
              borderWidth: 1,
              cornerRadius: 8,
              padding: 10,
            },
          },
          scales: chartType === 'pie' || chartType === 'doughnut' ? {} : {
            x: {
              grid: { color: gridColor },
              ticks: { color: tickColor, font: { size: 11 }, maxRotation: 45 },
            },
            y: {
              grid: { color: gridColor },
              ticks: { color: tickColor, font: { size: 11 } },
              beginAtZero: true,
            },
          },
        },
      };

      this._charts[canvasId] = new Chart(canvas, config);
    },

    mapChartType(recommended) {
      const map = {
        bar: 'bar',
        horizontal_bar: 'bar',
        line: 'line',
        pie: 'pie',
        doughnut: 'doughnut',
        stacked_bar: 'bar',
        area: 'line',
        timeseries: 'line',
      };
      return map[recommended] || 'bar';
    },

    // ---- Reports ----
    async fetchReports() {
      this.reportsLoading = true;
      try {
        const resp = await fetch('/api/v1/reports');
        if (resp.ok) {
          const data = await resp.json();
          this.reports = Array.isArray(data) ? data : (data.data || []);
        }
      } catch (err) {
        console.warn('Failed to load reports:', err);
      } finally {
        this.reportsLoading = false;
      }
    },

    async saveAsReport(conv) {
      if (!conv.response) return;

      const title = prompt('Report title:', conv.question);
      if (!title) return;

      try {
        const payload = {
          title,
          question: conv.question,
          plan: conv.response.plan || {},
          chart: conv.response.chart || {},
          result: conv.response.result || {},
          session_id: conv.response.session_id || this.sessionId,
        };

        const resp = await fetch('/api/v1/reports', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });

        if (!resp.ok) throw new Error('Failed to save report');

        const data = await resp.json();
        this.fetchReports();

        // Quick success toast (uses notification area if present)
        this.showToast('Report saved successfully!');
      } catch (err) {
        this.showToast(err.message, 'error');
      }
    },

    async deleteReport(id) {
      if (!confirm('Delete this report?')) return;
      try {
        await fetch(`/api/v1/reports/${id}`, { method: 'DELETE' });
        this.reports = this.reports.filter(r => r.id !== id);
      } catch (err) {
        this.showToast('Failed to delete report', 'error');
      }
    },

    async loadReport(report) {
      this.reportsDrawerOpen = false;
      this.question = report.question || report.title || '';
      if (this.question) {
        this.$nextTick(() => this.sendQuestion());
      }
    },

    // ---- Toast / notification ----
    _toast: null,
    _toastTimeout: null,

    showToast(msg, type = 'success') {
      this._toast = { msg, type };
      clearTimeout(this._toastTimeout);
      this._toastTimeout = setTimeout(() => { this._toast = null; }, 3500);
    },

    // ---- Result card helpers ----
    hasExplanation(conv) {
      return !!(conv.response?.explanation);
    },

    hasTable(conv) {
      const r = conv.response?.data;
      return r && r.columns?.length && r.rows?.length;
    },

    hasChart(conv) {
      return !!(conv.response?.showChart && conv.response?.chart && conv.response?.data?.rows?.length);
    },

    hasChartTypes(conv) {
      return (conv.response?.chartTypes?.length > 0) && !conv.response?.showChart;
    },

    hasPlan(conv) {
      // Show "Query Details" card when we have intent, tables, or SQL info
      const resp = conv.response;
      return !!(resp?.meta?.detected_intent || resp?.debug?.tables_used?.length || resp?.sql);
    },

    displayColumns(conv) {
      const cols = conv.response?.data?.columns || [];
      return cols.filter(c => !c.toLowerCase().endsWith('_id'));
    },

    tableColumns(conv) {
      return this.displayColumns(conv);
    },

    tableRows(conv) {
      return conv.response?.data?.rows || [];
    },

    _pageSize: 50,
    tableRowsLimited(conv) {
      const rows = this.tableRows(conv);
      const page = conv.response?._page || 1;
      const start = (page - 1) * this._pageSize;
      return rows.slice(start, start + this._pageSize);
    },

    totalRows(conv) {
      return conv.response?.data?.count ?? this.tableRows(conv).length;
    },

    totalPages(conv) {
      return Math.max(1, Math.ceil(this.tableRows(conv).length / this._pageSize));
    },

    tablePage(conv) { return conv.response?._page || 1; },
    prevPage(conv) { if (conv.response && conv.response._page > 1) conv.response._page--; },
    nextPage(conv) { if (conv.response && conv.response._page < this.totalPages(conv)) conv.response._page++; },

    executionTime(conv) {
      return conv.response?.meta?.execution_time_ms;
    },

    formatCellValue(val, col) {
      if (val == null) return '--';
      if (typeof val === 'number') {
        const lower = (col || '').toLowerCase();
        if (lower.includes('rate') || lower.includes('percent') || lower.includes('pct')) {
          return Fmt.percent(val);
        }
        return Fmt.number(val, val % 1 !== 0 ? 2 : 0);
      }
      return String(val);
    },

    isNumericColumn(col) {
      const lower = (col || '').toLowerCase();
      return (
        lower.includes('rate') || lower.includes('count') ||
        lower.includes('total') || lower.includes('avg') ||
        lower.includes('sum') || lower.includes('percent') ||
        lower.includes('pct') || lower.includes('volume') ||
        lower.includes('time') || lower.includes('days')
      );
    },

    planDetails(conv) {
      const resp = conv.response;
      if (!resp) return [];
      const items = [];

      // Intent
      if (resp.meta?.detected_intent) {
        items.push({ label: 'Intent', value: Fmt.label(resp.meta.detected_intent) });
      }

      // Tables used
      if (resp.debug?.tables_used?.length) {
        items.push({ label: 'Tables', value: resp.debug.tables_used.join(', ') });
      }

      // Confidence
      if (resp.verification?.conf != null) {
        items.push({ label: 'Confidence', value: Math.round(resp.verification.conf * 100) + '%' });
      }

      // SQL (truncated)
      if (resp.sql) {
        items.push({ label: 'SQL', value: Fmt.truncate(resp.sql, 80) });
      }

      // Citations count
      if (resp.citations?.length) {
        items.push({ label: 'Citations', value: resp.citations.length + ' sources' });
      }

      return items;
    },

    intentBadge(conv) {
      const resp = conv.response;
      if (!resp?.verification && !resp?.meta?.detected_intent) return null;
      return {
        allowed: resp.verification?.matches_intent ?? true,
        type: resp.meta?.detected_intent || 'unknown',
      };
    },

    // ---- History helpers ----
    conversationHistory() {
      return [...this.conversations].reverse();
    },

    scrollToConversation(convId) {
      this.sidebarOpen = false;
      this.$nextTick(() => {
        const el = document.getElementById('conv-' + convId);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    },
  }));

});
