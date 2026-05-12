<?php
// admin_programs.php

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

function computeStatus(string $dbStatus, string $startDate, string $endDate): string {
    if ($dbStatus === 'cancelled') return 'cancelled';
    $today = date('Y-m-d');
    if ($today < $startDate) return 'upcoming';
    if ($today > $endDate)   return 'completed';
    return 'ongoing';
}

$db       = getDB();
$user_id  = (int) $_SESSION['user_id'];
$message  = '';
$msg_type = '';

/* ── DELETE ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_program_id'])) {
    $pid  = (int) $_POST['delete_program_id'];
    $stmt = $db->prepare("DELETE FROM programs WHERE program_id = ?");
    $stmt->bind_param('i', $pid);
    $stmt->execute()
        ? ($message = 'Program deleted.') && ($msg_type = 'success')
        : ($message = 'Delete failed.') && ($msg_type = 'error');
    $stmt->close();

    // Audit log
    $al = $db->prepare("INSERT INTO audit_logs (performed_by, action, table_name, record_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE action=action");
    if ($al) { $action='DELETE'; $tbl='programs'; $al->bind_param('issi',$user_id,$action,$tbl,$pid); $al->execute(); $al->close(); }
}

/* ── CREATE ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_program'])) {
    $name     = trim($_POST['program_name'] ?? '');
    $desc     = trim($_POST['description']  ?? '');
    $sdate    = $_POST['start_date']        ?? '';
    $edate    = $_POST['end_date']          ?? '';
    $stime    = $_POST['start_time']        ?: null;
    $etime    = $_POST['end_time']          ?: null;
    $location = trim($_POST['location']     ?? '');
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
        $stmt->bind_param('ssssssssii', $name, $desc, $sdate, $edate, $stime, $etime, $location, $status, $maxp, $user_id);
        if ($stmt->execute()) {
            $new_id   = $db->insert_id;
            $message  = "Program \"{$name}\" created successfully.";
            $msg_type = 'success';
            $al = $db->prepare("INSERT INTO audit_logs (performed_by, action, table_name, record_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE action=action");
            if ($al) { $action='CREATE'; $tbl='programs'; $al->bind_param('issi',$user_id,$action,$tbl,$new_id); $al->execute(); $al->close(); }
        } else {
            $message  = 'Failed to create program.';
            $msg_type = 'error';
        }
        $stmt->close();
    }
}

/* ── EDIT ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_program'])) {
    $pid      = (int) ($_POST['program_id'] ?? 0);
    $name     = trim($_POST['program_name'] ?? '');
    $desc     = trim($_POST['description']  ?? '');
    $sdate    = $_POST['start_date']        ?? '';
    $edate    = $_POST['end_date']          ?? '';
    $stime    = $_POST['start_time']        ?: null;
    $etime    = $_POST['end_time']          ?: null;
    $location = trim($_POST['location']     ?? '');
    $maxp     = strlen(trim($_POST['max_participants'] ?? '')) ? (int)$_POST['max_participants'] : null;
    $force_status = $_POST['status'] ?? '';

    if (!$pid || !$name || !$sdate || !$edate) {
        $message  = 'All required fields must be filled.';
        $msg_type = 'error';
    } else {
        $status = $force_status === 'cancelled' ? 'cancelled' : computeStatus('', $sdate, $edate);
        $stmt   = $db->prepare(
            "UPDATE programs SET program_name=?, description=?, start_date=?, end_date=?,
             start_time=?, end_time=?, location=?, status=?, max_participants=? WHERE program_id=?"
        );
        $stmt->bind_param('ssssssssii', $name, $desc, $sdate, $edate, $stime, $etime, $location, $status, $maxp, $pid);
        if ($stmt->execute()) {
            $message  = "Program updated successfully.";
            $msg_type = 'success';
            $al = $db->prepare("INSERT INTO audit_logs (performed_by, action, table_name, record_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE action=action");
            if ($al) { $action='UPDATE'; $tbl='programs'; $al->bind_param('issi',$user_id,$action,$tbl,$pid); $al->execute(); $al->close(); }
        } else {
            $message  = 'Update failed.';
            $msg_type = 'error';
        }
        $stmt->close();
    }
}

/* ── FETCH ── */
$search      = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all';

$sql    = "SELECT p.*, COUNT(par.participation_id) AS participant_count
           FROM programs p
           LEFT JOIN participation par ON par.program_id = p.program_id";
$params = [];
$types  = '';
$where  = [];

if ($search) {
    $where[]  = "(p.program_name LIKE ? OR p.location LIKE ?)";
    $like      = '%'.$search.'%';
    $params[]  = $like;
    $params[]  = $like;
    $types    .= 'ss';
}
if ($status_filter !== 'all') {
    $where[]  = "p.status = ?";
    $params[] = $status_filter;
    $types   .= 's';
}
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= " GROUP BY p.program_id ORDER BY p.start_date DESC";

