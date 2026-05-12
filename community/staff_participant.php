<?php
// staff_participant.php

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
$message  = '';
$msg_type = '';

/* ── PER-PAGE & PAGINATION ── */
$per_page = 15;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;
$search   = trim($_GET['search'] ?? '');

/* ── COUNT TOTAL ── */
$count_sql    = "SELECT COUNT(*) FROM users u
                 JOIN participants p ON p.user_id = u.user_id
                 WHERE u.role = 'participant'";
$count_params = [];
$count_types  = '';

if ($search) {
    $count_sql   .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $like         = '%'.$search.'%';
    $count_params = [$like, $like, $like];
    $count_types  = 'sss';
}

if ($count_params) {
    $stmt = $db->prepare($count_sql);
    $stmt->bind_param($count_types, ...$count_params);
    $stmt->execute();
    $total_count = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
} else {
    $total_count = (int) $db->query($count_sql)->fetch_row()[0];
}

$total_pages = (int) ceil($total_count / $per_page);

/* ── FETCH PARTICIPANTS ── */
$sql    = "SELECT u.user_id, u.first_name, u.last_name, u.email, u.created_at,
                  p.participant_id, p.age, p.gender,
                  (SELECT COUNT(*) FROM participation pr WHERE pr.participant_id = p.participant_id) AS prog_count
           FROM users u
           JOIN participants p ON p.user_id = u.user_id
           WHERE u.role = 'participant'";
$params = [];
$types  = '';

if ($search) {
    $sql    .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $like    = '%'.$search.'%';
    $params  = [$like, $like, $like];
    $types   = 'sss';
}

$sql .= " ORDER BY u.created_at DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types   .= 'ii';

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ── VIEW SINGLE PARTICIPANT ── */
$view_data         = null;
$view_participations = [];

if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $pid  = (int) $_GET['view'];
    $stmt = $db->prepare(
        "SELECT u.first_name, u.last_name, u.email, u.created_at,
                p.participant_id, p.age, p.gender, p.address
         FROM users u JOIN participants p ON p.user_id = u.user_id
         WHERE p.participant_id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $view_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($view_data) {
        $ps = $db->prepare(
            "SELECT pg.program_name, pr.status, pr.participation_date
             FROM participation pr JOIN programs pg ON pg.program_id = pr.program_id
             WHERE pr.participant_id = ? ORDER BY pr.participation_date DESC LIMIT 10"
        );
        $ps->bind_param('i', $pid);
        $ps->execute();
        $view_participations = $ps->get_result()->fetch_all(MYSQLI_ASSOC);
        $ps->close();
    }
}

