<?php
// participant_program.php
session_start();
require_once 'database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'participant') {
    header('Location: participant_login.php');
    exit();
}

$db             = getDB();
$participant_id = (int) $_SESSION['participant_id'];
$message        = '';
$message_type   = '';

/* ════════════════════════════════════════════════════════════
 * Shared status-computation helper  (identical to staff page)
 * Rule order:
 *   1. DB status === 'cancelled'  → cancelled  (always honoured)
 *   2. today < start_date         → upcoming
 *   3. today > end_date           → completed
 *   4. otherwise                  → ongoing
 * ════════════════════════════════════════════════════════════ */
function computeStatus(string $dbStatus, string $startDate, string $endDate): string {
    if ($dbStatus === 'cancelled') return 'cancelled';
    $today = date('Y-m-d');
    if ($today < $startDate)  return 'upcoming';
    if ($today > $endDate)    return 'completed';
    return 'ongoing';
}

/* ── Shared constants (same values as staff page) ── */
const STATUS_LIST = ['upcoming', 'ongoing', 'completed', 'cancelled'];

const STATUS_COLORS = [
    'upcoming'  => '#3182ce',
    'ongoing'   => '#38a169',
    'completed' => '#718096',
    'cancelled' => '#e53e3e',
];

/* ═══════════════════════════
 * HANDLE JOIN
 * ═══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_program_id'])) {
    $program_id = (int) $_POST['join_program_id'];

    /* Check if already joined */
    $check = $db->prepare(
        "SELECT participation_id FROM participation
         WHERE participant_id = ? AND program_id = ? LIMIT 1"
    );
    $check->bind_param('ii', $participant_id, $program_id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $message      = 'You have already joined this program.';
        $message_type = 'warning';
    } else {
        /* Check capacity */
        $cap = $db->prepare(
            "SELECT p.status, p.start_date, p.end_date, p.max_participants,
                    (SELECT COUNT(*) FROM participation pr
                     WHERE pr.program_id = p.program_id AND pr.status != 'cancelled') AS joined
             FROM programs p WHERE p.program_id = ?"
        );
        $cap->bind_param('i', $program_id);
        $cap->execute();
        $cap_row = $cap->get_result()->fetch_assoc();
        $cap->close();

        /* Re-compute status server-side before allowing join */
        $realStatus = computeStatus(
            $cap_row['status'] ?? '',
            $cap_row['start_date'] ?? '',
            $cap_row['end_date']   ?? ''
        );

        if (!in_array($realStatus, ['upcoming', 'ongoing'])) {
            $message      = 'This program is no longer open for registration.';
            $message_type = 'error';
        } elseif ($cap_row['max_participants'] !== null && $cap_row['joined'] >= $cap_row['max_participants']) {
            $message      = 'This program has reached its maximum number of participants.';
            $message_type = 'error';
        } else {
            $today = date('Y-m-d');
            $ins   = $db->prepare(
                "INSERT INTO participation (participant_id, program_id, participation_date, status)
                 VALUES (?, ?, ?, 'registered')"
            );
            $ins->bind_param('iis', $participant_id, $program_id, $today);
            if ($ins->execute()) {
                $message      = 'Successfully joined the program!';
                $message_type = 'success';
            } else {
                $message      = 'Failed to join. Please try again.';
                $message_type = 'error';
            }
            $ins->close();
        }
    }
    $check->close();
}

/* ═══════════════════════════
 * FETCH + FILTER PROGRAMS
 * ═══════════════════════════ */
$filter = in_array($_GET['status'] ?? '', STATUS_LIST) ? $_GET['status'] : 'all';
$search = trim($_GET['search'] ?? '');

$sql = "SELECT p.*,
               (SELECT COUNT(*) FROM participation pr
                WHERE pr.program_id = p.program_id AND pr.status != 'cancelled') AS joined_count,
               (SELECT COUNT(*) FROM participation pr
                WHERE pr.program_id = p.program_id
                  AND pr.participant_id = ?
                  AND pr.status != 'cancelled') AS already_joined
        FROM programs p WHERE 1=1";
$params = [$participant_id];
$types  = 'i';

if ($filter !== 'all') {
    $sql      .= " AND p.status = ?";
    $params[]  = $filter;
    $types    .= 's';
}
if ($search) {
    $sql      .= " AND (p.program_name LIKE ? OR p.description LIKE ?)";
    $like      = '%' . $search . '%';
    $params[]  = $like;
    $params[]  = $like;
    $types    .= 'ss';
}
$sql .= " ORDER BY p.start_date ASC";

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$programs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/*
 * Re-sync computed status — keeps participant view consistent
 * with staff view without needing a cron job.
 */
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

/* Re-apply in-memory filter after potential re-sync */
if ($filter !== 'all') {
    $programs = array_values(array_filter($programs, fn($p) => $p['status'] === $filter));
}

