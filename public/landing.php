<?php require_once __DIR__ . '/includes/helpers.php'; ?>
<!DOCTYPE html>
<html lang="en" class="">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Intelis Insights — Governed Analytics for Laboratory Data</title>

  <!-- Tailwind CSS (CDN / Play) -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            accent:  { DEFAULT: '#0d9488', light: '#14b8a6', dark: '#0f766e', soft: 'rgba(13,148,136,0.10)' },
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

  <!-- Alpine.js -->
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>

  <!-- App styles -->
  <link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">

  <!-- App JS (Alpine components) -->
  <script src="/assets/js/app.js"></script>
</head>

<body class="min-h-screen" x-data="landingApp" x-init="init()">

  <!-- ================================================================== -->
  <!--  NAVIGATION                                                        -->
  <!-- ================================================================== -->
  <nav class="landing-nav">
    <div class="max-w-[1200px] mx-auto flex items-center justify-between px-6 h-16">
      <!-- Logo -->
      <a href="/" class="flex items-center gap-2.5 select-none">
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-teal-600 to-sky-500 flex items-center justify-center shadow-sm">
          <svg class="w-4.5 h-4.5 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>
          </svg>
        </div>
        <span class="text-base font-semibold tracking-tight">
          Intelis <span class="text-accent">Insights</span>
        </span>
      </a>

      <!-- Right actions -->
      <div class="flex items-center gap-3">
        <!-- Dark mode toggle -->
        <button @click="toggleDarkMode()" class="p-2 rounded-xl hover:bg-[var(--color-surface-alt)] transition" title="Toggle dark mode">
          <svg x-show="darkMode" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/>
          </svg>
          <svg x-show="!darkMode" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z"/>
          </svg>
        </button>

        <a href="/login" class="btn-secondary text-sm">Sign In</a>
        <a href="/login" class="btn-primary text-sm !py-2.5 !px-5">Get Started</a>
      </div>
    </div>
  </nav>

  <!-- ================================================================== -->
  <!--  HERO SECTION                                                      -->
  <!-- ================================================================== -->
  <section class="landing-hero pt-16">
    <div class="landing-hero-content">
      <!-- Badge -->
      <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full border border-[var(--color-border)] bg-[var(--color-surface)] text-xs font-medium text-[var(--color-text-muted)] mb-8">
        <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
        Governed Analytics Platform
      </div>

      <h1>
        Ask questions.<br>
        Get <span class="text-gradient">governed insights</span>.
      </h1>

      <p class="hero-subtitle">
        Intelis Insights transforms natural language questions into secure, governed analytics
        over your laboratory data. No SQL required. Privacy by design.
      </p>

      <div class="flex flex-wrap items-center justify-center gap-4 mt-8">
        <a href="/login" class="btn-primary">
          Get Started
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
          </svg>
        </a>
        <a href="#features" class="btn-secondary">
          Learn More
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5L12 21m0 0l-7.5-7.5M12 21V3"/>
          </svg>
        </a>
      </div>

      <!-- Floating demo preview -->
      <div class="mt-16 relative">
        <div class="glass-card p-6 max-w-lg mx-auto text-left">
          <div class="flex items-center gap-2 mb-3">
            <div class="w-6 h-6 rounded-full bg-gradient-to-br from-teal-600 to-sky-500 flex items-center justify-center text-white text-[10px] font-bold">Q</div>
            <span class="text-sm font-medium">What is the VL suppression rate by district?</span>
          </div>
          <div class="pl-8 space-y-2">
            <div class="flex gap-2">
              <span class="badge badge-accent">Suppression Rate</span>
              <span class="badge badge-secondary">District</span>
            </div>
            <p class="text-xs text-[var(--color-text-muted)] leading-relaxed">
              Analyzing suppression rate metric grouped by district dimension across all available time periods...
            </p>
            <div class="flex items-center gap-4 text-xs text-[var(--color-text-muted)] pt-1">
              <span>48 rows</span>
              <span>142ms</span>
              <span class="text-green-500 font-medium">Allowed</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ================================================================== -->
  <!--  FEATURES                                                          -->
  <!-- ================================================================== -->
  <section id="features" class="features-section">
    <div class="text-center">
      <h2 class="text-2xl sm:text-3xl font-bold tracking-tight">
        Built for <span class="text-gradient">secure analytics</span>
      </h2>
      <p class="text-[var(--color-text-muted)] mt-3 max-w-lg mx-auto">
        Every query is validated, planned, and executed through a governed pipeline that ensures data privacy and accuracy.
      </p>
    </div>

    <div class="features-grid">
      <!-- Feature 1 -->
      <div class="feature-card">
        <div class="feature-icon bg-teal-50 dark:bg-teal-950/50">
          <svg class="text-teal-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
          </svg>
        </div>
        <h3 class="text-base font-semibold mb-1">Governed Analytics</h3>
        <p class="text-sm text-[var(--color-text-muted)] leading-relaxed">
          Every query passes through intent validation and metric governance. Only approved analytics patterns are allowed to execute.
        </p>
      </div>

      <!-- Feature 2 -->
      <div class="feature-card">
        <div class="feature-icon bg-sky-50 dark:bg-sky-950/50">
          <svg class="text-sky-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 011.037-.443 48.282 48.282 0 005.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/>
          </svg>
        </div>
        <h3 class="text-base font-semibold mb-1">Natural Language Queries</h3>
        <p class="text-sm text-[var(--color-text-muted)] leading-relaxed">
          Ask questions in plain English. Our AI understands your intent and translates it into precise, optimized analytics queries.
        </p>
      </div>

      <!-- Feature 3 -->
      <div class="feature-card">
        <div class="feature-icon bg-cyan-50 dark:bg-cyan-950/50">
          <svg class="text-cyan-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
          </svg>
        </div>
        <h3 class="text-base font-semibold mb-1">Privacy by Design</h3>
        <p class="text-sm text-[var(--color-text-muted)] leading-relaxed">
          The AI assistant generates analytics but never accesses the database directly. Privacy safeguards are enforced automatically.
        </p>
      </div>

      <!-- Feature 4 -->
      <div class="feature-card">
        <div class="feature-icon bg-green-50 dark:bg-green-950/50">
          <svg class="text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
          </svg>
        </div>
        <h3 class="text-base font-semibold mb-1">Real-time Dashboards</h3>
        <p class="text-sm text-[var(--color-text-muted)] leading-relaxed">
          Results are rendered as interactive bento-grid dashboards with smart chart recommendations and exportable data tables.
        </p>
      </div>
    </div>
  </section>

  <!-- ================================================================== -->
  <!--  HOW IT WORKS                                                      -->
  <!-- ================================================================== -->
  <section class="how-section">
    <div class="text-center">
      <h2 class="text-2xl sm:text-3xl font-bold tracking-tight">
        How it works
      </h2>
      <p class="text-[var(--color-text-muted)] mt-3 max-w-lg mx-auto">
        Three simple steps from question to insight. No SQL, no dashboards to build, no data engineering required.
      </p>
    </div>

    <div class="how-steps">
      <!-- Step 1 -->
      <div class="how-step">
        <div class="how-step-number">1</div>
        <h3 class="text-lg font-semibold mb-2">Ask</h3>
        <p class="text-sm text-[var(--color-text-muted)] leading-relaxed">
          Type your question in plain language. Ask about metrics, trends, comparisons, or drill-downs.
        </p>
      </div>

      <!-- Step 2 -->
      <div class="how-step">
        <div class="how-step-number">2</div>
        <h3 class="text-lg font-semibold mb-2">Plan</h3>
        <p class="text-sm text-[var(--color-text-muted)] leading-relaxed">
          AI validates your intent, selects the right metric, dimensions, and filters, then compiles a governed query.
        </p>
      </div>

      <!-- Step 3 -->
      <div class="how-step">
        <div class="how-step-number">3</div>
        <h3 class="text-lg font-semibold mb-2">Results</h3>
        <p class="text-sm text-[var(--color-text-muted)] leading-relaxed">
          Get an interactive bento dashboard with data tables, charts, explanations, and query details.
        </p>
      </div>
    </div>

    <!-- Bottom CTA -->
    <div class="text-center mt-12">
      <a href="/login" class="btn-primary">
        Start Exploring
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
        </svg>
      </a>
    </div>
  </section>

  <!-- ================================================================== -->
  <!--  FOOTER                                                            -->
  <!-- ================================================================== -->
  <footer class="landing-footer">
    <div class="max-w-[1200px] mx-auto">
      <div class="flex items-center justify-center gap-2 mb-2">
        <div class="w-5 h-5 rounded-md bg-gradient-to-br from-teal-600 to-sky-500 flex items-center justify-center">
          <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>
          </svg>
        </div>
        <span class="text-sm font-semibold">Intelis <span class="text-accent">Insights</span></span>
      </div>
      <p>&copy; <?php echo date('Y'); ?> Intelis Insights. Governed analytics for laboratory data.</p>
    </div>
  </footer>

</body>
</html>
