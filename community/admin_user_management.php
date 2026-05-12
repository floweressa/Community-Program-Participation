<?php
// admin_user_management.php

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

$db       = getDB();
$admin_id = (int) $_SESSION['user_id'];
$message  = '';
$msg_type = '';

/* ── CREATE USER ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $first  = trim($_POST['first_name'] ?? '');
    $last   = trim($_POST['last_name']  ?? '');
    $email  = trim($_POST['email']      ?? '');
    $role   = $_POST['role']            ?? 'participant';
    $pass   = $_POST['password']        ?? '';

    if (!$first || !$last || !$email || !$pass) {
        $message  = 'All fields are required.';
        $msg_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message  = 'Invalid email address.';
        $msg_type = 'error';
    } elseif (strlen($pass) < 8) {
        $message  = 'Password must be at least 8 characters.';
        $msg_type = 'error';
    } elseif (!in_array($role, ['participant','staff','admin'])) {
        $message  = 'Invalid role selected.';
        $msg_type = 'error';
    } else {
        // Check duplicate
        $chk = $db->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
        $chk->bind_param('s', $email);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $message  = 'An account with that email already exists.';
            $msg_type = 'error';
        } else {
            $hashed = password_hash($pass, PASSWORD_BCRYPT);
            $stmt   = $db->prepare(
                "INSERT INTO users (first_name, last_name, email, password, role) VALUES (?,?,?,?,?)"
            );
            $stmt->bind_param('sssss', $first, $last, $email, $hashed, $role);
            if ($stmt->execute()) {
                $new_uid = $db->insert_id;
                // If participant, also create participants row
                if ($role === 'participant') {
                    $ins = $db->prepare("INSERT INTO participants (user_id) VALUES (?)");
                    $ins->bind_param('i', $new_uid);
                    $ins->execute();
                    $ins->close();
                }
                $message  = "User \"{$first} {$last}\" created successfully.";
                $msg_type = 'success';
                $al = $db->prepare("INSERT INTO audit_logs (performed_by, action, table_name, record_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE action=action");
                if ($al) { $action='CREATE'; $tbl='users'; $al->bind_param('issi',$admin_id,$action,$tbl,$new_uid); $al->execute(); $al->close(); }
            } else {
                $message  = 'Failed to create user.';
                $msg_type = 'error';
            }
            $stmt->close();
        }
        $chk->close();
    }
}

/* ── EDIT USER ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $uid   = (int) ($_POST['user_id']    ?? 0);
    $first = trim($_POST['first_name']   ?? '');
    $last  = trim($_POST['last_name']    ?? '');
    $email = trim($_POST['email']        ?? '');
    $role  = $_POST['role']              ?? '';
    $pass  = $_POST['password']          ?? '';

    if (!$uid || !$first || !$last || !$email) {
        $message  = 'Required fields are missing.';
        $msg_type = 'error';
    } elseif ($uid === $admin_id && $role !== 'admin') {
        $message  = 'You cannot remove your own admin role.';
        $msg_type = 'error';
    } else {
        if ($pass) {
            $hashed = password_hash($pass, PASSWORD_BCRYPT);
            $stmt   = $db->prepare("UPDATE users SET first_name=?, last_name=?, email=?, role=?, password=? WHERE user_id=?");
            $stmt->bind_param('sssssi', $first, $last, $email, $role, $hashed, $uid);
        } else {
            $stmt = $db->prepare("UPDATE users SET first_name=?, last_name=?, email=?, role=? WHERE user_id=?");
            $stmt->bind_param('ssssi', $first, $last, $email, $role, $uid);
        }
        if ($stmt->execute()) {
            // If role changed to participant and no participants row, add one
            if ($role === 'participant') {
                $ck = $db->prepare("SELECT participant_id FROM participants WHERE user_id = ? LIMIT 1");
                $ck->bind_param('i', $uid); $ck->execute(); $ck->store_result();
                if ($ck->num_rows === 0) {
                    $ins = $db->prepare("INSERT INTO participants (user_id) VALUES (?)");
                    $ins->bind_param('i', $uid); $ins->execute(); $ins->close();
                }
                $ck->close();
            }
            $message  = 'User updated successfully.';
            $msg_type = 'success';
            $al = $db->prepare("INSERT INTO audit_logs (performed_by, action, table_name, record_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE action=action");
            if ($al) { $action='UPDATE'; $tbl='users'; $al->bind_param('issi',$admin_id,$action,$tbl,$uid); $al->execute(); $al->close(); }
        } else {
            $message  = 'Update failed.';
            $msg_type = 'error';
        }
        $stmt->close();
    }
}

/* ── DELETE USER ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_id'])) {
    $uid = (int) $_POST['delete_user_id'];
    if ($uid === $admin_id) {
        $message  = 'You cannot delete your own account.';
        $msg_type = 'error';
    } else {
        $stmt = $db->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param('i', $uid);
        $stmt->execute()
            ? ($message = 'User deleted.') && ($msg_type = 'success')
            : ($message = 'Delete failed.') && ($msg_type = 'error');
        $stmt->close();
        $al = $db->prepare("INSERT INTO audit_logs (performed_by, action, table_name, record_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE action=action");
        if ($al) { $action='DELETE'; $tbl='users'; $al->bind_param('issi',$admin_id,$action,$tbl,$uid); $al->execute(); $al->close(); }
    }
}

/* ── FETCH ── */
$per_page   = 15;
$page       = max(1, (int)($_GET['page'] ?? 1));
$offset     = ($page - 1) * $per_page;
$search     = trim($_GET['search'] ?? '');
$role_filter = $_GET['role'] ?? 'all';

