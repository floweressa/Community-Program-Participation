<?php
// staff_program.php

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

/* ── DB ── */
$db      = getDB();
$user_id = (int) $_SESSION['user_id'];
$message  = '';
$msg_type = '';

/* ════════════════════════════════════════════════════════════
 * Status computation helper
 * Rule order:
 *   1. DB status === 'cancelled'  → cancelled  (always honoured)
 *   2. today < start_date         → upcoming
 *   3. today > end_date           → completed
 *   4. otherwise                  → ongoing
 * ════════════════════════════════════════════════════════════ */
function computeStatus(string $dbStatus, string $startDate, string $endDate): string {
    if ($dbStatus === 'cancelled') return 'cancelled';
    $today = date('Y-m-d');
    if ($today < $startDate) return 'upcoming';
    if ($today > $endDate)   return 'completed';
    return 'ongoing';
}

const STATUS_LIST = ['upcoming', 'ongoing', 'completed', 'cancelled'];

const STATUS_COLORS = [
    'upcoming'  => '#3182ce',
    'ongoing'   => '#38a169',
    'completed' => '#718096',
    'cancelled' => '#e53e3e',
];

/* ═══════════════════════════
 * POST HANDLERS
 * ═══════════════════════════ */

/* ── DELETE ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_program_id'])) {
    $pid  = (int) $_POST['delete_program_id'];
    $stmt = $db->prepare("DELETE FROM programs WHERE program_id = ?");
    $stmt->bind_param('i', $pid);
    $stmt->execute()
        ? ($message = 'Program deleted successfully.') && ($msg_type = 'success')
        : ($message = 'Delete failed. Please try again.') && ($msg_type = 'error');
    $stmt->close();
}

/* ── CREATE ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_program'])) {
    $name     = trim($_POST['program_name']   ?? '');
    $desc     = trim($_POST['description']    ?? '');
    $sdate    = $_POST['start_date']          ?? '';
    $edate    = $_POST['end_date']            ?? '';
    $stime    = $_POST['start_time']          ?? null;
    $etime    = $_POST['end_time']            ?? null;
    $location = trim($_POST['location']       ?? '');
    $maxp     = strlen(trim($_POST['max_participants'] ?? '')) ? (int)$_POST['max_participants'] : null;

    if (!$name || !$sdate || !$edate) {
        $message  = 'Program name, start date, and end date are required.';
        $msg_type = 'error';
    } elseif ($edate < $sdate) {
        $message  = 'End date cannot be earlier than start date.';
        $msg_type = 'error';
    } else {
        $status = computeStatus('', $sdate, $edate);
        if (($_POST['status'] ?? '') === 'cancelled') $status = 'cancelled';

        $stmt = $db->prepare(
            "INSERT INTO programs
             (program_name, description, start_date, end_date, start_time, end_time,
              location, status, max_participants, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('ssssssssii',
            $name, $desc, $sdate, $edate, $stime, $etime,
            $location, $status, $maxp, $user_id);
        if ($stmt->execute()) {
            $message  = "Program \"{$name}\" created successfully.";
            $msg_type = 'success';
        } else {
            $message  = 'Failed to create program. Please try again.';
            $msg_type = 'error';
        }
        $stmt->close();
    }
}

/* ── UPDATE ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_program'])) {
    $pid      = (int) $_POST['program_id'];
    $name     = trim($_POST['program_name']   ?? '');
    $desc     = trim($_POST['description']    ?? '');
    $sdate    = $_POST['start_date']          ?? '';
    $edate    = $_POST['end_date']            ?? '';
    $stime    = $_POST['start_time']          ?? null;
    $etime    = $_POST['end_time']            ?? null;
    $location = trim($_POST['location']       ?? '');
    $maxp     = strlen(trim($_POST['max_participants'] ?? '')) ? (int)$_POST['max_participants'] : null;

    $rawStatus = $_POST['status'] ?? 'upcoming';
    $status    = computeStatus($rawStatus, $sdate, $edate);

    $stmt = $db->prepare(
        "UPDATE programs
         SET program_name=?, description=?, start_date=?, end_date=?,
             start_time=?, end_time=?, location=?, status=?, max_participants=?
         WHERE program_id=?"
    );
    $stmt->bind_param('ssssssssii',
        $name, $desc, $sdate, $edate, $stime, $etime,
        $location, $status, $maxp, $pid);
    if ($stmt->execute()) {
        $message  = 'Program updated successfully.';
        $msg_type = 'success';
    } else {
        $message  = 'Update failed. Please try again.';
        $msg_type = 'error';
    }
    $stmt->close();
}

/* ═══════════════════════════
 * FETCH + FILTER PROGRAMS
 * ═══════════════════════════ */
