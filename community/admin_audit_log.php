<?php
// admin_audit_log.php

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

require_once 'database.php';

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$db       = getDB();
$admin_id = (int) $_SESSION['user_id'];

/* ── ENSURE TABLE EXISTS ── */
$db->query("
    CREATE TABLE IF NOT EXISTS audit_logs (
        log_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        performed_by INT UNSIGNED NULL,
        action       VARCHAR(50)  NOT NULL,
        table_name   VARCHAR(100) NOT NULL,
        record_id    INT UNSIGNED NULL,
        details      TEXT         NULL,
        ip_address   VARCHAR(45)  NULL,
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_performed_by (performed_by),
        INDEX idx_created_at   (created_at),
        INDEX idx_action       (action),
        INDEX idx_table_name   (table_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ── FILTERS ── */
$search       = trim($_GET['search']     ?? '');
$filter_action = $_GET['action_filter']  ?? 'all';
$filter_module = $_GET['module_filter']  ?? 'all';
$filter_role   = $_GET['role_filter']    ?? 'all';
$date_from     = $_GET['date_from']      ?? '';
$date_to       = $_GET['date_to']        ?? '';

/* ── PAGINATION ── */
$per_page = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

/* ── BUILD WHERE ── */
$where   = [];
$params  = [];
$types   = '';

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[]  = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ?)";
    $params   = array_merge($params, [$like, $like, $like, $like]);
    $types   .= 'ssss';
}
if ($filter_action !== 'all') {
    $where[]  = "al.action = ?";
    $params[] = $filter_action;
    $types   .= 's';
}
if ($filter_module !== 'all') {
    $where[]  = "al.table_name = ?";
    $params[] = $filter_module;
    $types   .= 's';
}
if ($filter_role !== 'all') {
    $where[]  = "u.role = ?";
    $params[] = $filter_role;
    $types   .= 's';
}
if ($date_from !== '') {
    $where[]  = "al.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
    $types   .= 's';
}
if ($date_to !== '') {
    $where[]  = "al.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types   .= 's';
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ── COUNT & LEFT JOIN ── */
$count_sql = "SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON u.user_id = al.performed_by $where_clause";
if ($params) {
    $stmt = $db->prepare($count_sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total_count = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
} else {
    $total_count = (int) $db->query($count_sql)->fetch_row()[0];
}
$total_pages = max(1, (int)ceil($total_count / $per_page));
$page        = min($page, $total_pages);

/* ── LEFT JOIN (FETCH LOGS) ── */
$data_sql = "
    SELECT al.log_id, al.action, al.table_name, al.record_id, al.details, al.ip_address, al.created_at,
           u.first_name, u.last_name, u.email, u.role
    FROM audit_logs al
    LEFT JOIN users u ON u.user_id = al.performed_by
    $where_clause
    ORDER BY al.created_at DESC
    LIMIT ? OFFSET ?
";
$data_params = array_merge($params, [$per_page, $offset]);
$data_types  = $types . 'ii';
$stmt = $db->prepare($data_sql);
$stmt->bind_param($data_types, ...$data_params);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ── DISTINCT ACTIONS & MODULES FOR FILTER DROPDOWNS ── */
$actions_res = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC");
$actions_list = $actions_res ? $actions_res->fetch_all(MYSQLI_ASSOC) : [];

$modules_res = $db->query("SELECT DISTINCT table_name FROM audit_logs ORDER BY table_name ASC");
$modules_list = $modules_res ? $modules_res->fetch_all(MYSQLI_ASSOC) : [];

/* ── COUNT & SUM (SUMMARY STATS) ── */
$stats = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(action='CREATE') AS creates,
        SUM(action='UPDATE') AS updates,
        SUM(action='DELETE') AS deletes,
        COUNT(DISTINCT performed_by) AS unique_users,
        SUM(DATE(created_at) = CURDATE()) AS today_count
    FROM audit_logs
")->fetch_assoc();

/* ── SESSION ── */
$first_name = e($_SESSION['first_name'] ?? '');
$last_name  = e($_SESSION['last_name']  ?? '');

/* ── HELPERS ── */
function buildQuery(array $overrides = [], array $remove = []): string {
    $params = $_GET;
    foreach ($remove as $k) unset($params[$k]);
    foreach ($overrides as $k => $v) $params[$k] = $v;
    unset($params['page']); // Always reset page when filter changes unless explicitly set
    if (isset($overrides['page'])) $params['page'] = $overrides['page'];
    return http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan – Audit Logs</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ── RESET & BASE ── */
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        body { background:#f8fafc; display:flex; height:100vh; overflow:hidden; }

        /* ── SIDEBAR ── */
        .nav-sidebar { width:260px; background:white; border-right:1px solid #e2e8f0;
            padding:25px; display:flex; flex-direction:column; flex-shrink:0; }
        .nav-logo { color:#334e5e; font-weight:700; font-size:1.3rem; margin-bottom:8px; letter-spacing:0.5px; }
        .nav-badge { display:inline-block; padding:2px 10px; background:white; color:white;
            border-radius:20px; font-size:0.7rem; text-transform:uppercase; letter-spacing:1px; margin-bottom:28px; }
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
        .header-actions { display:flex; align-items:center; gap:20px; color:#718096; }
        .admin-name-tag .sname { color:#1a202c; font-weight:700; font-size:0.9rem; display:block; text-align:right; }
        .admin-name-tag .srole { color:#e53e3e; font-size:0.72rem; text-transform:uppercase;
            letter-spacing:0.5px; font-weight:700; display:block; text-align:right; }
        .content-area { padding:36px 40px; overflow-y:auto; flex:1; }

        /* ── PAGE HEADER ── */
        .page-header { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:28px; }
        .header-text h1 { font-size:1.9rem; color:#1a202c; font-weight:700; }
        .header-text p  { color:#718096; margin-top:5px; font-size:0.95rem; }

        /* ── STAT CARDS ── */
        .stat-row { display:grid; grid-template-columns:repeat(6,1fr); gap:14px; margin-bottom:26px; }
        .stat-card { background:white; border-radius:14px; padding:18px 20px;
            box-shadow:0 2px 8px rgba(0,0,0,.05); border:1px solid #f0f4f8;
            display:flex; flex-direction:column; gap:6px; }
        .stat-val   { font-size:1.6rem; font-weight:700; color:#1a202c; line-height:1; }
        .stat-label { font-size:0.72rem; color:#a0aec0; font-weight:500; text-transform:uppercase; letter-spacing:0.5px; }
        .stat-icon  { width:34px; height:34px; border-radius:10px; display:flex;
            align-items:center; justify-content:center; font-size:0.95rem; margin-bottom:4px; }

        /* ── FILTER BAR ── */
        .filter-bar { background:white; border:1px solid #e2e8f0; border-radius:14px;
            padding:18px 22px; margin-bottom:20px;
            display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
        .filter-group { display:flex; flex-direction:column; gap:5px; }
        .filter-group label { font-size:0.74rem; font-weight:700; color:#718096;
            text-transform:uppercase; letter-spacing:0.4px; }
        .filter-group select,
        .filter-group input[type=text],
        .filter-group input[type=date] {
            padding:8px 12px; background:#f8fafc; border:1px solid #e2e8f0;
            border-radius:8px; font-size:0.85rem; font-family:inherit;
            color:#1a202c; outline-color:#334e5e; min-width:130px; }
        .filter-group select:focus,
        .filter-group input:focus { background:white; border-color:#334e5e; }
        .filter-actions { display:flex; gap:8px; align-items:flex-end; margin-left:auto; }
        .btn-filter { padding:9px 20px; background:#334e5e; color:white; border:none;
            border-radius:8px; font-size:0.85rem; font-weight:600; cursor:pointer;
            font-family:inherit; transition:0.2s; display:inline-flex; align-items:center; gap:6px; }
        .btn-filter:hover { background:#2a3f4d; }
        .btn-reset  { padding:9px 18px; background:#f1f5f9; color:#718096; border:1px solid #e2e8f0;
            border-radius:8px; font-size:0.85rem; font-weight:600; cursor:pointer;
            font-family:inherit; transition:0.2s; text-decoration:none;
            display:inline-flex; align-items:center; gap:6px; }
        .btn-reset:hover { background:#e2e8f0; }

        /* ── TABLE CARD ── */
        .table-card { background:white; border-radius:15px; border:1px solid #e2e8f0; padding:24px; }
        .table-card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; }
        .table-card-header h2 { font-size:1rem; font-weight:700; color:#334e5e; }
        .table-card-header span { font-size:0.82rem; color:#a0aec0; }
        table { width:100%; border-collapse:collapse; }
        thead th { text-align:left; padding:0 14px 13px; color:#a0aec0;
            font-size:0.72rem; text-transform:uppercase; letter-spacing:0.05em;
            border-bottom:1px solid #f1f5f9; white-space:nowrap; }
        tbody td { padding:13px 14px; font-size:0.88rem; color:#4a5568;
            border-bottom:1px solid #f8fafc; vertical-align:middle; }
        tbody tr:last-child td { border-bottom:none; }
        tbody tr:hover td { background:#fafbfc; }

        /* ── ACTION BADGE ── */
        .action-badge { display:inline-flex; align-items:center; gap:5px;
            padding:4px 11px; border-radius:20px; font-size:0.72rem; font-weight:700;
            text-transform:uppercase; letter-spacing:0.5px; white-space:nowrap; }
        .action-CREATE { background:#f0fff4; color:#276749; }
        .action-UPDATE { background:#ebf8ff; color:#2b6cb0; }
        .action-DELETE { background:#fff5f5; color:#c53030; }
        .action-LOGIN  { background:#faf5ff; color:#6b46c1; }
        .action-LOGOUT { background:#fffaf0; color:#c05621; }
        .action-VIEW   { background:#f0f4ff; color:#3c51a0; }
        .action-EXPORT { background:#f0fdfa; color:#0d7377; }
        .action-DEFAULT{ background:#f1f5f9; color:#718096; }

        /* ── ROLE PILL ── */
        .pill { display:inline-block; padding:3px 10px; border-radius:20px;
            font-size:0.7rem; font-weight:700; text-transform:capitalize; }
        .pill-admin       { background:#fff5f5; color:#c53030; }
        .pill-staff       { background:#ebf8ff; color:#2b6cb0; }
        .pill-participant { background:#f0fff4; color:#276749; }
        .pill-system      { background:#f1f5f9; color:#718096; }

        /* ── USER CELL ── */
        .user-cell { display:flex; align-items:center; gap:10px; }
        .user-initials { width:32px; height:32px; border-radius:50%; background:#edf2f7;
            display:flex; align-items:center; justify-content:center;
            color:#718096; font-weight:700; font-size:0.7rem; flex-shrink:0; }
        .user-cell-info .uname  { font-weight:700; color:#1a202c; font-size:0.86rem; display:block; }
        .user-cell-info .uemail { font-size:0.72rem; color:#a0aec0; }

        /* ── MODULE TAG ── */
        .module-tag { font-size:0.78rem; font-weight:600; color:#4a5568;
            background:#f1f5f9; padding:3px 9px; border-radius:6px;
            display:inline-block; white-space:nowrap; }

        /* ── DETAILS CELL ── */
        .details-cell { font-size:0.8rem; color:#718096; max-width:220px;
            overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

        /* ── TIMESTAMP ── */
        .ts-main { font-size:0.82rem; color:#4a5568; font-weight:500; }
        .ts-rel  { font-size:0.72rem; color:#a0aec0; margin-top:1px; }

        /* ── IP ADDRESS ── */
        .ip-tag { font-size:0.75rem; color:#a0aec0; font-family:monospace; }

        /* ── EMPTY STATE ── */
        .empty-state { text-align:center; padding:60px 30px; color:#a0aec0; }
        .empty-state i { font-size:2.5rem; margin-bottom:16px; display:block; opacity:0.4; }
        .empty-state p { font-size:0.95rem; }

        /* ── PAGINATION ── */
        .pagination { display:flex; gap:8px; justify-content:center; margin-top:22px; flex-wrap:wrap; }
        .pagination a, .pagination span {
            padding:8px 14px; border-radius:8px; border:1px solid #e2e8f0;
            background:white; color:#4a5568; font-size:0.83rem; text-decoration:none; font-weight:600; }
        .pagination a:hover  { background:#f1f5f9; }
        .pagination .current { background:#334e5e; color:white; border-color:#334e5e; }
        .pagination .dots    { border:none; background:none; color:#a0aec0; padding:8px 4px; }

        /* ── EXPORT BTN ── */
        .btn-export { padding:9px 18px; background:white; color:#334e5e;
            border:1.5px solid #334e5e; border-radius:8px; font-size:0.85rem;
            font-weight:600; cursor:pointer; font-family:inherit; transition:0.2s;
            display:inline-flex; align-items:center; gap:7px; text-decoration:none; }
        .btn-export:hover { background:#334e5e; color:white; }

        /* ── SEARCH ── */
        .search-clear-btn { position:absolute; right:12px; top:50%; transform:translateY(-50%);
            background:none; border:none; cursor:pointer; color:#a0aec0; font-size:0.75rem;
            display:none; padding:2px 4px; border-radius:50%; line-height:1; transition:color 0.15s; }
        .search-clear-btn:hover { color:#718096; }

        /* ── DETAIL TOOLTIP ── */
        .detail-tooltip { position:relative; cursor:default; }
        .detail-tooltip:hover .tooltip-box { display:block; }
        .tooltip-box { display:none; position:absolute; left:0; top:calc(100% + 4px);
            background:#1a202c; color:white; font-size:0.75rem; padding:8px 12px;
            border-radius:8px; white-space:pre-wrap; z-index:100; min-width:200px;
            max-width:320px; word-break:break-word; line-height:1.5;
            box-shadow:0 8px 24px rgba(0,0,0,.25); }
    </style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<nav class="nav-sidebar">
    <div class="nav-logo">Bayanihan</div>
    <div class="nav-badge">Admin</div>
    <a href="admin_dashboard.php"       class="nav-item"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="admin_program.php"         class="nav-item"><i class="fa-solid fa-shapes"></i> Programs</a>
    <a href="admin_participant.php"     class="nav-item"><i class="fa-solid fa-users"></i> Participants</a>
    <a href="admin_user_management.php" class="nav-item"><i class="fa-solid fa-user-shield"></i> User Management</a>
    <a href="admin_report.php"          class="nav-item"><i class="fa-solid fa-chart-bar"></i> Reports</a>
    <a href="admin_audit_log.php"       class="nav-item active"><i class="fa-solid fa-clipboard-list"></i> Audit Logs</a>
    <div class="nav-footer">
        <a href="logout.php" class="nav-item"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</nav>

<!-- ── MAIN ── -->
<div class="main-wrapper">

    <!-- Top Header -->
    <header class="top-header">
        <form id="searchForm" method="GET" style="display:contents;">
            <?php
            // Preserve all existing GET params except search & page
            foreach ($_GET as $k => $v) {
                if (!in_array($k, ['search','page'])) {
                    echo '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';
                }
            }
            ?>
            <div style="position:relative;">
                <i class="fa-solid fa-magnifying-glass"
                   style="position:absolute;left:15px;top:50%;transform:translateY(-50%);color:#a0aec0;pointer-events:none;"></i>
                <input id="globalSearch" type="text" name="search"
                       placeholder="Search by user name or email…"
                       value="<?= e($search) ?>"
                       style="width:360px;padding:10px 38px 10px 45px;background:#f1f5f9;border:none;border-radius:10px;outline-color:#334e5e;font-size:0.9rem;font-family:inherit;">
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

        <!-- Page Title -->
        <div class="page-header">
            <div class="header-text">
                <h1>Audit Logs</h1>
                <p>Track all system activities performed by admins, staff, and participants.</p>
            </div>
            <a href="?<?= e(buildQuery(['export' => 'csv'])) ?>" class="btn-export">
                <i class="fa-solid fa-download"></i> Export CSV
            </a>
        </div>

        <!-- Summary Stats -->
        <div class="stat-row">
            <?php
            $stat_cards = [
                ['val' => number_format((int)($stats['total']       ?? 0)), 'label' => 'Total Events',    'icon' => 'fa-list-check',   'bg' => '#ebf8ff', 'ic' => '#3182ce'],
                ['val' => number_format((int)($stats['today_count'] ?? 0)), 'label' => 'Events Today',    'icon' => 'fa-calendar-day', 'bg' => '#f0fff4', 'ic' => '#38a169'],
                ['val' => number_format((int)($stats['creates']     ?? 0)), 'label' => 'Creates',         'icon' => 'fa-plus-circle',  'bg' => '#f0fff4', 'ic' => '#276749'],
                ['val' => number_format((int)($stats['updates']     ?? 0)), 'label' => 'Updates',         'icon' => 'fa-pen-to-square','bg' => '#ebf8ff', 'ic' => '#2b6cb0'],
                ['val' => number_format((int)($stats['deletes']     ?? 0)), 'label' => 'Deletes',         'icon' => 'fa-trash',        'bg' => '#fff5f5', 'ic' => '#c53030'],
                ['val' => number_format((int)($stats['unique_users']?? 0)), 'label' => 'Active Users',    'icon' => 'fa-user-check',   'bg' => '#faf5ff', 'ic' => '#805ad5'],
            ];
            foreach ($stat_cards as $sc): ?>
            <div class="stat-card">
                <div class="stat-icon" style="background:<?= $sc['bg'] ?>;color:<?= $sc['ic'] ?>;">
                    <i class="fa-solid <?= $sc['icon'] ?>"></i>
                </div>
                <div class="stat-val"><?= $sc['val'] ?></div>
                <div class="stat-label"><?= $sc['label'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Filter Bar -->
        <form method="GET" id="filterForm">
            <div class="filter-bar">
                <div class="filter-group">
                    <label>Action</label>
                    <select name="action_filter" onchange="this.form.submit()">
                        <option value="all" <?= $filter_action==='all'?'selected':'' ?>>All Actions</option>
                        <?php foreach ($actions_list as $a): ?>
                        <option value="<?= e($a['action']) ?>" <?= $filter_action===$a['action']?'selected':'' ?>>
                            <?= e(ucfirst(strtolower($a['action']))) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Module</label>
                    <select name="module_filter" onchange="this.form.submit()">
                        <option value="all" <?= $filter_module==='all'?'selected':'' ?>>All Modules</option>
                        <?php foreach ($modules_list as $m): ?>
                        <option value="<?= e($m['table_name']) ?>" <?= $filter_module===$m['table_name']?'selected':'' ?>>
                            <?= e(ucwords(str_replace('_',' ',$m['table_name']))) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>User Role</label>
                    <select name="role_filter" onchange="this.form.submit()">
                        <option value="all"         <?= $filter_role==='all'        ?'selected':'' ?>>All Roles</option>
                        <option value="admin"       <?= $filter_role==='admin'      ?'selected':'' ?>>Admin</option>
                        <option value="staff"       <?= $filter_role==='staff'      ?'selected':'' ?>>Staff</option>
                        <option value="participant" <?= $filter_role==='participant'?'selected':'' ?>>Participant</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>From Date</label>
                    <input type="date" name="date_from" value="<?= e($date_from) ?>">
                </div>

                <div class="filter-group">
                    <label>To Date</label>
                    <input type="date" name="date_to" value="<?= e($date_to) ?>">
                </div>

                <!-- Preserve search if active -->
                <?php if ($search !== ''): ?>
                <input type="hidden" name="search" value="<?= e($search) ?>">
                <?php endif; ?>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter">
                        <i class="fa-solid fa-filter"></i> Apply
                    </button>
                    <a href="admin_audit_log.php" class="btn-reset">
                        <i class="fa-solid fa-rotate-left"></i> Reset
                    </a>
                </div>
            </div>
        </form>

        <!-- Logs Table -->
        <div class="table-card">
            <div class="table-card-header">
                <h2>Activity Log</h2>
                <span>
                    <?php if ($total_count > 0): ?>
                        Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $total_count)) ?>
                        of <?= number_format($total_count) ?> records
                    <?php else: ?>
                        No records found
                    <?php endif; ?>
                </span>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Module / Record</th>
                        <th>Details</th>
                        <th>IP Address</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="fa-solid fa-clipboard-list"></i>
                                <p>No audit log entries match your filters.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $i => $log):
                        $row_num = $offset + $i + 1;

                        /* User display */
                        $has_user   = !empty($log['first_name']);
                        $full_name  = $has_user ? e($log['first_name'] . ' ' . $log['last_name']) : 'System';
                        $email      = $has_user ? e($log['email']) : '—';
                        $initials   = $has_user
                            ? strtoupper(substr($log['first_name'],0,1) . substr($log['last_name'],0,1))
                            : 'SY';

                        /* Role */
                        $role       = $log['role'] ?? 'system';
                        $role_label = ucfirst($role);

                        /* Action badge class */
                        $act        = strtoupper($log['action'] ?? '');
                        $badge_cls  = in_array($act, ['CREATE','UPDATE','DELETE','LOGIN','LOGOUT','VIEW','EXPORT'])
                                      ? 'action-' . $act : 'action-DEFAULT';

                        /* Action icon */
                        $act_icons  = [
                            'CREATE' => 'fa-plus',      'UPDATE' => 'fa-pen',
                            'DELETE' => 'fa-trash',     'LOGIN'  => 'fa-right-to-bracket',
                            'LOGOUT' => 'fa-right-from-bracket', 'VIEW' => 'fa-eye',
                            'EXPORT' => 'fa-download',
                        ];
                        $act_icon   = $act_icons[$act] ?? 'fa-bolt';

                        /* Module */
                        $module_raw = $log['table_name'] ?? '';
                        $module_lbl = ucwords(str_replace('_', ' ', $module_raw));

                        /* Details */
                        $details_raw = $log['details'] ?? '';

                        /* Timestamp */
                        $ts   = strtotime($log['created_at']);
                        $now  = time();
                        $diff = $now - $ts;
                        if ($diff < 60)          $rel = 'Just now';
                        elseif ($diff < 3600)    $rel = floor($diff/60) . 'm ago';
                        elseif ($diff < 86400)   $rel = floor($diff/3600) . 'h ago';
                        elseif ($diff < 604800)  $rel = floor($diff/86400) . 'd ago';
                        else                     $rel = date('M j, Y', $ts);
                    ?>
                    <tr>
                        <td style="font-size:0.78rem;color:#a0aec0;font-weight:600;"><?= $row_num ?></td>

                        <!-- User -->
                        <td>
                            <div class="user-cell">
                                <div class="user-initials" style="<?= $has_user ? '' : 'background:#edf2f7;color:#a0aec0;' ?>">
                                    <?= $initials ?>
                                </div>
                                <div class="user-cell-info">
                                    <span class="uname"><?= $full_name ?></span>
                                    <span class="uemail"><?= $email ?></span>
                                </div>
                            </div>
                        </td>

                        <!-- Role -->
                        <td>
                            <span class="pill pill-<?= e($role) ?>"><?= $role_label ?></span>
                        </td>

                        <!-- Action -->
                        <td>
                            <span class="action-badge <?= $badge_cls ?>">
                                <i class="fa-solid <?= $act_icon ?>"></i>
                                <?= e(ucfirst(strtolower($log['action'] ?? ''))) ?>
                            </span>
                        </td>

                        <!-- Module / Record -->
                        <td>
                            <span class="module-tag"><?= e($module_lbl) ?></span>
                            <?php if ($log['record_id']): ?>
                            <span style="font-size:0.72rem;color:#a0aec0;margin-left:4px;">#<?= (int)$log['record_id'] ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- Details -->
                        <td>
                            <?php if ($details_raw): ?>
                            <div class="detail-tooltip">
                                <span class="details-cell"><?= e($details_raw) ?></span>
                                <div class="tooltip-box"><?= e($details_raw) ?></div>
                            </div>
                            <?php else: ?>
                            <span style="color:#e2e8f0;">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- IP Address -->
                        <td>
                            <?php if ($log['ip_address']): ?>
                            <span class="ip-tag"><?= e($log['ip_address']) ?></span>
                            <?php else: ?>
                            <span style="color:#e2e8f0;">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Timestamp -->
                        <td>
                            <div class="ts-main"><?= date('M j, Y · g:i A', $ts) ?></div>
                            <div class="ts-rel"><?= $rel ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?<?= e(buildQuery(['page' => $page - 1])) ?>">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
                <?php endif; ?>

                <?php
                $start = max(1, $page - 2);
                $end   = min($total_pages, $page + 2);
                if ($start > 1) {
                    echo '<a href="?' . e(buildQuery(['page' => 1])) . '">1</a>';
                    if ($start > 2) echo '<span class="dots">…</span>';
                }
                for ($i = $start; $i <= $end; $i++):
                    if ($i === $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?<?= e(buildQuery(['page' => $i])) ?>"><?= $i ?></a>
                    <?php endif;
                endfor;
                if ($end < $total_pages) {
                    if ($end < $total_pages - 1) echo '<span class="dots">…</span>';
                    echo '<a href="?' . e(buildQuery(['page' => $total_pages])) . '">' . $total_pages . '</a>';
                }
                ?>

                <?php if ($page < $total_pages): ?>
                <a href="?<?= e(buildQuery(['page' => $page + 1])) ?>">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<?php
/* ── CSV EXPORT ── */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // (LEFT JOIN Re-fetch all matching rows without limit for export)
    $export_sql = "
        SELECT al.log_id, al.action, al.table_name, al.record_id, al.details, al.ip_address, al.created_at,
               u.first_name, u.last_name, u.email, u.role
        FROM audit_logs al
        LEFT JOIN users u ON u.user_id = al.performed_by
        $where_clause
        ORDER BY al.created_at DESC
    ";
    if ($params) {
        // Remove the LIMIT params (last two)
        $export_params = $params; // no limit/offset for export
        $export_types  = $types;
        $stmt = $db->prepare($export_sql);
        $stmt->bind_param($export_types, ...$export_params);
        $stmt->execute();
        $export_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $export_rows = $db->query($export_sql)->fetch_all(MYSQLI_ASSOC);
    }

    // Output inline script to trigger download via data URI
    $csv  = "Log ID,User,Email,Role,Action,Module,Record ID,Details,IP Address,Timestamp\n";
    foreach ($export_rows as $r) {
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'System';
        $line = [
            $r['log_id'],
            $name,
            $r['email'] ?? '',
            $r['role']  ?? '',
            $r['action'],
            $r['table_name'],
            $r['record_id'] ?? '',
            str_replace(["\r","\n",'"'], ['',' ','""'], $r['details'] ?? ''),
            $r['ip_address'] ?? '',
            $r['created_at'],
        ];
        $csv .= implode(',', array_map(fn($v) => '"' . $v . '"', $line)) . "\n";
    }
    $b64 = base64_encode($csv);
    echo "<script>
        (function(){
            const a = document.createElement('a');
            a.href = 'data:text/csv;base64,{$b64}';
            a.download = 'audit_logs_" . date('Y-m-d') . ".csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            // Strip ?export from URL
            const u = new URL(window.location.href);
            u.searchParams.delete('export');
            window.history.replaceState(null,'',u.toString());
        })();
    </script>";
}
?>

<script>
/* ── Search clear button ── */
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
</script>
</body>
</html>