$count_sql = "SELECT COUNT(*) FROM users WHERE 1=1";
$c_params  = [];
$c_types   = '';
if ($search) {
    $count_sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
    $like       = '%'.$search.'%';
    $c_params   = [$like, $like, $like];
    $c_types    = 'sss';
}
if ($role_filter !== 'all') {
    $count_sql .= " AND role = ?";
    $c_params[] = $role_filter;
    $c_types   .= 's';
}
if ($c_params) {
    $stmt = $db->prepare($count_sql);
    $stmt->bind_param($c_types, ...$c_params);
    $stmt->execute();
    $total_count = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
} else {
    $total_count = (int)$db->query($count_sql)->fetch_row()[0];
}
$total_pages = max(1, (int)ceil($total_count / $per_page));

$sql    = "SELECT user_id, first_name, last_name, email, role, created_at FROM users WHERE 1=1";
$params = [];
$types  = '';
if ($search) {
    $sql    .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
    $like    = '%'.$search.'%';
    $params  = [$like, $like, $like];
    $types   = 'sss';
}
if ($role_filter !== 'all') {
    $sql    .= " AND role = ?";
    $params[] = $role_filter;
    $types   .= 's';
}
$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types   .= 'ii';

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ── EDIT PREFILL ── */
$edit_user = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT user_id, first_name, last_name, email, role FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', (int)$_GET['edit']);
    $stmt->execute();
    $edit_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$show_create = (isset($_GET['action']) && $_GET['action'] === 'add') || ($msg_type === 'error' && isset($_POST['create_user']));