$first_name = e($_SESSION['first_name'] ?? '');
$last_name  = e($_SESSION['last_name']  ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - Participants</title>
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

        /* ── MODAL ── */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4);
            z-index:1000; align-items:center; justify-content:center; }
        .modal-overlay.open { display:flex; }
        .modal { background:white; border-radius:20px; padding:35px; width:100%;
            max-width:580px; box-shadow:0 20px 40px rgba(0,0,0,0.15); }
        .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; }
        .modal-header h2 { font-size:1.3rem; color:#1a202c; }
        .modal-close { background:none; border:none; font-size:1.2rem; color:#a0aec0; cursor:pointer;
            text-decoration:none; }
        .modal-close:hover { color:#e53e3e; }
        .modal-footer { display:flex; justify-content:flex-end; gap:12px; margin-top:20px; }
    </style>
</head>
<body>

<!-- ── Sidebar ── -->
<nav class="nav-sidebar">
    <div class="nav-logo">Bayanihan</div>
    <a href="staff_dashboard.php"     class="nav-item"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="staff_program.php"       class="nav-item"><i class="fa-solid fa-shapes"></i> Programs</a>
    <a href="staff_participant.php"   class="nav-item active"><i class="fa-solid fa-users"></i> Participants</a>
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
        <?php if (isset($_GET['view'])): ?><input type='hidden' name='view' value='<?= (int)$_GET["view"] ?>'><?php endif; ?>
       <div class="header-search-wrap" style="position:relative;">
            <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:15px;top:50%;transform:translateY(-50%);color:#a0aec0;pointer-events:none;z-index:1;"></i>
            <input id="globalSearch" type="text" name="search" placeholder="Search participants..."
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
                <h1>Participants</h1>
                <p>Manage and monitor community members across all active outreach programs.</p>
            </div>
        </div>

        <div class="table-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                <p style="color:#718096;font-size:0.9rem;">
                    Showing <strong><?= $offset + 1 ?>–<?= min($offset + $per_page, $total_count) ?></strong>
                    of <strong><?= number_format($total_count) ?></strong> participants
                    <?= $search ? ' matching "<em>'.e($search).'</em>"' : '' ?>
                </p>
            </div>

            <table>
                <thead><tr>
                    <th style="width:40%;">Name</th>
                    <th>Gender / Age</th>
                    <th>Programs</th>
                    <th>Joined</th>
                    <th style="text-align:right;padding-right:15px;">Action</th>
                </tr></thead>
                <tbody>
                <?php if (empty($participants)): ?>
                    <tr><td colspan="5" style="text-align:center;color:#a0aec0;padding:30px;">No participants found.</td></tr>
                <?php else: ?>
                    <?php foreach ($participants as $part): ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <div class="user-initials">
                                    <?= strtoupper(substr($part['first_name'],0,1).substr($part['last_name'],0,1)) ?>
                                </div>
                                <div class="user-cell-info">
                                    <span class="uname"><?= e($part['first_name'].' '.$part['last_name']) ?></span>
                                    <span class="uemail"><?= e($part['email']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= e($part['gender'] ?? '—') ?> / <?= $part['age'] ? e($part['age']) : '—' ?></td>
                        <td><?= (int)$part['prog_count'] ?> program<?= $part['prog_count'] != 1 ? 's' : '' ?></td>
                        <td><?= date('M j, Y', strtotime($part['created_at'])) ?></td>
                        <td style="text-align:right;padding-right:15px;">
                            <a href="?view=<?= $part['participant_id'] ?><?= $search ? '&search='.urlencode($search) : '' ?>"
                               class="btn-secondary" style="padding:7px 16px;font-size:0.82rem;">
                                <i class="fa-solid fa-eye"></i> View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <span><?= number_format($total_count) ?> total participants</span>
                <div class="pages">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?= $page-1 ?><?= $search?'&search='.urlencode($search):'' ?>" class="page-num">
                        <i class="fa-solid fa-chevron-left" style="font-size:0.75rem;"></i>
                    </a>
                    <?php endif; ?>
                    <?php
                    $start = max(1, $page - 2);
                    $end   = min($total_pages, $page + 2);
                    for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?page=<?= $i ?><?= $search?'&search='.urlencode($search):'' ?>"
                       class="page-num <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page+1 ?><?= $search?'&search='.urlencode($search):'' ?>" class="page-num">
                        <i class="fa-solid fa-chevron-right" style="font-size:0.75rem;"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div><!-- /.main-wrapper -->

<!-- ── VIEW PARTICIPANT MODAL ── -->
<?php if ($view_data): ?>
<div class="modal-overlay open" id="modalView">
    <div class="modal">
        <div class="modal-header">
            <h2><?= e($view_data['first_name'].' '.$view_data['last_name']) ?></h2>
            <a href="staff_participant.php<?= $search?'?search='.urlencode($search):'' ?>" class="modal-close">
                <i class="fa-solid fa-xmark"></i>
            </a>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:20px;">
            <?php
            $fields = [
                'Email'   => $view_data['email'],
                'Gender'  => $view_data['gender'] ?? '—',
                'Age'     => $view_data['age'] ?? '—',
                'Address' => $view_data['address'] ?? '—',
                'Joined'  => date('M j, Y', strtotime($view_data['created_at'])),
            ];
            foreach ($fields as $lbl => $val): ?>
            <div style="background:#f8fafc;padding:12px;border-radius:10px;<?= in_array($lbl,['Email','Address'])?'grid-column:span 2;':'' ?>">
                <span style="font-size:0.7rem;color:#a0aec0;font-weight:700;text-transform:uppercase;"><?= $lbl ?></span>
                <div style="color:#1a202c;font-weight:600;margin-top:3px;font-size:0.9rem;"><?= e((string)$val) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <h3 style="font-size:0.9rem;font-weight:700;color:#334e5e;margin-bottom:12px;text-transform:uppercase;letter-spacing:0.5px;">
            Program History
        </h3>
        <?php if (empty($view_participations)): ?>
        <p style="color:#a0aec0;font-size:0.9rem;">No participation records.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;max-height:220px;overflow-y:auto;">
            <?php foreach ($view_participations as $vp): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
                <div>
                    <div style="font-weight:600;color:#1a202c;font-size:0.88rem;"><?= e($vp['program_name']) ?></div>
                    <div style="font-size:0.75rem;color:#a0aec0;margin-top:2px;"><?= date('M j, Y', strtotime($vp['participation_date'])) ?></div>
                </div>
                <span class="pill pill-<?= $vp['status'] ?>"><?= ucfirst($vp['status']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="modal-footer">
            <a href="staff_participant.php<?= $search?'?search='.urlencode($search):'' ?>" class="btn-secondary">Close</a>
        </div>
    </div>
</div>
<?php endif; ?>

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