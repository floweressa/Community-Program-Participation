<?php
// participant_participation.php
session_start();
require_once 'database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'participant') {
    header('Location: participant_login.php');
    exit();
}

$db             = getDB();
$participant_id = (int) $_SESSION['participant_id'];

// --- KPI: Active programs ---
$stmt = $db->prepare(
    "SELECT COUNT(*) AS cnt FROM participation WHERE participant_id = ? AND status = 'registered'"
);
$stmt->bind_param('i', $participant_id);
$stmt->execute();
$active_count = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// --- KPI: Completed actions ---
$stmt = $db->prepare(
    "SELECT COUNT(*) AS cnt FROM participation WHERE participant_id = ? AND status = 'completed'"
);
$stmt->bind_param('i', $participant_id);
$stmt->execute();
$completed_count = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// --- KPI: Hours contributed (estimate: sessions × 2h) ---
$stmt = $db->prepare(
    "SELECT COUNT(*) AS present FROM attendance a
     JOIN participation p ON p.participation_id = a.participation_id
     WHERE p.participant_id = ? AND a.status = 'present'"
);
$stmt->bind_param('i', $participant_id);
$stmt->execute();
$present_sessions = $stmt->get_result()->fetch_assoc()['present'];
$stmt->close();
$hours_contributed = $present_sessions * 2;

// --- History: all participation records ---
$status_filter = $_GET['status'] ?? 'all';
$search        = trim($_GET['search'] ?? '');

$sql = "SELECT pr.*, pg.program_name, pg.description, pg.location, pg.start_date, pg.end_date, pg.status AS program_status
        FROM participation pr
        JOIN programs pg ON pg.program_id = pr.program_id
        WHERE pr.participant_id = ?";
$params = [$participant_id];
$types  = 'i';

if ($status_filter !== 'all') {
    $sql    .= " AND pr.status = ?";
    $params[] = $status_filter;
    $types   .= 's';
}
if ($search) {
    $sql    .= " AND pg.program_name LIKE ?";
    $params[] = '%' . $search . '%';
    $types   .= 's';
}

$sql .= " ORDER BY pr.participation_date DESC";

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$status_colors = [
    'registered' => '#3182ce',
    'completed'  => '#38a169',
    'cancelled'  => '#a0aec0',
];

//Profile Avatar
$first = $_SESSION['first_name'] ?? '';
$last  = $_SESSION['last_name'] ?? '';

