<?php
// participant_attendance.php
session_start();
require_once 'database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'participant') {
    header('Location: participant_login.php');
    exit();
}

$db             = getDB();
$participant_id = (int) $_SESSION['participant_id'];

// --- KPI: Overall stats ---
$stmt = $db->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(a.status = 'present') AS present,
        SUM(a.status = 'absent') AS absent
     FROM attendance a
     JOIN participation p ON p.participation_id = a.participation_id
     WHERE p.participant_id = ?"
);
$stmt->bind_param('i', $participant_id);
$stmt->execute();
$kpi = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total   = (int) $kpi['total'];
$present = (int) $kpi['present'];
$absent  = (int) $kpi['absent'];
$rate    = $total > 0 ? round(($present / $total) * 100, 1) : 0;

// --- Attendance Records ---
$program_filter = (int) ($_GET['program_id'] ?? 0);
$search         = trim($_GET['search'] ?? '');

$sql = "SELECT a.attendance_id, a.attendance_date, a.status,
               pg.program_name, pg.location,
               par.participation_id
        FROM attendance a
        JOIN participation par ON par.participation_id = a.participation_id
        JOIN programs pg ON pg.program_id = par.program_id
        WHERE par.participant_id = ?";
$params = [$participant_id];
$types  = 'i';

if ($program_filter > 0) {
    $sql    .= " AND par.program_id = ?";
    $params[] = $program_filter;
    $types   .= 'i';
}

