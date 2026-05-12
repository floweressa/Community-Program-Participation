<?php
// staff_attendance.php

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

/* ── SUBMIT ATTENDANCE BATCH ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    $program_id = (int)($_POST['program_id']   ?? 0);
    $att_date   = $_POST['attendance_date']    ?? date('Y-m-d');
    $statuses   = $_POST['attendance']         ?? [];

    if (!$program_id || empty($statuses)) {
        $message  = 'Please select a program and mark at least one participant.';
        $msg_type = 'error';
    } else {
        $saved = 0;
        foreach ($statuses as $part_id => $status) {
            $part_id = (int) $part_id;
            if (!in_array($status, ['present', 'absent'])) continue;

            $chk = $db->prepare(
                "SELECT attendance_id FROM attendance
                 WHERE participation_id = ? AND attendance_date = ? LIMIT 1"
            );
            $chk->bind_param('is', $part_id, $att_date);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($existing) {
                $upd = $db->prepare("UPDATE attendance SET status = ? WHERE attendance_id = ?");
                $upd->bind_param('si', $status, $existing['attendance_id']);
                $upd->execute() && $saved++;
                $upd->close();
            } else {
                $ins = $db->prepare("INSERT INTO attendance (participation_id, attendance_date, status) VALUES (?,?,?)");
                $ins->bind_param('iss', $part_id, $att_date, $status);
                $ins->execute() && $saved++;
                $ins->close();
            }
        }
        $message  = "Attendance saved — {$saved} record(s) updated for " . date('M j, Y', strtotime($att_date)) . ".";
        $msg_type = 'success';
    }
}

/* ── SELECTED PROGRAM & DATE ── */
$selected_program = (int)($_POST['program_id']   ?? $_GET['program_id'] ?? 0);
$search           = trim($_GET['search'] ?? '');
$selected_date    = $_POST['attendance_date']    ?? $_GET['date']       ?? date('Y-m-d');

/* ── FETCH ALL PROGRAMS ── */
$all_programs = $db->query(
    "SELECT program_id, program_name, location FROM programs
     WHERE status IN ('upcoming','ongoing') ORDER BY program_name ASC"
)->fetch_all(MYSQLI_ASSOC);

/* ── FETCH PARTICIPANTS FOR SELECTED PROGRAM ── */
$session_participants = [];
$session_info         = null;
$attendance_map       = [];

if ($selected_program > 0) {
    $stmt = $db->prepare("SELECT program_name, location, max_participants FROM programs WHERE program_id = ?");
    $stmt->bind_param('i', $selected_program);
    $stmt->execute();
    $session_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $db->prepare(
        "SELECT pr.participation_id, u.first_name, u.last_name, u.email, u.user_id,
                p.participant_id
         FROM participation pr
         JOIN participants p ON p.participant_id = pr.participant_id
         JOIN users u        ON u.user_id = p.user_id
         WHERE pr.program_id = ? AND pr.status IN ('registered','completed')
         ORDER BY u.last_name ASC, u.first_name ASC"
    );
    $stmt->bind_param('i', $selected_program);
    $stmt->execute();
    $session_participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Apply search filter in-memory after fetch
    if ($search) {
        $lc = strtolower($search);
        $session_participants = array_values(array_filter(
            $session_participants,
            fn($p) => str_contains(strtolower($p['first_name'] . ' ' . $p['last_name']), $lc)
                   || str_contains(strtolower($p['email'] ?? ''), $lc)
        ));
    }

    if ($session_participants) {
        $part_ids     = array_column($session_participants, 'participation_id');
        $placeholders = implode(',', array_fill(0, count($part_ids), '?'));
        $types_str    = str_repeat('i', count($part_ids));

        $att_stmt    = $db->prepare(
            "SELECT participation_id, status FROM attendance
             WHERE participation_id IN ($placeholders) AND attendance_date = ?"
        );
        $bind_params = array_merge($part_ids, [$selected_date]);
        $att_stmt->bind_param($types_str . 's', ...$bind_params);
        $att_stmt->execute();
        foreach ($att_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
            $attendance_map[$a['participation_id']] = $a['status'];
        }
        $att_stmt->close();
    }
}

$present_count  = count(array_filter($attendance_map, fn($s) => $s === 'present'));
$absent_count   = count(array_filter($attendance_map, fn($s) => $s === 'absent'));
$total_enrolled = count($session_participants);

