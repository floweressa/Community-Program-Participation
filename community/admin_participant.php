<?php
// admin_participant.php

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
$message  = '';
$msg_type = '';

/* ── DELETE PARTICIPANT ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_participant_id'])) {
    $pid  = (int) $_POST['delete_participant_id'];
    // Get user_id first
    $stmt = $db->prepare("SELECT user_id FROM participants WHERE participant_id = ?");
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $uid  = (int) $row['user_id'];
        $stmt = $db->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param('i', $uid);
        $stmt->execute()
            ? ($message = 'Participant removed successfully.') && ($msg_type = 'success')
            : ($message = 'Delete failed.') && ($msg_type = 'error');
        $stmt->close();

        $al = $db->prepare("INSERT INTO audit_logs (performed_by, action, table_name, record_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE action=action");
        if ($al) { $action='DELETE'; $tbl='users'; $al->bind_param('issi',$admin_id,$action,$tbl,$uid); $al->execute(); $al->close(); }
    }
}

/* ── PAGINATION & SEARCH ── */
$per_page = 15;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;
$search   = trim($_GET['search'] ?? '');
$gender_filter = $_GET['gender'] ?? 'all';

/*COUNT*/
$count_sql = "SELECT COUNT(*) FROM users u JOIN participants p ON p.user_id = u.user_id WHERE u.role = 'participant'";
$c_params  = [];
$c_types   = '';
if ($search) {
    $count_sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $like       = '%'.$search.'%';
    $c_params   = [$like, $like, $like];
    $c_types    = 'sss';
}
if ($gender_filter !== 'all') {
    $count_sql .= " AND p.gender = ?";
    $c_params[] = $gender_filter;
    $c_types   .= 's';
}
if ($c_params) {
    $stmt = $db->prepare($count_sql);
    $stmt->bind_param($c_types, ...$c_params);
    $stmt->execute();
    $total_count = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
} else {
    $total_count = (int) $db->query($count_sql)->fetch_row()[0];
}
$total_pages = max(1, (int)ceil($total_count / $per_page));

/*COUNT, AVERAGE, & JOIN*/
$sql    = "SELECT u.user_id, u.first_name, u.last_name, u.email, u.created_at,
                  p.participant_id, p.age, p.gender, p.address,
                  (SELECT COUNT(*) FROM participation pr WHERE pr.participant_id = p.participant_id) AS prog_count,
                  (SELECT ROUND(AVG(a.status='present')*100)
                   FROM attendance a JOIN participation pr2 ON pr2.participation_id = a.participation_id
                   WHERE pr2.participant_id = p.participant_id) AS att_rate
           FROM users u JOIN participants p ON p.user_id = u.user_id
           WHERE u.role = 'participant'";