$first_name = e($_SESSION['first_name'] ?? '');
$last_name  = e($_SESSION['last_name']  ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - User Management</title>
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
        .filter-row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; }
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
        .pill-admin       { background:#fff5f5; color:#c53030; }
        .pill-staff       { background:#ebf8ff; color:#2b6cb0; }
        .pill-participant { background:#f0fff4; color:#276749; }
        .btn-primary { background:#334e5e; color:white; padding:11px 22px; border-radius:10px; border:none; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:0.2s; font-size:0.9rem; text-decoration:none; font-family:inherit; }
        .btn-primary:hover { background:#2a3f4d; }
        .btn-edit { background:#ebf8ff; color:#3182ce; padding:7px 13px; border-radius:8px; border:1px solid #bee3f8; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-size:0.8rem; text-decoration:none; transition:0.2s; }
        .btn-edit:hover { background:#3182ce; color:white; }
        .btn-danger { background:#fff5f5; color:#e53e3e; padding:7px 13px; border-radius:8px; border:1px solid #fed7d7; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-size:0.8rem; font-family:inherit; transition:0.2s; }
        .btn-danger:hover { background:#e53e3e; color:white; border-color:#e53e3e; }
        .pagination { display:flex; gap:8px; justify-content:center; margin-top:24px; }
        .pagination a, .pagination span { padding:8px 14px; border-radius:8px; border:1px solid #e2e8f0; background:white; color:#4a5568; font-size:0.85rem; text-decoration:none; font-weight:600; }
        .pagination a:hover { background:#f1f5f9; }
        .pagination .current { background:#334e5e; color:white; border-color:#334e5e; }
        .search-clear-btn { position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#a0aec0; font-size:0.75rem; display:none; padding:2px 4px; }
        /* Modal */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:1000; align-items:center; justify-content:center; }
        .modal-overlay.open { display:flex; }
        .modal { background:white; border-radius:20px; padding:35px; width:100%; max-width:520px; box-shadow:0 25px 50px rgba(0,0,0,0.15); }
        .modal h2 { font-size:1.3rem; color:#1a202c; margin-bottom:6px; }
        .modal p.sub { color:#718096; font-size:0.88rem; margin-bottom:24px; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .form-group { display:flex; flex-direction:column; gap:6px; }
        .form-group.full { grid-column:span 2; }
        label { font-size:0.82rem; font-weight:600; color:#4a5568; }
        input[type=text], input[type=email], input[type=password], select {
            padding:10px 14px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px;
            font-size:0.9rem; font-family:inherit; outline-color:#334e5e; width:100%; }
        input:focus, select:focus { background:white; border-color:#334e5e; }
        .modal-footer { display:flex; justify-content:flex-end; gap:12px; margin-top:24px; }
        .btn-secondary { background:#f1f5f9; color:#4a5568; padding:11px 22px; border-radius:10px; border:1px solid #e2e8f0; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:0.2s; font-size:0.9rem; font-family:inherit; }
    </style>
</head>
<body>

<nav class="nav-sidebar">
    <div class="nav-logo">Bayanihan</div>
    <div class="nav-badge">Admin</div>
    <a href="admin_dashboard.php"        class="nav-item"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="admin_program.php"         class="nav-item"><i class="fa-solid fa-shapes"></i> Programs</a>
    <a href="admin_participant.php"      class="nav-item"><i class="fa-solid fa-users"></i> Participants</a>
    <a href="admin_user_management.php"  class="nav-item active"><i class="fa-solid fa-user-shield"></i> User Management</a>
    <a href="admin_report.php"          class="nav-item"><i class="fa-solid fa-chart-bar"></i> Reports</a>
    <a href="admin_audit_log.php"       class="nav-item"><i class="fa-solid fa-clipboard-list"></i> Audit Logs</a>
    <div class="nav-footer">
        <a href="logout.php" class="nav-item"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</nav>

<div class="main-wrapper">
    <header class="top-header">
        <form id="searchForm" method="GET" style="display:contents;">
            <?php if ($role_filter !== 'all'): ?><input type="hidden" name="role" value="<?= e($role_filter) ?>"><?php endif; ?>
            <div style="position:relative;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:15px;top:50%;transform:translateY(-50%);color:#a0aec0;pointer-events:none;"></i>
                <input id="globalSearch" type="text" name="search" placeholder="Search users by name or email..."
                       value="<?= e($search) ?>"
                       style="width:380px;padding:10px 38px 10px 45px;background:#f1f5f9;border:none;border-radius:10px;outline-color:#334e5e;font-size:0.9rem;font-family:inherit;">
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
                <h1>User Management</h1>
                <p>Create, edit, and manage all platform user accounts.</p>
            </div>
            <button class="btn-primary" onclick="openModal('createModal')">
                <i class="fa-solid fa-user-plus"></i> Add New User
            </button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $msg_type ?>"><?= e($message) ?></div>
        <?php endif; ?>

        <div class="filter-row">
            <a href="admin_user_management.php<?= $search ? '?search='.urlencode($search) : '' ?>" class="chip <?= $role_filter === 'all' ? 'active' : '' ?>">All Roles</a>
            <?php foreach (['admin','staff','participant'] as $r): ?>
            <a href="?role=<?= $r ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="chip <?= $role_filter === $r ? 'active' : '' ?>"><?= ucfirst($r) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="table-card">
            <div class="table-card-header">
                <span style="font-size:0.85rem;color:#a0aec0;">Showing <?= count($users) ?> of <?= number_format($total_count) ?> users</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="4" style="text-align:center;color:#a0aec0;padding:30px;">No users found.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <div class="user-initials" style="<?= $u['role']==='admin' ? 'background:#fff5f5;color:#c53030;' : ($u['role']==='staff' ? 'background:#ebf8ff;color:#3182ce;' : '') ?>">
                                    <?= strtoupper(substr($u['first_name'],0,1).substr($u['last_name'],0,1)) ?>
                                </div>
                                <div class="user-cell-info">
                                    <span class="uname"><?= e($u['first_name'].' '.$u['last_name']) ?>
                                        <?php if ($u['user_id'] === $admin_id): ?><span style="font-size:0.7rem;color:#a0aec0;font-weight:400;"> (you)</span><?php endif; ?>
                                    </span>
                                    <span class="uemail"><?= e($u['email']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><span class="pill pill-<?= $u['role'] ?>"><?= ucfirst(e($u['role'])) ?></span></td>
                        <td style="font-size:0.8rem;color:#a0aec0;"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                        <td>
                            <div style="display:flex;gap:8px;">
                                <a href="?edit=<?= $u['user_id'] ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="btn-edit">
                                    <i class="fa-solid fa-pen"></i> Edit
                                </a>
                                <?php if ($u['user_id'] !== $admin_id): ?>
                                <form method="POST" onsubmit="return confirm('Permanently delete this user?');">
                                    <input type="hidden" name="delete_user_id" value="<?= $u['user_id'] ?>">
                                    <button type="submit" class="btn-danger"><i class="fa-solid fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?page=<?= $page-1 ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $role_filter !== 'all' ? '&role='.$role_filter : '' ?>"><i class="fa-solid fa-chevron-left"></i></a>
                <?php endif; ?>
                <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current"><?= $i ?></span>
                <?php else: ?>
                    <a href="?page=<?= $i ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $role_filter !== 'all' ? '&role='.$role_filter : '' ?>"><?= $i ?></a>
                <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page+1 ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $role_filter !== 'all' ? '&role='.$role_filter : '' ?>"><i class="fa-solid fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- CREATE MODAL -->
<div class="modal-overlay <?= $show_create && !$edit_user ? 'open' : '' ?>" id="createModal">
    <div class="modal">
        <h2>Add New User</h2>
        <p class="sub">Create a new user account with the selected role.</p>
        <form method="POST">
            <div class="form-grid">
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" name="first_name" placeholder="Juan" required value="<?= isset($_POST['create_user']) ? e($_POST['first_name'] ?? '') : '' ?>">
                </div>
                <div class="form-group">
                    <label>Middle Name </label>
                    <input type="text" name="middle_name" placeholder="Juan" value="<?= isset($_POST['create_user']) ? e($_POST['middle_name'] ?? '') : '' ?>">
                </div>
                <div class="form-group">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" placeholder="Dela Cruz" required value="<?= isset($_POST['create_user']) ? e($_POST['last_name'] ?? '') : '' ?>">
                </div>
                <div class="form-group full">
                    <label>Email Address *</label>
                    <input type="email" name="email" placeholder="user@bayanihan.org" required value="<?= isset($_POST['create_user']) ? e($_POST['email'] ?? '') : '' ?>">
                </div>
                <div class="form-group">
                    <label>Role *</label>
                    <select name="role">
                        <option value="participant">Participant</option>
                        <option value="staff">Staff</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Password * (min 8 chars)</label>
                    <input type="password" name="password" placeholder="••••••••" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" name="create_user" class="btn-primary"><i class="fa-solid fa-user-plus"></i> Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT MODAL -->
<?php if ($edit_user): ?>
<div class="modal-overlay open" id="editModal">
    <div class="modal">
        <h2>Edit User</h2>
        <p class="sub">Updating account for <?= e($edit_user['first_name'].' '.$edit_user['last_name']) ?>.</p>
        <form method="POST">
            <input type="hidden" name="user_id" value="<?= $edit_user['user_id'] ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" name="first_name" value="<?= e($edit_user['first_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" value="<?= e($edit_user['last_name']) ?>" required>
                </div>
                <div class="form-group full">
                    <label>Email Address *</label>
                    <input type="email" name="email" value="<?= e($edit_user['email']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Role *</label>
                    <select name="role" <?= $edit_user['user_id'] === $admin_id ? 'disabled' : '' ?>>
                        <?php foreach (['participant','staff','admin'] as $r): ?>
                        <option value="<?= $r ?>" <?= $edit_user['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($edit_user['user_id'] === $admin_id): ?>
                        <input type="hidden" name="role" value="admin">
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>New Password (leave blank to keep)</label>
                    <input type="password" name="password" placeholder="••••••••">
                </div>
            </div>
            <div class="modal-footer">
                <a href="admin_user_management.php<?= $search ? '?search='.urlencode($search) : '' ?>" class="btn-secondary">Cancel</a>
                <button type="submit" name="edit_user" class="btn-primary"><i class="fa-solid fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) { if (e.target === el) el.classList.remove('open'); });
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
    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function(){ form.submit(); }, 500); });
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