$filter = in_array($_GET['status'] ?? '', STATUS_LIST) ? $_GET['status'] : 'all';
$search = trim($_GET['search'] ?? '');

$sql    = "SELECT p.*,
                  (SELECT COUNT(*) FROM participation pr
                   WHERE pr.program_id = p.program_id AND pr.status != 'cancelled') AS joined_count
           FROM programs p WHERE 1=1";
$params = [];
$types  = '';

if ($filter !== 'all') {
    $sql      .= " AND p.status = ?";
    $params[]  = $filter;
    $types    .= 's';
}
if ($search) {
    $sql      .= " AND p.program_name LIKE ?";
    $params[]  = '%' . $search . '%';
    $types    .= 's';
}
$sql .= " ORDER BY p.start_date ASC";

if ($params) {
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $programs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $programs = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
}

/* Re-sync computed status into the DB if it has drifted */
foreach ($programs as &$p) {
    $computed = computeStatus($p['status'], $p['start_date'], $p['end_date']);
    if ($computed !== $p['status']) {
        $sync = $db->prepare("UPDATE programs SET status=? WHERE program_id=?");
        $sync->bind_param('si', $computed, $p['program_id']);
        $sync->execute();
        $sync->close();
        $p['status'] = $computed;
    }
}
unset($p);

if ($filter !== 'all') {
    $programs = array_values(array_filter($programs, fn($p) => $p['status'] === $filter));
}

