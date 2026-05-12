<?php
// staff_dashboard.php

/* ── AUTH ── */
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Location: staff_login.php');
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
$search = trim($_GET['search'] ?? '');

$active_programs = (int) $db->query(
    "SELECT COUNT(*) FROM programs WHERE status IN ('upcoming','ongoing')"
)->fetch_row()[0];

$total_participants = (int) $db->query(
    "SELECT COUNT(*) FROM participants"
)->fetch_row()[0];

$today = date('Y-m-d');

$stmt = $db->prepare("SELECT COUNT(*) FROM attendance WHERE attendance_date = ? AND status = 'present'");
$stmt->bind_param('s', $today);
$stmt->execute();
$attendance_today = (int) $stmt->get_result()->fetch_row()[0];
$stmt->close();

$stmt = $db->prepare("SELECT COUNT(*) FROM attendance WHERE attendance_date = ?");
$stmt->bind_param('s', $today);
$stmt->execute();
$total_today = (int) $stmt->get_result()->fetch_row()[0];
$stmt->close();
$today_rate = $total_today > 0 ? round(($attendance_today / $total_today) * 100) : 0;

/* ── Recent registrations (filtered by search) ── */
if ($search !== '') {
    $like = '%' . $search . '%';
    $recent_stmt = $db->prepare(
        "SELECT u.first_name, u.last_name, u.email,
                pr.status, pr.participation_date, pg.program_name
         FROM participation pr
         JOIN participants p ON p.participant_id = pr.participant_id
         JOIN users u        ON u.user_id = p.user_id
         JOIN programs pg    ON pg.program_id = pr.program_id
         WHERE u.first_name LIKE ? OR u.last_name LIKE ?
            OR u.email LIKE ? OR pg.program_name LIKE ?
            OR CONCAT(u.first_name,' ',u.last_name) LIKE ?
         ORDER BY pr.created_at DESC LIMIT 20"
    );
    $recent_stmt->bind_param('sssss', $like, $like, $like, $like, $like);
    $recent_stmt->execute();
    $recent = $recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recent_stmt->close();
} else {
    $recent = $db->query(
        "SELECT u.first_name, u.last_name, u.email,
                pr.status, pr.participation_date, pg.program_name
         FROM participation pr
         JOIN participants p ON p.participant_id = pr.participant_id
         JOIN users u        ON u.user_id = p.user_id
         JOIN programs pg    ON pg.program_id = pr.program_id
         ORDER BY pr.created_at DESC LIMIT 5"
    )->fetch_all(MYSQLI_ASSOC);
}

$first_name = e($_SESSION['first_name'] ?? '');
$last_name  = e($_SESSION['last_name']  ?? '');

/* ── CHART DATA ── */
// Participants per program — top 5 (bar chart)
$per_prog_res = $db->query(
    "SELECT pg.program_name, COUNT(pr.participation_id) AS cnt
     FROM programs pg
     LEFT JOIN participation pr ON pr.program_id = pg.program_id
     GROUP BY pg.program_id, pg.program_name
     ORDER BY cnt DESC LIMIT 5"
)->fetch_all(MYSQLI_ASSOC);
$chart_prog_labels = json_encode(array_column($per_prog_res, 'program_name'));
$chart_prog_data   = json_encode(array_map('intval', array_column($per_prog_res, 'cnt')));

// Attendance breakdown today — present vs absent (doughnut)
$att_absent_today = $total_today - $attendance_today;
$chart_att_labels = json_encode(['Present', 'Absent']);
$chart_att_data   = json_encode([$attendance_today, $att_absent_today > 0 ? $att_absent_today : 0]);

