<?php
// staff_participation.php

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
$db       = getDB();
$message  = '';
$msg_type = '';

/* ── UPDATE STATUS ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $prid    = (int) $_POST['participation_id'];
    $status  = $_POST['new_status'] ?? '';
    $allowed = ['registered', 'completed', 'cancelled'];

    if (in_array($status, $allowed)) {
        $stmt = $db->prepare("UPDATE participation SET status = ? WHERE participation_id = ?");
        $stmt->bind_param('si', $status, $prid);
        if ($stmt->execute()) {
            $message  = 'Participation status updated to "'.ucfirst($status).'".';
            $msg_type = 'success';
        } else {
            $message  = 'Update failed.';
            $msg_type = 'error';
        }
        $stmt->close();
    }
}

/* ── FILTERS ── */
$status_filter  = $_GET['status']   ?? 'all';
$program_filter = (int)($_GET['program_id'] ?? 0);
$search         = trim($_GET['search'] ?? '');
$per_page       = 20;
$page           = max(1, (int)($_GET['page'] ?? 1));
$offset         = ($page - 1) * $per_page;

/* ── BUILD QUERY ── */
$where  = "WHERE 1=1";
$params = [];
$types  = '';

if ($status_filter !== 'all') {
    $where   .= " AND pr.status = ?";
    $params[]  = $status_filter;
    $types    .= 's';
}
if ($program_filter > 0) {
    $where   .= " AND pr.program_id = ?";
    $params[]  = $program_filter;
    $types    .= 'i';
}
if ($search) {
    $like      = '%'.$search.'%';
    $where    .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?
                        OR pg.program_name LIKE ?
                        OR CONCAT(u.first_name,' ',u.last_name) LIKE ?)";
    $params    = array_merge($params, [$like, $like, $like, $like, $like]);
    $types    .= 'sssss';
}

$base_sql = "FROM participation pr
             JOIN participants p  ON p.participant_id = pr.participant_id
             JOIN users u         ON u.user_id = p.user_id
             JOIN programs pg     ON pg.program_id = pr.program_id
             $where";

$count_stmt = $db->prepare("SELECT COUNT(*) $base_sql");
if ($params) { $count_stmt->bind_param($types, ...$params); }
$count_stmt->execute();
$total_count = (int) $count_stmt->get_result()->fetch_row()[0];
$count_stmt->close();
$total_pages = (int) ceil($total_count / $per_page);

$list_params   = $params;
$list_types    = $types . 'ii';
$list_params[] = $per_page;
$list_params[] = $offset;

$list_stmt = $db->prepare(
    "SELECT pr.participation_id, pr.status, pr.participation_date,
            u.first_name, u.last_name, u.email, u.user_id,
            pg.program_name, pg.program_id
     $base_sql
     ORDER BY pr.created_at DESC
     LIMIT ? OFFSET ?"
);
$list_stmt->bind_param($list_types, ...$list_params);
$list_stmt->execute();
$records = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$list_stmt->close();

/* ── PROGRAMS FOR FILTER DROPDOWN ── */
$all_programs = $db->query("SELECT program_id, program_name FROM programs ORDER BY program_name ASC")->fetch_all(MYSQLI_ASSOC);

/* ── QUERY STRING HELPER ── */
function qStr(array $overrides = []): string {
    $base = array_filter([
        'status'     => $_GET['status']     ?? '',
        'program_id' => $_GET['program_id'] ?? '',
        'search'     => $_GET['search']     ?? '',
        'page'       => $_GET['page']       ?? '',
    ]);
    return '?' . http_build_query(array_merge($base, $overrides));
}