$params = [];
$types  = '';
if ($search) {
    $sql    .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $like    = '%'.$search.'%';
    $params  = [$like, $like, $like];
    $types   = 'sss';
}
if ($gender_filter !== 'all') {
    $sql    .= " AND p.gender = ?";
    $params[] = $gender_filter;
    $types   .= 's';
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

/* ── VIEW SINGLE ── */
$view_data = null;
$view_parts = [];
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
            "SELECT pg.program_name, pr.status, pr.participation_date,
                    (SELECT ROUND(AVG(a.status='present')*100)
                     FROM attendance a WHERE a.participation_id = pr.participation_id) AS att_rate
             FROM participation pr JOIN programs pg ON pg.program_id = pr.program_id
             WHERE pr.participant_id = ? ORDER BY pr.participation_date DESC LIMIT 10"
        );
        $ps->bind_param('i', $pid);
        $ps->execute();
        $view_parts = $ps->get_result()->fetch_all(MYSQLI_ASSOC);
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
    <title>Bayanihan - Admin Participants</title>
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
        .page-header { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:30px; }
        .header-text h1 { font-size:2rem; color:#1a202c; font-weight:700; }
        .header-text p { color:#718096; margin-top:5px; font-size:0.95rem; }
        .alert { padding:12px 20px; border-radius:10px; font-size:0.9rem; margin-bottom:20px; }
        .alert-success { background:#f0fff4; border:1px solid #9ae6b4; color:#276749; }
        .alert-error   { background:#fff5f5; border:1px solid #feb2b2; color:#c53030; }
        .filter-row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; align-items:center; }
        .chip { padding:7px 18px; background:white; border:1px solid #e2e8f0; border-radius:30px; font-size:0.83rem; color:#718096; text-decoration:none; transition:0.2s; }
        .chip.active { background:#334e5e; color:white; border-color:#334e5e; }
        .chip:hover:not(.active) { background:#f8fafc; }
        .table-card { background:white; border-radius:15px; border:1px solid #e2e8f0; padding:25px; }
        .table-card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
        table { width:100%; border-collapse:collapse; }
        thead th { text-align:left; padding:0 15px 15px; color:#a0aec0; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1px solid #f1f5f9; }
        tbody td { padding:13px 15px; font-size:0.88rem; color:#4a5568; border-bottom:1px solid #f8fafc; vertical-align:middle; }
        tbody tr:last-child td { border-bottom:none; }
        tbody tr:hover td { background:#fafbfc; }
        .user-cell { display:flex; align-items:center; gap:12px; }
        .user-initials { width:36px; height:36px; border-radius:50%; background:#edf2f7; display:flex; align-items:center; justify-content:center; color:#718096; font-weight:700; font-size:0.75rem; flex-shrink:0; }
        .user-cell-info .uname { font-weight:700; color:#1a202c; display:block; font-size:0.88rem; }
        .user-cell-info .uemail { font-size:0.75rem; color:#a0aec0; }
        .pill { display:inline-block; padding:3px 10px; border-radius:20px; font-size:0.72rem; font-weight:700; text-transform:capitalize; }
        .pill-registered { background:#ebf8ff; color:#2b6cb0; }
        .pill-completed  { background:#f0fff4; color:#276749; }
        .pill-cancelled  { background:#f7fafc; color:#718096; }
        .btn-view { background:#f1f5f9; color:#4a5568; padding:7px 13px; border-radius:8px; border:1px solid #e2e8f0; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-size:0.8rem; text-decoration:none; transition:0.2s; }
        .btn-view:hover { background:#334e5e; color:white; border-color:#334e5e; }
        .btn-danger { background:#fff5f5; color:#e53e3e; padding:7px 13px; border-radius:8px; border:1px solid #fed7d7; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-size:0.8rem; font-family:inherit; transition:0.2s; }
        .btn-danger:hover { background:#e53e3e; color:white; border-color:#e53e3e; }
        .rate-bar { width:60px; height:5px; background:#e2e8f0; border-radius:10px; display:inline-block; vertical-align:middle; margin-left:6px; }
        .rate-fill { height:100%; border-radius:10px; background:#334e5e; }
        /* Pagination */
        .pagination { display:flex; gap:8px; justify-content:center; margin-top:24px; }
        .pagination a, .pagination span { padding:8px 14px; border-radius:8px; border:1px solid #e2e8f0; background:white; color:#4a5568; font-size:0.85rem; text-decoration:none; font-weight:600; }
        .pagination a:hover { background:#f1f5f9; }
        .pagination .current { background:#334e5e; color:white; border-color:#334e5e; }
        .search-clear-btn { position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#a0aec0; font-size:0.75rem; display:none; padding:2px 4px; }
        /* Drawer */
        .drawer-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.35); z-index:900; }
        .drawer-overlay.open { display:block; }
        .drawer { position:fixed; right:0; top:0; bottom:0; width:420px; background:white; box-shadow:-4px 0 20px rgba(0,0,0,0.1); z-index:901; padding:35px; overflow-y:auto; transform:translateX(100%); transition:transform 0.3s ease; }
        .drawer.open { transform:translateX(0); }
    </style>
</head>
<body>

<nav class="nav-sidebar">
    <div class="nav-logo">Bayanihan</div>
    <div class="nav-badge">Admin</div>
    <a href="admin_dashboard.php"        class="nav-item"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="admin_program.php"         class="nav-item"><i class="fa-solid fa-shapes"></i> Programs</a>
    <a href="admin_participant.php"      class="nav-item active"><i class="fa-solid fa-users"></i> Participants</a>
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
            <?php if ($gender_filter !== 'all'): ?><input type="hidden" name="gender" value="<?= e($gender_filter) ?>"><?php endif; ?>
            <div style="position:relative;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:15px;top:50%;transform:translateY(-50%);color:#a0aec0;pointer-events:none;"></i>
                <input id="globalSearch" type="text" name="search" placeholder="Search participants..."
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
                <h1>Participants</h1>
                <p><?= number_format($total_count) ?> registered participants on the platform.</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $msg_type ?>"><?= e($message) ?></div>
        <?php endif; ?>

        <div class="filter-row">
            <a href="admin_participant.php<?= $search ? '?search='.urlencode($search) : '' ?>" class="chip <?= $gender_filter === 'all' ? 'active' : '' ?>">All Genders</a>
            <?php foreach (['male','female','other'] as $g): ?>
            <a href="?gender=<?= $g ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="chip <?= $gender_filter === $g ? 'active' : '' ?>"><?= ucfirst($g) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="table-card">
            <div class="table-card-header">
                <span style="font-size:0.85rem;color:#a0aec0;">Showing <?= number_format(count($participants)) ?> of <?= number_format($total_count) ?></span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Participant</th>
                        <th>Gender / Age</th>
                        <th>Programs</th>
                        <th>Att. Rate</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($participants)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#a0aec0;padding:30px;">No participants found.</td></tr>
                <?php else: ?>
                    <?php foreach ($participants as $pt): ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <div class="user-initials">
                                    <?= strtoupper(substr($pt['first_name'],0,1).substr($pt['last_name'],0,1)) ?>
                                </div>
                                <div class="user-cell-info">
                                    <span class="uname"><?= e($pt['first_name'].' '.$pt['last_name']) ?></span>
                                    <span class="uemail"><?= e($pt['email']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td style="font-size:0.82rem;">
                            <?= $pt['gender'] ? ucfirst(e($pt['gender'])) : '—' ?>
                            <?= $pt['age'] ? ' · '.$pt['age'].'y' : '' ?>
                        </td>
                        <td style="font-weight:700;color:#334e5e;"><?= $pt['prog_count'] ?></td>
                        <td>
                            <?php $rate = (int)($pt['att_rate'] ?? 0); ?>
                            <span style="font-weight:700;color:<?= $rate>=75?'#38a169':($rate>=50?'#dd6b20':'#e53e3e') ?>;"><?= $rate ?>%</span>
                            <div class="rate-bar"><div class="rate-fill" style="width:<?= $rate ?>%;"></div></div>
                        </td>
                        <td style="font-size:0.8rem;color:#a0aec0;"><?= date('M j, Y', strtotime($pt['created_at'])) ?></td>
                        <td>
                            <div style="display:flex;gap:8px;">
                                <a href="?view=<?= $pt['participant_id'] ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="btn-view"><i class="fa-solid fa-eye"></i> View</a>
                                <form method="POST" onsubmit="return confirm('Remove this participant and their account? This cannot be undone.');">
                                    <input type="hidden" name="delete_participant_id" value="<?= $pt['participant_id'] ?>">
                                    <button type="submit" class="btn-danger"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </div>
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
                <a href="?page=<?= $page-1 ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $gender_filter !== 'all' ? '&gender='.urlencode($gender_filter) : '' ?>">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
                <?php endif; ?>
                <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current"><?= $i ?></span>
                <?php else: ?>
                    <a href="?page=<?= $i ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $gender_filter !== 'all' ? '&gender='.urlencode($gender_filter) : '' ?>"><?= $i ?></a>
                <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page+1 ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $gender_filter !== 'all' ? '&gender='.urlencode($gender_filter) : '' ?>">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Participant Detail Drawer -->
<?php if ($view_data): ?>
<div class="drawer-overlay open" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer open" id="drawer">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
        <h2 style="font-size:1.2rem;color:#1a202c;font-weight:700;">Participant Details</h2>
        <a href="admin_participant.php<?= $search ? '?search='.urlencode($search) : '' ?>" style="color:#a0aec0;font-size:1.1rem;text-decoration:none;">
            <i class="fa-solid fa-xmark"></i>
        </a>
    </div>

    <div style="text-align:center;margin-bottom:24px;">
        <div style="width:72px;height:72px;border-radius:50%;background:#334e5e;color:white;display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:700;margin:0 auto 12px;">
            <?= strtoupper(substr($view_data['first_name'],0,1).substr($view_data['last_name'],0,1)) ?>
        </div>
        <div style="font-size:1.15rem;font-weight:700;color:#1a202c;"><?= e($view_data['first_name'].' '.$view_data['last_name']) ?></div>
        <div style="font-size:0.85rem;color:#a0aec0;margin-top:4px;"><?= e($view_data['email']) ?></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:24px;">
        <?php
        $details = [
            ['label'=>'Gender',  'val'=> $view_data['gender'] ? ucfirst($view_data['gender']) : '—'],
            ['label'=>'Age',     'val'=> $view_data['age'] ?? '—'],
            ['label'=>'Address', 'val'=> $view_data['address'] ?? '—'],
            ['label'=>'Joined',  'val'=> date('M j, Y', strtotime($view_data['created_at']))],
        ];
        foreach ($details as $d): ?>
        <div style="background:#f8fafc;border-radius:10px;padding:14px;">
            <div style="font-size:0.72rem;font-weight:700;color:#a0aec0;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;"><?= $d['label'] ?></div>
            <div style="font-size:0.9rem;color:#1a202c;font-weight:600;"><?= e((string)$d['val']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <h3 style="font-size:0.9rem;font-weight:700;color:#334e5e;margin-bottom:12px;">Program Participation</h3>
    <?php if (empty($view_parts)): ?>
        <p style="color:#a0aec0;font-size:0.85rem;">No participation records.</p>
    <?php else: ?>
        <?php foreach ($view_parts as $vp): ?>
        <div style="background:#f8fafc;border-radius:10px;padding:14px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <div style="font-weight:600;color:#1a202c;font-size:0.88rem;"><?= e($vp['program_name']) ?></div>
                <div style="font-size:0.75rem;color:#a0aec0;margin-top:2px;"><?= date('M j, Y', strtotime($vp['participation_date'])) ?></div>
            </div>
            <div style="text-align:right;">
                <span style="font-size:0.72rem;font-weight:700;text-transform:capitalize;
                    color:<?= $vp['status']==='completed' ? '#38a169' : ($vp['status']==='registered' ? '#3182ce' : '#a0aec0') ?>;">
                    <?= ucfirst(e($vp['status'])) ?>
                </span>
                <?php if ($vp['att_rate'] !== null): ?>
                <div style="font-size:0.72rem;color:#a0aec0;"><?= $vp['att_rate'] ?>% attendance</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
function closeDrawer() {
    document.getElementById('drawer')?.classList.remove('open');
    document.getElementById('drawerOverlay')?.classList.remove('open');
    setTimeout(function(){ window.history.back(); }, 200);
}

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