// Participation by status (doughnut)
$part_status_res = $db->query(
    "SELECT status, COUNT(*) AS cnt FROM participation GROUP BY status ORDER BY cnt DESC"
)->fetch_all(MYSQLI_ASSOC);
$chart_pstat_labels = json_encode(array_map('ucfirst', array_column($part_status_res, 'status')));
$chart_pstat_data   = json_encode(array_map('intval', array_column($part_status_res, 'cnt')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Chart.js for dashboard statistics -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        /* ── RESET ── */
        * { margin:0; padding:0; box-sizing:border-box;
            font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        body { background:#f8fafc; display:flex; height:100vh; overflow:hidden; }

        /* ── SIDEBAR ── */
        .nav-sidebar { width:260px; background:white; border-right:1px solid #e2e8f0;
            padding:25px; display:flex; flex-direction:column; flex-shrink:0; }
        .nav-logo { color:#334e5e; font-weight:700; font-size:1.3rem;
            margin-bottom:40px; letter-spacing:0.5px; }
        .nav-item { display:flex; align-items:center; gap:15px; padding:12px 15px;
            color:#718096; text-decoration:none; border-radius:10px; margin-bottom:8px;
            transition:0.2s; font-size:0.95rem; font-weight:500; }
        .nav-item:hover { background:#f1f5f9; color:#334e5e; }
        .nav-item.active { background:#f1f5f9; color:#334e5e;
            border-right:4px solid #334e5e; border-radius:10px 0 0 10px; font-weight:600; }
        .nav-footer { margin-top:auto; padding-top:20px; border-top:1px solid #f1f5f9; }

        /* ── MAIN WRAPPER ── */
        .main-wrapper { flex:1; display:flex; flex-direction:column; overflow:hidden; }
        .top-header { padding:15px 40px; background:white; border-bottom:1px solid #e2e8f0;
            display:flex; justify-content:space-between; align-items:center; flex-shrink:0; }
        .search-container { position: relative; width: 350px; }
        .search-container i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #a0aec0; }
        .search-container input { width: 100%; padding: 10px 15px 10px 45px; background: #f1f5f9; border: none; border-radius: 10px; outline-color: #334e5e; font-size: 0.9rem; }
        .search-clear-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #a0aec0;
            font-size: 0.75rem;
            display: none;
            padding: 2px 4px;
            border-radius: 50%;
            line-height: 1;
            transition: color 0.15s;
        }
        .search-clear-btn:hover { color: #718096; }
        .header-actions { display:flex; align-items:center; gap:20px; color:#718096; }
        .staff-name-tag { display:flex; flex-direction:column; align-items:flex-end; line-height:1.2; }
        .staff-name-tag .sname { color:#1a202c; font-weight:700; font-size:0.9rem; }
        .staff-name-tag .srole { color:#a0aec0; font-size:0.75rem;
            text-transform:uppercase; letter-spacing:0.5px; }
        .content-area { padding:40px; overflow-y:auto; flex:1; }

        /* ── ALERTS ── */
        .alert { padding:12px 20px; border-radius:10px; font-size:0.9rem; margin-bottom:20px; }
        .alert-success { background:#f0fff4; border:1px solid #9ae6b4; color:#276749; }
        .alert-error   { background:#fff5f5; border:1px solid #feb2b2; color:#c53030; }
        .alert-warning { background:#fffbeb; border:1px solid #f6e05e; color:#744210; }

        /* ── BUTTONS ── */
        .btn-primary { background:#334e5e; color:white; padding:11px 22px;
            border-radius:10px; border:none; font-weight:600; cursor:pointer;
            display:inline-flex; align-items:center; gap:8px; transition:0.2s;
            font-size:0.9rem; text-decoration:none; font-family:inherit; }
        .btn-primary:hover { background:#2a3f4d; }
        .btn-secondary { background:#f1f5f9; color:#4a5568; padding:11px 22px;
            border-radius:10px; border:1px solid #e2e8f0; font-weight:600; cursor:pointer;
            display:inline-flex; align-items:center; gap:8px; transition:0.2s;
            font-size:0.9rem; text-decoration:none; font-family:inherit; }
        .btn-secondary:hover { background:#e2e8f0; }
        .btn-danger { background:#fff5f5; color:#e53e3e; padding:11px 22px;
            border-radius:10px; border:1px solid #fed7d7; font-weight:600; cursor:pointer;
            display:inline-flex; align-items:center; gap:8px; transition:0.2s;
            font-size:0.9rem; font-family:inherit; }
        .btn-danger:hover { background:#e53e3e; color:white; border-color:#e53e3e; }

        /* ── TABLE ── */
        .table-card { background:white; border-radius:15px;
            border:1px solid #e2e8f0; padding:25px; }
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
        .user-initials { width:38px; height:38px; border-radius:50%; background:#edf2f7;
            display:flex; align-items:center; justify-content:center;
            color:#718096; font-weight:700; font-size:0.78rem; flex-shrink:0; }
        .user-cell-info .uname { font-weight:700; color:#1a202c; display:block; font-size:0.92rem; }
        .user-cell-info .uemail { font-size:0.78rem; color:#a0aec0; }

        /* ── STATUS PILLS ── */
        .pill { display:inline-block; padding:4px 12px; border-radius:20px;
            font-size:0.75rem; font-weight:700; text-transform:capitalize; }
        .pill-registered { background:#ebf8ff; color:#2b6cb0; }
        .pill-completed  { background:#f0fff4; color:#276749; }
        .pill-cancelled  { background:#f7fafc; color:#718096; }
        .pill-upcoming   { background:#ebf8ff; color:#2b6cb0; }
        .pill-ongoing    { background:#f0fff4; color:#276749; }

        /* ── KPI SECTION ── */
        .kpi-section { margin-bottom:30px; display:flex; flex-direction:column; gap:16px; }

        /* Single row — 3-column grid for staff (3 KPI cards) */
        .kpi-row-top {
            display:grid;
            grid-template-columns: repeat(3, 1fr);
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

        /* Progress bar (kept for attendance card) */
        .kpi-bar-wrap { width:80px; height:6px; background:#e2e8f0;
            border-radius:10px; margin-top:8px; position:relative; }
        .kpi-bar-fill { position:absolute; top:0; left:0; height:100%;
            background:#334e5e; border-radius:10px; }

        /* ── CHARTS SECTION ── */
        .charts-section { display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-bottom:30px; }
        .chart-card { background:white; border-radius:15px; border:1px solid #e2e8f0;
            padding:22px; display:flex; flex-direction:column; }
        .chart-card h3 { font-size:0.9rem; font-weight:700; color:#334e5e; margin-bottom:4px; }
        .chart-card p  { font-size:0.75rem; color:#a0aec0; margin-bottom:16px; }
        .chart-canvas-wrap { flex:1; position:relative; min-height:180px; }

        /* ── EXPORT MODAL ── */
        .export-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.35);
            z-index:999; align-items:center; justify-content:center; }
        .export-overlay.open { display:flex; }
        .export-modal { background:white; border-radius:18px; padding:30px 32px;
            width:400px; max-width:95vw; box-shadow:0 20px 50px rgba(0,0,0,.15); }
        .export-modal h2 { font-size:1.1rem; color:#1a202c; font-weight:700; margin-bottom:6px; }
        .export-modal p  { font-size:0.85rem; color:#718096; margin-bottom:20px; }
        .export-option { display:flex; align-items:center; gap:14px; padding:13px 15px;
            border:1px solid #e2e8f0; border-radius:12px; margin-bottom:10px;
            cursor:pointer; transition:0.2s; text-decoration:none; color:inherit; }
        .export-option:hover { background:#f8fafc; border-color:#cbd5e0; }
        .export-option i.icon { width:34px; height:34px; border-radius:9px; background:#f1f5f9;
            display:flex; align-items:center; justify-content:center;
            font-size:0.9rem; color:#334e5e; flex-shrink:0; }
        .export-option .export-label { font-weight:600; font-size:0.88rem; color:#1a202c; }
        .export-option .export-desc  { font-size:0.73rem; color:#a0aec0; margin-top:1px; }
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

<!-- ── Sidebar ── -->
<nav class="nav-sidebar">
    <div class="nav-logo">Bayanihan</div>
    <a href="staff_dashboard.php"     class="nav-item active"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="staff_program.php"       class="nav-item"><i class="fa-solid fa-shapes"></i> Programs</a>
    <a href="staff_participant.php"   class="nav-item"><i class="fa-solid fa-users"></i> Participants</a>
    <a href="staff_participation.php" class="nav-item"><i class="fa-solid fa-handshake-angle"></i> Participation</a>
    <a href="staff_attendance.php"    class="nav-item"><i class="fa-solid fa-calendar-check"></i> Attendance</a>
    <a href="staff_report.php"        class="nav-item"><i class="fa-solid fa-chart-bar"></i> Reports</a>
    <div class="nav-footer">
        <a href="logout.php" class="nav-item"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</nav>

<!-- ── Main ── -->
<div class="main-wrapper">
    <header class="top-header">
<form id="searchForm" method="GET" style="display:contents;">
        <div class="header-search-wrap" style="position:relative;">
            <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:15px;top:50%;transform:translateY(-50%);color:#a0aec0;pointer-events:none;z-index:1;"></i>
            <input id="globalSearch" type="text" name="search" placeholder="Search participants, programs..."
                   value="<?= htmlspecialchars($search) ?>"
                   style="width:100%;padding:10px 38px 10px 45px;background:#f1f5f9;border:none;border-radius:10px;outline-color:#334e5e;font-size:0.9rem;font-family:inherit;">
            <button type="button" id="searchClearBtn" class="search-clear-btn">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </form>
        <div class="header-actions">
            <i class="fa-regular fa-bell"></i>
            <i class="fa-regular fa-circle-question"></i>
            <div class="staff-name-tag">
                <span class="sname"><?= $first_name . ' ' . $last_name ?></span>
                <span class="srole">Staff</span>
            </div>
        </div>
    </header>

    <main class="content-area">

        <!-- Greeting -->
        <section style="margin-bottom:35px;">
            <h1 style="font-size:2rem;color:#1a202c;font-weight:700;">
                <?= timeGreeting() ?>, <?= $first_name ?>!
            </h1>
            <p style="color:#718096;margin-top:5px;">Here's what's happening in your community today.</p>
        </section>

        <!-- KPI Cards -->
        <section class="kpi-section">
            <div class="kpi-row-top">
            <?php
            $kpi_cards = [
                ['val' => number_format($active_programs),    'label' => 'Active Programs',
                 'icon' => 'fa-rocket',       'bg' => '#ebf8ff', 'ic' => '#3182ce'],
                ['val' => number_format($total_participants), 'label' => 'Total Participants',
                 'icon' => 'fa-users',        'bg' => '#f0fff4', 'ic' => '#38a169'],
                ['val' => number_format($attendance_today),   'label' => 'Attendance Today',
                 'icon' => 'fa-calendar-day', 'bg' => '#f1f5f9', 'ic' => '#4a5568',
                 'bar' => $today_rate],
            ];
            foreach ($kpi_cards as $c): ?>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:<?= $c['bg'] ?>;color:<?= $c['ic'] ?>;">
                    <i class="fa-solid <?= $c['icon'] ?>"></i>
                </div>
                <div class="kpi-body">
                    <div class="kpi-val"><?= $c['val'] ?></div>
                    <div class="kpi-label"><?= $c['label'] ?></div>
                    <?php if (isset($c['bar'])): ?>
                    <div class="kpi-bar-wrap">
                        <div class="kpi-bar-fill" style="width:<?= $c['bar'] ?>%;"></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </section>

        <!-- ══════════════════════════════════════════════════
             EXPORT BUTTON — opens modal to choose data category
        ════════════════════════════════════════════════════ -->
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
                        <div class="export-desc">Programs, participants, attendance &amp; reports — all in one ZIP</div>
                    </div>
                </a>

                <div class="export-divider">or choose a category</div>

                <!-- Individual categories -->
                <a href="export.php?type=participants" class="export-option">
                    <i class="fa-solid fa-users icon"></i>
                    <div>
                        <div class="export-label">Participants / Student Records</div>
                        <div class="export-desc">Participant details and program enrollments</div>
                    </div>
                </a>
                <a href="export.php?type=programs" class="export-option">
                    <i class="fa-solid fa-shapes icon"></i>
                    <div>
                        <div class="export-label">Programs List</div>
                        <div class="export-desc">All programs with status and participant counts</div>
                    </div>
                </a>
                <a href="export.php?type=attendance" class="export-option">
                    <i class="fa-solid fa-calendar-day icon"></i>
                    <div>
                        <div class="export-label">Attendance Records</div>
                        <div class="export-desc">Daily attendance logs with present/absent status</div>
                    </div>
                </a>
                <a href="export.php?type=reports" class="export-option">
                    <i class="fa-solid fa-chart-bar icon"></i>
                    <div>
                        <div class="export-label">Reports</div>
                        <div class="export-desc">Summary participation and attendance report</div>
                    </div>
                </a>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             STATISTICS CHARTS SECTION
             Row: Participants per Program · Attendance Today · Participation Status
        ════════════════════════════════════════════════════ -->
        <section class="charts-section">

            <!-- Bar — Top 5 Programs by Participants -->
            <div class="chart-card">
                <h3>Participants per Program</h3>
                <p>Top 5 programs by enrollment</p>
                <div class="chart-canvas-wrap">
                    <canvas id="chartPerProg"></canvas>
                </div>
            </div>

            <!-- Doughnut — Attendance Today -->
            <div class="chart-card">
                <h3>Today's Attendance</h3>
                <p>Present vs. absent breakdown for today</p>
                <div class="chart-canvas-wrap">
                    <canvas id="chartAttToday"></canvas>
                </div>
            </div>

            <!-- Doughnut — Participation Status -->
            <div class="chart-card">
                <h3>Participation Status</h3>
                <p>Overall participation status distribution</p>
                <div class="chart-canvas-wrap">
                    <canvas id="chartPartStat"></canvas>
                </div>
            </div>

        </section>
        <!-- end charts section -->

        <!-- Recent Registrations -->
        <div class="table-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                <div>
                    <h2 style="font-size:1.1rem;color:#334e5e;font-weight:700;">
                        <?= $search !== '' ? 'Search Results' : 'Recent Registrations' ?>
                    </h2>
                </div>
                <a href="staff_participation.php" class="btn-secondary" style="font-size:0.8rem;padding:8px 16px;">
                    View All
                </a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Participant</th>
                        <th>Program</th>
                        <th>Date Joined</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($recent)): ?>
                    <tr><td colspan="4" style="text-align:center;color:#a0aec0;padding:30px;">No registrations yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recent as $r): ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <div class="user-initials">
                                    <?= strtoupper(substr($r['first_name'],0,1).substr($r['last_name'],0,1)) ?>
                                </div>
                                <div class="user-cell-info">
                                    <span class="uname"><?= e($r['first_name'].' '.$r['last_name']) ?></span>
                                    <span class="uemail"><?= e($r['email']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= e($r['program_name']) ?></td>
                        <td><?= date('M j, Y', strtotime($r['participation_date'])) ?></td>
                        <td><span class="pill pill-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>
</div><!-- /.main-wrapper -->

<script>
(function () {
    const input = document.getElementById('globalSearch');
    const btn   = document.getElementById('searchClearBtn');
    const form  = document.getElementById('searchForm');
    if (!input || !btn || !form) return;

    function toggle() {
        btn.style.display = input.value.length > 0 ? 'block' : 'none';
    }
    toggle();
    input.addEventListener('input', toggle);

    /* Submit on Enter */
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); form.submit(); }
    });

    /* Auto-submit after user stops typing (500 ms debounce) */
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
    const PALETTE   = ['#3182ce','#38a169','#dd6b20','#805ad5','#e53e3e','#4a5568'];
    const gridColor = '#f1f5f9';
    const fontColor = '#718096';

    // 1. Bar — Participants per Program (top 5)
    const progCtx = document.getElementById('chartPerProg');
    if (progCtx) {
        new Chart(progCtx, {
            type: 'bar',
            data: {
                labels: <?= $chart_prog_labels ?>,
                datasets: [{
                    label: 'Participants',
                    data: <?= $chart_prog_data ?>,
                    backgroundColor: PALETTE,
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend:{ display:false } },
                scales: {
                    x: { grid:{ display:false }, ticks:{ color:fontColor, font:{size:10}, maxRotation:30 } },
                    y: { grid:{ color:gridColor }, ticks:{ color:fontColor, font:{size:11}, stepSize:1 }, beginAtZero:true }
                }
            }
        });
    }

    // 2. Doughnut — Today's Attendance
    const attCtx = document.getElementById('chartAttToday');
    if (attCtx) {
        new Chart(attCtx, {
            type: 'doughnut',
            data: {
                labels: <?= $chart_att_labels ?>,
                datasets: [{
                    data: <?= $chart_att_data ?>,
                    backgroundColor: ['#38a169','#e2e8f0'],
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

    // 3. Doughnut — Participation Status
    const pstatCtx = document.getElementById('chartPartStat');
    if (pstatCtx) {
        new Chart(pstatCtx, {
            type: 'doughnut',
            data: {
                labels: <?= $chart_pstat_labels ?>,
                datasets: [{
                    data: <?= $chart_pstat_data ?>,
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

    // Close export modal on background click
    document.getElementById('exportOverlay').addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
})();
</script>
</body>
</html>