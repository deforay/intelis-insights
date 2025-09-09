<?php
// public/chat.php  (or src/Views/chat.php)
// Renders a chat UI and injects LLM config from config/app.php

declare(strict_types=1);

// Resolve project root regardless of this file's location
$root = dirname(__DIR__);
if (!is_file("$root/config/app.php")) {
    // if running from src/Views/chat.php, adjust one more level up
    $maybeRoot = dirname($root);
    if (is_file("$maybeRoot/config/app.php")) {
        $root = $maybeRoot;
    }
}

$appCfg = require $root . '/config/app.php';

$llmCfg          = $appCfg['llm'] ?? [];
$defaultProvider = $llmCfg['provider'] ?? null;
$providers       = $llmCfg['providers'] ?? [];

// Build a minimal client-facing payload
$clientCfg = [
    'defaultProvider' => $defaultProvider,
    'providers' => array_map(function ($p) {
        return [
            'model'   => $p['model']   ?? '',
            // (Keys and base URLs remain server-side; we don’t expose API keys)
            'timeout' => $p['timeout'] ?? 30,
        ];
    }, $providers),
];

// Precompute a safe list of provider names
$providerNames = array_keys($providers);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InteLIS Insights</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --primary-blue: #0d6efd;
            --light-blue: #e7f1ff;
            --dark-gray: #2c3e50;
            --medium-gray: #6c757d;
            --light-gray: #f8f9fa;
            --border-color: #dee2e6;
            --success-green: #198754;
            --warning-orange: #fd7e14;
        }

        body {
            background: var(--light-gray);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .chat-container {
            max-width: 100%;
            margin: 1rem auto;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            background: white;
            display: flex;
            flex-direction: column;
            height: 95vh;
            overflow: hidden;
        }

        .chat-header {
            background: white;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            border-top-left-radius: 16px;
            border-top-right-radius: 16px;
        }

        .chat-header h5 {
            font-weight: 600;
            color: var(--dark-gray);
            margin-bottom: 0;
        }

        .chat-header small {
            color: var(--medium-gray);
            font-size: 0.75rem;
            font-weight: 400;
        }

        .status-badges {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .status-badge {
            background: var(--light-blue);
            color: var(--primary-blue);
            border: 1px solid rgba(13, 110, 253, 0.2);
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', monospace;
            font-size: 0.75rem;
            font-weight: 500;
            padding: 0.25rem 0.75rem;
            border-radius: 8px;
        }

        .btn-modern {
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.2s ease;
            border: 1px solid var(--border-color);
            background: white;
            color: var(--medium-gray);
        }

        .btn-modern:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            background: var(--light-gray);
        }

        .btn-primary.btn-modern {
            background: var(--primary-blue);
            border-color: var(--primary-blue);
            color: white;
        }

        .btn-primary.btn-modern:hover {
            background: #0b5ed7;
            border-color: #0b5ed7;
        }

        /* Settings panel */
        .settings-panel {
            background: #fafbfc;
            border-bottom: 1px solid var(--border-color);
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .settings-content {
            padding: 1.5rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.875rem;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            border: 1px solid var(--border-color);
            padding: 0.75rem 1rem;
            transition: all 0.2s ease;
            background: white;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
        }

        .form-text {
            color: var(--medium-gray);
            font-size: 0.75rem;
        }

        /* Chat messages */
        .chat-body {
            flex-grow: 1;
            padding: 1.5rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            background: #fafbfc;
            scroll-behavior: smooth;
        }

        .chat-body::-webkit-scrollbar {
            width: 6px;
        }

        .chat-body::-webkit-scrollbar-track {
            background: transparent;
        }

        .chat-body::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 3px;
        }

        .user-message,
        .ai-message {
            max-width: 95%;
            padding: 1rem 1.25rem;
            border-radius: 16px;
            line-height: 1.5;
            animation: messageSlide 0.3s ease-out;
        }

        @keyframes messageSlide {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .user-message {
            background: var(--primary-blue);
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
            margin-left: auto;
        }

        .ai-message {
            background: white;
            color: var(--dark-gray);
            align-self: flex-start;
            border-bottom-left-radius: 4px;
            border: 1px solid var(--border-color);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .message-content {
            white-space: pre-wrap;
        }

        /* Engine info styling */
        .engine-info {
            font-size: 0.875rem;
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--medium-gray);
        }

        .engine-info strong {
            color: var(--dark-gray);
        }

        .engine-badge {
            background: var(--light-blue);
            color: var(--primary-blue);
            border: 1px solid rgba(13, 110, 253, 0.2);
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', monospace;
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            margin-left: 0.5rem;
        }

        /* SQL block styling */
        .sql-query-block {
            background: #f8f9fa;
            border: 1px solid var(--border-color);
            padding: 1rem;
            margin-top: 1rem;
            border-radius: 12px;
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', monospace;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 0.8125rem;
            line-height: 1.4;
        }

        .sql-query-block strong {
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
            display: block;
        }

        /* Table styling */
        .sql-result-table-container {
            margin-top: 1rem;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .table {
            margin-bottom: 0;
            font-size: 0.875rem;
        }

        .table thead th {
            background: var(--light-gray);
            border-bottom: 2px solid var(--border-color);
            font-weight: 600;
            color: var(--dark-gray);
            padding: 0.75rem;
        }

        .table tbody td {
            padding: 0.75rem;
            border-bottom: 1px solid #f0f0f0;
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .table tbody tr:hover {
            background-color: #fafbfc;
        }

        /* Timing info */
        .timing-info {
            margin-top: 1rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border-color);
            font-size: 0.75rem;
            color: var(--medium-gray);
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .timing-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .context-debug-info {
            margin-top: 0.5rem;
            padding: 0.5rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 0.75rem;
            color: #6c757d;
        }

        /* Chat input */
        .chat-input {
            padding: 1.5rem;
            border-top: 1px solid var(--border-color);
            background: white;
            border-bottom-left-radius: 16px;
            border-bottom-right-radius: 16px;
        }

        .input-group {
            position: relative;
        }

        .chat-input-field {
            border-radius: 12px;
            border: 2px solid var(--border-color);
            padding: 1rem 1.25rem;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
            background: white;
            padding-right: 120px;
            position: relative;
            z-index: 1;
        }

        .chat-input-field:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
            outline: none;
        }

        .input-buttons {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            z-index: 10;
            gap: 0.5rem;
        }

        .btn-icon {
            border: 1px solid var(--border-color);
            background: #fff;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            transition: all 0.2s ease;
        }

        .btn-send {
            background: var(--primary-blue);
            color: white;
        }

        .btn-send:hover:not(:disabled) {
            background: #0b5ed7;
            transform: scale(1.05);
        }

        .btn-send:disabled {
            background: var(--border-color);
            color: var(--medium-gray);
            cursor: not-allowed;
            border: 1px solid #ced4da;
            opacity: 1;
        }

        .btn-cancel {
            background: #dc3545;
            color: white;
        }

        .btn-cancel:hover {
            background: #c82333;
            transform: scale(1.05);
        }

        /* Loading animation */
        .loading-message {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            border-bottom-left-radius: 4px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .loading-dots {
            display: flex;
            gap: 0.25rem;
        }

        .loading-dot {
            width: 8px;
            height: 8px;
            background: var(--primary-blue);
            border-radius: 50%;
            animation: loadingPulse 1.4s infinite ease-in-out;
        }

        .loading-dot:nth-child(2) {
            animation-delay: 0.2s;
        }

        .loading-dot:nth-child(3) {
            animation-delay: 0.4s;
        }

        @keyframes loadingPulse {

            0%,
            80%,
            100% {
                transform: scale(0.8);
                opacity: 0.5;
            }

            40% {
                transform: scale(1);
                opacity: 1;
            }
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .chat-container {
                margin: 0.5rem;
                height: calc(100vh - 1rem);
                border-radius: 12px;
            }

            .chat-header {
                padding: 1rem;
                border-radius: 12px 12px 0 0;
            }

            .settings-content {
                padding: 1rem;
            }

            .settings-row {
                flex-direction: column;
                gap: 1rem;
            }

            .user-message,
            .ai-message {
                max-width: 95%;
            }

            .status-badges {
                flex-wrap: wrap;
            }

            .timing-info {
                flex-direction: column;
                gap: 0.5rem;
            }
        }

        /* Dark mode support for better contrast */
        @media (prefers-color-scheme: dark) {
            /* Keep light theme for consistency with existing design */
        }


        /* Chart button styling */
        .chart-button {
            margin-top: 1rem;
            padding: 0.5rem 1rem;
            background: var(--primary-blue);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .chart-button:hover {
            background: #0b5ed7;
            transform: translateY(-1px);
        }

        .chart-options {
            background: #f8f9fa;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .chart-option-button {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 0.75rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-block;
        }

        .chart-option-button:hover {
            background: var(--light-blue);
            border-color: var(--primary-blue);
        }

        .medical-chart {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            min-height: 600px !important;
            max-width: 100%;
            overflow: hidden;
        }

        .chart-options {
            background: #f8f9fa;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            max-width: 100%;
        }

        .chart-option-button {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 0.75rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-block;
            max-width: 100%;
            word-wrap: break-word;
        }

        .chart-option-button:hover {
            background: var(--light-blue);
            border-color: var(--primary-blue);
        }

        /* Responsive chart containers */
        @media (max-width: 768px) {
            .medical-chart {
                height: 400px !important;
                min-height: 450px;
                padding: 0.5rem;
            }
        }

        .chart-panel {
            background: #f8fafc;
            /* subtle */
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 14px 16px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
            margin-top: 10px;
        }

        .chart-panel__header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .chart-panel__title {
            font-size: 15px;
            font-weight: 600;
            color: #111827;
            margin: 0;
        }

        .chart-panel__close {
            margin-left: auto;
            border: 0;
            background: transparent;
            padding: 6px 8px;
            line-height: 1;
            border-radius: 8px;
            cursor: pointer;
            color: #6b7280;
        }

        .chart-panel__close:hover,
        .chart-panel__close:focus {
            background: #eef2ff;
            color: #374151;
            outline: none;
        }

        .chart-options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 10px;
        }

        .chart-card {
            display: flex;
            flex-direction: column;
            text-align: left;
            gap: 4px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
            padding: 12px 14px;
            cursor: pointer;
            transition: box-shadow .15s ease, transform .04s ease;
        }

        .chart-card:hover {
            box-shadow: 0 2px 10px rgba(0, 0, 0, .06);
            transform: translateY(-1px);
        }

        .chart-card:focus {
            outline: 2px solid #6366f1;
            outline-offset: 2px;
        }

        .chart-card__title {
            font-weight: 700;
            color: #111827;
            font-size: 14px;
            margin: 0;
        }

        .chart-card__desc {
            color: #6b7280;
            font-size: 12px;
            margin: 0;
        }

        .chart-card[aria-pressed="true"] {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, .18);
        }

        /* small icon label style for the Generate charts */
        .link-icon {
            text-decoration: none;
        }

        .link-icon i {
            font-style: normal;
            margin-right: 4px;
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
</head>

<body class="p-2">
    <div class="chat-container">
        <div class="chat-header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-3">
                <div>
                    <h5 class="mb-0">InteLIS Insights</h5>

                </div>
                <div class="status-badges">
                    <span id="active-provider" class="status-badge">—</span>
                    <span id="active-model" class="status-badge">—</span>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">

                <button class="btn btn-modern" id="toggle-settings">
                    <i class="bi bi-gear"></i> Settings
                </button>
                <button id="reset-context-btn" class="btn btn-modern" title="Reset conversation context">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset Context
                </button>
                <button id="clear-btn" class="btn btn-modern">
                    <i class="bi bi-arrow-clockwise"></i> Clear
                </button>
            </div>
        </div>

        <!-- Settings panel -->
        <div id="settings-panel" class="settings-panel" style="display:none;">
            <div class="settings-content">
                <div class="row settings-row g-3">
                    <div class="col-12 col-md-4">
                        <label for="provider-select" class="form-label">Provider</label>
                        <select id="provider-select" class="form-select form-select-sm">
                            <?php foreach ($providerNames as $p): ?>
                                <option value="<?= htmlspecialchars($p, ENT_QUOTES) ?>"
                                    <?= $p === $defaultProvider ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p, ENT_QUOTES) ?><?= $p === $defaultProvider ? ' (default)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="model-input" class="form-label">Model</label>
                        <input id="model-input" type="text" class="form-control" placeholder="gpt-4o-mini">
                        <div class="form-text">
                            Uses server defaults from <code>config/app.php</code>. Override here per request.
                        </div>
                    </div>
                    <div class="col-12 col-md-2 d-flex align-items-end">
                        <button id="save-settings" class="btn btn-primary btn-modern w-100">Save</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="chat-body" id="chat-body">
            <div class="ai-message">
                <div class="message-content">Hello! Ask me a question about InteLIS.</div>
            </div>
        </div>

        <div class="chat-input">
            <div class="input-group">
                <input
                    type="text"
                    id="user-input"
                    class="form-control chat-input-field"
                    placeholder="Ask a question..."
                    autocomplete="off">
                <div class="input-buttons">
                    <button
                        id="cancel-btn"
                        class="btn btn-icon btn-cancel d-none"
                        type="button"
                        title="Cancel request"
                        aria-label="Cancel">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                        <span class="visually-hidden">Cancel</span>
                    </button>

                    <button
                        id="send-btn"
                        class="btn btn-icon btn-send"
                        type="button"
                        disabled
                        title="Send message"
                        aria-label="Send">
                        <i class="bi bi-send-fill" aria-hidden="true"></i>
                        <span class="visually-hidden">Send</span>
                    </button>
                </div>
            </div>
            <small style="color:#888;font-size:0.7em;float:right;">Note: The LLM does not access your database directly. Personally Identifiable Information (PII) is strictly prohibited.</small>
        </div>
    </div>

    <script src="/js/charts.js?v=<?= filemtime(__DIR__ . '/../../public/js/charts.js'); ?>"></script>
    <script id="llm-config" type="application/json">
        <?= json_encode($clientCfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
    </script>

    <script>
        const chatBody = document.getElementById('chat-body');
        const userInput = document.getElementById('user-input');
        const sendBtn = document.getElementById('send-btn');
        const cancelBtn = document.getElementById('cancel-btn');
        const clearBtn = document.getElementById('clear-btn');
        const resetContextBtn = document.getElementById('reset-context-btn');
        const toggleSettingsBtn = document.getElementById('toggle-settings');
        const settingsPanel = document.getElementById('settings-panel');
        const providerSelect = document.getElementById('provider-select');
        const modelInput = document.getElementById('model-input');
        const saveSettingsBtn = document.getElementById('save-settings');
        const activeProviderBadge = document.getElementById('active-provider');
        const activeModelBadge = document.getElementById('active-model');
        const API_URL = '/ask';
        let abortController = null;

        // Read server-provided config
        const llmCfg = JSON.parse(document.getElementById('llm-config').textContent || '{}');
        const defaultProvider = llmCfg.defaultProvider || '';
        const providerDefaults = llmCfg.providers || {};

        function updateBadges(provider, model) {
            activeProviderBadge.textContent = provider || '—';
            activeModelBadge.textContent = model || '—';
        }

        function addMessage(sender, content, saveToHistory = true) {
            const messageDiv = document.createElement('div');
            messageDiv.className = sender === 'user' ? 'user-message' : 'ai-message';
            messageDiv.innerHTML = `<div class="message-content">${content}</div>`;
            chatBody.appendChild(messageDiv);

            // Smooth scroll to bottom
            scrollToBottom();

            if (saveToHistory) localStorage.setItem('chatHistory', chatBody.innerHTML);
            return messageDiv;
        }

        function addLoadingMessage() {
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'loading-message';
            loadingDiv.innerHTML = `
                <div class="loading-dots">
                    <div class="loading-dot"></div>
                    <div class="loading-dot"></div>
                    <div class="loading-dot"></div>
                </div>
                <span>Processing your query...</span>
            `;
            chatBody.appendChild(loadingDiv);
            chatBody.scrollTop = chatBody.scrollHeight;
            return loadingDiv;
        }

        function loadChatHistory() {
            const history = localStorage.getItem('chatHistory');
            if (history) {
                chatBody.innerHTML = history;
                // Use setTimeout to ensure DOM is updated before scrolling
                setTimeout(() => scrollToBottom(), 100);
            }
        }

        function scrollToBottom(smooth = false) {
            if (smooth) {
                chatBody.scrollTo({
                    top: chatBody.scrollHeight,
                    behavior: 'smooth'
                });
            } else {
                chatBody.scrollTop = chatBody.scrollHeight;
            }
        }

        function loadSettings() {
            let provider = providerSelect.value || defaultProvider || (Object.keys(providerDefaults)[0] || '');

            const savedProvider = localStorage.getItem('llmProvider');
            if (savedProvider && providerDefaults[savedProvider]) {
                provider = savedProvider;
                providerSelect.value = savedProvider;
            }

            const providerDefaultModel = providerDefaults[provider]?.model || '';
            const savedModel = localStorage.getItem('llmModel') || '';
            const model = savedModel || providerDefaultModel;

            modelInput.value = model;
            modelInput.placeholder = providerDefaultModel;

            updateBadges(provider, model);
        }

        function saveSettings() {
            const provider = providerSelect.value;
            const model = (modelInput.value || providerDefaults[provider]?.model || '').trim();

            localStorage.setItem('llmProvider', provider);
            localStorage.setItem('llmModel', model);

            updateBadges(provider, model);
        }

        providerSelect.addEventListener('change', () => {
            const provider = providerSelect.value;
            const defaultModel = providerDefaults[provider]?.model || '';
            modelInput.value = defaultModel;
            modelInput.placeholder = defaultModel;
            updateBadges(provider, defaultModel);
        });

        toggleSettingsBtn.addEventListener('click', () => {
            settingsPanel.style.display = settingsPanel.style.display === 'none' ? 'block' : 'none';
        });

        saveSettingsBtn.addEventListener('click', () => {
            saveSettings();
            settingsPanel.style.display = 'none';
        });

        let inFlight = false;
        async function sendMessage() {
            if (inFlight) return;
            inFlight = true;
            const query = userInput.value.trim();
            if (query === '') return;

            const provider = (localStorage.getItem('llmProvider') || providerSelect.value || '').trim();
            const model = (localStorage.getItem('llmModel') || modelInput.value || '').trim();

            addMessage('user', query);
            userInput.value = '';
            sendBtn.disabled = true;
            cancelBtn.classList.remove('d-none');

            abortController = new AbortController();
            const signal = abortController.signal;

            const loadingMessage = addLoadingMessage();

            try {
                const payload = {
                    q: query
                };
                if (provider) payload.provider = provider;
                if (model) payload.model = model;

                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload),
                    signal
                });

                if (!res.ok) {
                    let errorText = `HTTP ${res.status} ${res.statusText}`;
                    try {
                        const err = await res.json();
                        errorText = err.detail || err.error || errorText;
                        if (err.raw_sql) {
                            errorText += `<br><div class="sql-query-block"><strong>Generated SQL:</strong><br>${err.raw_sql}</div>`;
                        }
                    } catch {}
                    if (chatBody.contains(loadingMessage)) chatBody.removeChild(loadingMessage);
                    addMessage('ai', `<strong>Error:</strong> ${errorText}`);
                    return;
                }

                const data = await res.json();
                if (chatBody.contains(loadingMessage)) chatBody.removeChild(loadingMessage);

                // Reflect the engine actually used by the server
                const usedProvider = data.timing?.provider || provider || defaultProvider || '—';
                const usedModel = data.timing?.model_used || model || (providerDefaults[usedProvider]?.model || '—');
                updateBadges(usedProvider, usedModel);

                // Build response HTML
                let responseHtml = `<div class="engine-info"><strong>Engine:</strong> ${usedProvider} <span class="engine-badge">${usedModel}</span></div>`;
                responseHtml += `<div class="mb-2"><strong>Result:</strong></div>`;

                if (Array.isArray(data.rows) && data.rows.length > 0) {
                    responseHtml += `<div class="sql-result-table-container"><table class="table table-sm">`;
                    responseHtml += `<thead><tr>`;
                    Object.keys(data.rows[0]).forEach(key => {
                        responseHtml += `<th>${key}</th>`;
                    });
                    responseHtml += `</tr></thead><tbody>`;
                    data.rows.forEach(row => {
                        responseHtml += `<tr>`;
                        Object.values(row).forEach(value => {
                            responseHtml += `<td>${value}</td>`;
                        });
                        responseHtml += `</tr>`;
                    });
                    responseHtml += `</tbody></table></div>`;
                } else {
                    responseHtml += `<div class="text-muted">No data found.</div>`;
                }

                responseHtml += `<div class="sql-query-block"><strong>Generated SQL:</strong><br>${data.sql || ''}</div>`;

                if (data.timing) {
                    responseHtml += `<div class="timing-info">
                <div class="timing-item"><i class="bi bi-clock text-primary"></i><span>Total: ${data.timing.total_ms ?? '—'} ms</span></div>
                <div class="timing-item"><i class="bi bi-cpu text-success"></i><span>Query: ${data.timing.query_processing_ms ?? '—'} ms</span></div>
                <div class="timing-item"><i class="bi bi-database text-info"></i><span>DB: ${data.timing.db_execution_ms ?? '—'} ms</span></div>
            </div>`;
                }

                //             if (data.debug && data.debug.conversation_context) {
                //                 const contextInfo = data.debug.conversation_context;
                //                 responseHtml += `<div class="context-debug-info">
                //     <strong>Context Debug:</strong><br>
                //     <small>
                //         History Count: ${data.debug.conversation_history_count || 0}<br>
                //         Has Context: ${contextInfo.has_context ? 'Yes' : 'No'}<br>
                //         ${contextInfo.has_context ? `Suggested Filters: ${(contextInfo.suggested_filters || []).length}` : ''}
                //     </small>
                // </div>`;
                //             }

                const messageDiv = addMessage('ai', responseHtml);

                // Persist chart meta + render the button now
                if (data.chart_suggestions?.suitable_for_charts) {
                    const meta = document.createElement('script');
                    meta.type = 'application/json';
                    meta.className = 'chart-meta';
                    meta.textContent = JSON.stringify({
                        rows: data.rows || [],
                        chart_suggestions: data.chart_suggestions
                    });
                    messageDiv.appendChild(meta);

                    if (typeof chartGenerator !== 'undefined') {
                        chartGenerator.addChartButton({
                                rows: data.rows || [],
                                chart_suggestions: data.chart_suggestions
                            },
                            messageDiv
                        );
                    }

                    // IMPORTANT: history must be saved again after DOM was mutated
                    localStorage.setItem('chatHistory', chatBody.innerHTML);
                }



            } catch (error) {
                if (chatBody.contains(loadingMessage)) chatBody.removeChild(loadingMessage);
                addMessage('ai', error.name === 'AbortError' ?
                    `<strong>Request Cancelled:</strong> The query was interrupted.` :
                    `<strong>Network Error:</strong> Failed to connect to the API.`);
            } finally {
                inFlight = false;
                sendBtn.disabled = false;
                cancelBtn.classList.add('d-none');
                abortController = null;
                userInput.focus();
            }
        }

        function rehydrateChartButtons() {
            const metas = chatBody.querySelectorAll('.ai-message .chart-meta');
            metas.forEach(meta => {
                try {
                    const payload = JSON.parse(meta.textContent || '{}');
                    const container = meta.closest('.ai-message');
                    if (!payload?.chart_suggestions?.suitable_for_charts || !container || typeof chartGenerator === 'undefined') return;

                    // ❗ skip if already present from saved HTML
                    if (container.querySelector('.generate-chart-btn')) return;

                    chartGenerator.addChartButton(payload, container);
                } catch {}
            });
        }




        userInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        sendBtn.addEventListener('click', sendMessage);

        userInput.addEventListener('input', () => {
            sendBtn.disabled = userInput.value.trim() === '';
        });

        cancelBtn.addEventListener('click', () => {
            if (abortController) abortController.abort();
        });

        clearBtn.addEventListener('click', () => {
            localStorage.removeItem('chatHistory');
            chatBody.innerHTML = '<div class="ai-message"><div class="message-content">Hello! Ask me a question about InteLIS.</div></div>';
        });

        // Delegated click: works for fresh + restored buttons
        chatBody.addEventListener('click', (e) => {
            const btn = e.target.closest('.generate-chart-btn');
            if (!btn) return;

            const container = btn.closest('.ai-message');
            if (!container) return;

            const metaEl = container.querySelector('.chart-meta');
            if (!metaEl) return;

            let payload = null;
            try {
                payload = JSON.parse(metaEl.textContent || '{}');
            } catch {}

            if (payload?.chart_suggestions?.suitable_for_charts) {
                window.chartGenerator.showChartOptions(payload, container);
            }
        });


        resetContextBtn.addEventListener('click', async () => {
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        clear_context: true
                    })
                });

                if (response.ok) {
                    const data = await response.json();
                    console.log('Context cleared:', data.message);

                    // Show brief confirmation message
                    const confirmMsg = document.createElement('div');
                    confirmMsg.className = 'ai-message';
                    confirmMsg.innerHTML = '<div class="message-content"><em>Conversation context has been reset. Previous queries will no longer influence new questions.</em></div>';
                    chatBody.appendChild(confirmMsg);
                    chatBody.scrollTop = chatBody.scrollHeight;

                    // Save to history so the confirmation persists
                    localStorage.setItem('chatHistory', chatBody.innerHTML);
                }
            } catch (error) {
                console.error('Failed to reset context:', error);
                addMessage('ai', '<strong>Error:</strong> Failed to reset conversation context.');
            }
        });

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && !cancelBtn.classList.contains('d-none') && abortController) {
                abortController.abort();
            }
        });


        document.addEventListener('DOMContentLoaded', () => {
            loadChatHistory();
            loadSettings();
            rehydrateChartButtons();
            userInput.focus();
        });
    </script>
</body>

</html>