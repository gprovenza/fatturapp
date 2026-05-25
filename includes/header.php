<!DOCTYPE html>
<html lang="it" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'Fatturazione', ENT_QUOTES, 'UTF-8') ?></title>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        /* =========================================================
           VARIABILI GLOBALI
        ========================================================= */
        :root {
            --sidebar-width: 255px;
            --sidebar-bg: #0f172a;
            --sidebar-text: #94a3b8;
            --sidebar-hover-bg: rgba(255,255,255,0.07);
            --sidebar-active-bg: rgba(37,99,235,0.18);
            --sidebar-active-text: #60a5fa;
            --sidebar-active-border: #2563eb;
            --sidebar-border: rgba(255,255,255,0.06);
            --topbar-height: 0px;
            --radius-card: 0.875rem;
            --shadow-card: 0 1px 3px rgba(0,0,0,0.07), 0 4px 12px rgba(0,0,0,0.05);
            --shadow-card-hover: 0 4px 8px rgba(0,0,0,0.1), 0 12px 24px rgba(0,0,0,0.08);
        }

        /* Font system modernizzato */
        body {
            min-height: 100vh;
            background-color: var(--bs-body-bg);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        /* =========================================================
           SIDEBAR
        ========================================================= */
        #sidebar {
            width: var(--sidebar-width);
            min-height: 100vh;
            position: fixed;
            left: 0; top: 0; bottom: 0;
            z-index: 200;
            background: var(--sidebar-bg);
            color: var(--sidebar-text);
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            overflow-x: hidden;
            transition: transform 0.3s cubic-bezier(.4,0,.2,1);
            border-right: 1px solid var(--sidebar-border);
        }
        /* scrollbar sottile sidebar */
        #sidebar::-webkit-scrollbar { width: 4px; }
        #sidebar::-webkit-scrollbar-track { background: transparent; }
        #sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.12); border-radius: 2px; }

        #sidebar .sidebar-brand {
            padding: 1.1rem 1.1rem 0.9rem;
            font-size: 1rem;
            font-weight: 700;
            color: #e2e8f0;
            letter-spacing: 0.3px;
            border-bottom: 1px solid var(--sidebar-border);
            flex-shrink: 0;
        }
        #sidebar .sidebar-brand .brand-icon {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, #1e3a5f, #2563eb);
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .9rem;
            color: #fff;
            flex-shrink: 0;
        }

        #sidebar .sidebar-section-label {
            padding: 1rem 1.1rem 0.3rem;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: rgba(148,163,184,0.5);
        }

        #sidebar .nav-link {
            color: var(--sidebar-text);
            padding: 0.52rem 0.75rem;
            border-radius: 0.45rem;
            margin: 0.08rem 0.65rem;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            transition: background 0.15s, color 0.15s;
            position: relative;
        }
        #sidebar .nav-link:hover {
            background: var(--sidebar-hover-bg);
            color: #e2e8f0;
        }
        #sidebar .nav-link.active {
            background: var(--sidebar-active-bg);
            color: var(--sidebar-active-text);
            font-weight: 600;
        }
        #sidebar .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0; top: 20%; bottom: 20%;
            width: 3px;
            background: var(--sidebar-active-border);
            border-radius: 0 3px 3px 0;
            margin-left: -0.65rem;
        }
        #sidebar .nav-link i {
            font-size: .95rem;
            width: 1.15rem;
            text-align: center;
            flex-shrink: 0;
            opacity: .85;
        }
        #sidebar .nav-link.active i { opacity: 1; }

        #sidebar .sidebar-footer {
            margin-top: auto;
            padding: 0.85rem 1.1rem;
            border-top: 1px solid var(--sidebar-border);
            font-size: 0.8rem;
            color: var(--sidebar-text);
            flex-shrink: 0;
        }
        #sidebar .sidebar-footer .user-avatar {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, #334155, #475569);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .8rem;
            color: #e2e8f0;
            font-weight: 700;
            flex-shrink: 0;
        }
        #sidebar .sidebar-footer .user-name {
            font-weight: 600;
            color: #e2e8f0;
            font-size: 0.85rem;
            line-height: 1.2;
        }

        /* =========================================================
           MAIN CONTENT
        ========================================================= */
        #main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-left 0.3s cubic-bezier(.4,0,.2,1);
        }

        /* =========================================================
           TOPBAR MOBILE
        ========================================================= */
        #topbar {
            display: none;
            position: sticky;
            top: 0;
            z-index: 150;
            backdrop-filter: blur(8px);
        }
        #sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 199;
            backdrop-filter: blur(2px);
        }

        /* =========================================================
           MOBILE
        ========================================================= */
        @media (max-width: 767.98px) {
            #sidebar { transform: translateX(calc(-1 * var(--sidebar-width))); }
            #sidebar.open {
                transform: translateX(0);
                box-shadow: 8px 0 32px rgba(0,0,0,0.3);
            }
            #sidebar-overlay.show { display: block; }
            #topbar { display: flex; }
            #main-content { margin-left: 0; }
        }

        /* =========================================================
           DARK MODE
        ========================================================= */
        [data-bs-theme="dark"] #sidebar {
            --sidebar-bg: #080f1a;
            --sidebar-border: rgba(255,255,255,0.05);
        }
        [data-bs-theme="dark"] body {
            background-color: #0d1117;
        }

        /* =========================================================
           CARDS
        ========================================================= */
        .stat-card {
            border: none !important;
            border-radius: var(--radius-card);
            transition: transform 0.2s cubic-bezier(.4,0,.2,1), box-shadow 0.2s;
            overflow: hidden;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-card-hover) !important;
        }
        .stat-card .stat-icon {
            font-size: 2.4rem;
            line-height: 1;
        }

        .page-card {
            border: 1px solid var(--bs-border-color) !important;
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            overflow: hidden;
        }
        .page-card .card-header {
            border-bottom: 1px solid var(--bs-border-color);
            font-weight: 600;
            font-size: .9rem;
        }

        /* =========================================================
           TOAST
        ========================================================= */
        #toast-container {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 1100;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            max-width: 360px;
        }
        #toast-container .toast {
            border-radius: 0.625rem !important;
            backdrop-filter: blur(8px);
        }

        /* =========================================================
           PAGE CONTENT
        ========================================================= */
        .page-content {
            padding: 1.75rem;
            flex: 1;
        }
        @media (max-width: 575.98px) {
            .page-content { padding: 1rem; }
        }

        /* =========================================================
           DARK MODE TOGGLE
        ========================================================= */
        #darkModeToggle {
            background: none;
            border: none;
            color: var(--sidebar-text);
            font-size: 1rem;
            cursor: pointer;
            padding: 0.3rem;
            border-radius: 0.35rem;
            transition: background 0.15s, color 0.15s;
            line-height: 1;
        }
        #darkModeToggle:hover {
            background: var(--sidebar-hover-bg);
            color: #e2e8f0;
        }

        /* =========================================================
           FORMS & TABLE IMPROVEMENTS
        ========================================================= */
        .form-control, .form-select {
            border-radius: 0.5rem;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 3px rgba(37,99,235,.18);
            border-color: #2563eb;
        }
        .btn {
            border-radius: 0.5rem;
            font-weight: 500;
        }
        .btn-primary {
            background: linear-gradient(135deg,#2563eb,#1d4ed8);
            border-color: #1d4ed8;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg,#1d4ed8,#1e40af);
            border-color: #1e40af;
        }
        .table > :not(caption) > * > * {
            padding: .65rem 1rem;
        }
        .table thead th {
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        /* solo thead chiari (non table-dark) ricevono il colore muted */
        .table thead:not(.table-dark) th {
            color: var(--bs-secondary-color);
        }
        /* Numeri tabular nelle tabelle — colonne importi/ore non "ballano" */
        .table {
            font-variant-numeric: tabular-nums;
        }
        /* Hover riga tabella più visibile */
        .table-hover > tbody > tr:hover > * {
            --bs-table-hover-bg: rgba(37,99,235,0.06);
        }
        [data-bs-theme="dark"] .table-hover > tbody > tr:hover > * {
            --bs-table-hover-bg: rgba(96,165,250,0.09);
        }
        /* Focus ring visibile per accessibilità */
        :focus-visible {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
        }
        [data-bs-theme="dark"] :focus-visible {
            outline-color: #60a5fa;
        }

        /* =========================================================
           PAGE HEADER UTILITY
        ========================================================= */
        .page-header {
            margin-bottom: 1.5rem;
        }
        .page-header h4, .page-header h5 {
            font-weight: 700;
            margin-bottom: .15rem;
        }
        .page-header .page-subtitle {
            font-size: .85rem;
            color: var(--bs-secondary-color);
        }
    </style>

    <?php if (!empty($extra_head)) echo $extra_head; ?>

    <!-- Ripristina dark mode prima del render per evitare flash -->
    <script>
        (function() {
            const theme = localStorage.getItem('fatturazione-theme') || 'light';
            document.documentElement.setAttribute('data-bs-theme', theme);
        })();
    </script>
</head>
<body>
