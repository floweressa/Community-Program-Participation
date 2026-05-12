<?php
// admin_dashboard.php

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

/* ── HELPERS ── */
require_once 'database.php';

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function timeGreeting(): string {
    $h = (int) date('G');
    if ($h < 12) return 'Good morning';
    if ($h < 17) return 'Good afternoon';
    return 'Good evening';
}

/* ── DB ── */
$db = getDB();

/* ── KPIs ── */
$total_users        = (int) $db->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
$total_participants = (int) $db->query("SELECT COUNT(*) FROM participants")->fetch_row()[0];
$total_staff        = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'staff'")->fetch_row()[0];
$total_programs     = (int) $db->query("SELECT COUNT(*) FROM programs")->fetch_row()[0];
$active_programs    = (int) $db->query("SELECT COUNT(*) FROM programs WHERE status IN ('upcoming','ongoing')")->fetch_row()[0];

$today = date('Y-m-d');
$stmt  = $db->prepare("SELECT COUNT(*) FROM attendance WHERE attendance_date = ?");
$stmt->bind_param('s', $today);
$stmt->execute();
$att_today = (int) $stmt->get_result()->fetch_row()[0];
$stmt->close();

/* ── CHART DATA ── */
// COUNT (Programs by status (doughnut chart))
$prog_status_res = $db->query(
    "SELECT status, COUNT(*) AS cnt FROM programs GROUP BY status ORDER BY cnt DESC"
)->fetch_all(MYSQLI_ASSOC);
$chart_prog_labels = json_encode(array_column($prog_status_res, 'status'));
$chart_prog_data   = json_encode(array_map('intval', array_column($prog_status_res, 'cnt')));

// COUNT (Users by role (bar chart))
$role_res = $db->query(
    "SELECT role, COUNT(*) AS cnt FROM users GROUP BY role ORDER BY cnt DESC"
)->fetch_all(MYSQLI_ASSOC);
$chart_role_labels = json_encode(array_map('ucfirst', array_column($role_res, 'role')));
$chart_role_data   = json_encode(array_map('intval', array_column($role_res, 'cnt')));

// COUNT (Participants registered per month – last 6 months (line chart))
$monthly_res = $db->query(
    "SELECT DATE_FORMAT(created_at,'%b %Y') AS mo, COUNT(*) AS cnt
     FROM users
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY YEAR(created_at), MONTH(created_at)
     ORDER BY YEAR(created_at), MONTH(created_at)"
)->fetch_all(MYSQLI_ASSOC);
$chart_mo_labels = json_encode(array_column($monthly_res, 'mo'));
$chart_mo_data   = json_encode(array_map('intval', array_column($monthly_res, 'cnt')));

/* ── Recent Users ── */
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $like  = '%' . $search . '%';
    $stmt  = $db->prepare(
        "SELECT user_id, first_name, last_name, email, role, created_at
         FROM users
         WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ?
            OR CONCAT(first_name,' ',last_name) LIKE ?
         ORDER BY created_at DESC LIMIT 20"
    );
    $stmt->bind_param('ssss', $like, $like, $like, $like);
    $stmt->execute();
    $recent_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $recent_users = $db->query(
        "SELECT user_id, first_name, last_name, email, role, created_at
         FROM users ORDER BY created_at DESC LIMIT 8"
    )->fetch_all(MYSQLI_ASSOC);
}