$first_name = e($_SESSION['first_name'] ?? '');
$last_name  = e($_SESSION['last_name']  ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - Program Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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

        /* ── PAGE HEADER ── */
        .page-header { display:flex; justify-content:space-between;
            align-items:flex-end; margin-bottom:30px; }
        .header-text h1 { font-size:2rem; color:#1a202c; font-weight:700; }
        .header-text p { color:#718096; margin-top:5px; font-size:0.95rem; }

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

        /* ── CHIPS ── */
        .chip { padding:8px 18px; background:white; border:1px solid #e2e8f0;
            border-radius:30px; font-size:0.85rem; color:#718096; text-decoration:none;
            transition:0.2s; display:inline-flex; align-items:center; gap:5px; }
        .chip.active { background:#334e5e; color:white; border-color:#334e5e; }
        .chip:hover:not(.active) { background:#f8fafc; border-color:#cbd5e0; color:#334e5e; }

        /* ── MODAL ── */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4);
            z-index:1000; align-items:center; justify-content:center; }
        .modal-overlay.open { display:flex; }
        .modal { background:white; border-radius:20px; padding:35px; width:100%;
            max-width:540px; box-shadow:0 20px 40px rgba(0,0,0,0.15); }
        .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; }
        .modal-header h2 { font-size:1.3rem; color:#1a202c; }
        .modal-close { background:none; border:none; font-size:1.2rem; color:#a0aec0; cursor:pointer; }
        .modal-close:hover { color:#e53e3e; }
        .modal-grid { display:grid; grid-template-columns:1fr 1fr; gap:15px; }
        .modal-grid .full { grid-column:span 2; }
        .form-group { display:flex; flex-direction:column; gap:6px; }
        .form-group label { font-size:0.75rem; font-weight:700; color:#718096; text-transform:uppercase; }
        .form-group input, .form-group select, .form-group textarea {
            padding:11px 14px; background:#f8fafc; border:1px solid #e2e8f0;
            border-radius:10px; font-size:0.9rem; color:#2d3748;
            outline-color:#334e5e; width:100%; font-family:inherit; }
        .form-group textarea { resize:vertical; min-height:70px; }
        .modal-footer { display:flex; justify-content:flex-end; gap:12px; margin-top:25px; }

        /* ── PROGRAM GRID ── */
        .prog-filter-row { display:flex; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:28px; }
        .prog-chips { display:flex; gap:8px; flex-wrap:wrap; }
        .prog-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:25px; }
        .prog-card { background:white; border-radius:20px; border:1px solid #e2e8f0;
            overflow:hidden; display:flex; flex-direction:column;
            transition:transform 0.2s, box-shadow 0.2s; }
        .prog-card:hover { transform:translateY(-4px); box-shadow:0 12px 30px -8px rgba(0,0,0,0.1); }
        .prog-card-stripe { height:6px; flex-shrink:0; }
        .prog-card-body { padding:22px 24px 20px; display:flex; flex-direction:column; flex:1; }
        .prog-card-title-row { display:flex; justify-content:space-between;
            align-items:flex-start; gap:10px; margin-bottom:10px; }
        .prog-card-title { font-size:1.05rem; color:#1a202c; font-weight:700; line-height:1.35; }
        .prog-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px;
            border-radius:20px; font-size:0.75rem; font-weight:700; white-space:nowrap; flex-shrink:0; }
        .prog-card-desc { font-size:0.85rem; color:#718096; line-height:1.55; margin-bottom:18px; flex:1; }
        .prog-card-meta { border-top:1px solid #f1f5f9; padding-top:14px; margin-bottom:18px; }
        .prog-meta-row { display:flex; justify-content:space-between; align-items:center;
            margin-bottom:7px; font-size:0.82rem; }
        .prog-meta-label { color:#a0aec0; display:flex; align-items:center; gap:6px; }
        .prog-meta-value { color:#4a5568; font-weight:600; text-align:right; }
        .prog-card-actions { display:flex; gap:10px; padding-top:14px; border-top:1px solid #f1f5f9; }
        .prog-card-btn { flex:1; justify-content:center; padding:9px 12px; }
        .prog-empty { text-align:center; padding:70px 20px; color:#718096; }
        .prog-empty i { font-size:3rem; color:#cbd5e0; display:block; margin-bottom:15px; }
        .prog-empty p { font-size:0.95rem; }
        .req { color:#e53e3e; }
        .form-hint { font-size:0.75rem; color:#a0aec0; font-weight:400; margin-left:4px; }
    </style>
</head>
<body>

<!-- ── Sidebar ── -->
<nav class="nav-sidebar">
    <div class="nav-logo">Bayanihan</div>
    <a href="staff_dashboard.php"     class="nav-item"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="staff_program.php"       class="nav-item active"><i class="fa-solid fa-shapes"></i> Programs</a>
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
<form method="GET" style="display:contents;">
        <?php if ($filter !== 'all'): ?><input type='hidden' name='status' value='<?= htmlspecialchars($filter) ?>'><?php endif; ?>
        <div class="header-search-wrap" style="position:relative;">
            <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:15px;top:50%;transform:translateY(-50%);color:#a0aec0;pointer-events:none;z-index:1;"></i>
            <input id="globalSearch" type="text" name="search" placeholder="Search programs..."
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

        <?php if ($message): ?>
        <div class="alert alert-<?= $msg_type ?>"><?= e($message) ?></div>
        <?php endif; ?>

        <div class="page-header">
            <div class="header-text">
                <h1>Program Management</h1>
                <p>Track, edit, and organise community initiatives.</p>
            </div>
            <button class="btn-primary" onclick="openModal('modalCreate')">
                <i class="fa-solid fa-plus"></i> Create Program
            </button>
        </div>

        <!-- Filter Row -->
        <div class="prog-filter-row">
            <div class="prog-chips">
                <a href="staff_program.php<?= $search ? '?search='.urlencode($search) : '' ?>"
                   class="chip <?= $filter === 'all' ? 'active' : '' ?>">All</a>
                <?php foreach (STATUS_LIST as $s): ?>
                    <a href="?status=<?= $s ?><?= $search ? '&search='.urlencode($search) : '' ?>"
                       class="chip <?= $filter === $s ? 'active' : '' ?>">
                        <?= ucfirst($s) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Program Cards -->
        <?php if (empty($programs)): ?>
        <div class="prog-empty">
            <i class="fa-solid fa-folder-open"></i>
            <p>No programs found<?= $filter !== 'all' ? ' for status <strong>'.ucfirst($filter).'</strong>' : '' ?>.</p>
        </div>
        <?php else: ?>
        <div class="prog-grid">
            <?php foreach ($programs as $p):
                $sc       = STATUS_COLORS[$p['status']] ?? '#718096';
                $capacity = $p['max_participants']
                    ? $p['joined_count'] . ' / ' . $p['max_participants']
                    : $p['joined_count'] . ' / Unlimited';
                $details = [
                    ['label' => 'Start Date',   'icon' => 'fa-calendar-days',  'value' => date('M j, Y', strtotime($p['start_date']))],
                    ['label' => 'End Date',     'icon' => 'fa-calendar-check', 'value' => date('M j, Y', strtotime($p['end_date']))],
                    ['label' => 'Location',     'icon' => 'fa-location-dot',   'value' => e($p['location'] ?? '—')],
                    ['label' => 'Participants', 'icon' => 'fa-users',           'value' => $capacity],
                ];
                if ($p['start_time']) {
                    $details[] = ['label' => 'Time', 'icon' => 'fa-clock',
                        'value' => date('g:i A', strtotime($p['start_time'])) . ' – ' . date('g:i A', strtotime($p['end_time']))];
                }
            ?>
            <div class="prog-card">
                <div class="prog-card-stripe" style="background:<?= $sc ?>;"></div>
                <div class="prog-card-body">
                    <div class="prog-card-title-row">
                        <h3 class="prog-card-title"><?= e($p['program_name']) ?></h3>
                        <span class="prog-badge" style="color:<?= $sc ?>;border:1px solid <?= $sc ?>20;background:<?= $sc ?>12;">
                            <?= ucfirst($p['status']) ?>
                        </span>
                    </div>
                    <p class="prog-card-desc">
                        <?= e(mb_strimwidth($p['description'] ?? '', 0, 110, '…')) ?>
                    </p>
                    <div class="prog-card-meta">
                        <?php foreach ($details as $d): ?>
                        <div class="prog-meta-row">
                            <span class="prog-meta-label">
                                <i class="fa-solid <?= $d['icon'] ?>"></i> <?= $d['label'] ?>
                            </span>
                            <span class="prog-meta-value"><?= $d['value'] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="prog-card-actions">
                        <button class="btn-secondary prog-card-btn"
                                onclick='openEditModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)'>
                            <i class="fa-solid fa-pen"></i> Edit
                        </button>
                        <form method="POST" style="flex:1;"
                              onsubmit="return confirm('Permanently delete this program? This cannot be undone.');">
                            <input type="hidden" name="delete_program_id" value="<?= $p['program_id'] ?>">
                            <button type="submit" class="btn-danger prog-card-btn" style="width:100%;">
                                <i class="fa-solid fa-trash"></i> Delete
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </main>
</div><!-- /.main-wrapper -->

<!-- ── CREATE MODAL ── -->
<div class="modal-overlay" id="modalCreate">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fa-solid fa-plus" style="color:#334e5e;margin-right:8px;"></i>Create Program</h2>
            <button class="modal-close" onclick="closeModal('modalCreate')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="create_program" value="1">
            <div class="modal-grid">
                <div class="form-group full">
                    <label>Program Name <span class="req">*</span></label>
                    <input type="text" name="program_name" placeholder="e.g. Urban Gardening Initiative" required>
                </div>
                <div class="form-group full">
                    <label>Description</label>
                    <textarea name="description" placeholder="Brief program description…"></textarea>
                </div>
                <div class="form-group">
                    <label>Start Date <span class="req">*</span></label>
                    <input type="date" name="start_date" required>
                </div>
                <div class="form-group">
                    <label>End Date <span class="req">*</span></label>
                    <input type="date" name="end_date" required>
                </div>
                <div class="form-group">
                    <label>Start Time</label>
                    <input type="time" name="start_time">
                </div>
                <div class="form-group">
                    <label>End Time</label>
                    <input type="time" name="end_time">
                </div>
                <div class="form-group full">
                    <label>Location</label>
                    <input type="text" name="location" placeholder="e.g. Community Hall Room 3">
                </div>
                <div class="form-group">
                    <label>Override Status
                        <span class="form-hint">(auto-computed from dates unless cancelled)</span>
                    </label>
                    <select name="status">
                        <option value="">Auto (from dates)</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Max Participants</label>
                    <input type="number" name="max_participants" placeholder="Leave blank for unlimited" min="1">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('modalCreate')">Cancel</button>
                <button type="submit" class="btn-primary"><i class="fa-solid fa-check"></i> Create Program</button>
            </div>
        </form>
    </div>
</div>

<!-- ── EDIT MODAL ── -->
<div class="modal-overlay" id="modalEdit">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fa-solid fa-pen" style="color:#334e5e;margin-right:8px;"></i>Edit Program</h2>
            <button class="modal-close" onclick="closeModal('modalEdit')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="editForm">
            <input type="hidden" name="update_program" value="1">
            <input type="hidden" name="program_id"    id="edit_program_id">
            <div class="modal-grid">
                <div class="form-group full">
                    <label>Program Name <span class="req">*</span></label>
                    <input type="text" name="program_name" id="edit_name" required>
                </div>
                <div class="form-group full">
                    <label>Description</label>
                    <textarea name="description" id="edit_desc"></textarea>
                </div>
                <div class="form-group">
                    <label>Start Date <span class="req">*</span></label>
                    <input type="date" name="start_date" id="edit_sdate" required>
                </div>
                <div class="form-group">
                    <label>End Date <span class="req">*</span></label>
                    <input type="date" name="end_date" id="edit_edate" required>
                </div>
                <div class="form-group">
                    <label>Start Time</label>
                    <input type="time" name="start_time" id="edit_stime">
                </div>
                <div class="form-group">
                    <label>End Time</label>
                    <input type="time" name="end_time" id="edit_etime">
                </div>
                <div class="form-group full">
                    <label>Location</label>
                    <input type="text" name="location" id="edit_location">
                </div>
                <div class="form-group">
                    <label>Status
                        <span class="form-hint">(auto-computed unless cancelled)</span>
                    </label>
                    <select name="status" id="edit_status">
                        <option value="upcoming">Upcoming</option>
                        <option value="ongoing">Ongoing</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Max Participants</label>
                    <input type="number" name="max_participants" id="edit_maxp" min="1" placeholder="Unlimited">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('modalEdit')">Cancel</button>
                <button type="submit" class="btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => {
        if (e.target === overlay) overlay.classList.remove('open');
    });
});

function openEditModal(p) {
    document.getElementById('edit_program_id').value = p.program_id;
    document.getElementById('edit_name').value        = p.program_name;
    document.getElementById('edit_desc').value        = p.description || '';
    document.getElementById('edit_sdate').value       = p.start_date;
    document.getElementById('edit_edate').value       = p.end_date;
    document.getElementById('edit_stime').value       = p.start_time || '';
    document.getElementById('edit_etime').value       = p.end_time   || '';
    document.getElementById('edit_location').value    = p.location   || '';
    document.getElementById('edit_status').value      = p.status;
    document.getElementById('edit_maxp').value        = p.max_participants || '';
    openModal('modalEdit');
}
</script>
<script>
(function () {
    const input = document.getElementById('globalSearch');
    const btn   = document.getElementById('searchClearBtn');
    if (!input || !btn) return;

    function toggle() {
        btn.style.display = input.value.length > 0 ? 'block' : 'none';
    }
    toggle();
    input.addEventListener('input', toggle);

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