$first_name = e($_SESSION['first_name'] ?? '');
$last_name  = e($_SESSION['last_name']  ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - Participation</title>
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

        /* ── CHIPS ── */
        .chip { padding:8px 20px; background:white; border:1px solid #e2e8f0;
            border-radius:30px; font-size:0.85rem; color:#718096; text-decoration:none; transition:0.2s; }
        .chip.active { background:#334e5e; color:white; border-color:#334e5e; }
        .chip:hover:not(.active) { background:#f8fafc; border-color:#cbd5e0; }

        /* ── TABLE ── */
        .table-card { background:white; border-radius:15px; border:1px solid #e2e8f0; padding:25px; }
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

        /* ── PAGINATION ── */
        .pagination { display:flex; justify-content:space-between; align-items:center;
            margin-top:20px; color:#a0aec0; font-size:0.85rem; }
        .pages { display:flex; align-items:center; gap:8px; }
        .page-num { width:32px; height:32px; display:flex; align-items:center;
            justify-content:center; border-radius:50%; text-decoration:none;
            color:#718096; font-weight:600; font-size:0.85rem; }
        .page-num.active { background:#334e5e; color:white; }
        .page-num:hover:not(.active) { background:#f1f5f9; }
    </style>
</head>
<body>

<!-- ── Sidebar ── -->
<nav class="nav-sidebar">
    <div class="nav-logo">Bayanihan</div>
    <a href="staff_dashboard.php"     class="nav-item"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="staff_program.php"       class="nav-item"><i class="fa-solid fa-shapes"></i> Programs</a>
    <a href="staff_participant.php"   class="nav-item"><i class="fa-solid fa-users"></i> Participants</a>
    <a href="staff_participation.php" class="nav-item active"><i class="fa-solid fa-handshake-angle"></i> Participation</a>
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
        <?php if ($status_filter !== 'all'): ?><input type='hidden' name='status' value='<?= htmlspecialchars($status_filter) ?>'><?php endif; ?>
        <?php if ($program_filter): ?><input type='hidden' name='program_id' value='<?= $program_filter ?>'><?php endif; ?>
        <div class="header-search-wrap" style="position:relative;">
            <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:15px;top:50%;transform:translateY(-50%);color:#a0aec0;pointer-events:none;z-index:1;"></i>
            <input id="globalSearch" type="text" name="search" placeholder="Search participants or programs..."
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
                <h1>Participation Records</h1>
                <p>View and manage community enrollment across all programs.</p>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" style="display:flex;gap:10px;margin-bottom:25px;flex-wrap:wrap;align-items:center;">
            <?php if ($search !== ''): ?>
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <?php endif; ?>
            <?php if ($status_filter !== 'all'): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
            <?php endif; ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="<?= qStr(['status'=>'all','page'=>1]) ?>" class="chip <?= $status_filter==='all'?'active':'' ?>">All</a>
                <?php foreach (['registered','completed','cancelled'] as $s): ?>
                <a href="<?= qStr(['status'=>$s,'page'=>1]) ?>" class="chip <?= $status_filter===$s?'active':'' ?>"><?= ucfirst($s) ?></a>
                <?php endforeach; ?>
            </div>
            <select name="program_id" onchange="this.form.submit()"
                    style="padding:8px 14px;border:1px solid #e2e8f0;border-radius:10px;font-size:0.88rem;color:#334e5e;font-weight:600;outline-color:#334e5e;background:white;font-family:inherit;">
                <option value="0">All Programs</option>
                <?php foreach ($all_programs as $ap): ?>
                <option value="<?= $ap['program_id'] ?>" <?= $program_filter===(int)$ap['program_id']?'selected':'' ?>>
                    <?= e($ap['program_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>

        <!-- Records Table -->
        <div class="table-card">
            <div style="margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;">
                <p style="color:#718096;font-size:0.88rem;">
                    Showing <strong><?= $total_count > 0 ? $offset+1 : 0 ?>–<?= min($offset+$per_page,$total_count) ?></strong>
                    of <strong><?= number_format($total_count) ?></strong> records
                </p>
            </div>

            <table>
                <thead><tr>
                    <th style="width:30%;">Participant</th>
                    <th>Program</th>
                    <th>Date Joined</th>
                    <th>Status</th>
                    <th style="text-align:right;padding-right:15px;">Change Status</th>
                </tr></thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="5" style="text-align:center;color:#a0aec0;padding:30px;">No records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($records as $rec): ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <div class="user-initials">
                                    <?= strtoupper(substr($rec['first_name'],0,1).substr($rec['last_name'],0,1)) ?>
                                </div>
                                <div class="user-cell-info">
                                    <span class="uname"><?= e($rec['first_name'].' '.$rec['last_name']) ?></span>
                                    <span class="uemail"><?= e($rec['email']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= e($rec['program_name']) ?></td>
                        <td><?= date('M j, Y', strtotime($rec['participation_date'])) ?></td>
                        <td><span class="pill pill-<?= $rec['status'] ?>"><?= ucfirst($rec['status']) ?></span></td>
                        <td style="text-align:right;padding-right:15px;">
                            <form method="POST" style="display:inline-flex;gap:6px;align-items:center;">
                                <input type="hidden" name="participation_id" value="<?= $rec['participation_id'] ?>">
                                <select name="new_status"
                                        style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.8rem;color:#334e5e;font-weight:600;outline-color:#334e5e;background:white;font-family:inherit;">
                                    <?php foreach (['registered','completed','cancelled'] as $opt): ?>
                                    <option value="<?= $opt ?>" <?= $rec['status']===$opt?'selected':'' ?>><?= ucfirst($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="update_status" value="1" class="btn-primary" style="padding:6px 14px;font-size:0.8rem;">
                                    Save
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <span><?= number_format($total_count) ?> total records</span>
                <div class="pages">
                    <?php if ($page > 1): ?>
                    <a href="<?= qStr(['page'=>$page-1]) ?>" class="page-num"><i class="fa-solid fa-chevron-left" style="font-size:0.75rem;"></i></a>
                    <?php endif; ?>
                    <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
                    <a href="<?= qStr(['page'=>$i]) ?>" class="page-num <?= $i===$page?'active':'' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                    <a href="<?= qStr(['page'=>$page+1]) ?>" class="page-num"><i class="fa-solid fa-chevron-right" style="font-size:0.75rem;"></i></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
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
</script>
</body>
</html>