if ($search) {
    $sql    .= " AND (pg.program_name LIKE ? OR pg.location LIKE ?)";
    $like      = '%' . $search . '%';
    $params[]  = $like;
    $params[]  = $like;
    $types    .= 'ss';
}
$sql .= " ORDER BY a.attendance_date DESC";

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Programs for filter chips ---
$stmt = $db->prepare(
    "SELECT DISTINCT pg.program_id, pg.program_name
     FROM programs pg
     JOIN participation par ON par.program_id = pg.program_id
     WHERE par.participant_id = ?
     ORDER BY pg.program_name ASC"
);
$stmt->bind_param('i', $participant_id);
$stmt->execute();
$my_programs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

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
    <title>Bayanihan - Attendance</title>
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
        .content-scroll { padding: 40px; overflow-y: auto; flex: 1; }
        .page-header h1 { font-size: 2rem; color: #1a202c; margin-bottom: 5px;}
        .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 40px; }
        .stat-card { background: white; padding: 25px; border-radius: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; }
        .stat-info h3 { font-size: 2rem; color: #1a202c; font-weight: 700; }
        .stat-info p { font-size: 0.9rem; color: #718096; margin-bottom: 8px; font-weight: 500; }
        .progress-bar-bg { width: 100%; height: 6px; background: #edf2f7; border-radius: 10px; margin-top: 15px; overflow: hidden; }
        .progress-bar-fill { height: 100%; background: #334e5e; border-radius: 10px; }
        .stat-icon-circle { width: 60px; height: 60px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; background: #f0fff4; color: #38a169; }
        .filter-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .chips-container { display: flex; gap: 10px; flex-wrap: wrap; }
        .chip { padding: 8px 20px; background: white; border: 1px solid #e2e8f0; border-radius: 30px; font-size: 0.85rem; color: #718096; text-decoration: none; transition: 0.2s; }
        .chip.active { background: #334e5e; color: white; border-color: #334e5e; }
        .chip:hover:not(.active) { background: #f8fafc; border-color: #cbd5e0; }
        .attendance-list { display: flex; flex-direction: column; gap: 12px; }
        .attendance-item { background: white; padding: 20px 25px; border-radius: 15px; border: 1px solid #e2e8f0; display: grid; grid-template-columns: 0.5fr 2fr 1.5fr 1.5fr 1fr; align-items: center; }
        .icon-box { width: 45px; height: 45px; border-radius: 10px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #334e5e; }
        .prog-name h4 { font-size: 0.95rem; color: #1a202c; font-weight: 600; }
        .prog-name p { font-size: 0.8rem; color: #718096; }
        .label-text { font-size: 0.7rem; font-weight: 700; color: #a0aec0; text-transform: uppercase; letter-spacing: 0.5px; }
        .status-pill { font-size: 0.75rem; font-weight: 700; letter-spacing: 0.5px; text-align: center; text-transform: capitalize; }
        .status-present { color: #38a169; }
        .status-absent { color: #e53e3e; }
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
    <a href="participant_participation.php" class="nav-item"><i class="fa-solid fa-handshake-angle"></i> My Participation</a>
    <a href="participant_attendance.php" class="nav-item active"><i class="fa-solid fa-calendar-check"></i> Attendance</a>
    <a href="participant_profile.php" class="nav-item"><i class="fa-solid fa-circle-user"></i> Profile</a>
    <div class="nav-footer">
        <a href="logout.php" class="nav-item"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</nav>

<div class="main-wrapper">
    <header class="top-header">
        <form method="GET" style="display:contents;">
        <input type="hidden" name="program_id" value="<?= $program_filter ?>">
        <div class="header-search-wrap" style="position:relative;">
            <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:15px;top:50%;transform:translateY(-50%);color:#a0aec0;pointer-events:none;z-index:1;"></i>
            <input id="globalSearch" type="text" name="search" placeholder="Search sessions..."
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
            <h1>Attendance</h1>
        </div>

        <section class="stats-grid">
            <div class="stat-card">
                <div class="stat-info" style="flex: 1;">
                    <p>Overall Attendance Rate</p>
                    <h3><?= $rate ?>%</h3>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" style="width: <?= $rate ?>%;"></div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <p>Total Sessions</p>
                    <h3><?= $total ?></h3>
                    <span style="font-size: 0.8rem; color: #718096;">
                        <?= $present ?> Present &bull; <?= $absent ?> Absent
                    </span>
                </div>
                <div class="stat-icon-circle">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
            </div>
        </section>

        <div class="filter-row">
            <div class="chips-container">
                <a href="participant_attendance.php<?= $search ? '?search='.urlencode($search) : '' ?>" class="chip <?= $program_filter === 0 ? 'active' : '' ?>">
                    All Programs
                </a>
                <?php foreach ($my_programs as $mp): ?>
                <a href="?program_id=<?= $mp['program_id'] ?><?= $search ? '&search='.urlencode($search) : '' ?>"
                   class="chip <?= $program_filter === (int)$mp['program_id'] ? 'active' : '' ?>">
                    <?= htmlspecialchars($mp['program_name']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <section class="attendance-list">
            <?php if (empty($records)): ?>
                <div class="no-records">
                    <i class="fa-solid fa-calendar-xmark"></i>
                    <p>No attendance records found.</p>
                </div>
            <?php else: ?>
            <!-- Header row -->
            <div class="attendance-item" style="background:transparent; border:none; padding-bottom:0;">
                <div></div>
                <div class="label-text">Program</div>
                <div class="label-text">Date</div>
                <div class="label-text">Location</div>
                <div class="label-text" style="text-align:center;">Status</div>
            </div>

            <?php foreach ($records as $rec): ?>
            <div class="attendance-item">
                <div class="icon-box"><i class="fa-solid fa-calendar-day"></i></div>
                <div class="prog-name">
                    <h4><?= htmlspecialchars($rec['program_name']) ?></h4>
                </div>
                <div>
                    <p style="font-size:0.85rem; color:#4a5568;">
                        <strong><?= date('M d, Y', strtotime($rec['attendance_date'])) ?></strong>
                    </p>
                </div>
                <div>
                    <p style="font-size:0.85rem; color:#4a5568;">
                        <i class="fa-solid fa-location-dot" style="font-size:0.7rem; margin-right:5px;"></i>
                        <?= htmlspecialchars($rec['location'] ?? '—') ?>
                    </p>
                </div>
                <div>
                    <div class="status-pill status-<?= $rec['status'] ?>">
                        <?= ucfirst($rec['status']) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </section>
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