if ($params) {
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $programs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $programs = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
}

/* ── EDIT PREFILL ── */
$edit_program = null;

if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {

    $edit_id = (int) $_GET['edit'];

    $stmt = $db->prepare("SELECT * FROM programs WHERE program_id = ? LIMIT 1");

    if ($stmt) {
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $edit_program = $result ? $result->fetch_assoc() : null;

        $stmt->close();
    }
}

$show_create = isset($_GET['action']) && $_GET['action'] === 'create';

$first_name = e($_SESSION['first_name'] ?? '');
$last_name  = e($_SESSION['last_name']  ?? '');

$status_colors = ['upcoming'=>'#3182ce','ongoing'=>'#38a169','completed'=>'#718096','cancelled'=>'#e53e3e'];
$status_bg     = ['upcoming'=>'#ebf8ff','ongoing'=>'#f0fff4','completed'=>'#f7fafc','cancelled'=>'#fff5f5'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - Admin Programs</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        body { background:#f8fafc; display:flex; height:100vh; overflow:hidden; }
        .nav-sidebar { width:260px; background:white; border-right:1px solid #e2e8f0; padding:25px; display:flex; flex-direction:column; flex-shrink:0; }
        .nav-logo { color:#334e5e; font-weight:700; font-size:1.3rem; margin-bottom:8px; letter-spacing:0.5px; }
        .nav-badge { display:inline-block; padding:2px 10px; background:white; color:white; border-radius:20px; font-size:0.7rem; text-transform:uppercase; letter-spacing:1px; margin-bottom:28px; }
        .nav-item { display:flex; align-items:center; gap:15px; padding:12px 15px; color:#718096; text-decoration:none; border-radius:10px; margin-bottom:8px; transition:0.2s; font-size:0.95rem; font-weight:500; }
        .nav-item:hover { background:#f1f5f9; color:#334e5e; }
        .nav-item.active { background:#f1f5f9; color:#334e5e; border-right:4px solid #334e5e; border-radius:10px 0 0 10px; font-weight:600; }
        .nav-footer { margin-top:auto; padding-top:20px; border-top:1px solid #f1f5f9; }
        .main-wrapper { flex:1; display:flex; flex-direction:column; overflow:hidden; }
        .top-header { padding:15px 40px; background:white; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-shrink:0; }
        .header-actions { display:flex; align-items:center; gap:20px; color:#718096; }
        .admin-name-tag { display:flex; flex-direction:column; align-items:flex-end; line-height:1.2; }
        .admin-name-tag .sname { color:#1a202c; font-weight:700; font-size:0.9rem; }
        .admin-name-tag .srole { color:#e53e3e; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; }
        .content-area { padding:40px; overflow-y:auto; flex:1; }
        .page-header { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:30px; }
        .header-text h1 { font-size:2rem; color:#1a202c; font-weight:700; }
        .header-text p { color:#718096; margin-top:5px; font-size:0.95rem; }
        .alert { padding:12px 20px; border-radius:10px; font-size:0.9rem; margin-bottom:20px; }
        .alert-success { background:#f0fff4; border:1px solid #9ae6b4; color:#276749; }
        .alert-error   { background:#fff5f5; border:1px solid #feb2b2; color:#c53030; }
        .btn-primary { background:#334e5e; color:white; padding:11px 22px; border-radius:10px; border:none; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:0.2s; font-size:0.9rem; text-decoration:none; font-family:inherit; }
        .btn-primary:hover { background:#2a3f4d; }
        .btn-secondary { background:#f1f5f9; color:#4a5568; padding:11px 22px; border-radius:10px; border:1px solid #e2e8f0; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:0.2s; font-size:0.9rem; text-decoration:none; font-family:inherit; }
        .btn-secondary:hover { background:#e2e8f0; }
        .btn-danger { background:#fff5f5; color:#e53e3e; padding:8px 14px; border-radius:8px; border:1px solid #fed7d7; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:0.2s; font-size:0.82rem; font-family:inherit; }
        .btn-danger:hover { background:#e53e3e; color:white; border-color:#e53e3e; }
        .btn-edit { background:#ebf8ff; color:#3182ce; padding:8px 14px; border-radius:8px; border:1px solid #bee3f8; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:0.2s; font-size:0.82rem; text-decoration:none; }
        .btn-edit:hover { background:#3182ce; color:white; }
        .filter-row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; align-items:center; }
        .chip { padding:7px 18px; background:white; border:1px solid #e2e8f0; border-radius:30px; font-size:0.83rem; color:#718096; text-decoration:none; transition:0.2s; }
        .chip.active { background:#334e5e; color:white; border-color:#334e5e; }
        .chip:hover:not(.active) { background:#f8fafc; border-color:#cbd5e0; }
        .table-card { background:white; border-radius:15px; border:1px solid #e2e8f0; padding:25px; }
        table { width:100%; border-collapse:collapse; }
        thead th { text-align:left; padding:0 15px 15px; color:#a0aec0; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #f1f5f9; }
        tbody td { padding:14px 15px; font-size:0.88rem; color:#4a5568; border-bottom:1px solid #f8fafc; vertical-align:middle; }
        tbody tr:last-child td { border-bottom:none; }
        tbody tr:hover td { background:#fafbfc; }
        .pill { display:inline-block; padding:3px 10px; border-radius:20px; font-size:0.72rem; font-weight:700; text-transform:capitalize; }
        .search-clear-btn { position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#a0aec0; font-size:0.75rem; display:none; padding:2px 4px; border-radius:50%; line-height:1; }

        /* ── MODAL ── */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:1000; align-items:center; justify-content:center; }
        .modal-overlay.open { display:flex; }
        .modal { background:white; border-radius:20px; padding:35px; width:100%; max-width:600px; max-height:90vh; overflow-y:auto; box-shadow:0 25px 50px rgba(0,0,0,0.15); }
        .modal h2 { font-size:1.3rem; color:#1a202c; margin-bottom:6px; }
        .modal p.sub { color:#718096; font-size:0.88rem; margin-bottom:24px; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .form-group { display:flex; flex-direction:column; gap:6px; }
        .form-group.full { grid-column:span 2; }
        label { font-size:0.82rem; font-weight:600; color:#4a5568; }
        input[type=text], input[type=date], input[type=time], input[type=number], select, textarea {
            padding:10px 14px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px;
            font-size:0.9rem; font-family:inherit; outline-color:#334e5e; width:100%; transition:0.2s; }
        input:focus, select:focus, textarea:focus { background:white; border-color:#334e5e; }
        textarea { resize:vertical; min-height:80px; }
        .modal-footer { display:flex; justify-content:flex-end; gap:12px; margin-top:24px; }
    </style>
</head>
<body>

<nav class="nav-sidebar">
    <div class="nav-logo">Bayanihan</div>
    <div class="nav-badge">Admin</div>
    <a href="admin_dashboard.php"        class="nav-item"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="admin_program.php"         class="nav-item active"><i class="fa-solid fa-shapes"></i> Programs</a>
    <a href="admin_participant.php"      class="nav-item"><i class="fa-solid fa-users"></i> Participants</a>
    <a href="admin_user_management.php"  class="nav-item"><i class="fa-solid fa-user-shield"></i> User Management</a>
    <a href="admin_report.php"          class="nav-item"><i class="fa-solid fa-chart-bar"></i> Reports</a>
    <a href="admin_audit_log.php"       class="nav-item"><i class="fa-solid fa-clipboard-list"></i> Audit Logs</a>
    <div class="nav-footer">
        <a href="logout.php" class="nav-item"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</nav>

<div class="main-wrapper">
    <header class="top-header">
        <form id="searchForm" method="GET" style="display:contents;">
            <?php if ($status_filter !== 'all'): ?><input type="hidden" name="status" value="<?= e($status_filter) ?>"><?php endif; ?>
            <div style="position:relative;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:15px;top:50%;transform:translateY(-50%);color:#a0aec0;pointer-events:none;"></i>
                <input id="globalSearch" type="text" name="search" placeholder="Search programs..."
                       value="<?= e($search) ?>"
                       style="width:350px;padding:10px 38px 10px 45px;background:#f1f5f9;border:none;border-radius:10px;outline-color:#334e5e;font-size:0.9rem;font-family:inherit;">
                <button type="button" id="searchClearBtn" class="search-clear-btn"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </form>
        <div class="header-actions">
            <i class="fa-regular fa-bell"></i>
            <div class="admin-name-tag">
                <span class="sname"><?= $first_name . ' ' . $last_name ?></span>
                <span class="srole">Administrator</span>
            </div>
        </div>
    </header>

    <main class="content-area">
        <div class="page-header">
            <div class="header-text">
                <h1>Programs</h1>
                <p>Manage all community programs across the platform.</p>
            </div>
            <button class="btn-primary" onclick="openModal('createModal')">
                <i class="fa-solid fa-plus"></i> New Program
            </button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $msg_type ?>"><?= e($message) ?></div>
        <?php endif; ?>

        <div class="filter-row">
            <a href="admin_programs.php<?= $search ? '?search='.urlencode($search) : '' ?>" class="chip <?= $status_filter === 'all' ? 'active' : '' ?>">All</a>
            <?php foreach (['upcoming','ongoing','completed','cancelled'] as $st): ?>
            <a href="?status=<?= $st ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="chip <?= $status_filter === $st ? 'active' : '' ?>"><?= ucfirst($st) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Program</th>
                        <th>Location</th>
                        <th>Duration</th>
                        <th>Participants</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($programs)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#a0aec0;padding:30px;">No programs found.</td></tr>
                <?php else: ?>
                    <?php foreach ($programs as $pg):
                        $st = computeStatus($pg['status'], $pg['start_date'], $pg['end_date']);
                        $sc = $status_colors[$st] ?? '#718096';
                        $sb = $status_bg[$st]     ?? '#f7fafc';
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight:700;color:#1a202c;font-size:0.92rem;"><?= e($pg['program_name']) ?></div>
                            <div style="font-size:0.76rem;color:#a0aec0;margin-top:2px;"><?= e(mb_strimwidth($pg['description'] ?? '', 0, 55, '...')) ?></div>
                        </td>
                        <td><?= e($pg['location'] ?? '—') ?></td>
                        <td style="font-size:0.82rem;">
                            <?= date('M j', strtotime($pg['start_date'])) ?> – <?= date('M j, Y', strtotime($pg['end_date'])) ?>
                        </td>
                        <td style="font-weight:700;color:#334e5e;"><?= number_format($pg['participant_count']) ?></td>
                        <td>
                            <span class="pill" style="background:<?= $sb ?>;color:<?= $sc ?>;"><?= ucfirst($st) ?></span>
                        </td>
                        <td>
                            <div style="display:flex;gap:8px;">
                                <a href="?edit=<?= $pg['program_id'] ?>" class="btn-edit"><i class="fa-solid fa-pen"></i> Edit</a>
                                <form method="POST" onsubmit="return confirm('Delete this program? This cannot be undone.');">
                                    <input type="hidden" name="delete_program_id" value="<?= $pg['program_id'] ?>">
                                    <button type="submit" class="btn-danger"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<!-- CREATE MODAL -->
<div class="modal-overlay <?= ($show_create && !$edit_program) ? 'open' : '' ?>" id="createModal">
    <div class="modal">
        <h2>Create New Program</h2>
        <p class="sub">Fill in the details below to add a new community program.</p>
        <form method="POST">
            <div class="form-grid">
                <div class="form-group full">
                    <label>Program Name *</label>
                    <input type="text" name="program_name" placeholder="Program name" required>
                </div>
                <div class="form-group full">
                    <label>Description</label>
                    <textarea name="description" placeholder="Brief description..."></textarea>
                </div>
                <div class="form-group">
                    <label>Start Date *</label>
                    <input type="date" name="start_date" required>
                </div>
                <div class="form-group">
                    <label>End Date *</label>
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
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" placeholder="Venue / address">
                </div>
                <div class="form-group">
                    <label>Max Participants</label>
                    <input type="number" name="max_participants" min="1" placeholder="Leave blank for unlimited">
                </div>
                <div class="form-group">
                    <label>Override Status</label>
                    <select name="status">
                        <option value="">Auto-compute</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" name="create_program" class="btn-primary"><i class="fa-solid fa-plus"></i> Create</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT MODAL -->
<?php if ($edit_program): ?>
<div class="modal-overlay open" id="editModal">
    <div class="modal">
        <h2>Edit Program</h2>
        <p class="sub">Update details for "<?= e($edit_program['program_name']) ?>".</p>
        <form method="POST">
            <input type="hidden" name="program_id" value="<?= $edit_program['program_id'] ?>">
            <div class="form-grid">
                <div class="form-group full">
                    <label>Program Name *</label>
                    <input type="text" name="program_name" value="<?= e($edit_program['program_name']) ?>" required>
                </div>
                <div class="form-group full">
                    <label>Description</label>
                    <textarea name="description"><?= e($edit_program['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>Start Date *</label>
                    <input type="date" name="start_date" value="<?= e($edit_program['start_date']) ?>" required>
                </div>
                <div class="form-group">
                    <label>End Date *</label>
                    <input type="date" name="end_date" value="<?= e($edit_program['end_date']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Start Time</label>
                    <input type="time" name="start_time" value="<?= e($edit_program['start_time'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>End Time</label>
                    <input type="time" name="end_time" value="<?= e($edit_program['end_time'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" value="<?= e($edit_program['location'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Max Participants</label>
                    <input type="number" name="max_participants" min="1" value="<?= e($edit_program['max_participants'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Override Status</label>
                    <select name="status">
                        <option value="">Auto-compute</option>
                        <option value="cancelled" <?= $edit_program['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <a href="admin_programs.php" class="btn-secondary">Cancel</a>
                <button type="submit" name="edit_program" class="btn-primary"><i class="fa-solid fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (e.target === el) el.classList.remove('open');
    });
});

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