/* ── LEFT JOIN (Recent Audit Logs) ── */
$recent_logs = [];
$log_check = $db->query("SHOW TABLES LIKE 'audit_logs'");
if ($log_check && $log_check->num_rows > 0) {
    $recent_logs = $db->query(
        "SELECT al.action, al.table_name, al.created_at,
                u.first_name, u.last_name
         FROM audit_logs al
         LEFT JOIN users u ON u.user_id = al.performed_by
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetch_all(MYSQLI_ASSOC);
}

$first_name = e($_SESSION['first_name'] ?? '');
$last_name  = e($_SESSION['last_name']  ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Chart.js for dashboard statistics -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        body { background:#f8fafc; display:flex; height:100vh; overflow:hidden; }

        /* ── SIDEBAR ── */
        .nav-sidebar { width:260px; background:white; border-right:1px solid #e2e8f0;
            padding:25px; display:flex; flex-direction:column; flex-shrink:0; }
        .nav-logo { color:#334e5e; font-weight:700; font-size:1.3rem; margin-bottom:8px; letter-spacing:0.5px; }
        .nav-badge { display:inline-block; padding:2px 10px; background:white; color:white;
            border-radius:20px; font-size:0.7rem; text-transform:uppercase; letter-spacing:1px;
            margin-bottom:28px; }
        .nav-item { display:flex; align-items:center; gap:15px; padding:12px 15px;
            color:#718096; text-decoration:none; border-radius:10px; margin-bottom:8px;
            transition:0.2s; font-size:0.95rem; font-weight:500; }
        .nav-item:hover { background:#f1f5f9; color:#334e5e; }
        .nav-item.active { background:#f1f5f9; color:#334e5e;
            border-right:4px solid #334e5e; border-radius:10px 0 0 10px; font-weight:600; }
        .nav-footer { margin-top:auto; padding-top:20px; border-top:1px solid #f1f5f9; }

        /* ── MAIN ── */
        .main-wrapper { flex:1; display:flex; flex-direction:column; overflow:hidden; }
        .top-header { padding:15px 40px; background:white; border-bottom:1px solid #e2e8f0;
            display:flex; justify-content:space-between; align-items:center; flex-shrink:0; }
        .header-actions { display:flex; align-items:center; gap:20px; color:#718096; }
        .admin-name-tag { display:flex; flex-direction:column; align-items:flex-end; line-height:1.2; }
        .admin-name-tag .sname { color:#1a202c; font-weight:700; font-size:0.9rem; }
        .admin-name-tag .srole { color:#e53e3e; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; }
        .content-area { padding:36px 40px; overflow-y:auto; flex:1; }

        /* ── SEARCH ── */
        .search-clear-btn { position:absolute; right:12px; top:50%; transform:translateY(-50%);
            background:none; border:none; cursor:pointer; color:#a0aec0; font-size:0.75rem;
            display:none; padding:2px 4px; border-radius:50%; line-height:1; transition:color 0.15s; }
        .search-clear-btn:hover { color:#718096; }

        /* ── KPI — IMPROVED LAYOUT ── */
        .kpi-section { margin-bottom:30px; display:flex; flex-direction:column; gap:16px; }

        /* Row 1 — 4-column grid */
        .kpi-row-top {
            display:grid;
            grid-template-columns: repeat(4, 1fr);
            gap:16px;
        }

        /* Row 2 — 2-column, cards are wider (each ≈50%) */
        .kpi-row-bottom {
            display:grid;
            grid-template-columns: repeat(2, 1fr);
            gap:16px;
        }

        /* Shared card shell */
        .kpi-card {
            background:white;
            border-radius:18px;
            box-shadow:0 4px 12px rgba(0,0,0,.05);
            border:1px solid #f0f4f8;
            padding:22px 24px;
            display:flex;
            align-items:center;
            gap:18px;
            position:relative;
            overflow:hidden;
            transition:box-shadow 0.2s, transform 0.2s;
        }
        
        /* Icon bubble */
        .kpi-icon {
            width:50px; height:50px; border-radius:14px;
            display:flex; align-items:center; justify-content:center;
            font-size:1.25rem; flex-shrink:0;
        }

        /* Text block */
        .kpi-body { flex:1; min-width:0; }
        .kpi-val   { font-size:1.9rem; color:#1a202c; font-weight:700; line-height:1; }
        .kpi-label { font-size:0.78rem; color:#718096; margin-top:5px; font-weight:500;
                     text-transform:uppercase; letter-spacing:0.4px; }

        /* ── CLICKABLE KPI CARDS ── */
        a.kpi-card-link { display:block; text-decoration:none; color:inherit; border-radius:18px; }
        a.kpi-card-link:focus-visible { outline:2px solid #334e5e; outline-offset:3px; }
        a.kpi-card-link .kpi-card { cursor:pointer; }
        a.kpi-card-link:hover .kpi-card { box-shadow:0 8px 20px rgba(0,0,0,.09); transform:translateY(-2px); }

        /* Bottom-row cards get a small trend line accent at the top */
        .kpi-row-bottom .kpi-card::before {
            content:''; position:absolute; top:0; left:0; right:0; height:3px;
            border-radius:18px 18px 0 0;
        }
        /* ── CONTENT GRID ── */
        .two-col { display:grid; grid-template-columns:1fr 320px; gap:24px; }

        /* ── TABLE CARD ── */
        .table-card { background:white; border-radius:15px; border:1px solid #e2e8f0; padding:25px; }
        .table-card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
        table { width:100%; border-collapse:collapse; }
        thead th { text-align:left; padding:0 15px 15px; color:#a0aec0;
            font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em;
            border-bottom:1px solid #f1f5f9; }
        tbody td { padding:14px 15px; font-size:0.9rem; color:#4a5568;
            border-bottom:1px solid #f8fafc; vertical-align:middle; }
        tbody tr:last-child td { border-bottom:none; }
        tbody tr:hover td { background:#fafbfc; }

        /* ── USER CELL ── */
        .user-cell { display:flex; align-items:center; gap:12px; }
        .user-initials { width:36px; height:36px; border-radius:50%; background:#edf2f7;
            display:flex; align-items:center; justify-content:center;
            color:#718096; font-weight:700; font-size:0.75rem; flex-shrink:0; }
        .user-cell-info .uname  { font-weight:700; color:#1a202c; display:block; font-size:0.88rem; }
        .user-cell-info .uemail { font-size:0.75rem; color:#a0aec0; }

        /* ── PILLS ── */
        .pill { display:inline-block; padding:3px 10px; border-radius:20px;
            font-size:0.72rem; font-weight:700; text-transform:capitalize; }
        .pill-admin       { background:#fff5f5; color:#c53030; }
        .pill-staff       { background:#ebf8ff; color:#2b6cb0; }
        .pill-participant { background:#f0fff4; color:#276749; }

        /* ── BUTTONS ── */
        .btn-primary { background:#334e5e; color:white; padding:9px 18px;
            border-radius:10px; border:none; font-weight:600; cursor:pointer;
            display:inline-flex; align-items:center; gap:8px; transition:0.2s;
            font-size:0.85rem; text-decoration:none; font-family:inherit; }
        .btn-primary:hover { background:#2a3f4d; }
        .btn-secondary { background:#f1f5f9; color:#4a5568; padding:9px 18px;
            border-radius:10px; border:1px solid #e2e8f0; font-weight:600; cursor:pointer;
            display:inline-flex; align-items:center; gap:8px; transition:0.2s;
            font-size:0.85rem; text-decoration:none; font-family:inherit; }
        .btn-secondary:hover { background:#e2e8f0; }

        /* ── QUICK LINKS ── */
        .quick-link { display:flex; align-items:center; gap:14px; padding:14px 18px;
            background:#f8fafc; border-radius:12px; text-decoration:none;
            color:#334e5e; font-weight:600; font-size:0.9rem; margin-bottom:10px;
            border:1px solid #e2e8f0; transition:0.2s; }
        .quick-link:hover { background:#f1f5f9; border-color:#cbd5e0; }
        .quick-link i { width:32px; height:32px; background:white; border-radius:8px;
            display:flex; align-items:center; justify-content:center; font-size:0.9rem;
            box-shadow:0 1px 3px rgba(0,0,0,0.08); flex-shrink:0; }

        /* ── ACTIVITY ── */
        .activity-item { display:flex; align-items:flex-start; gap:12px;
            padding:12px 0; border-bottom:1px solid #f1f5f9; }
        .activity-item:last-child { border-bottom:none; }
        .activity-dot { width:8px; height:8px; border-radius:50%; background:#334e5e;
            margin-top:6px; flex-shrink:0; }
        .activity-text { font-size:0.85rem; color:#4a5568; }
        .activity-time { font-size:0.75rem; color:#a0aec0; margin-top:3px; }

        /* ── CHARTS SECTION ── */
        .charts-section { display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-bottom:28px; }
        .chart-card { background:white; border-radius:15px; border:1px solid #e2e8f0;
            padding:22px; display:flex; flex-direction:column; }
        .chart-card h3 { font-size:0.9rem; font-weight:700; color:#334e5e;
            margin-bottom:4px; }
        .chart-card p  { font-size:0.75rem; color:#a0aec0; margin-bottom:16px; }
        .chart-canvas-wrap { flex:1; position:relative; min-height:180px; }

        /* ── EXPORT MODAL ── */
        .export-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.35);
            z-index:999; align-items:center; justify-content:center; }
        .export-overlay.open { display:flex; }
        .export-modal { background:white; border-radius:18px; padding:30px 32px;
            width:420px; max-width:95vw; box-shadow:0 20px 50px rgba(0,0,0,.15); }
        .export-modal h2 { font-size:1.1rem; color:#1a202c; font-weight:700; margin-bottom:6px; }
        .export-modal p  { font-size:0.85rem; color:#718096; margin-bottom:22px; }
        .export-option { display:flex; align-items:center; gap:14px; padding:14px 16px;
            border:1px solid #e2e8f0; border-radius:12px; margin-bottom:10px;
            cursor:pointer; transition:0.2s; text-decoration:none; color:inherit; }
        .export-option:hover { background:#f8fafc; border-color:#cbd5e0; }
        .export-option i.icon { width:36px; height:36px; border-radius:9px; background:#f1f5f9;
            display:flex; align-items:center; justify-content:center;
            font-size:0.95rem; color:#334e5e; flex-shrink:0; }
        .export-option .export-label { font-weight:600; font-size:0.9rem; color:#1a202c; }
        .export-option .export-desc  { font-size:0.75rem; color:#a0aec0; margin-top:1px; }
        .export-close { background:none; border:none; cursor:pointer; color:#a0aec0;
            font-size:1.1rem; float:right; margin-top:-4px; padding:2px 6px; border-radius:6px; }
        .export-close:hover { background:#f1f5f9; color:#718096; }
        /* "Download All" option — visually distinct with a teal accent */
        .export-option-all { background:#f0f9ff; border-color:#bee3f8; }
        .export-option-all:hover { background:#e6f4ff; border-color:#90cdf4; }
        .export-option-all i.icon { background:#ebf8ff; color:#2b6cb0; }
        .export-option-all .export-label { color:#2b6cb0; }
        .export-divider { display:flex; align-items:center; gap:10px;
            margin:14px 0 10px; color:#a0aec0; font-size:0.72rem;
            text-transform:uppercase; letter-spacing:0.05em; font-weight:600; }
        .export-divider::before,.export-divider::after {
            content:''; flex:1; height:1px; background:#e2e8f0; }
    </style>
</head>
<body>

<nav class="nav-sidebar">
    <div class="nav-logo">Bayanihan</div>
    <div class="nav-badge">Admin</div>
    <a href="admin_dashboard.php"       class="nav-item active"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="admin_program.php"         class="nav-item"><i class="fa-solid fa-shapes"></i> Programs</a>
    <a href="admin_participant.php"     class="nav-item"><i class="fa-solid fa-users"></i> Participants</a>
    <a href="admin_user_management.php" class="nav-item"><i class="fa-solid fa-user-shield"></i> User Management</a>
    <a href="admin_report.php"          class="nav-item"><i class="fa-solid fa-chart-bar"></i> Reports</a>
    <a href="admin_audit_log.php"       class="nav-item"><i class="fa-solid fa-clipboard-list"></i> Audit Logs</a>
    <div class="nav-footer">
        <a href="logout.php" class="nav-item"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</nav>

<div class="main-wrapper">
    <header class="top-header">
        <form id="searchForm" method="GET" style="display:contents;">
            <div style="position:relative;">
                <i class="fa-solid fa-magnifying-glass"
                   style="position:absolute;left:15px;top:50%;transform:translateY(-50%);color:#a0aec0;pointer-events:none;z-index:1;"></i>
                <input id="globalSearch" type="text" name="search" placeholder="Search users..."
                       value="<?= e($search) ?>"
                       style="width:350px;padding:10px 38px 10px 45px;background:#f1f5f9;border:none;border-radius:10px;outline-color:#334e5e;font-size:0.9rem;font-family:inherit;">
                <button type="button" id="searchClearBtn" class="search-clear-btn">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </form>
        <div class="header-actions">
            <i class="fa-regular fa-bell"></i>
            <i class="fa-regular fa-circle-question"></i>
            <div class="admin-name-tag">
                <span class="sname"><?= $first_name . ' ' . $last_name ?></span>
                <span class="srole">Administrator</span>
            </div>
        </div>
    </header>

    <main class="content-area">

        <section style="margin-bottom:28px;">
            <h1 style="font-size:2rem;color:#1a202c;font-weight:700;">
                <?= timeGreeting() ?>, <?= $first_name ?>!
            </h1>
            <p style="color:#718096;margin-top:5px;">System-wide overview for the Bayanihan platform.</p>
        </section>

        <section class="kpi-section">

            <!-- Row 1: People metrics — 4 equal columns -->
            <div class="kpi-row-top">
                <?php
                $top_cards = [
                    ['val' => number_format($total_users),        'label' => 'Total Users',      'icon' => 'fa-users',        'bg' => '#ebf8ff', 'ic' => '#3182ce', 'href' => 'admin_user_management.php'],
                    ['val' => number_format($total_participants),  'label' => 'Participants',      'icon' => 'fa-person',       'bg' => '#f0fff4', 'ic' => '#38a169', 'href' => 'admin_participant.php'],
                    ['val' => number_format($total_staff),         'label' => 'Staff Members',     'icon' => 'fa-user-tie',     'bg' => '#faf5ff', 'ic' => '#805ad5', 'href' => 'admin_user_management.php?role=staff'],
                    ['val' => number_format($att_today),           'label' => 'Attendance Today',  'icon' => 'fa-calendar-day', 'bg' => '#f1f5f9', 'ic' => '#4a5568', 'href' => 'admin_report.php'],
                ];
                foreach ($top_cards as $c): ?>
                <a href="<?= $c['href'] ?>" class="kpi-card-link">
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:<?= $c['bg'] ?>;color:<?= $c['ic'] ?>;">
                        <i class="fa-solid <?= $c['icon'] ?>"></i>
                    </div>
                    <div class="kpi-body">
                        <div class="kpi-val"><?= $c['val'] ?></div>
                        <div class="kpi-label"><?= $c['label'] ?></div>
                    </div>
                </div>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Row 2: Program metrics — 2 wider columns -->
            <div class="kpi-row-bottom">
                <?php
                $bottom_cards = [
                    [
                        'val'    => number_format($total_programs),
                        'label'  => 'Total Programs',
                        'icon'   => 'fa-shapes',
                        'bg'     => '#fffaf0', 'ic' => '#dd6b20',
                        'accent' => 'accent-orange',
                        'sub'    => 'All programs registered on the platform',
                        'href'   => 'admin_program.php',
                    ],
                    [
                        'val'    => number_format($active_programs),
                        'label'  => 'Active Programs',
                        'icon'   => 'fa-rocket',
                        'bg'     => '#f0fff4', 'ic' => '#38a169',
                        'accent' => 'accent-green',
                        'sub'    => 'Upcoming &amp; ongoing programs',
                        'href'   => 'admin_program.php',
                    ],
                ];
                foreach ($bottom_cards as $c): ?>
                <a href="<?= $c['href'] ?>" class="kpi-card-link">
                <div class="kpi-card <?= $c['accent'] ?>">
                    <div class="kpi-icon" style="background:<?= $c['bg'] ?>;color:<?= $c['ic'] ?>;">
                        <i class="fa-solid <?= $c['icon'] ?>"></i>
                    </div>
                    <div class="kpi-body">
                        <div class="kpi-val"><?= $c['val'] ?></div>
                        <div class="kpi-label"><?= $c['label'] ?></div>
                        <div style="font-size:0.75rem;color:#a0aec0;margin-top:5px;"><?= $c['sub'] ?></div>
                    </div>
                </div>
                </a>
                <?php endforeach; ?>
            </div>

        </section>
        <!-- end KPI section -->

        <!-- EXPORT BUTTON — opens modal to choose data category -->
        <div style="display:flex;justify-content:flex-end;margin-bottom:20px;">
            <button class="btn-primary" onclick="document.getElementById('exportOverlay').classList.add('open')">
                <i class="fa-solid fa-download"></i> Download / Export
            </button>
        </div>

        <!-- Export Modal -->
        <div class="export-overlay" id="exportOverlay">
            <div class="export-modal">
                <button class="export-close"
                    onclick="document.getElementById('exportOverlay').classList.remove('open')">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <h2>Download / Export Data</h2>
                <p>Download everything at once, or pick a specific category.</p>

                <!-- Download All -->
                <a href="export.php?type=all" class="export-option export-option-all">
                    <i class="fa-solid fa-file-zipper icon"></i>
                    <div>
                        <div class="export-label">Download All</div>
                        <div class="export-desc">Users, programs, participants, attendance &amp; audit logs — all in one ZIP</div>
                    </div>
                </a>

                <div class="export-divider">or choose a category</div>

                <!-- Individual categories -->
                <a href="export.php?type=users" class="export-option">
                    <i class="fa-solid fa-users icon"></i>
                    <div>
                        <div class="export-label">Users List</div>
                        <div class="export-desc">All registered users with roles and dates</div>
                    </div>
                </a>
                <a href="export.php?type=programs" class="export-option">
                    <i class="fa-solid fa-shapes icon"></i>
                    <div>
                        <div class="export-label">Programs List</div>
                        <div class="export-desc">All programs with status and participant counts</div>
                    </div>
                </a>
                <a href="export.php?type=participants" class="export-option">
                    <i class="fa-solid fa-person icon"></i>
                    <div>
                        <div class="export-label">Participants / Student Records</div>
                        <div class="export-desc">Participant details and program enrollments</div>
                    </div>
                </a>
                <a href="export.php?type=attendance" class="export-option">
                    <i class="fa-solid fa-calendar-day icon"></i>
                    <div>
                        <div class="export-label">Attendance Records</div>
                        <div class="export-desc">Daily attendance logs with present/absent status</div>
                    </div>
                </a>
                <a href="export.php?type=logs" class="export-option">
                    <i class="fa-solid fa-clipboard-list icon"></i>
                    <div>
                        <div class="export-label">Audit Logs</div>
                        <div class="export-desc">System activity and change history</div>
                    </div>
                </a>
            </div>
        </div>

        <!-- STATISTICS CHARTS SECTION - Row: Programs by Status · Users by Role · Monthly Registrations -->
        <section class="charts-section">

            <!-- Doughnut — Programs by Status -->
            <div class="chart-card">
                <h3>Programs by Status</h3>
                <p>Distribution across all program states</p>
                <div class="chart-canvas-wrap">
                    <canvas id="chartProgStatus"></canvas>
                </div>
            </div>

            <!-- Bar — Users by Role -->
            <div class="chart-card">
                <h3>Users by Role</h3>
                <p>Breakdown of registered user types</p>
                <div class="chart-canvas-wrap">
                    <canvas id="chartUserRole"></canvas>
                </div>
            </div>

            <!-- Line — Monthly Registrations -->
            <div class="chart-card">
                <h3>Monthly Registrations</h3>
                <p>New user sign-ups over the last 6 months</p>
                <div class="chart-canvas-wrap">
                    <canvas id="chartMonthly"></canvas>
                </div>
            </div>

        </section>
        <!-- end charts section -->

        <div class="two-col">

            <!-- Recent Users Table -->
            <div class="table-card">
                <div class="table-card-header">
                    <div>
                        <h2 style="font-size:1.05rem;color:#334e5e;font-weight:700;">
                            <?= $search !== '' ? 'Search Results' : 'Recently Registered Users' ?>
                        </h2>
                    </div>
                    <a href="admin_user_management.php" class="btn-secondary">View All</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($recent_users)): ?>
                        <tr><td colspan="3" style="text-align:center;color:#a0aec0;padding:30px;">No users found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_users as $u): ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <div class="user-initials">
                                        <?= strtoupper(substr($u['first_name'],0,1).substr($u['last_name'],0,1)) ?>
                                    </div>
                                    <div class="user-cell-info">
                                        <span class="uname"><?= e($u['first_name'].' '.$u['last_name']) ?></span>
                                        <span class="uemail"><?= e($u['email']) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><span class="pill pill-<?= $u['role'] ?>"><?= ucfirst(e($u['role'])) ?></span></td>
                            <td style="font-size:0.82rem;color:#a0aec0;"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Quick Links + Activity -->
            <div style="display:flex;flex-direction:column;gap:20px;">
                <div class="table-card">
                    <h3 style="font-size:0.95rem;font-weight:700;color:#334e5e;margin-bottom:14px;">Quick Actions</h3>
                    <a href="admin_user_management.php?action=add" class="quick-link">
                        <i class="fa-solid fa-user-plus"></i> Add New User
                    </a>
                    <a href="admin_program.php?action=create" class="quick-link">
                        <i class="fa-solid fa-plus"></i> Create Program
                    </a>
                    <a href="admin_report.php" class="quick-link">
                        <i class="fa-solid fa-chart-bar"></i> View Reports
                    </a>
                    <a href="admin_audit_log.php" class="quick-link">
                        <i class="fa-solid fa-clipboard-list"></i> Audit Logs
                    </a>
                </div>

                <?php if (!empty($recent_logs)): ?>
                <div class="table-card">
                    <h3 style="font-size:0.95rem;font-weight:700;color:#334e5e;margin-bottom:14px;">Recent Activity</h3>
                    <?php foreach ($recent_logs as $log): ?>
                    <div class="activity-item">
                        <div class="activity-dot"></div>
                        <div>
                            <div class="activity-text">
                                <strong><?= e($log['first_name'].' '.$log['last_name']) ?></strong>
                                <?= e($log['action']) ?> in <em><?= e($log['table_name']) ?></em>
                            </div>
                            <div class="activity-time"><?= date('M j, g:i A', strtotime($log['created_at'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>

    </main>
</div>

<script>
(function () {
    const input = document.getElementById('globalSearch');
    const btn   = document.getElementById('searchClearBtn');
    const form  = document.getElementById('searchForm');
    if (!input || !btn) return;
    function toggle() { btn.style.display = input.value.length > 0 ? 'block' : 'none'; }
    toggle();
    input.addEventListener('input', toggle);
    let timer;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { form.submit(); }, 500);
    });
    btn.addEventListener('click', function () {
        input.value = '';
        const url = new URL(window.location.href);
        url.searchParams.delete('search');
        window.location.href = url.toString();
    });
})();

/* ── STATISTICS CHARTS ── */
(function () {
    // Shared palette consistent with dashboard colours
    const PALETTE = ['#3182ce','#38a169','#dd6b20','#805ad5','#e53e3e','#4a5568'];
    const gridColor = '#f1f5f9';
    const fontColor = '#718096';

    // 1. Doughnut — Programs by Status
    const progCtx = document.getElementById('chartProgStatus');
    if (progCtx) {
        new Chart(progCtx, {
            type: 'doughnut',
            data: {
                labels: <?= $chart_prog_labels ?>,
                datasets: [{
                    data: <?= $chart_prog_data ?>,
                    backgroundColor: PALETTE,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: { position:'bottom', labels:{ font:{size:11}, color:fontColor, padding:12 } }
                }
            }
        });
    }

    // 2. Bar — Users by Role
    const roleCtx = document.getElementById('chartUserRole');
    if (roleCtx) {
        new Chart(roleCtx, {
            type: 'bar',
            data: {
                labels: <?= $chart_role_labels ?>,
                datasets: [{
                    label: 'Users',
                    data: <?= $chart_role_data ?>,
                    backgroundColor: PALETTE,
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend:{ display:false } },
                scales: {
                    x: { grid:{ display:false }, ticks:{ color:fontColor, font:{size:11} } },
                    y: { grid:{ color:gridColor }, ticks:{ color:fontColor, font:{size:11}, stepSize:1 }, beginAtZero:true }
                }
            }
        });
    }

    // 3. Line — Monthly Registrations
    const moCtx = document.getElementById('chartMonthly');
    if (moCtx) {
        new Chart(moCtx, {
            type: 'line',
            data: {
                labels: <?= $chart_mo_labels ?>,
                datasets: [{
                    label: 'New Users',
                    data: <?= $chart_mo_data ?>,
                    borderColor: '#334e5e',
                    backgroundColor: 'rgba(51,78,94,.08)',
                    borderWidth: 2.5,
                    pointBackgroundColor: '#334e5e',
                    pointRadius: 4,
                    fill: true,
                    tension: 0.35
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend:{ display:false } },
                scales: {
                    x: { grid:{ display:false }, ticks:{ color:fontColor, font:{size:11} } },
                    y: { grid:{ color:gridColor }, ticks:{ color:fontColor, font:{size:11}, stepSize:1 }, beginAtZero:true }
                }
            }
        });
    }

    // Close export modal on overlay background click
    document.getElementById('exportOverlay').addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
})();
</script>
</body>
</html>


