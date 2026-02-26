<?php require_once __DIR__ . '/includes/helpers.php'; ?>
<!DOCTYPE html>
<html lang="en" class="">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In — Intelis Insights</title>

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
  <script src="<?= asset('/assets/js/app.js') ?>"></script>
</head>

<body class="min-h-screen" x-data="loginApp" x-init="init()">

  <!-- Dark mode toggle (floating) -->
  <div class="fixed top-4 right-4 z-50">
    <button @click="toggleDarkMode()" class="p-2.5 rounded-xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-sm hover:bg-[var(--color-surface-alt)] transition" title="Toggle dark mode">
      <svg x-show="darkMode" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/>
      </svg>
      <svg x-show="!darkMode" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z"/>
      </svg>
    </button>
  </div>

  <div class="login-page">
    <div class="login-card">
      <!-- Logo -->
      <div class="text-center mb-8">
        <a href="/" class="inline-flex items-center gap-2.5 select-none">
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-teal-600 to-sky-500 flex items-center justify-center shadow-sm">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>
            </svg>
          </div>
          <span class="text-lg font-semibold tracking-tight">
            Intelis <span class="text-accent">Insights</span>
          </span>
        </a>
      </div>

      <h2 class="text-xl font-semibold text-center mb-1">Welcome back</h2>
      <p class="text-sm text-[var(--color-text-muted)] text-center mb-6">Sign in to access your analytics dashboard</p>

      <!-- Error message -->
      <div x-show="error" x-transition class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800">
        <p class="text-sm text-red-600 dark:text-red-400" x-text="error"></p>
      </div>

      <!-- Login form -->
      <form @submit.prevent="signIn()" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-1.5" for="email">Email</label>
          <input
            id="email"
            type="email"
            x-model="email"
            class="login-input"
            placeholder="you@example.com"
            autocomplete="email"
            required
          >
        </div>

        <div>
          <label class="block text-sm font-medium mb-1.5" for="password">Password</label>
          <input
            id="password"
            type="password"
            x-model="password"
            class="login-input"
            placeholder="Enter your password"
            autocomplete="current-password"
            required
          >
        </div>

        <div class="flex items-center justify-between text-sm">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" class="rounded border-[var(--color-border)] text-accent focus:ring-accent">
            <span class="text-[var(--color-text-muted)]">Remember me</span>
          </label>
          <a href="#" class="text-accent hover:text-accent-dark transition text-sm font-medium">Forgot password?</a>
        </div>

        <button
          type="submit"
          :disabled="loading"
          class="btn-primary w-full justify-center !text-base"
          :class="{ 'opacity-60 cursor-not-allowed': loading }"
        >
          <svg x-show="loading" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
          </svg>
          <span x-show="!loading">Sign In</span>
          <span x-show="loading">Signing in...</span>
        </button>
      </form>

      <!-- Divider -->
      <div class="flex items-center gap-3 my-6">
        <div class="flex-1 h-px bg-[var(--color-border)]"></div>
        <span class="text-xs text-[var(--color-text-muted)]">or</span>
        <div class="flex-1 h-px bg-[var(--color-border)]"></div>
      </div>

      <!-- Demo mode -->
      <button
        @click="email = 'demo@intelis.io'; password = 'demo'; signIn()"
        class="btn-secondary w-full justify-center text-sm"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z"/>
        </svg>
        Try Demo Mode
      </button>

      <p class="text-xs text-[var(--color-text-muted)] text-center mt-6">
        By signing in, you agree to the <a href="#" class="text-accent hover:underline">Terms of Service</a>
        and <a href="#" class="text-accent hover:underline">Privacy Policy</a>.
      </p>
    </div>
  </div>

</body>
</html>