$initials = strtoupper(
    ($first[0] ?? '') .
    ($last[0] ?? '')
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - My Participation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        body { background: #f8fafc; display: flex; height: 100vh; overflow: hidden; }
        .nav-sidebar { width: 260px; background: white; border-right: 1px solid #e2e8f0; padding: 25px; display: flex; flex-direction: column; flex-shrink: 0; }
        .nav-logo { color: #334e5e; font-weight: 700; font-size: 1.3rem; margin-bottom: 40px; letter-spacing: 0.5px; }
        .nav-item { display: flex; align-items: center; gap: 15px; padding: 12px 15px; color: #718096; text-decoration: none; border-radius: 10px; margin-bottom: 8px; transition: 0.2s; font-size: 0.95rem; font-weight: 500; }
        .nav-item:hover { background: #f1f5f9; color: #334e5e; }
        .nav-item.active { background: #f1f5f9; color: #334e5e; border-right: 4px solid #334e5e; border-radius: 10px 0 0 10px; font-weight: 600; }
        .nav-footer { margin-top: auto; padding-top: 20px; border-top: 1px solid #f1f5f9; }
        .main-wrapper { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .top-header { padding: 15px 40px; background: white; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
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
        .header-actions { display: flex; align-items: center; gap: 20px; color: #718096; }
        .profile-img { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0; }
        .content-scroll { padding: 40px; overflow-y: auto; flex: 1; background: #f8fafc; }
        .page-header { margin-bottom: 35px; }
        .page-header h1 { font-size: 2rem; font-weight: 700; color: #1a202c; margin-bottom: 5px; }
        .page-header p { color: #718096; font-size: 1rem; line-height: 1.6; max-width: 600px; }
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 45px; }
        .stat-card { background: white; padding: 25px; border-radius: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: flex-start; }
        .stat-info h3 { font-size: 1.8rem; color: #1a202c; font-weight: 700; }
        .stat-info p { font-size: 0.85rem; color: #718096; margin-top: 8px; font-weight: 500; }
        .stat-icon { width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        .bg-blue { background: #ebf8ff; color: #3182ce; } .bg-green { background: #f0fff4; color: #38a169; } .bg-star { background: #fffaf0; color: #dd6b20; }
        .filter-row { display: flex; gap: 10px; margin-bottom: 25px; }
        .chip { padding: 8px 20px; background: white; border: 1px solid #e2e8f0; border-radius: 30px; font-size: 0.85rem; color: #718096; text-decoration: none; transition: 0.2s; }
        .chip.active { background: #334e5e; color: white; border-color: #334e5e; }
        .chip:hover:not(.active) { background: #f8fafc; border-color: #cbd5e0; }
        .section-title { font-size: 1.35rem; font-weight: 700; color: #1a202c; margin-bottom: 20px; }
        .history-list { display: flex; flex-direction: column; gap: 15px; }
        .history-item { background: white; padding: 20px 25px; border-radius: 18px; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; transition: 0.2s; }
        .history-item:hover { border-color: #cbd5e0; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .prog-main { display: flex; align-items: center; gap: 20px; flex: 1; }
        .prog-icon { width: 52px; height: 52px; border-radius: 10px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #334e5e; font-size: 1.3rem; flex-shrink: 0; }
        .prog-info h4 { font-size: 1.05rem; font-weight: 600; color: #1a202c; margin-bottom: 4px; }
        .meta-date { color: #a0aec0; font-size: 0.8rem; font-weight: 500; margin-bottom: 4px; display: block; }
        .prog-desc { color: #718096; font-size: 0.9rem; line-height: 1.4; }
        .prog-status { text-align: right; min-width: 120px; }
        .status-text { font-size: 0.8rem; font-weight: 700; text-transform: capitalize; margin-bottom: 5px; }
        .no-records { text-align: center; padding: 60px 20px; color: #718096; }
        .no-records i { font-size: 3rem; margin-bottom: 15px; color: #cbd5e0; display: block; }
        .profile-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: #334e5e;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 0.5px;

            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            border: 2px solid white;
        }
    </style>
</head>
<body>
<nav class="nav-sidebar">
    <div class="nav-logo">Bayanihan</div>
    <a href="participant_dashboard.php" class="nav-item"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="participant_program.php" class="nav-item"><i class="fa-solid fa-shapes"></i> Programs</a>
    <a href="participant_participation.php" class="nav-item active"><i class="fa-solid fa-handshake-angle"></i> My Participation</a>
    <a href="participant_attendance.php" class="nav-item"><i class="fa-solid fa-calendar-check"></i> Attendance</a>
    <a href="participant_profile.php" class="nav-item"><i class="fa-solid fa-circle-user"></i> Profile</a>
    <div class="nav-footer">
        <a href="logout.php" class="nav-item"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</nav>

<div class="main-wrapper">
    <header class="top-header">
        <form method="GET" style="display:contents;">
        <?php if ($status_filter !== 'all'): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
        <?php endif; ?>
        <div class="header-search-wrap" style="position:relative;">
            <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:15px;top:50%;transform:translateY(-50%);color:#a0aec0;pointer-events:none;z-index:1;"></i>
            <input id="globalSearch" type="text" name="search" placeholder="Search activities..."
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
            <div class="profile-avatar"><?= strtoupper($initials) ?></div>
        </div>
    </header>

    <main class="content-scroll">
        <div class="page-header">
            <h1>My Participation</h1>
            <p>Manage your civic engagements and track progress.</p>
        </div>

        <section class="stats-grid">
            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= str_pad($active_count, 2, '0', STR_PAD_LEFT) ?></h3>
                    <p>Active Programs</p>
                </div>
                <div class="stat-icon bg-blue"><i class="fa-solid fa-handshake-angle"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= $hours_contributed ?></h3>
                    <p>Hours Contributed</p>
                </div>
                <div class="stat-icon bg-green"><i class="fa-solid fa-clock"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= str_pad($completed_count, 2, '0', STR_PAD_LEFT) ?></h3>
                    <p>Completed Actions</p>
                </div>
                <div class="stat-icon bg-star"><i class="fa-solid fa-trophy"></i></div>
            </div>
        </section>

        <div class="filter-row">
            <a href="participant_participation.php" class="chip <?= $status_filter === 'all' ? 'active' : '' ?>">All</a>
            <a href="?status=registered" class="chip <?= $status_filter === 'registered' ? 'active' : '' ?>">Registered</a>
            <a href="?status=completed" class="chip <?= $status_filter === 'completed' ? 'active' : '' ?>">Completed</a>
            <a href="?status=cancelled" class="chip <?= $status_filter === 'cancelled' ? 'active' : '' ?>">Cancelled</a>
        </div>

        <div class="section-title">Participation History</div>

        <div class="history-list">
            <?php if (empty($records)): ?>
                <div class="no-records">
                    <i class="fa-solid fa-folder-open"></i>
                    <p>No participation records found.</p>
                </div>
            <?php else: ?>
                <?php foreach ($records as $rec): ?>
                <div class="history-item">
                    <div class="prog-main">
                        <div class="prog-icon"><i class="fa-solid fa-shapes"></i></div>
                        <div class="prog-info">
                            <span class="meta-date">
                                Joined <?= date('M j, Y', strtotime($rec['participation_date'])) ?>
                            </span>
                            <h4><?= htmlspecialchars($rec['program_name']) ?></h4>
                            <p class="prog-desc">
                                <?= htmlspecialchars(mb_strimwidth($rec['description'] ?? '', 0, 80, '...')) ?>
                            </p>
                        </div>
                    </div>
                    <div class="prog-status">
                        <div class="status-text"
                             style="color:<?= $status_colors[$rec['status']] ?? '#718096' ?>">
                            <?= ucfirst($rec['status']) ?>
                        </div>
                        <div style="font-size:0.75rem; color:#a0aec0; margin-top:4px;">
                            <?= htmlspecialchars($rec['location'] ?? '') ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>
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