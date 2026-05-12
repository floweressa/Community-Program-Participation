<?php
// admin_reports.php

/* ── AUTH ── */
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

require_once 'database.php';

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$db           = getDB();
$generated_at = date('F j, Y \a\t g:i A');
$admin_name   = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

/* ── PLATFORM KPIs ── */
$kpi = $db->query("
    SELECT
        (SELECT COUNT(*) FROM users)                                              AS total_users,
        (SELECT COUNT(*) FROM users WHERE role = 'participant')                  AS total_participants,
        (SELECT COUNT(*) FROM users WHERE role = 'staff')                        AS total_staff,
        (SELECT COUNT(*) FROM users WHERE role = 'admin')                        AS total_admins,
        (SELECT COUNT(*) FROM programs)                                          AS total_programs,
        (SELECT COUNT(*) FROM programs WHERE status = 'ongoing')                 AS ongoing_programs,
        (SELECT COUNT(*) FROM programs WHERE status = 'upcoming')                AS upcoming_programs,
        (SELECT COUNT(*) FROM programs WHERE status = 'completed')               AS completed_programs,
        (SELECT COUNT(*) FROM participation)                                     AS total_participations,
        (SELECT COUNT(*) FROM attendance)                                        AS total_sessions,
        (SELECT COUNT(*) FROM attendance WHERE status = 'present')               AS total_present,
        (SELECT COUNT(*) FROM attendance WHERE status = 'absent')                AS total_absent,
        (SELECT ROUND(AVG(cnt),1) FROM (SELECT COUNT(*) AS cnt FROM participation GROUP BY program_id) s) AS avg_per_program,
        (SELECT MAX(cnt) FROM (SELECT COUNT(*) AS cnt FROM participation GROUP BY program_id) s)          AS max_per_program
")->fetch_assoc();

$overall_rate = $kpi['total_sessions'] > 0
    ? round(($kpi['total_present'] / $kpi['total_sessions']) * 100, 1) : 0;

/* ── PROGRAM SUMMARY ── */
$program_summary = $db->query("
    SELECT pg.program_id, pg.program_name, pg.location, pg.status,
           pg.start_date, pg.end_date,
           COUNT(DISTINCT par.participation_id) AS total_joined,
           COUNT(DISTINCT a.attendance_id)      AS total_sessions,
           SUM(a.status = 'present')            AS present_count,
           CASE WHEN COUNT(DISTINCT a.attendance_id) > 0
                THEN ROUND((SUM(a.status = 'present') / COUNT(DISTINCT a.attendance_id)) * 100, 1)
                ELSE 0 END                      AS attendance_rate
    FROM programs pg
    LEFT JOIN participation par ON par.program_id    = pg.program_id
    LEFT JOIN attendance a      ON a.participation_id = par.participation_id
    GROUP BY pg.program_id, pg.program_name, pg.location, pg.status, pg.start_date, pg.end_date
    ORDER BY total_joined DESC
")->fetch_all(MYSQLI_ASSOC);

/* ── USER GROWTH (last 6 months) ── */
$user_growth = $db->query("
    SELECT DATE_FORMAT(created_at,'%b %Y') AS month_label,
           DATE_FORMAT(created_at,'%Y-%m') AS yr_month,
           COUNT(*) AS new_users
    FROM users
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY yr_month, month_label
    ORDER BY yr_month ASC
")->fetch_all(MYSQLI_ASSOC);

/* ── TOP 10 PARTICIPANTS ── */
$top_participants = $db->query("
    SELECT CONCAT(u.first_name,' ',u.last_name) AS full_name, u.email,
           COUNT(DISTINCT par.program_id)        AS programs_joined,
           SUM(a.status = 'present')             AS sessions_present,
           CASE WHEN COUNT(DISTINCT a.attendance_id) > 0
                THEN ROUND((SUM(a.status='present') / COUNT(DISTINCT a.attendance_id)) * 100, 1)
                ELSE 0 END AS attendance_rate
    FROM users u
    JOIN participants pt   ON pt.user_id        = u.user_id
    JOIN participation par ON par.participant_id = pt.participant_id
    LEFT JOIN attendance a ON a.participation_id = par.participation_id
    WHERE u.role = 'participant'
    GROUP BY u.user_id, u.first_name, u.last_name, u.email
    ORDER BY sessions_present DESC LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

$first_name = e($_SESSION['first_name'] ?? '');
$last_name  = e($_SESSION['last_name']  ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - Admin Reports</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        body { background:#f8fafc; display:flex; height:100vh; overflow:hidden; }
        .nav-sidebar { width:260px; background:white; border-right:1px solid #e2e8f0; padding:25px; display:flex; flex-direction:column; flex-shrink:0; }
        .nav-logo { color:#334e5e; font-weight:700; font-size:1.3rem; margin-bottom:8px; }
        .nav-badge { display:inline-block; padding:2px 10px; background:white; color:white; border-radius:20px; font-size:0.7rem; text-transform:uppercase; letter-spacing:1px; margin-bottom:28px; }
        .nav-item { display:flex; align-items:center; gap:15px; padding:12px 15px; color:#718096; text-decoration:none; border-radius:10px; margin-bottom:8px; transition:0.2s; font-size:0.95rem; font-weight:500; }
        .nav-item:hover { background:#f1f5f9; color:#334e5e; }
        .nav-item.active { background:#f1f5f9; color:#334e5e; border-right:4px solid #334e5e; border-radius:10px 0 0 10px; font-weight:600; }
        .nav-footer { margin-top:auto; padding-top:20px; border-top:1px solid #f1f5f9; }
        .main-wrapper { flex:1; display:flex; flex-direction:column; overflow:hidden; }
        .top-header { padding:15px 40px; background:white; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-shrink:0; }
        .header-actions { display:flex; align-items:center; gap:20px; color:#718096; }
        .admin-name-tag .sname { color:#1a202c; font-weight:700; font-size:0.9rem; display:block; text-align:right; }
        .admin-name-tag .srole { color:#e53e3e; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; display:block; text-align:right; }
        .content-area { padding:40px; overflow-y:auto; flex:1; }
        .page-header { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:35px; }
        .btn-primary { background:#334e5e; color:white; padding:11px 22px; border-radius:10px; border:none; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:0.2s; font-size:0.9rem; text-decoration:none; font-family:inherit; }
        .btn-primary:hover { background:#2a3f4d; }
        .report-section { margin-bottom:45px; }
        .section-heading { display:flex; align-items:center; gap:10px; font-size:1.1rem; font-weight:700; color:#334e5e; margin-bottom:18px; padding-bottom:12px; border-bottom:2px solid #e2e8f0; }
        /* KPI grid */
        .kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; }
        .kpi-card { background:white; border-radius:16px; padding:20px; border:1px solid #e2e8f0; display:flex; flex-direction:column; }
        .kpi-icon-row { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px; }
        .kpi-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1rem; }
        .kpi-val { font-size:1.8rem; font-weight:700; color:#1a202c; line-height:1; }
        .kpi-label { font-size:0.78rem; color:#718096; font-weight:500; margin-top:6px; }
        /* Table */
        .table-card { background:white; border-radius:16px; border:1px solid #e2e8f0; overflow:hidden; }
        table { width:100%; border-collapse:collapse; }
        thead th { text-align:left; padding:12px 16px; color:#a0aec0; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; background:#fafbfc; border-bottom:1px solid #f1f5f9; font-weight:600; }
        thead th:not(:first-child) { text-align:right; }
        tbody tr { border-bottom:1px solid #f8fafc; }
        tbody tr:last-child { border-bottom:none; }
        tbody tr:hover { background:#fafbfc; }
        tbody td { padding:13px 16px; font-size:0.88rem; color:#4a5568; }
        tbody td:not(:first-child) { text-align:right; }
        .pill { display:inline-block; padding:3px 10px; border-radius:20px; font-size:0.72rem; font-weight:700; text-transform:capitalize; }
        .pill-ongoing  { background:#ebf8ff; color:#3182ce; }
        .pill-upcoming { background:#fffaf0; color:#dd6b20; }
        .pill-completed{ background:#f0fff4; color:#38a169; }
        .pill-cancelled{ background:#f7fafc; color:#a0aec0; }
        .rate-bar-wrap { display:flex; align-items:center; gap:10px; justify-content:flex-end; }
        .rate-bar-bg   { width:80px; height:6px; background:#e2e8f0; border-radius:10px; flex-shrink:0; }
        .rate-bar-fill { height:100%; border-radius:10px; }
        /* Summary footer */
        .summary-footer { background:#334e5e; color:white; border-radius:16px; padding:24px 30px; display:flex; justify-content:space-between; align-items:center; }
        .sf-item { text-align:center; }
        .sf-val { font-size:1.6rem; font-weight:700; }
        .sf-lbl { font-size:0.78rem; opacity:0.7; margin-top:4px; }
        .sf-divider { width:1px; background:rgba(255,255,255,0.2); height:50px; }
        /* Growth bar chart */
        .bar-chart { display:flex; align-items:flex-end; gap:12px; height:120px; padding:0 4px; }
        .bar-col { display:flex; flex-direction:column; align-items:center; gap:6px; flex:1; }
        .bar { background:#334e5e; border-radius:4px 4px 0 0; width:100%; transition:opacity 0.2s; min-height:4px; }
        .bar:hover { opacity:0.75; }
        .bar-label { font-size:0.7rem; color:#a0aec0; font-weight:600; }
        .bar-val { font-size:0.72rem; color:#4a5568; font-weight:700; }
        @media print {
            .nav-sidebar, .top-header, .btn-primary { display:none !important; }
            body { display:block; height:auto; overflow:visible; background:white; }
            .main-wrapper { display:block; overflow:visible; }
            .content-area { padding:0; overflow:visible; }
        }
    </style>
</head>
<body>

<nav class="nav-sidebar">
    <div class="nav-logo">Bayanihan</div>
    <div class="nav-badge">Admin</div>
    <a href="admin_dashboard.php"        class="nav-item"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="admin_program.php"         class="nav-item"><i class="fa-solid fa-shapes"></i> Programs</a>
    <a href="admin_participant.php"      class="nav-item"><i class="fa-solid fa-users"></i> Participants</a>
    <a href="admin_user_management.php"  class="nav-item"><i class="fa-solid fa-user-shield"></i> User Management</a>
    <a href="admin_report.php"          class="nav-item active"><i class="fa-solid fa-chart-bar"></i> Reports</a>
    <a href="admin_audit_log.php"       class="nav-item"><i class="fa-solid fa-clipboard-list"></i> Audit Logs</a>
    <div class="nav-footer">
        <a href="logout.php" class="nav-item"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</nav>

<div class="main-wrapper">
    <header class="top-header">
        <div style="font-size:0.88rem;color:white;">
            Generated on <?= e($generated_at) ?>
        </div>
        <div class="header-actions">
            <button class="btn-primary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Export</button>
            <div class="admin-name-tag">
                <span class="sname"><?= $first_name . ' ' . $last_name ?></span>
                <span class="srole">Administrator</span>
            </div>
        </div>
    </header>

    <main class="content-area">
        <div class="page-header">
            <div>
                <h1 style="font-size:2rem;color:#1a202c;font-weight:700;">Platform Analytics Report</h1>
                <p style="color:#718096;margin-top:5px;">Complete system-wide overview — Admin view</p>
            </div>
        </div>

        <!-- SECTION 1: KPIs -->
        <section class="report-section">
            <div class="section-heading"><i class="fa-solid fa-gauge-high"></i> Platform-Wide KPIs</div>
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon-row">
                        <div><div class="kpi-val"><?= number_format($kpi['total_users']) ?></div><div class="kpi-label">Total Users</div></div>
                        <div class="kpi-icon" style="background:#ebf8ff;color:#3182ce;"><i class="fa-solid fa-users"></i></div>
                    </div>
                    <div style="font-size:0.72rem;color:#a0aec0;"><?= $kpi['total_participants'] ?> participants &bull; <?= $kpi['total_staff'] ?> staff &bull; <?= $kpi['total_admins'] ?> admin</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon-row">
                        <div><div class="kpi-val"><?= number_format($kpi['total_programs']) ?></div><div class="kpi-label">Total Programs</div></div>
                        <div class="kpi-icon" style="background:#fffaf0;color:#dd6b20;"><i class="fa-solid fa-shapes"></i></div>
                    </div>
                    <div style="font-size:0.72rem;color:#a0aec0;"><?= $kpi['ongoing_programs'] ?> ongoing &bull; <?= $kpi['upcoming_programs'] ?> upcoming &bull; <?= $kpi['completed_programs'] ?> completed</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon-row">
                        <div><div class="kpi-val"><?= $overall_rate ?>%</div><div class="kpi-label">Overall Attendance Rate</div></div>
                        <div class="kpi-icon" style="background:#f0fff4;color:#38a169;"><i class="fa-solid fa-circle-check"></i></div>
                    </div>
                    <div style="font-size:0.72rem;color:#a0aec0;"><?= number_format($kpi['total_present']) ?> present &bull; <?= number_format($kpi['total_absent']) ?> absent</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon-row">
                        <div><div class="kpi-val"><?= number_format((float)$kpi['avg_per_program'], 1) ?></div><div class="kpi-label">Avg. Participants / Program</div></div>
                        <div class="kpi-icon" style="background:#faf5ff;color:#805ad5;"><i class="fa-solid fa-chart-line"></i></div>
                    </div>
                    <div style="font-size:0.72rem;color:#a0aec0;">Max: <?= $kpi['max_per_program'] ?> participants</div>
                </div>
            </div>
        </section>

        <!-- SECTION 2: User Growth -->
        <?php if (!empty($user_growth)): ?>
        <section class="report-section">
            <div class="section-heading"><i class="fa-solid fa-chart-bar"></i> New User Registrations (Last 6 Months)</div>
            <div style="background:white;border-radius:16px;border:1px solid #e2e8f0;padding:28px;">
                <?php $max_u = max(array_column($user_growth,'new_users')); ?>
                <div class="bar-chart">
                    <?php foreach ($user_growth as $g):
                        $h = $max_u > 0 ? round(($g['new_users'] / $max_u) * 110) : 4; ?>
                    <div class="bar-col">
                        <div class="bar-val"><?= $g['new_users'] ?></div>
                        <div class="bar" style="height:<?= $h ?>px;"></div>
                        <div class="bar-label"><?= e($g['month_label']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- SECTION 3: Program Summary -->
        <section class="report-section">
            <div class="section-heading"><i class="fa-solid fa-table-list"></i> Program Performance Summary</div>
            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th style="text-align:left;">Program</th>
                            <th>Status</th>
                            <th>Participants</th>
                            <th>Sessions</th>
                            <th>Present</th>
                            <th>Att. Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($program_summary)): ?>
                        <tr><td colspan="6" style="text-align:center;color:#a0aec0;padding:24px;">No program data.</td></tr>
                    <?php else: ?>
                        <?php foreach ($program_summary as $pg):
                            $rate  = (float)$pg['attendance_rate'];
                            $color = $rate >= 75 ? '#38a169' : ($rate >= 50 ? '#dd6b20' : '#e53e3e');
                        ?>
                        <tr>
                            <td style="text-align:left;">
                                <div style="font-weight:700;color:#1a202c;"><?= e($pg['program_name']) ?></div>
                                <div style="font-size:0.75rem;color:#a0aec0;"><?= e($pg['location'] ?? '—') ?></div>
                            </td>
                            <td><span class="pill pill-<?= e($pg['status']) ?>"><?= ucfirst(e($pg['status'])) ?></span></td>
                            <td style="font-weight:700;color:#334e5e;"><?= number_format($pg['total_joined']) ?></td>
                            <td><?= number_format($pg['total_sessions']) ?></td>
                            <td style="color:#38a169;font-weight:600;"><?= number_format($pg['present_count']) ?></td>
                            <td>
                                <div class="rate-bar-wrap">
                                    <span style="color:<?= $color ?>;font-weight:700;font-size:0.82rem;"><?= $rate ?>%</span>
                                    <div class="rate-bar-bg"><div class="rate-bar-fill" style="width:<?= $rate ?>%;background:<?= $color ?>;"></div></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- SECTION 4: Top Participants -->
        <section class="report-section">
            <div class="section-heading"><i class="fa-solid fa-ranking-star"></i> Top 10 Most Engaged Participants</div>
            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th style="text-align:center;">Rank</th>
                            <th style="text-align:left;">Participant</th>
                            <th>Programs</th>
                            <th>Sessions Present</th>
                            <th>Att. Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($top_participants)): ?>
                        <tr><td colspan="5" style="text-align:center;color:#a0aec0;padding:24px;">No data.</td></tr>
                    <?php else: ?>
                        <?php foreach ($top_participants as $rank => $p):
                            $rate  = (float)$p['attendance_rate'];
                            $rc    = $rate >= 75 ? '#38a169' : ($rate >= 50 ? '#dd6b20' : '#e53e3e');
                        ?>
                        <tr>
                            <td style="text-align:center;">
                                <?php if ($rank === 0): ?><span style="color:#f6c90e;"><i class="fa-solid fa-trophy"></i></span>
                                <?php elseif ($rank === 1): ?><span style="color:#a0aec0;"><i class="fa-solid fa-medal"></i></span>
                                <?php elseif ($rank === 2): ?><span style="color:#cd7f32;"><i class="fa-solid fa-medal"></i></span>
                                <?php else: ?><span style="color:#a0aec0;font-weight:600;"><?= $rank+1 ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:left;">
                                <div style="font-weight:700;color:#1a202c;"><?= e($p['full_name']) ?></div>
                                <div style="font-size:0.75rem;color:#a0aec0;"><?= e($p['email']) ?></div>
                            </td>
                            <td><?= $p['programs_joined'] ?></td>
                            <td style="color:#38a169;font-weight:700;"><?= $p['sessions_present'] ?></td>
                            <td><span style="color:<?= $rc ?>;font-weight:700;"><?= $rate ?>%</span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Summary Footer -->
        <div class="summary-footer">
            <div class="sf-item"><div class="sf-val"><?= number_format($kpi['total_users']) ?></div><div class="sf-lbl">Total Users</div></div>
            <div class="sf-divider"></div>
            <div class="sf-item"><div class="sf-val"><?= number_format($kpi['total_programs']) ?></div><div class="sf-lbl">Total Programs</div></div>
            <div class="sf-divider"></div>
            <div class="sf-item"><div class="sf-val"><?= number_format($kpi['total_participations']) ?></div><div class="sf-lbl">Participation Records</div></div>
            <div class="sf-divider"></div>
            <div class="sf-item"><div class="sf-val"><?= $overall_rate ?>%</div><div class="sf-lbl">Overall Attendance Rate</div></div>
            <div class="sf-divider"></div>
            <div class="sf-item" style="text-align:right;">
                <div style="font-size:0.78rem;opacity:0.6;line-height:1.5;"><?= e($generated_at) ?><br>Admin Analytics Report</div>
            </div>
        </div>

        <div style="height:40px;"></div>
    </main>
</div>
</body>
</html>