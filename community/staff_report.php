<?php
// staff_report.php — Administrative Analytics Report
// Requires: users, programs, participants, participation, attendance tables

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Location: staff_login.php');
    exit();
}

require_once 'database.php';

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$db = getDB();
$generated_at = date('F j, Y \a\t g:i A');
$staff_name   = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

// ══════════════════════════════════════════════════════════════════
// SECTION 1 — Platform-Wide KPIs (COUNT, AVG, MAX, MIN)
// ══════════════════════════════════════════════════════════════════
$kpi = $db->query("
    SELECT
        (SELECT COUNT(*) FROM users WHERE role = 'participant')                  AS total_users,
        (SELECT COUNT(*) FROM users WHERE role = 'participant')                  AS total_participant_users,
        (SELECT COUNT(*) FROM users WHERE role = 'staff')                        AS total_staff_users,
        (SELECT COUNT(*) FROM programs)                                          AS total_programs,
        (SELECT COUNT(*) FROM programs WHERE status = 'ongoing')                 AS ongoing_programs,
        (SELECT COUNT(*) FROM programs WHERE status = 'upcoming')                AS upcoming_programs,
        (SELECT COUNT(*) FROM programs WHERE status = 'completed')               AS completed_programs,
        (SELECT COUNT(*) FROM participants)                                       AS total_participants,
        (SELECT COUNT(*) FROM participation)                                      AS total_participations,
        (SELECT COUNT(*) FROM attendance)                                         AS total_sessions,
        (SELECT COUNT(*) FROM attendance WHERE status = 'present')               AS total_present,
        (SELECT COUNT(*) FROM attendance WHERE status = 'absent')                AS total_absent,
        (SELECT ROUND(AVG(cnt),1) FROM (
            SELECT COUNT(*) AS cnt FROM participation GROUP BY program_id
        ) sub_avg)                                                               AS avg_participants_per_program,
        (SELECT MAX(cnt) FROM (
            SELECT COUNT(*) AS cnt FROM participation GROUP BY program_id
        ) sub_max)                                                               AS max_participants_in_program,
        (SELECT MIN(cnt) FROM (
            SELECT COUNT(*) AS cnt FROM participation GROUP BY program_id
        ) sub_min)                                                               AS min_participants_in_program
")->fetch_assoc();

$overall_rate = $kpi['total_sessions'] > 0
    ? round(($kpi['total_present'] / $kpi['total_sessions']) * 100, 1)
    : 0;

// ══════════════════════════════════════════════════════════════════
// SECTION 2 — Per-Program Summary
// JOIN: programs → participation → attendance → users/participants
// Subquery: active participant count per program
// ══════════════════════════════════════════════════════════════════
$program_summary = $db->query("
    SELECT
        pg.program_id,
        pg.program_name,
        pg.location,
        pg.status                                                          AS program_status,
        pg.start_date,
        pg.end_date,
        COUNT(DISTINCT par.participation_id)                               AS total_joined,
        -- Subquery 1: active (registered) participants per program
        (SELECT COUNT(*) FROM participation p2
         WHERE p2.program_id = pg.program_id AND p2.status = 'registered') AS active_participants,
        -- Subquery 2: completed participants per program
        (SELECT COUNT(*) FROM participation p3
         WHERE p3.program_id = pg.program_id AND p3.status = 'completed') AS completed_participants,
        COUNT(DISTINCT a.attendance_id)                                    AS total_sessions,
        SUM(a.status = 'present')                                          AS present_count,
        SUM(a.status = 'absent')                                           AS absent_count,
        CASE
            WHEN COUNT(DISTINCT a.attendance_id) > 0
            THEN ROUND((SUM(a.status = 'present') / COUNT(DISTINCT a.attendance_id)) * 100, 1)
            ELSE 0
        END                                                                AS attendance_rate
    FROM programs pg
    LEFT JOIN participation par ON par.program_id  = pg.program_id
    LEFT JOIN attendance a      ON a.participation_id = par.participation_id
    GROUP BY pg.program_id, pg.program_name, pg.location,
             pg.status, pg.start_date, pg.end_date
    ORDER BY total_joined DESC
")->fetch_all(MYSQLI_ASSOC);

// ══════════════════════════════════════════════════════════════════
// SECTION 3 — Programs with ABOVE-AVERAGE Participation
// Subquery 3: filter using avg subquery
// ══════════════════════════════════════════════════════════════════
$above_avg_programs = $db->query("
    SELECT
        pg.program_name,
        pg.status,
        COUNT(par.participation_id) AS participant_count
    FROM programs pg
    JOIN participation par ON par.program_id = pg.program_id
    GROUP BY pg.program_id, pg.program_name, pg.status
    HAVING COUNT(par.participation_id) > (
        SELECT AVG(cnt) FROM (
            SELECT COUNT(*) AS cnt FROM participation GROUP BY program_id
        ) sub
    )
    ORDER BY participant_count DESC
")->fetch_all(MYSQLI_ASSOC);

// ══════════════════════════════════════════════════════════════════
// SECTION 4 — Top 10 Most Engaged Participants
// JOIN: users → participants → participation → attendance
// Subquery 4: highest engagement (present sessions)
// ══════════════════════════════════════════════════════════════════
$top_participants = $db->query("
    SELECT
        CONCAT(u.first_name, ' ', u.last_name)         AS full_name,
        u.email,
        COUNT(DISTINCT par.program_id)                  AS programs_joined,
        COUNT(DISTINCT a.attendance_id)                 AS total_sessions,
        SUM(a.status = 'present')                       AS sessions_present,
        CASE
            WHEN COUNT(DISTINCT a.attendance_id) > 0
            THEN ROUND((SUM(a.status = 'present') / COUNT(DISTINCT a.attendance_id)) * 100, 1)
            ELSE 0
        END                                             AS attendance_rate,
        -- Subquery: rank by present sessions vs platform max
        (SELECT MAX(present_cnt) FROM (
            SELECT SUM(a2.status = 'present') AS present_cnt
            FROM attendance a2
            JOIN participation par2 ON par2.participation_id = a2.participation_id
            GROUP BY par2.participant_id
        ) eng)                                          AS platform_max_present
    FROM users u
    JOIN participants pt   ON pt.user_id        = u.user_id
    JOIN participation par ON par.participant_id = pt.participant_id
    LEFT JOIN attendance a ON a.participation_id = par.participation_id
    WHERE u.role = 'participant'
    GROUP BY u.user_id, u.first_name, u.last_name, u.email
    ORDER BY sessions_present DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// ══════════════════════════════════════════════════════════════════
// SECTION 5 — CTE: Monthly Attendance Summary
// Computes monthly attendance rates grouped by month + program
// ══════════════════════════════════════════════════════════════════
$monthly_summary = $db->query("
    WITH monthly_attendance AS (
        SELECT
            DATE_FORMAT(a.attendance_date, '%Y-%m')    AS yr_month,
            DATE_FORMAT(a.attendance_date, '%b %Y')    AS month_label,
            pg.program_name,
            COUNT(a.attendance_id)                     AS total_records,
            SUM(a.status = 'present')                  AS present_count,
            SUM(a.status = 'absent')                   AS absent_count,
            ROUND(
                (SUM(a.status = 'present') / COUNT(a.attendance_id)) * 100
            , 1)                                       AS monthly_rate
        FROM attendance a
        JOIN participation par ON par.participation_id = a.participation_id
        JOIN programs pg       ON pg.program_id        = par.program_id
        GROUP BY yr_month, month_label, pg.program_id, pg.program_name
    )
    SELECT *
    FROM monthly_attendance
    ORDER BY yr_month DESC, monthly_rate DESC
    LIMIT 60
")->fetch_all(MYSQLI_ASSOC);

// Group monthly by month label for display
$monthly_grouped = [];
foreach ($monthly_summary as $row) {
    $monthly_grouped[$row['month_label']][] = $row;
}

// ══════════════════════════════════════════════════════════════════
// SECTION 6 — Attendance MAX / MIN per program (rankings)
// ══════════════════════════════════════════════════════════════════
$att_extremes = $db->query("
    SELECT
        pg.program_name,
        MAX(month_totals.present_count) AS highest_monthly_present,
        MIN(month_totals.present_count) AS lowest_monthly_present,
        AVG(month_totals.present_count) AS avg_monthly_present
    FROM programs pg
    JOIN (
        SELECT
            par.program_id,
            DATE_FORMAT(a.attendance_date, '%Y-%m') AS yr_month,
            SUM(a.status = 'present')               AS present_count
        FROM attendance a
        JOIN participation par ON par.participation_id = a.participation_id
        GROUP BY par.program_id, yr_month
    ) month_totals ON month_totals.program_id = pg.program_id
    GROUP BY pg.program_id, pg.program_name
    ORDER BY highest_monthly_present DESC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - Analytics Report</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ── SCREEN BASE ── */
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #f8fafc;
            color: #1a202c;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* ── SIDEBAR ── */
        .nav-sidebar {
            width: 260px; background: white; border-right: 1px solid #e2e8f0;
            padding: 25px; display: flex; flex-direction: column; flex-shrink: 0;
        }
        .nav-logo { color:#334e5e; font-weight:700; font-size:1.3rem; margin-bottom:40px; letter-spacing:0.5px; }
        .nav-item {
            display:flex; align-items:center; gap:15px; padding:12px 15px;
            color:#718096; text-decoration:none; border-radius:10px; margin-bottom:8px;
            transition:0.2s; font-size:0.95rem; font-weight:500;
        }
        .nav-item:hover { background:#f1f5f9; color:#334e5e; }
        .nav-item.active {
            background:#f1f5f9; color:#334e5e;
            border-right:4px solid #334e5e; border-radius:10px 0 0 10px; font-weight:600;
        }
        .nav-footer { margin-top:auto; padding-top:20px; border-top:1px solid #f1f5f9; }

        /* ── MAIN WRAPPER ── */
        .main-wrapper { flex:1; display:flex; flex-direction:column; overflow:hidden; }
        .top-header {
            padding:15px 40px; background:white; border-bottom:1px solid #e2e8f0;
            display:flex; justify-content:space-between; align-items:center; flex-shrink:0;
        }
        .header-actions { display:flex; align-items:center; gap:15px; color:#718096; }
        .staff-name-tag { display:flex; flex-direction:column; align-items:flex-end; line-height:1.2; }
        .staff-name-tag .sname { color:#1a202c; font-weight:700; font-size:0.9rem; }
        .staff-name-tag .srole { color:#a0aec0; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; }
        .content-area { padding:40px; overflow-y:auto; flex:1; }

        /* ── BUTTONS ── */
        .btn-primary {
            background:#334e5e; color:white; padding:10px 20px; border-radius:10px;
            border:none; font-weight:600; cursor:pointer; display:inline-flex;
            align-items:center; gap:8px; font-size:0.9rem; text-decoration:none;
            font-family:inherit; transition:background 0.2s;
        }
        .btn-primary:hover { background:#2a3f4d; }
        .btn-secondary {
            background:#f1f5f9; color:#4a5568; padding:10px 20px; border-radius:10px;
            border:1px solid #e2e8f0; font-weight:600; cursor:pointer;
            display:inline-flex; align-items:center; gap:8px; font-size:0.9rem;
            text-decoration:none; font-family:inherit; transition:background 0.2s;
        }
        .btn-secondary:hover { background:#e2e8f0; }

        /* ── PAGE HEADER ── */
        .page-header {
            display:flex; justify-content:space-between; align-items:flex-start;
            margin-bottom:35px;
        }
        .page-header h1 { font-size:2rem; color:#1a202c; font-weight:700; }
        .page-header .subtitle { color:#718096; margin-top:5px; font-size:0.95rem; }

        /* ── SECTION BLOCKS ── */
        .report-section { margin-bottom:45px; }
        .section-heading {
            display:flex; align-items:center; gap:10px;
            font-size:1.1rem; font-weight:700; color:#334e5e;
            margin-bottom:18px; padding-bottom:12px;
            border-bottom:2px solid #e2e8f0;
        }
        .section-heading i { font-size:1rem; }

        /* ── KPI GRID ── */
        .kpi-grid {
            display:grid; grid-template-columns:repeat(4,1fr); gap:16px;
            margin-bottom:20px;
        }
        .kpi-card {
            background:white; border-radius:16px; padding:20px;
            border:1px solid #e2e8f0; display:flex; flex-direction:column;
        }
        .kpi-icon-row { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px; }
        .kpi-icon {
            width:40px; height:40px; border-radius:10px;
            display:flex; align-items:center; justify-content:center; font-size:1rem;
        }
        .kpi-val { font-size:1.8rem; font-weight:700; color:#1a202c; line-height:1; }
        .kpi-label { font-size:0.78rem; color:#718096; font-weight:500; margin-top:6px; }

        /* ── TABLES ── */
        .table-card {
            background:white; border-radius:16px; border:1px solid #e2e8f0;
            overflow:hidden;
        }
        .table-card-header {
            padding:18px 24px; border-bottom:1px solid #f1f5f9;
            display:flex; justify-content:space-between; align-items:center;
        }
        .table-card-header h3 { font-size:0.95rem; font-weight:700; color:#1a202c; }
        .table-card-header span { font-size:0.8rem; color:#a0aec0; }
        table { width:100%; border-collapse:collapse; }
        thead th {
            text-align:left; padding:12px 16px; color:#a0aec0;
            font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em;
            background:#fafbfc; border-bottom:1px solid #f1f5f9; font-weight:600;
        }
        thead th:not(:first-child) { text-align:right; }
        tbody tr { border-bottom:1px solid #f8fafc; transition:background 0.1s; }
        tbody tr:last-child { border-bottom:none; }
        tbody tr:hover { background:#fafbfc; }
        tbody td { padding:13px 16px; font-size:0.88rem; color:#4a5568; }
        tbody td:not(:first-child) { text-align:right; }
        .cell-primary { font-weight:600; color:#1a202c; }
        .cell-sub { font-size:0.75rem; color:#a0aec0; margin-top:2px; }

        /* ── PILLS ── */
        .pill {
            display:inline-block; padding:3px 10px; border-radius:20px;
            font-size:0.72rem; font-weight:700; text-transform:capitalize;
        }
        .pill-ongoing  { background:#ebf8ff; color:#3182ce; }
        .pill-upcoming { background:#fffaf0; color:#dd6b20; }
        .pill-completed{ background:#f0fff4; color:#38a169; }
        .pill-cancelled{ background:#f7fafc; color:#a0aec0; }

        /* ── RATE BAR ── */
        .rate-bar-wrap { display:flex; align-items:center; gap:10px; justify-content:flex-end; }
        .rate-bar-bg { width:80px; height:6px; background:#e2e8f0; border-radius:10px; flex-shrink:0; }
        .rate-bar-fill { height:100%; border-radius:10px; }

        /* ── ABOVE-AVG BADGES ── */
        .above-avg-tag {
            display:inline-block; background:#f0fff4; color:#38a169;
            font-size:0.7rem; font-weight:700; padding:2px 8px; border-radius:10px;
            margin-left:8px;
        }

        /* ── MONTHLY SECTION ── */
        .month-block { margin-bottom:24px; }
        .month-label-row {
            font-size:0.82rem; font-weight:700; color:#718096;
            text-transform:uppercase; letter-spacing:0.06em;
            padding:8px 16px; background:#f8fafc;
            border-top:1px solid #f1f5f9; border-bottom:1px solid #f1f5f9;
        }

        /* ── SUMMARY FOOTER ── */
        .summary-footer {
            background:#334e5e; color:white; border-radius:16px;
            padding:24px 30px; display:flex; justify-content:space-between; align-items:center;
        }
        .summary-footer .sf-item { text-align:center; }
        .summary-footer .sf-val { font-size:1.6rem; font-weight:700; }
        .summary-footer .sf-lbl { font-size:0.78rem; opacity:0.7; margin-top:4px; }
        .sf-divider { width:1px; background:rgba(255,255,255,0.2); height:50px; }

        /* ══════════════════════════════════════════
           PRINT STYLES — A4, clean, no chrome
        ══════════════════════════════════════════ */
        @media print {
            @page { size:A4 portrait; margin:18mm 15mm 18mm 15mm; }

            /* Hide all screen UI */
            .nav-sidebar,
            .top-header,
            .no-print,
            .btn-primary,
            .btn-secondary { display:none !important; }

            body { background:white; display:block; height:auto; overflow:visible; }
            .main-wrapper { display:block; overflow:visible; }
            .content-area { padding:0; overflow:visible; }

            /* Report header */
            .print-header { display:block !important; }

            /* Prevent page breaks inside key blocks */
            .kpi-card, .table-card, .month-block { break-inside:avoid; }
            .report-section { page-break-inside:avoid; margin-bottom:28pt; }

            /* Flatten card borders for print */
            .kpi-card, .table-card {
                border:1px solid #ccc !important;
                box-shadow:none !important;
                border-radius:6px !important;
            }
            .kpi-grid { grid-template-columns:repeat(4,1fr); gap:8px; }

            /* Table tweaks */
            thead th { background:#f1f5f9 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            tbody tr:hover { background:none !important; }

            /* Pills keep color */
            .pill { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .rate-bar-fill { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .summary-footer { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .kpi-icon { -webkit-print-color-adjust:exact; print-color-adjust:exact; }

            a { text-decoration:none; color:inherit; }
        }

        /* Print-only header (hidden on screen) */
        .print-header { display:none; }
    </style>
</head>
<body>

<!-- ── Sidebar (screen only) ── -->
<nav class="nav-sidebar no-print">
    <div class="nav-logo">Bayanihan</div>
    <a href="staff_dashboard.php"     class="nav-item"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="staff_program.php"       class="nav-item"><i class="fa-solid fa-shapes"></i> Programs</a>
    <a href="staff_participant.php"   class="nav-item"><i class="fa-solid fa-users"></i> Participants</a>
    <a href="staff_participation.php" class="nav-item"><i class="fa-solid fa-handshake-angle"></i> Participation</a>
    <a href="staff_attendance.php"    class="nav-item"><i class="fa-solid fa-calendar-check"></i> Attendance</a>
    <a href="staff_report.php"        class="nav-item active"><i class="fa-solid fa-chart-bar"></i> Reports</a>
    <div class="nav-footer">
        <a href="logout.php" class="nav-item"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</nav>

<!-- ── Main ── -->
<div class="main-wrapper">
    <!-- Top header (screen only) -->
    <header class="top-header no-print">
        <div style="font-size:1rem; font-weight:700; color: white;">
            <i class="fa-solid fa-chart-bar" style="margin-right:8px;"></i>Analytics Report
        </div>
        <div class="header-actions">
            <button class="btn-secondary" onclick="window.print()">
                <i class="fa-solid fa-print"></i> Print / Export PDF
            </button>
            <div class="staff-name-tag">
                <span class="sname"><?= e($staff_name) ?></span>
                <span class="srole">Staff</span>
            </div>
        </div>
    </header>

    <main class="content-area">

        <!-- ── Print-only header ── -->
        <div class="print-header" style="border-bottom:2px solid #334e5e; padding-bottom:16px; margin-bottom:28px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-end;">
                <div>
                    <div style="font-size:22pt; font-weight:700; color:#334e5e; letter-spacing:0.5px;">Bayanihan</div>
                    <div style="font-size:14pt; font-weight:700; color:#1a202c; margin-top:4px;">Community Program Analytics Report</div>
                    <?php date_default_timezone_set('Asia/Manila'); ?>
                    <div style="font-size:9pt; color:#718096; margin-top:4px;">
                        Generated <?= date('Y/m/d H:i:s') ?> &nbsp;|&nbsp; Prepared by: <?= e($staff_name) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Screen Page Header ── -->
        <div class="page-header no-print">
            <div>
                <h1>Analytics Report</h1>
                <p class="subtitle">Report &nbsp;&bull;&nbsp; Prepared by <?= e($staff_name) ?></p>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════ -->
        <!-- SECTION 1 — Platform KPIs                             -->
        <!-- ══════════════════════════════════════════════════════ -->
        <section class="report-section">
            <div class="section-heading">
                <i class="fa-solid fa-gauge-high"></i>
                Platform-Wide Key Performance Indicators
            </div>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon-row">
                        <div>
                            <div class="kpi-val"><?= number_format($kpi['total_users']) ?></div>
                            <div class="kpi-label">Total Users (Participants)</div>
                        </div>
                        <div class="kpi-icon" style="background:#ebf8ff;color:#3182ce;">
                            <i class="fa-solid fa-users"></i>
                        </div>
                    </div>
                    <div style="font-size:0.75rem;color:#a0aec0;">
                        Includes participant accounts only
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon-row">
                        <div>
                            <div class="kpi-val"><?= number_format($kpi['total_programs']) ?></div>
                            <div class="kpi-label">Total Programs</div>
                        </div>
                        <div class="kpi-icon" style="background:#fffaf0;color:#dd6b20;">
                            <i class="fa-solid fa-shapes"></i>
                        </div>
                    </div>
                    <div style="font-size:0.75rem;color:#a0aec0;">
                        <?= $kpi['ongoing_programs'] ?> ongoing &bull;
                        <?= $kpi['upcoming_programs'] ?> upcoming &bull;
                        <?= $kpi['completed_programs'] ?> completed
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon-row">
                        <div>
                            <div class="kpi-val"><?= $overall_rate ?>%</div>
                            <div class="kpi-label">Overall Attendance Rate</div>
                        </div>
                        <div class="kpi-icon" style="background:#f0fff4;color:#38a169;">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                    </div>
                    <div style="font-size:0.75rem;color:#a0aec0;">
                        <?= number_format($kpi['total_present']) ?> present &bull;
                        <?= number_format($kpi['total_absent']) ?> absent
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon-row">
                        <div>
                            <div class="kpi-val"><?= number_format($kpi['avg_participants_per_program'], 1) ?></div>
                            <div class="kpi-label">Avg. Participants / Program</div>
                        </div>
                        <div class="kpi-icon" style="background:#faf5ff;color:#805ad5;">
                            <i class="fa-solid fa-chart-line"></i>
                        </div>
                    </div>
                    <div style="font-size:0.75rem;color:#a0aec0;">
                        Max: <?= $kpi['max_participants_in_program'] ?> &bull;
                        Min: <?= $kpi['min_participants_in_program'] ?>
                    </div>
                </div>
            </div>

            <!-- Summary totals row -->
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:16px;">
                <div class="kpi-card" style="flex-direction:row;gap:16px;">
                    <div class="kpi-icon" style="background:#fff5f5;color:#e53e3e;flex-shrink:0;">
                        <i class="fa-solid fa-handshake-angle"></i>
                    </div>
                    <div>
                        <div class="kpi-val"><?= number_format($kpi['total_participations']) ?></div>
                        <div class="kpi-label">Total Participation Records</div>
                    </div>
                </div>
                <div class="kpi-card" style="flex-direction:row;gap:16px;">
                    <div class="kpi-icon" style="background:#f0fff4;color:#38a169;flex-shrink:0;">
                        <i class="fa-solid fa-calendar-day"></i>
                    </div>
                    <div>
                        <div class="kpi-val"><?= number_format($kpi['total_sessions']) ?></div>
                        <div class="kpi-label">Total Attendance Records</div>
                    </div>
                </div>
                <div class="kpi-card" style="flex-direction:row;gap:16px;">
                    <div class="kpi-icon" style="background:#ebf8ff;color:#3182ce;flex-shrink:0;">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <div>
                        <div class="kpi-val"><?= number_format($kpi['total_participants']) ?></div>
                        <div class="kpi-label">Registered Participants</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- COUNT, SUM, and AVG calculated per program using JOIN between programs and participation -->
        <!-- ══════════════════════════════════════════════════════ -->
        <!-- SECTION 2 — Per-Program Summary Table                 -->
        <!-- ══════════════════════════════════════════════════════ -->
        <section class="report-section">
            <div class="section-heading">
                <i class="fa-solid fa-table-list"></i>
                Program Performance Summary
            </div>

            <div class="table-card">
                <div class="table-card-header">
                    <h3>All Programs</h3>
                    <span><?= count($program_summary) ?> programs</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th style="text-align:left;">Program</th>
                            <th>Status</th>
                            <th>Total Joined</th>
                            <th>Active</th>
                            <th>Completed</th>
                            <th>Sessions</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Att. Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($program_summary)): ?>
                        <tr><td colspan="9" style="text-align:center;color:#a0aec0;padding:30px;">No program data available.</td></tr>
                    <?php else: ?>
                        <?php foreach ($program_summary as $row):
                            $rate  = (float) $row['attendance_rate'];
                            $color = $rate >= 75 ? '#38a169' : ($rate >= 50 ? '#dd6b20' : '#e53e3e');
                        ?>
                        <tr>
                            <td>
                                <div class="cell-primary"><?= e($row['program_name']) ?></div>
                                <div class="cell-sub">
                                    <i class="fa-solid fa-location-dot" style="font-size:0.65rem;"></i>
                                    <?= e($row['location'] ?? '—') ?>
                                </div>
                            </td>
                            <td><span class="pill pill-<?= e($row['program_status']) ?>"><?= ucfirst(e($row['program_status'])) ?></span></td>
                            <td><?= number_format($row['total_joined']) ?></td>
                            <td style="color:#3182ce;font-weight:600;"><?= number_format($row['active_participants']) ?></td>
                            <td style="color:#38a169;font-weight:600;"><?= number_format($row['completed_participants']) ?></td>
                            <td><?= number_format($row['total_sessions']) ?></td>
                            <td><?= number_format($row['present_count']) ?></td>
                            <td style="color:#e53e3e;"><?= number_format($row['absent_count']) ?></td>
                            <td>
                                <div class="rate-bar-wrap">
                                    <span style="color:<?= $color ?>;font-weight:700;font-size:0.82rem;"><?= $rate ?>%</span>
                                    <div class="rate-bar-bg">
                                        <div class="rate-bar-fill" style="width:<?= $rate ?>%;background:<?= $color ?>;"></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Subquery in HAVING clause: compares COUNT with the average value from a subquery -->
        <!-- ══════════════════════════════════════════════════════ -->
        <!-- SECTION 3 — Above-Average Participation Programs       -->
        <!-- Subquery: HAVING COUNT > (SELECT AVG...)              -->
        <!-- ══════════════════════════════════════════════════════ -->
        <section class="report-section">
            <div class="section-heading">
                <i class="fa-solid fa-arrow-trend-up"></i>
                Programs with Above-Average Participation
            </div>

            <?php if (empty($above_avg_programs)): ?>
                <div style="background:white;border:1px solid #e2e8f0;border-radius:16px;padding:40px;text-align:center;color:#a0aec0;">
                    <i class="fa-solid fa-chart-bar" style="font-size:2rem;display:block;margin-bottom:12px;"></i>
                    No programs exceed the average participation threshold.
                </div>
            <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
                <?php foreach ($above_avg_programs as $i => $prog): ?>
                <div style="background:white;border:1px solid #e2e8f0;border-radius:14px;padding:18px;display:flex;flex-direction:column;gap:6px;">
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="font-size:0.75rem;font-weight:700;color:#a0aec0;">#<?= $i+1 ?></span>
                        <span class="above-avg-tag"><i class="fa-solid fa-arrow-up" style="font-size:0.6rem;"></i> Above Avg</span>
                    </div>
                    <div style="font-size:0.95rem;font-weight:700;color:#1a202c;"><?= e($prog['program_name']) ?></div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;">
                        <span class="pill pill-<?= e($prog['status']) ?>"><?= ucfirst(e($prog['status'])) ?></span>
                        <span style="font-size:1.2rem;font-weight:700;color:#334e5e;"><?= number_format($prog['participant_count']) ?> <span style="font-size:0.75rem;font-weight:500;color:#a0aec0;">joined</span></span>
                    </div>
                    <div style="height:4px;background:#e2e8f0;border-radius:10px;margin-top:6px;">
                        <div style="height:100%;border-radius:10px;background:#334e5e;width:<?= min(100, round(($prog['participant_count'] / ($kpi['max_participants_in_program'] ?: 1)) * 100)) ?>%;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- JOIN: users → participants → participation → attendance | Subquery used to get MAX engagement from platform -->
        <!-- ══════════════════════════════════════════════════════ -->
        <!-- SECTION 4 — Top 10 Most Engaged Participants          -->
        <!-- JOIN: users → participants → participation → attendance -->
        <!-- ══════════════════════════════════════════════════════ -->
        <section class="report-section">
            <div class="section-heading">
                <i class="fa-solid fa-ranking-star"></i>
                Top 10 Most Engaged Participants
            </div>

            <div class="table-card">
                <div class="table-card-header">
                    <h3>Ranked by Attendance Sessions (Present)</h3>
                    <span>Top 10 of <?= number_format($kpi['total_participants']) ?> participants</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th style="text-align:center;">Rank</th>
                            <th style="text-align:left;">Participant</th>
                            <th>Programs Joined</th>
                            <th>Total Sessions</th>
                            <th>Sessions Present</th>
                            <th>Att. Rate</th>
                            <th>Engagement Score</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($top_participants)): ?>
                        <tr><td colspan="7" style="text-align:center;color:#a0aec0;padding:30px;">No participant data.</td></tr>
                    <?php else: ?>
                        <?php foreach ($top_participants as $rank => $p):
                            $platform_max = (int) ($p['platform_max_present'] ?: 1);
                            $score = round(($p['sessions_present'] / $platform_max) * 100);
                            $rate  = (float) $p['attendance_rate'];
                            $rateColor = $rate >= 75 ? '#38a169' : ($rate >= 50 ? '#dd6b20' : '#e53e3e');
                        ?>
                        <tr>
                            <td style="text-align:center;">
                                <?php if ($rank === 0): ?>
                                    <span style="color:#f6c90e;font-size:1.1rem;"><i class="fa-solid fa-trophy"></i></span>
                                <?php elseif ($rank === 1): ?>
                                    <span style="color:#a0aec0;font-size:1rem;"><i class="fa-solid fa-medal"></i></span>
                                <?php elseif ($rank === 2): ?>
                                    <span style="color:#cd7f32;font-size:1rem;"><i class="fa-solid fa-medal"></i></span>
                                <?php else: ?>
                                    <span style="color:#a0aec0;font-weight:600;"><?= $rank + 1 ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:left;">
                                <div class="cell-primary"><?= e($p['full_name']) ?></div>
                                <div class="cell-sub"><?= e($p['email']) ?></div>
                            </td>
                            <td><?= $p['programs_joined'] ?></td>
                            <td><?= $p['total_sessions'] ?></td>
                            <td style="color:#38a169;font-weight:700;"><?= $p['sessions_present'] ?></td>
                            <td>
                                <span style="color:<?= $rateColor ?>;font-weight:700;"><?= $rate ?>%</span>
                            </td>
                            <td>
                                <div class="rate-bar-wrap">
                                    <span style="font-size:0.82rem;color:#334e5e;font-weight:700;"><?= $score ?>%</span>
                                    <div class="rate-bar-bg">
                                        <div class="rate-bar-fill" style="width:<?= $score ?>%;background:#334e5e;"></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- CTE (Common Table Expression): monthly_attendance, grouping data by month and program -->
        <!-- ══════════════════════════════════════════════════════ -->
        <!-- SECTION 5 — CTE: Monthly Attendance Breakdown         -->
        <!-- WITH monthly_attendance AS (...)                      -->
        <!-- ══════════════════════════════════════════════════════ -->
        <section class="report-section">
            <div class="section-heading">
                <i class="fa-solid fa-calendar-days"></i>
                Monthly Attendance Breakdown
            </div>

            <?php if (empty($monthly_grouped)): ?>
                <div style="background:white;border:1px solid #e2e8f0;border-radius:16px;padding:40px;text-align:center;color:#a0aec0;">
                    <i class="fa-solid fa-calendar-xmark" style="font-size:2rem;display:block;margin-bottom:12px;"></i>
                    No monthly attendance data available.
                </div>
            <?php else: ?>
            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th style="text-align:left;">Month</th>
                            <th style="text-align:left;">Program</th>
                            <th>Total Records</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Monthly Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($monthly_grouped as $month => $rows): ?>
                        <tr>
                            <td colspan="6" class="month-label-row" style="text-align:left !important;"><?= e($month) ?></td>
                        </tr>
                        <?php foreach ($rows as $r):
                            $mrate = (float) $r['monthly_rate'];
                            $mc    = $mrate >= 75 ? '#38a169' : ($mrate >= 50 ? '#dd6b20' : '#e53e3e');
                        ?>
                        <tr>
                            <td></td>
                            <td style="text-align:left;color:#4a5568;"><?= e($r['program_name']) ?></td>
                            <td><?= $r['total_records'] ?></td>
                            <td style="color:#38a169;font-weight:600;"><?= $r['present_count'] ?></td>
                            <td style="color:#e53e3e;"><?= $r['absent_count'] ?></td>
                            <td>
                                <div class="rate-bar-wrap">
                                    <span style="color:<?= $mc ?>;font-weight:700;font-size:0.82rem;"><?= $mrate ?>%</span>
                                    <div class="rate-bar-bg">
                                        <div class="rate-bar-fill" style="width:<?= $mrate ?>%;background:<?= $mc ?>;"></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <!-- MAX, MIN, and AVG calculated for monthly present sessions per program -->
        <!-- ══════════════════════════════════════════════════════ -->
        <!-- SECTION 6 — Attendance Extremes per Program           -->
        <!-- MAX / MIN / AVG monthly present per program           -->
        <!-- ══════════════════════════════════════════════════════ -->
        <?php if (!empty($att_extremes)): ?>
        <section class="report-section">
            <div class="section-heading">
                <i class="fa-solid fa-arrow-up-wide-short"></i>
                Monthly Attendance Extremes by Program
            </div>

            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th style="text-align:left;">Program</th>
                            <th>Highest Month (Present)</th>
                            <th>Lowest Month (Present)</th>
                            <th>Avg Monthly (Present)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($att_extremes as $ex): ?>
                    <tr>
                        <td class="cell-primary" style="text-align:left;"><?= e($ex['program_name']) ?></td>
                        <td style="color:#38a169;font-weight:700;"><?= number_format($ex['highest_monthly_present']) ?></td>
                        <td style="color:#e53e3e;font-weight:700;"><?= number_format($ex['lowest_monthly_present']) ?></td>
                        <td style="color:#334e5e;font-weight:600;"><?= number_format((float)$ex['avg_monthly_present'], 1) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════════════ -->
        <!-- SUMMARY FOOTER                                        -->
        <!-- ══════════════════════════════════════════════════════ -->
        <div class="summary-footer">
            <div class="sf-item">
                <div class="sf-val"><?= number_format($kpi['total_users']) ?></div>
                <div class="sf-lbl">Total Users (Participants)</div>
            </div>
            <div class="sf-divider"></div>
            <div class="sf-item">
                <div class="sf-val"><?= number_format($kpi['total_programs']) ?></div>
                <div class="sf-lbl">Total Programs</div>
            </div>
            <div class="sf-divider"></div>
            <div class="sf-item">
                <div class="sf-val"><?= number_format($kpi['total_participations']) ?></div>
                <div class="sf-lbl">Participation Records</div>
            </div>
            <div class="sf-divider"></div>
            <div class="sf-item">
                <div class="sf-val"><?= number_format($kpi['total_sessions']) ?></div>
                <div class="sf-lbl">Attendance Records</div>
            </div>
            <div class="sf-divider"></div>
            <div class="sf-item">
                <div class="sf-val"><?= $overall_rate ?>%</div>
                <div class="sf-lbl">Overall Attendance Rate</div>
            </div>
            <div class="sf-divider"></div>
            <div class="sf-item" style="text-align:right;">
                <div style="font-size:0.78rem;opacity:0.6;line-height:1.5;">
                    <?= $generated_at ?><br>Bayanihan Analytics Report
                </div>
            </div>
        </div>

        <!-- Bottom spacer -->
        <div style="height:40px;"></div>

    </main>
</div>

</body>
</html>