/* ── ATTENDANCE HISTORY ── */
$history = [];
if ($selected_program > 0) {
    $hist_stmt = $db->prepare(
        "SELECT a.attendance_date,
                SUM(a.status = 'present') AS present,
                SUM(a.status = 'absent')  AS absent,
                COUNT(*) AS total
         FROM attendance a
         JOIN participation pr ON pr.participation_id = a.participation_id
         WHERE pr.program_id = ?
         GROUP BY a.attendance_date
         ORDER BY a.attendance_date DESC
         LIMIT 5"
    );
    $hist_stmt->bind_param('i', $selected_program);
    $hist_stmt->execute();
    $history = $hist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $hist_stmt->close();
}

$first_name = e($_SESSION['first_name'] ?? '');
$last_name  = e($_SESSION['last_name']  ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - Attendance</title>
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
        .btn-danger { background:#fff5f5; color:#e53e3e; padding:11px 22px;
            border-radius:10px; border:1px solid #fed7d7; font-weight:600; cursor:pointer;
            display:inline-flex; align-items:center; gap:8px; transition:0.2s;
            font-size:0.9rem; font-family:inherit; }
        .btn-danger:hover { background:#e53e3e; color:white; border-color:#e53e3e; }

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
    </style>
</head>
<body>

<!-- ── Sidebar ── -->
<nav class="nav-sidebar">
    <div class="nav-logo">Bayanihan</div>
    <a href="staff_dashboard.php"     class="nav-item"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="staff_program.php"       class="nav-item"><i class="fa-solid fa-shapes"></i> Programs</a>
    <a href="staff_participant.php"   class="nav-item"><i class="fa-solid fa-users"></i> Participants</a>
    <a href="staff_participation.php" class="nav-item"><i class="fa-solid fa-handshake-angle"></i> Participation</a>
    <a href="staff_attendance.php"    class="nav-item active"><i class="fa-solid fa-calendar-check"></i> Attendance</a>
    <a href="staff_report.php"        class="nav-item"><i class="fa-solid fa-chart-bar"></i> Reports</a>
    <div class="nav-footer">
        <a href="logout.php" class="nav-item"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</nav>

<!-- ── Main ── -->
<div class="main-wrapper">
    <header class="top-header">
<form method="GET" style="display:contents;">
        <?php if ($selected_program): ?><input type='hidden' name='program_id' value='<?= $selected_program ?>'><?php endif; ?>
        <?php if ($selected_date !== date('Y-m-d')): ?><input type='hidden' name='date' value='<?= htmlspecialchars($selected_date) ?>'><?php endif; ?>
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
                <h1>Mark Attendance</h1>
                <p>Update daily participation for your active community sessions.</p>
            </div>
        </div>

        <!-- Controls Form -->
        <form method="POST" id="attendanceForm">
            <div style="display:flex;gap:20px;margin-bottom:25px;flex-wrap:wrap;">

                <!-- Program Selector -->
                <div style="background:white;padding:20px;border-radius:15px;border:1px solid #e2e8f0;flex:1;min-width:220px;">
                    <label style="font-size:0.72rem;font-weight:700;color:#a0aec0;text-transform:uppercase;display:block;margin-bottom:8px;">
                        Select Program
                    </label>
                    <select name="program_id" onchange="this.form.submit()"
                            style="width:100%;padding:10px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;color:#334e5e;font-weight:600;outline-color:#334e5e;font-family:inherit;">
                        <option value="0">— Choose a program —</option>
                        <?php foreach ($all_programs as $ap): ?>
                        <option value="<?= $ap['program_id'] ?>" <?= $selected_program===(int)$ap['program_id']?'selected':'' ?>>
                            <?= e($ap['program_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date Selector -->
                <div style="background:white;padding:20px;border-radius:15px;border:1px solid #e2e8f0;flex:0 0 auto;min-width:180px;">
                    <label style="font-size:0.72rem;font-weight:700;color:#a0aec0;text-transform:uppercase;display:block;margin-bottom:8px;">
                        Session Date
                    </label>
                    <input type="date" name="attendance_date" value="<?= e($selected_date) ?>"
                           onchange="this.form.submit()"
                           style="width:100%;padding:10px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;color:#334e5e;font-weight:600;outline-color:#334e5e;font-family:inherit;">
                </div>

                <?php if ($session_info): ?>
                <!-- Session Info -->
                <div style="background:white;padding:20px;border-radius:15px;border:1px solid #e2e8f0;flex:1.5;display:flex;align-items:center;gap:25px;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:42px;height:42px;background:#edf2f7;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#334e5e;">
                            <i class="fa-solid fa-users-viewfinder"></i>
                        </div>
                        <div>
                            <span style="font-size:0.7rem;color:#a0aec0;font-weight:700;text-transform:uppercase;display:block;">Enrolled</span>
                            <strong style="color:#334e5e;font-size:1.05rem;">
                                <?= $present_count + $absent_count ?> marked / <?= $total_enrolled ?> total
                            </strong>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:42px;height:42px;background:#edf2f7;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#334e5e;">
                            <i class="fa-solid fa-location-dot"></i>
                        </div>
                        <div>
                            <span style="font-size:0.7rem;color:#a0aec0;font-weight:700;text-transform:uppercase;display:block;">Location</span>
                            <strong style="color:#334e5e;font-size:1.05rem;"><?= e($session_info['location'] ?? '—') ?></strong>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:42px;height:42px;background:#f0fff4;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#38a169;">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div>
                            <span style="font-size:0.7rem;color:#a0aec0;font-weight:700;text-transform:uppercase;display:block;">Present</span>
                            <strong style="color:#38a169;font-size:1.05rem;"><?= $present_count ?></strong>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:42px;height:42px;background:#fff5f5;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#e53e3e;">
                            <i class="fa-solid fa-circle-xmark"></i>
                        </div>
                        <div>
                            <span style="font-size:0.7rem;color:#a0aec0;font-weight:700;text-transform:uppercase;display:block;">Absent</span>
                            <strong style="color:#e53e3e;font-size:1.05rem;"><?= $absent_count ?></strong>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($selected_program > 0 && !empty($session_participants)): ?>
            <!-- Participant Attendance List -->
            <div style="background:white;border-radius:15px;border:1px solid #e2e8f0;padding:25px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                    <h2 style="font-size:1.1rem;color:#334e5e;font-weight:700;">
                        <?= e($session_info['program_name'] ?? '') ?> — <?= date('l, F j, Y', strtotime($selected_date)) ?>
                    </h2>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <button type="button" class="btn-secondary" style="padding:8px 16px;font-size:0.82rem;" onclick="markAll('present')">
                            <i class="fa-solid fa-check"></i> Mark All Present
                        </button>
                        <button type="button" class="btn-danger" style="padding:8px 16px;font-size:0.82rem;" onclick="markAll('absent')">
                            <i class="fa-solid fa-xmark"></i> Mark All Absent
                        </button>
                    </div>
                </div>

                <?php foreach ($session_participants as $sp):
                    $pid      = $sp['participation_id'];
                    $current  = $attendance_map[$pid] ?? '';
                    $initials = strtoupper(substr($sp['first_name'],0,1).substr($sp['last_name'],0,1));
                ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-bottom:1px solid #f1f5f9;"
                     id="row_<?= $pid ?>">
                    <div class="user-cell">
                        <div class="user-initials"><?= $initials ?></div>
                        <div class="user-cell-info">
                            <span class="uname"><?= e($sp['first_name'].' '.$sp['last_name']) ?></span>
                            <span class="uemail"><?= e($sp['email']) ?></span>
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <!-- Present Button -->
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="radio" name="attendance[<?= $pid ?>]" value="present"
                                   <?= $current==='present'?'checked':'' ?>
                                   onchange="highlightRow(<?= $pid ?>,'present')"
                                   style="display:none;" class="att-radio-<?= $pid ?>">
                            <div id="btn_present_<?= $pid ?>"
                                 onclick="selectStatus(<?= $pid ?>,'present')"
                                 style="width:38px;height:38px;border-radius:50%;border:2px solid <?= $current==='present'?'#334e5e':'#e2e8f0' ?>;
                                        background:<?= $current==='present'?'#334e5e':'white' ?>;
                                        color:<?= $current==='present'?'white':'#a0aec0' ?>;
                                        display:flex;align-items:center;justify-content:center;cursor:pointer;transition:0.2s;">
                                <i class="fa-solid fa-check" style="font-size:0.9rem;"></i>
                            </div>
                        </label>
                        <!-- Absent Button -->
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="radio" name="attendance[<?= $pid ?>]" value="absent"
                                   <?= $current==='absent'?'checked':'' ?>
                                   onchange="highlightRow(<?= $pid ?>,'absent')"
                                   style="display:none;" class="att-radio-<?= $pid ?>">
                            <div id="btn_absent_<?= $pid ?>"
                                 onclick="selectStatus(<?= $pid ?>,'absent')"
                                 style="width:38px;height:38px;border-radius:50%;border:2px solid <?= $current==='absent'?'#e53e3e':'#e2e8f0' ?>;
                                        background:<?= $current==='absent'?'#e53e3e':'white' ?>;
                                        color:<?= $current==='absent'?'white':'#a0aec0' ?>;
                                        display:flex;align-items:center;justify-content:center;cursor:pointer;transition:0.2s;">
                                <i class="fa-solid fa-xmark" style="font-size:0.9rem;"></i>
                            </div>
                        </label>
                    </div>
                </div>
                <?php endforeach; ?>

                <div style="display:flex;justify-content:flex-end;margin-top:20px;padding-top:15px;border-top:1px solid #f1f5f9;">
                    <button type="submit" name="submit_attendance" value="1" class="btn-primary" style="padding:12px 30px;">
                        <i class="fa-solid fa-floppy-disk"></i> Save Attendance
                    </button>
                </div>
            </div>

            <?php elseif ($selected_program > 0): ?>
            <div style="text-align:center;padding:50px;background:white;border-radius:15px;border:1px solid #e2e8f0;color:#718096;">
                <i class="fa-solid fa-users-slash" style="font-size:2.5rem;color:#cbd5e0;display:block;margin-bottom:12px;"></i>
                <p>No enrolled participants found for this program.</p>
            </div>

            <?php else: ?>
            <div style="text-align:center;padding:60px;background:white;border-radius:15px;border:1px solid #e2e8f0;color:#718096;">
                <i class="fa-solid fa-calendar-check" style="font-size:2.5rem;color:#cbd5e0;display:block;margin-bottom:12px;"></i>
                <p>Select a program above to start marking attendance.</p>
            </div>
            <?php endif; ?>

        </form>

        <?php if (!empty($history)): ?>
        <div class="table-card" style="margin-top:25px;">
            <h2 style="font-size:1rem;color:#334e5e;font-weight:700;margin-bottom:18px;">
                Recent Sessions — <?= e($session_info['program_name'] ?? '') ?>
            </h2>
            <table>
                <thead><tr>
                    <th>Date</th>
                    <th>Present</th>
                    <th>Absent</th>
                    <th>Total Marked</th>
                    <th>Rate</th>
                </tr></thead>
                <tbody>
                <?php foreach ($history as $h):
                    $rate = $h['total'] > 0 ? round(($h['present']/$h['total'])*100) : 0;
                ?>
                <tr>
                    <td><strong><?= date('M j, Y', strtotime($h['attendance_date'])) ?></strong></td>
                    <td style="color:#38a169;font-weight:600;"><?= $h['present'] ?></td>
                    <td style="color:#e53e3e;font-weight:600;"><?= $h['absent'] ?></td>
                    <td><?= $h['total'] ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:70px;height:6px;background:#edf2f7;border-radius:10px;overflow:hidden;">
                                <div style="width:<?= $rate ?>%;height:100%;background:#334e5e;border-radius:10px;"></div>
                            </div>
                            <span style="font-size:0.82rem;font-weight:700;color:#334e5e;"><?= $rate ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </main>
</div><!-- /.main-wrapper -->

<script>
function selectStatus(pid, status) {
    const pBtn = document.getElementById('btn_present_' + pid);
    const aBtn = document.getElementById('btn_absent_'  + pid);

    pBtn.style.background  = 'white';
    pBtn.style.borderColor = '#e2e8f0';
    pBtn.style.color       = '#a0aec0';
    aBtn.style.background  = 'white';
    aBtn.style.borderColor = '#e2e8f0';
    aBtn.style.color       = '#a0aec0';

    if (status === 'present') {
        pBtn.style.background  = '#334e5e';
        pBtn.style.borderColor = '#334e5e';
        pBtn.style.color       = 'white';
    } else {
        aBtn.style.background  = '#e53e3e';
        aBtn.style.borderColor = '#e53e3e';
        aBtn.style.color       = 'white';
    }

    document.querySelectorAll('.att-radio-' + pid).forEach(r => {
        if (r.value === status) r.checked = true;
    });

    const row = document.getElementById('row_' + pid);
    row.style.background = status === 'present' ? '#f0fff4' : '#fff5f5';
}

function highlightRow(pid, status) {
    const row = document.getElementById('row_' + pid);
    if (row) row.style.background = status === 'present' ? '#f0fff4' : '#fff5f5';
}

function markAll(status) {
    document.querySelectorAll('[id^="row_"]').forEach(row => {
        const pid = row.id.replace('row_', '');
        selectStatus(parseInt(pid), status);
    });
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