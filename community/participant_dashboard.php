<?php
// participant_dashboard.php
session_start();
require_once 'database.php';

// Auth guard
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'participant') {
    header('Location: participant_login.php');
    exit();
}

$db             = getDB();
$participant_id = (int) $_SESSION['participant_id'];
$first_name     = htmlspecialchars($_SESSION['first_name']);

// --- KPI: Total Programs Joined ---
$stmt = $db->prepare(
    "SELECT COUNT(*) AS total FROM participation WHERE participant_id = ?"
);
$stmt->bind_param('i', $participant_id);
$stmt->execute();
$total_programs = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// --- KPI: Active (registered) Programs ---
$stmt = $db->prepare(
    "SELECT COUNT(*) AS active FROM participation
     WHERE participant_id = ? AND status = 'registered'"
);
$stmt->bind_param('i', $participant_id);
$stmt->execute();
$active_programs = $stmt->get_result()->fetch_assoc()['active'];
$stmt->close();

// --- KPI: Attendance Rate ---
$stmt = $db->prepare(
    "SELECT
        COUNT(*) AS total_sessions,
        SUM(a.status = 'present') AS present_count
     FROM attendance a
     JOIN participation p ON p.participation_id = a.participation_id
     WHERE p.participant_id = ?"
);
$stmt->bind_param('i', $participant_id);
$stmt->execute();
$att_row        = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_sessions = (int) $att_row['total_sessions'];
$present_count  = (int) $att_row['present_count'];
$attendance_rate = $total_sessions > 0
    ? round(($present_count / $total_sessions) * 100)
    : 0;

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
    <title>Bayanihan - Dashboard</title>
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
        .header-actions { display: flex; align-items: center; gap: 20px; color: #718096; margin-left:auto; }
        .content-scroll { padding: 40px; overflow-y: auto; flex: 1; }
        .welcome-msg h1 { font-size: 2rem; color: #1a202c; font-weight: 700;}
        .welcome-msg p { color: #718096; margin-top: 5px; }
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 35px; }
        .stat-card { background: white; padding: 25px; border-radius: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: flex-start; }
        .stat-icon { width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        .stat-info h3 { font-size: 1.8rem; color: #1a202c; }
        .stat-info p { font-size: 0.85rem; color: #718096; margin-top: 8px; font-weight: 500; }
        .bg-blue { background: #ebf8ff; color: #3182ce; }
        .bg-green { background: #f0fff4; color: #38a169; }
        .bg-star { background: #fffaf0; color: #dd6b20; }
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

        .header-search-wrap { position:relative; width:350px; }
        .header-search-wrap i { position:absolute; left:15px; top:50%;
            transform:translateY(-50%); color:white; }
        .header-search-wrap input { width:100%; padding:10px 15px 10px 45px;
            background:white; border:none; border-radius:10px;
            outline-color:white; font-size:0.9rem; font-family:inherit; }
    </style>
</head>
<body>
<nav class="nav-sidebar">
    <div class="nav-logo">Bayanihan</div>
    <a href="participant_dashboard.php" class="nav-item active"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="participant_program.php" class="nav-item"><i class="fa-solid fa-shapes"></i> Programs</a>
    <a href="participant_participation.php" class="nav-item"><i class="fa-solid fa-handshake-angle"></i> My Participation</a>
    <a href="participant_attendance.php" class="nav-item"><i class="fa-solid fa-calendar-check"></i> Attendance</a>
    <a href="participant_profile.php" class="nav-item"><i class="fa-solid fa-circle-user"></i> Profile</a>
    <div class="nav-footer">
        <a href="logout.php" class="nav-item"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</nav>

<div class="main-wrapper">
    <header class="top-header">
        <div class="header-search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text">
        </div>
        <div class="header-actions">
            <i class="fa-regular fa-bell"></i>
            <i class="fa-regular fa-circle-question"></i>
            <div class="profile-avatar"><?= strtoupper($initials) ?></div>
        </div>
    </header>

    <main class="content-scroll">
        <section class="welcome-msg">
            <h1>Welcome back, <?= $first_name ?>!</h1>
            <p>Stay connected with community programs and track your participation anytime.</p>
        </section>

        <section class="stats-grid">
            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= str_pad($total_programs, 2, '0', STR_PAD_LEFT) ?></h3>
                    <p>Total Programs Joined</p>
                </div>
                <div class="stat-icon bg-blue"><i class="fa-solid fa-rocket"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= $attendance_rate ?>%</h3>
                    <p>Attendance Rate</p>
                </div>
                <div class="stat-icon bg-green"><i class="fa-solid fa-shield-halved"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <h3><?= str_pad($active_programs, 2, '0', STR_PAD_LEFT) ?></h3>
                    <p>Active Programs</p>
                </div>
                <div class="stat-icon bg-star"><i class="fa-solid fa-star"></i></div>
            </div>
        </section>
    </main>
</div>
</body>
</html>