/* ── Profile initials ── */
$initials = strtoupper(
    (($_SESSION['first_name'] ?? '')[0] ?? '') .
    (($_SESSION['last_name']  ?? '')[0] ?? '')
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - Programs</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ════════════════════════════════
           Base / Layout
        ════════════════════════════════ */
        * { margin: 0; padding: 0; box-sizing: border-box;
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        body { background: #f8fafc; display: flex; height: 100vh; overflow: hidden; }

        /* ── Sidebar ── */
        .nav-sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #e2e8f0;
            padding: 25px;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .nav-logo {
            color: #334e5e;
            font-weight: 700;
            font-size: 1.3rem;
            margin-bottom: 40px;
            letter-spacing: 0.5px;
        }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 15px;
            color: #718096;
            text-decoration: none;
            border-radius: 10px;
            margin-bottom: 8px;
            transition: 0.2s;
            font-size: 0.95rem;
            font-weight: 500;
        }
        .nav-item:hover { background: #f1f5f9; color: #334e5e; }
        .nav-item.active {
            background: #f1f5f9;
            color: #334e5e;
            border-right: 4px solid #334e5e;
            border-radius: 10px 0 0 10px;
            font-weight: 600;
        }
        .nav-footer {
            margin-top: auto;
            padding-top: 20px;
            border-top: 1px solid #f1f5f9;
        }

        /* ── Main wrapper ── */
        .main-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* ── Top header ── */
        .top-header {
            padding: 15px 40px;
            background: white;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
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
        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
            color: #718096;
        }
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

        /* ── Content scroll area ── */
        .content-scroll {
            padding: 40px;
            overflow-y: auto;
            flex: 1;
        }

        /* ── Page intro ── */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 28px;
        }
        .header-text h1 {
            font-size: 2rem;
            color: #1a202c;
            font-weight: 700;
            margin-bottom: 5px;
        }
        .header-text p { color: #718096; font-size: 0.95rem; line-height: 1.5; }

        /* ── Alert ── */
        .alert {
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 0.9rem;
            margin-bottom: 20px;
        }
        .alert-success { background: #f0fff4; border: 1px solid #9ae6b4; color: #276749; }
        .alert-error   { background: #fff5f5; border: 1px solid #feb2b2; color: #c53030; }
        .alert-warning { background: #fffbeb; border: 1px solid #f6e05e; color: #744210; }

        /* ── Chips ── */
        .chip {
            padding: 8px 18px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 30px;
            font-size: 0.85rem;
            color: #718096;
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-family: inherit;
        }
        .chip.active { background: #334e5e; color: white; border-color: #334e5e; }
        .chip:hover:not(.active) { background: #f8fafc; border-color: #cbd5e0; color: #334e5e; }

        /* ══════════════════════════════════════
           Shared Program Card Styles
           (identical selectors to staff page)
        ══════════════════════════════════════ */

        /* ── Filter row ── */
        .prog-filter-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 28px;
        }
        .prog-chips { display: flex; gap: 8px; flex-wrap: wrap; }

        /* ── Grid ── */
        .prog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 25px;
        }

        /* ── Card ── */
        .prog-card {
            background: white;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .prog-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px -8px rgba(0,0,0,0.1);
        }
        .prog-card-stripe { height: 6px; flex-shrink: 0; }
        .prog-card-body {
            padding: 22px 24px 20px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        /* ── Title row ── */
        .prog-card-title-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 10px;
        }
        .prog-card-title {
            font-size: 1.05rem;
            color: #1a202c;
            font-weight: 700;
            line-height: 1.35;
        }
        .prog-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            border: 1px solid;
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* ── Description ── */
        .prog-card-desc {
            font-size: 0.85rem;
            color: #718096;
            line-height: 1.55;
            margin-bottom: 18px;
            flex: 1;
        }

        /* ── Meta ── */
        .prog-card-meta {
            border-top: 1px solid #f1f5f9;
            padding-top: 14px;
            margin-bottom: 18px;
        }
        .prog-meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 7px;
            font-size: 0.82rem;
        }
        .prog-meta-label {
            color: #a0aec0;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .prog-meta-value {
            color: #4a5568;
            font-weight: 600;
            text-align: right;
        }

        /* ── Action area ── */
        .prog-card-actions {
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
        }

        /* ── Join button (participant-specific variant) ── */
        .btn-join {
            width: 100%;
            padding: 11px;
            background: #cfe9ef;
            color: #334e5e;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
            font-size: 0.92rem;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-join:hover:not(:disabled) { background: #334e5e; color: white; }
        .btn-join:disabled { background: #e2e8f0; color: #a0aec0; cursor: not-allowed; }
        .btn-join.btn-joined { background: #c6f6d5; color: #276749; }
        .btn-join.btn-full   { background: #fed7d7; color: #c53030; }

        /* ── Empty state ── */
        .prog-empty {
            text-align: center;
            padding: 70px 20px;
            color: #718096;
        }
        .prog-empty i {
            font-size: 3rem;
            color: #cbd5e0;
            display: block;
            margin-bottom: 15px;
        }
        .prog-empty p { font-size: 0.95rem; }
    </style>
</head>
<body>

<!-- ═══════════════════
     SIDEBAR
═══════════════════ -->
<nav class="nav-sidebar">
    <div class="nav-logo">Bayanihan</div>
    <a href="participant_dashboard.php"    class="nav-item"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="participant_program.php"      class="nav-item active"><i class="fa-solid fa-shapes"></i> Programs</a>
    <a href="participant_participation.php" class="nav-item"><i class="fa-solid fa-handshake-angle"></i> My Participation</a>
    <a href="participant_attendance.php"   class="nav-item"><i class="fa-solid fa-calendar-check"></i> Attendance</a>
    <a href="participant_profile.php"      class="nav-item"><i class="fa-solid fa-circle-user"></i> Profile</a>
    <div class="nav-footer">
        <a href="logout.php" class="nav-item"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</nav>

<!-- ═══════════════════
     MAIN WRAPPER
═══════════════════ -->
<div class="main-wrapper">

    <!-- Top header with search -->
    <header class="top-header">
        <form method="GET" action="participant_program.php" style="display:contents;">
            <?php if ($filter !== 'all'): ?>
                <input type="hidden" name="status" value="<?= $filter ?>">
            <?php endif; ?>
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
            <div class="profile-avatar"><?= htmlspecialchars($initials) ?></div>
        </div>
    </header>

    <!-- Scrollable content -->
    <div class="content-scroll">

        <!-- Page intro -->
        <div class="page-header">
            <div class="header-text">
                <h1>Programs</h1>
                <p>Explore and engage in community programs that support growth, health, and local connection.</p>
            </div>
        </div>

        <!-- Alert -->
        <?php if ($message): ?>
            <div class="alert alert-<?= htmlspecialchars($message_type) ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- ── Filter Row ── -->
        <div class="prog-filter-row">
            <!-- Status chips -->
            <div class="prog-chips">
                <a href="participant_program.php<?= $search ? '?search='.urlencode($search) : '' ?>"
                   class="chip <?= $filter === 'all' ? 'active' : '' ?>">All</a>
                <?php foreach (STATUS_LIST as $s): ?>
                    <a href="?status=<?= $s ?><?= $search ? '&search='.urlencode($search) : '' ?>"
                       class="chip <?= $filter === $s ? 'active' : '' ?>">
                        <?= ucfirst($s) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- ── Program Cards Grid ── -->
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

                $is_joined = (int)$p['already_joined'] > 0;
                $is_full   = $p['max_participants'] && $p['joined_count'] >= $p['max_participants'];
                $can_join  = !$is_joined && !$is_full && in_array($p['status'], ['upcoming', 'ongoing']);

                $details = [
                    ['label' => 'Start Date',   'icon' => 'fa-calendar-days',  'value' => date('M j, Y', strtotime($p['start_date']))],
                    ['label' => 'End Date',     'icon' => 'fa-calendar-check', 'value' => date('M j, Y', strtotime($p['end_date']))],
                    ['label' => 'Location',     'icon' => 'fa-location-dot',   'value' => htmlspecialchars($p['location'] ?? '—')],
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

                    <!-- Title + Badge -->
                    <div class="prog-card-title-row">
                        <h3 class="prog-card-title"><?= htmlspecialchars($p['program_name']) ?></h3>
                        <span class="prog-badge" style="background:transparent;color:<?= $sc ?>;border:none;">
                            <i class="fa-solid <?= $icon ?>"></i> <?= ucfirst($p['status']) ?>
                        </span>
                    </div>

                    <!-- Description -->
                    <p class="prog-card-desc">
                        <?= htmlspecialchars(mb_strimwidth($p['description'] ?? '', 0, 110, '…')) ?>
                    </p>

                    <!-- Meta Details -->
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

                    <!-- Join / Status Button -->
                    <div class="prog-card-actions">
                        <?php if ($is_joined): ?>
                            <button class="btn-join btn-joined" disabled>
                                <i class="fa-solid fa-circle-check"></i> Joined
                            </button>
                        <?php elseif ($is_full): ?>
                            <button class="btn-join btn-full" disabled>
                                <i class="fa-solid fa-users-slash"></i> Program Full
                            </button>
                        <?php elseif (!in_array($p['status'], ['upcoming', 'ongoing'])): ?>
                            <button class="btn-join" disabled>
                                <i class="fa-solid fa-ban"></i> Unavailable
                            </button>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="join_program_id" value="<?= $p['program_id'] ?>">
                                <button type="submit" class="btn-join">
                                    <i class="fa-solid fa-handshake-angle"></i> Join Program
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div><!-- /.content-scroll -->
</div><!-- /.main-wrapper -->

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