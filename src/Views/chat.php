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
            // (Keys and base URLs remain server-side; we don't expose API keys)
            'timeout' => $p['timeout'] ?? 30,
        ];
    }, $providers),
    'routing' => $llmCfg['routing'] ?? [],
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

        /* Modal Styles */
        .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        }

        .modal-header {
            padding: 1.5rem 2rem 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-title {
            font-weight: 600;
            color: var(--dark-gray);
        }

        .modal-body {
            padding: 1.5rem 2rem 2rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
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
            margin-top: 0.25rem;
        }

        .form-check-input:checked {
            background-color: var(--primary-blue);
            border-color: var(--primary-blue);
        }

        .form-check-input:focus {
            border-color: var(--primary-blue);
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
        }

        /* Settings sections */
        .settings-section {
            margin-bottom: 2rem;
        }

        .settings-section:last-child {
            margin-bottom: 0;
        }

        .settings-section h6 {
            color: var(--dark-gray);
            font-weight: 600;
            margin-bottom: 1rem;
            font-size: 1rem;
        }

        .per-step-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-top: 1rem;
        }

        @media (min-width: 768px) {
            .per-step-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .step-config {
            padding: 1rem;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: #fafbfc;
        }

        .step-config h7 {
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.875rem;
            margin-bottom: 0.75rem;
            display: block;
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

        .alert-compact {
            padding: .5rem .75rem;
            margin-top: .5rem;
            font-size: .82rem
        }

        .alert-compact .small {
            font-size: .8rem
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

        /* Engine info */
        .engine-info {
            font-size: 0.875rem;
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--medium-gray);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }

        .engine-info strong {
            color: var(--dark-gray);
        }

        .engine-badge {
            background: var(--light-blue);
            color: var(--primary-blue);
            border: 1px solid rgba(13, 110, 253, .2);
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', monospace;
            font-size: 0.65rem;
            padding: 0.15rem 0.35rem;
            border-radius: 4px;
            margin-left: 0.3rem;
            display: inline-block;
        }

        .citations-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: .35rem;
            margin-top: .35rem
        }

        .citation-badge {
            background: #eef2ff;
            color: #374151;
            border: 1px solid #dbeafe;
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', monospace;
            font-size: .72rem;
            padding: .15rem .4rem;
            border-radius: 6px
        }

        .citation-badge[data-hit="true"] {
            border-color: #6366f1;
            box-shadow: 0 0 0 2px rgba(99, 102, 241, .15)
        }

        .details-box {
            background: #fafbfc;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: .6rem .75rem;
            margin-top: .5rem
        }

        .details-box code {
            font-size: .72rem
        }

        .details-box {
            background: #fafbfc;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: .6rem .75rem;
            margin-top: .5rem;
        }

        .details-box code {
            font-size: .72rem;
        }


        /* SQL block */
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

        /* Table */
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
            background: #fff;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            transition: .2s;
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

        /* Loading */
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
            animation-delay: .2s;
        }

        .loading-dot:nth-child(3) {
            animation-delay: .4s;
        }

        @keyframes loadingPulse {

            0%,
            80%,
            100% {
                transform: scale(.8);
                opacity: .5;
            }

            40% {
                transform: scale(1);
                opacity: 1;
            }
        }

        /* Chart Panel Styles - to match MedicalChartGenerator */
        .chart-panel {
            background: #f8fafc;
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

        .medical-chart {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            min-height: 400px;
            max-width: 100%;
            overflow: hidden;
            position: relative;
        }

        /* Generate Chart button style */
        .generate-chart-btn {
            color: var(--primary-blue);
            text-decoration: none;
            font-size: 0.875rem;
            cursor: pointer;
            border: none;
            background: none;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        .generate-chart-btn:hover {
            background-color: var(--light-blue);
            color: var(--primary-blue);
            text-decoration: none;
        }

        .link-icon {
            text-decoration: none;
        }

        .link-icon i {
            font-style: normal;
            margin-right: 4px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .chat-container {
                margin: .5rem;
                height: calc(100vh - 1rem);
                border-radius: 12px;
            }

            .chat-header {
                padding: 1rem;
                border-radius: 12px 12px 0 0;
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
                gap: .5rem;
            }

            .modal-header,
            .modal-body {
                padding: 1rem 1.5rem;
            }

            .medical-chart {
                min-height: 300px;
                padding: 0.5rem;
            }

            .chart-options-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
                <button class="btn btn-modern" data-bs-toggle="modal" data-bs-target="#settingsModal">
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

        <div class="chat-body" id="chat-body">
            <div class="ai-message">
                <div class="message-content">Hello! Ask me a question about InteLIS.</div>
            </div>
        </div>

        <div class="chat-input">
            <div class="input-group">
                <input type="text" id="user-input" class="form-control chat-input-field" placeholder="Ask a question..." autocomplete="off">
                <div class="input-buttons">
                    <button id="cancel-btn" class="btn btn-icon btn-cancel d-none" type="button" title="Cancel request" aria-label="Cancel">
                        <i class="bi bi-x-lg" aria-hidden="true"></i><span class="visually-hidden">Cancel</span>
                    </button>
                    <button id="send-btn" class="btn btn-icon btn-send" type="button" disabled title="Send message" aria-label="Send">
                        <i class="bi bi-send-fill" aria-hidden="true"></i><span class="visually-hidden">Send</span>
                    </button>
                </div>
            </div>
            <small style="color:#888;font-size:0.7em;float:right;">Note: The LLM does not access your database directly. Personally Identifiable Information (PII) is strictly prohibited.</small>
        </div>
    </div>

    <!-- Settings Modal -->
    <div class="modal fade" id="settingsModal" tabindex="-1" aria-labelledby="settingsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="settingsModalLabel">
                        <i class="bi bi-gear me-2"></i>LLM Settings
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="settings-section">
                        <h6>Basic Configuration</h6>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label for="provider-select" class="form-label">Provider</label>
                                <select id="provider-select" class="form-select">
                                    <!-- Options populated by JavaScript -->
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="model-input" class="form-label">Model</label>
                                <input id="model-input" type="text" class="form-control" placeholder="gpt-4o-mini">
                                <div class="form-text">Uses server defaults from config/app.php. Override here per request.</div>
                            </div>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="per-step-toggle">
                            <label class="form-check-label" for="per-step-toggle">
                                <strong>Use different models per step</strong>
                            </label>
                        </div>
                        <div class="form-text mb-3">Configure different LLM providers/models for intent analysis, SQL generation, and chart creation.</div>

                        <div id="per-step-config" class="per-step-grid" style="display:none;">
                            <!-- Intent -->
                            <div class="step-config">
                                <h7>Intent Analysis</h7>
                                <select id="intent-provider" class="form-select form-select-sm mb-2">
                                    <!-- Options populated by JavaScript -->
                                </select>
                                <input id="intent-model" class="form-control form-control-sm" placeholder="model name">
                            </div>
                            <!-- SQL -->
                            <div class="step-config">
                                <h7>SQL Generation</h7>
                                <select id="sql-provider" class="form-select form-select-sm mb-2">
                                    <!-- Options populated by JavaScript -->
                                </select>
                                <input id="sql-model" class="form-control form-control-sm" placeholder="model name">
                            </div>
                            <!-- Chart -->
                            <div class="step-config">
                                <h7>Chart Analysis</h7>
                                <select id="chart-provider" class="form-select form-select-sm mb-2">
                                    <!-- Options populated by JavaScript -->
                                </select>
                                <input id="chart-model" class="form-control form-control-sm" placeholder="model name">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary btn-modern" id="save-settings">Save Settings</button>
                </div>
            </div>
        </div>
    </div>

    <script src="/js/charts.js"></script>
    <script id="llm-config" type="application/json">
        <?= json_encode($clientCfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
    </script>

    <script>
        // --- DOM refs ---
        const perStepToggle = document.getElementById('per-step-toggle');
        const perStepConfig = document.getElementById('per-step-config');
        const intentProvider = document.getElementById('intent-provider');
        const intentModel = document.getElementById('intent-model');
        const sqlProvider = document.getElementById('sql-provider');
        const sqlModel = document.getElementById('sql-model');
        const chartProvider = document.getElementById('chart-provider');
        const chartModel = document.getElementById('chart-model');

        const chatBody = document.getElementById('chat-body');
        const userInput = document.getElementById('user-input');
        const sendBtn = document.getElementById('send-btn');
        const cancelBtn = document.getElementById('cancel-btn');
        const clearBtn = document.getElementById('clear-btn');
        const resetContextBtn = document.getElementById('reset-context-btn');
        const providerSelect = document.getElementById('provider-select');
        const modelInput = document.getElementById('model-input');
        const saveSettingsBtn = document.getElementById('save-settings');
        const activeProviderBadge = document.getElementById('active-provider');
        const activeModelBadge = document.getElementById('active-model');
        const settingsModal = document.getElementById('settingsModal');
        const API_URL = '/ask';
        let abortController = null;

        // Read server-provided config
        const llmCfg = JSON.parse(document.getElementById('llm-config').textContent || '{}');
        const defaultProvider = llmCfg.defaultProvider || '';
        const providerDefaults = llmCfg.providers || {};

        // --- helpers ---
        function populateProviderSelect(selectEl) {
            selectEl.innerHTML = '';
            Object.keys(providerDefaults).forEach(p => {
                const opt = document.createElement('option');
                opt.value = p;
                opt.textContent = p;
                selectEl.appendChild(opt);
            });
        }

        function escapeHtml(s = '') {
            return String(s).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#39;');
        }

        function renderCitationsSection(data) {
            const cits = Array.isArray(data.citations) ? data.citations.filter(Boolean) : [];
            const retrieved = Array.isArray(data.retrieved_context_ids) ? data.retrieved_context_ids.filter(Boolean) : [];
            if (!cits.length && !retrieved.length) return '';

            const uniq = Array.from(new Set([...retrieved, ...cits]));
            const badges = uniq.map(id => {
                const hit = cits.includes(id);
                return `<span class="citation-badge" data-hit="${hit}" title="${hit?'Cited by SQL step':'Retrieved only'}">${escapeHtml(id)}</span>`;
            }).join(' ');

            const mode = cits.length ? 'RAG (grounded)' : 'RAG (retrieval only)';
            const contextId = 'ctx-' + Math.random().toString(36).substr(2, 9);

            return `
<button class="btn btn-sm btn-outline-primary mb-2" type="button" onclick="document.getElementById('${contextId}').style.display='block';this.style.display='none';">
    <i class="bi bi-info-circle"></i> View Context (${uniq.length} items)
</button>
<div id="${contextId}" class="details-box" style="display:none;">
    <div class="d-flex justify-content-between align-items-start mb-2">
        <strong>Context: ${mode}</strong>
        <button type="button" class="btn-close" style="font-size:0.7rem;" onclick="this.closest('.details-box').style.display='none';this.parentElement.parentElement.previousElementSibling.style.display='block';" aria-label="Close"></button>
    </div>
    <div class="citations-wrap">${badges}</div>
    <details class="mt-1"><summary class="small text-muted">Show raw IDs</summary>
        <code>${escapeHtml(JSON.stringify({citations:cits,retrieved_context_ids:retrieved},null,2))}</code>
    </details>
</div>`;
        }


        function setStepDefaultsFromRouting() {
            const routing = llmCfg.routing || {};
            const fallbackProv = defaultProvider || Object.keys(providerDefaults)[0] || '';

            const rIntent = routing.intent || {};
            const rSql = routing.sql || {};
            const rChart = routing.chart || {};

            intentProvider.value = rIntent.provider || fallbackProv;
            sqlProvider.value = rSql.provider || fallbackProv;
            chartProvider.value = rChart.provider || fallbackProv;

            intentModel.value = rIntent.model || (providerDefaults[intentProvider.value]?.model || '');
            sqlModel.value = rSql.model || (providerDefaults[sqlProvider.value]?.model || '');
            chartModel.value = rChart.model || (providerDefaults[chartProvider.value]?.model || '');

            intentModel.placeholder = providerDefaults[intentProvider.value]?.model || '';
            sqlModel.placeholder = providerDefaults[sqlProvider.value]?.model || '';
            chartModel.placeholder = providerDefaults[chartProvider.value]?.model || '';
        }

        function updateBadges(provider, model) {
            activeProviderBadge.textContent = provider || '—';
            activeModelBadge.textContent = model || '—';
        }

        function addMessage(sender, content, saveToHistory = true) {
            const messageDiv = document.createElement('div');
            messageDiv.className = sender === 'user' ? 'user-message' : 'ai-message';
            messageDiv.innerHTML = `<div class="message-content">${content}</div>`;
            chatBody.appendChild(messageDiv);
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

            // per-step
            const usePerStep = localStorage.getItem('llmUsePerStep') === '1';
            perStepToggle.checked = usePerStep;
            perStepConfig.style.display = usePerStep ? 'grid' : 'none';

            // defaults from server routing
            setStepDefaultsFromRouting();

            // overrides from localStorage
            const spIntent = localStorage.getItem('llmIntentProvider');
            const smIntent = localStorage.getItem('llmIntentModel');
            const spSql = localStorage.getItem('llmSqlProvider');
            const smSql = localStorage.getItem('llmSqlModel');
            const spChart = localStorage.getItem('llmChartProvider');
            const smChart = localStorage.getItem('llmChartModel');

            if (spIntent && providerDefaults[spIntent]) intentProvider.value = spIntent;
            if (spSql && providerDefaults[spSql]) sqlProvider.value = spSql;
            if (spChart && providerDefaults[spChart]) chartProvider.value = spChart;

            if (smIntent) intentModel.value = smIntent;
            if (smSql) sqlModel.value = smSql;
            if (smChart) chartModel.value = smChart;

            intentModel.placeholder = providerDefaults[intentProvider.value]?.model || '';
            sqlModel.placeholder = providerDefaults[sqlProvider.value]?.model || '';
            chartModel.placeholder = providerDefaults[chartProvider.value]?.model || '';
        }

        function saveSettings() {
            const provider = providerSelect.value;
            const model = (modelInput.value || providerDefaults[provider]?.model || '').trim();

            localStorage.setItem('llmProvider', provider);
            localStorage.setItem('llmModel', model);

            const usePerStep = perStepToggle.checked;
            localStorage.setItem('llmUsePerStep', usePerStep ? '1' : '0');

            if (usePerStep) {
                localStorage.setItem('llmIntentProvider', intentProvider.value);
                localStorage.setItem('llmIntentModel', (intentModel.value || providerDefaults[intentProvider.value]?.model || '').trim());

                localStorage.setItem('llmSqlProvider', sqlProvider.value);
                localStorage.setItem('llmSqlModel', (sqlModel.value || providerDefaults[sqlProvider.value]?.model || '').trim());

                localStorage.setItem('llmChartProvider', chartProvider.value);
                localStorage.setItem('llmChartModel', (chartModel.value || providerDefaults[chartProvider.value]?.model || '').trim());
            }
            updateBadges(provider, model);
        }

        // Event listeners
        perStepToggle.addEventListener('change', () => {
            perStepConfig.style.display = perStepToggle.checked ? 'grid' : 'none';
        });

        [intentProvider, sqlProvider, chartProvider].forEach(sel => {
            sel.addEventListener('change', () => {
                if (sel === intentProvider) {
                    intentModel.placeholder = providerDefaults[intentProvider.value]?.model || '';
                    if (!intentModel.value) intentModel.value = intentModel.placeholder;
                } else if (sel === sqlProvider) {
                    sqlModel.placeholder = providerDefaults[sqlProvider.value]?.model || '';
                    if (!sqlModel.value) sqlModel.value = sqlModel.placeholder;
                } else {
                    chartModel.placeholder = providerDefaults[chartProvider.value]?.model || '';
                    if (!chartModel.value) chartModel.value = chartModel.placeholder;
                }
            });
        });

        providerSelect.addEventListener('change', () => {
            const provider = providerSelect.value;
            const defaultModel = providerDefaults[provider]?.model || '';
            modelInput.value = defaultModel;
            modelInput.placeholder = defaultModel;
            updateBadges(provider, defaultModel);
        });

        saveSettingsBtn.addEventListener('click', () => {
            saveSettings();
            // Close modal
            const modal = bootstrap.Modal.getInstance(settingsModal);
            modal.hide();
        });

        let inFlight = false;
        async function sendMessage() {
            if (inFlight) return;
            inFlight = true;
            const query = userInput.value.trim();
            if (query === '') return;

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

                // build payload from settings
                const usePerStep = localStorage.getItem('llmUsePerStep') === '1';
                if (usePerStep) {
                    const map = {};
                    const ip = (localStorage.getItem('llmIntentProvider') || intentProvider.value || '').trim();
                    const im = (localStorage.getItem('llmIntentModel') || intentModel.value || '').trim();
                    const sp = (localStorage.getItem('llmSqlProvider') || sqlProvider.value || '').trim();
                    const sm = (localStorage.getItem('llmSqlModel') || sqlModel.value || '').trim();
                    const cp = (localStorage.getItem('llmChartProvider') || chartProvider.value || '').trim();
                    const cm = (localStorage.getItem('llmChartModel') || chartModel.value || '').trim();

                    if (ip || im) map.intent = {};
                    if (ip) map.intent.provider = ip;
                    if (im) map.intent.model = im;

                    if (sp || sm) map.sql = {};
                    if (sp) map.sql.provider = sp;
                    if (sm) map.sql.model = sm;

                    if (cp || cm) map.chart = {};
                    if (cp) map.chart.provider = cp;
                    if (cm) map.chart.model = cm;

                    if (Object.keys(map).length) payload.provider_map = map;
                } else {
                    const provider = (localStorage.getItem('llmProvider') || providerSelect.value || '').trim();
                    const model = (localStorage.getItem('llmModel') || modelInput.value || '').trim();
                    if (provider) payload.provider = provider;
                    if (model) payload.model = model;
                }

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

                // Show engines actually used
                const llmTiming = data.timing?.llm || {};
                const usedIntent = llmTiming.intent || {};
                const usedSql = llmTiming.sql || {};
                const usedChart = llmTiming.chart || {};

                // Top badges reflect the SQL step
                updateBadges(usedSql.provider || '—', usedSql.model || '—');

                const ragCtxCount = (data.retrieved_context_ids || []).length;
                const ragCitCount = (data.citations || []).length;
                const ragBadgeHtml = `<span class="engine-badge">rag: ${ragCtxCount} ctx • ${ragCitCount} cit</span>`;


                // Build response HTML
                let responseHtml = `<div class="engine-info">
                    <strong>Engines:</strong>
                    <span class="engine-badge">intent: ${usedIntent.provider || '—'} • ${usedIntent.model || '—'}</span>
                    <span class="engine-badge">sql: ${usedSql.provider || '—'} • ${usedSql.model || '—'}</span>
                    <span class="engine-badge">chart: ${usedChart.provider || '—'} • ${usedChart.model || '—'}</span>
                    ${ragBadgeHtml}
                    </div>`;
                responseHtml += `<div class="mb-2"><strong>Result:</strong></div>`;

                // --- RAG citations & context ---

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
                responseHtml += renderCitationsSection(data);

                if (data.timing) {
                    responseHtml += `<div class="timing-info">
                        <div class="timing-item"><i class="bi bi-clock text-primary"></i><span>Total: ${data.timing.total_ms ?? '—'} ms</span></div>
                        <div class="timing-item"><i class="bi bi-cpu text-success"></i><span>Query: ${data.timing.query_processing_ms ?? '—'} ms</span></div>
                        <div class="timing-item"><i class="bi bi-database text-info"></i><span>DB: ${data.timing.db_execution_ms ?? '—'} ms</span></div>
                    </div>`;
                }

                // Add verification display
                if (data.verification) {
                    if (!data.verification.matches_intent || data.verification.confidence < 0.8) {
                        const confPct = Math.round((data.verification.confidence ?? 0) * 100);
                        const why = escapeHtml(data.verification.reasoning || '');
                        responseHtml += `<div class="alert alert-warning alert-compact mt-2">
  <div><i class="bi bi-exclamation-triangle"></i> <strong>Confidence:</strong> ${confPct}%</div>
  <details class="mt-1"><summary class="small">Why</summary><div class="mt-1">${why}</div></details>
</div>`;

                    }

                    if (data.concerns && data.concerns.length > 0) {
                        responseHtml += `<div class="alert alert-info mt-2">
                            <small><strong>Notes:</strong> ${data.concerns.join(', ')}</small>
                        </div>`;
                    }
                }

                const messageDiv = addMessage('ai', responseHtml);

                // Charts - use existing chartGenerator from charts.js
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
                        }, messageDiv);
                    }

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
                    if (container.querySelector('.generate-chart-btn')) return; // skip duplicate

                    chartGenerator.addChartButton(payload, container);
                } catch (e) {
                    console.error('Error rehydrating chart button:', e);
                }
            });
        }

        // Event listeners
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
                    const confirmMsg = document.createElement('div');
                    confirmMsg.className = 'ai-message';
                    confirmMsg.innerHTML = '<div class="message-content"><em>Conversation context has been reset. Previous queries will no longer influence new questions.</em></div>';
                    chatBody.appendChild(confirmMsg);
                    chatBody.scrollTop = chatBody.scrollHeight;
                    localStorage.setItem('chatHistory', chatBody.innerHTML);
                }
            } catch (error) {
                addMessage('ai', '<strong>Error:</strong> Failed to reset conversation context.');
            }
        });

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && !cancelBtn.classList.contains('d-none') && abortController) {
                abortController.abort();
            }
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            // Populate provider selects
            populateProviderSelect(providerSelect);
            populateProviderSelect(intentProvider);
            populateProviderSelect(sqlProvider);
            populateProviderSelect(chartProvider);

            loadChatHistory();
            loadSettings();
            rehydrateChartButtons(); // Rehydrate chart buttons after loading history
            userInput.focus();
        });
    </script>
</body>

</html>