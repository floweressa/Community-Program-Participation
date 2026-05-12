<?php
// participant_profile.php
session_start();
require_once 'database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'participant') {
    header('Location: participant_login.php');
    exit();
}

$db      = getDB();
$user_id = (int) $_SESSION['user_id'];
$message = '';
$msg_type = '';

// --- Fetch user + participant profile ---
$stmt = $db->prepare(
    "SELECT u.first_name, u.middle_name, u.last_name, u.email,
            p.participant_id, p.age, p.gender, p.address
     FROM users u
     LEFT JOIN participants p ON p.user_id = u.user_id
     WHERE u.user_id = ? LIMIT 1"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

// --- Handle Profile Update ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name  = trim($_POST['first_name']  ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name']   ?? '');
    $email       = trim($_POST['email']       ?? '');
    $age         = (int) ($_POST['age'] ?? 0);
    $gender      = trim($_POST['gender']  ?? '');
    $address     = trim($_POST['address'] ?? '');

    if (!$first_name || !$last_name || !$email) {
        $message  = 'First name, last name, and email are required.';
        $msg_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message  = 'Please enter a valid email address.';
        $msg_type = 'error';
    } else {
        // Check email uniqueness (excluding current user)
        $chk = $db->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1");
        $chk->bind_param('si', $email, $user_id);
        $chk->execute();
        $chk->store_result();

        if ($chk->num_rows > 0) {
            $message  = 'That email is already used by another account.';
            $msg_type = 'error';
        } else {
            // Update users table
            $upd = $db->prepare(
                "UPDATE users SET first_name=?, middle_name=?, last_name=?, email=? WHERE user_id=?"
            );
            $upd->bind_param('ssssi', $first_name, $middle_name, $last_name, $email, $user_id);
            $upd->execute();
            $upd->close();

            // Update participants table
            $participant_id = (int) $profile['participant_id'];
            if ($participant_id) {
                $upd2 = $db->prepare(
                    "UPDATE participants SET age=?, gender=?, address=? WHERE participant_id=?"
                );
                $upd2->bind_param('issi', $age, $gender, $address, $participant_id);
                $upd2->execute();
                $upd2->close();
            }

            // Update session
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name']  = $last_name;

            $message  = 'Profile updated successfully!';
            $msg_type = 'success';

            // Re-fetch
            $stmt = $db->prepare(
                "SELECT u.first_name, u.middle_name, u.last_name, u.email,
                        p.participant_id, p.age, p.gender, p.address
                 FROM users u LEFT JOIN participants p ON p.user_id = u.user_id
                 WHERE u.user_id = ? LIMIT 1"
            );
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $profile = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        $chk->close();
    }
}

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
    <title>Bayanihan - Profile</title>
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
        .header-actions { display: flex; align-items: center; gap: 20px; color: #718096; margin-left: auto;}
        .content-scroll { padding: 40px; overflow-y: auto; flex: 1; }
        .profile-intro { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; }
        .intro-text h1 { font-size: 2rem; color: #1a202c; margin-bottom: 5px; }
        .intro-text p { color: #718096; font-size: 1rem; max-width: 500px; line-height: 1.5; }
        .avatar-container { position: relative; width: 100px; height: 100px; }
        .edit-avatar-btn { position: absolute; bottom: 0; right: 0; background: #334e5e; color: white; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; border: 2px solid white; cursor: pointer; }
        .settings-grid { display: grid; grid-template-columns: 1fr; gap: 30px; }
        .card { background: white; border-radius: 20px; border: 1px solid #e2e8f0; padding: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .card-header { font-size: 1rem; font-weight: 700; color: #334e5e; margin-bottom: 25px; letter-spacing: 0.3px; }
        .form-group { margin-bottom: 20px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; }
        .form-row-names { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 20px; }
        label { display: block; font-size: 0.75rem; font-weight: 700; color: #718096; margin-bottom: 8px; text-transform: uppercase; }
        input, select, textarea { width: 100%; padding: 12px 15px; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 10px; color: #4a5568; font-size: 0.95rem; outline-color: #334e5e; }
        textarea { height: 80px; resize: none; }
        .btn-group { display: flex; justify-content: flex-end; align-items: center; gap: 30px; margin-top: 30px; }
        .btn-discard { color: #718096; font-weight: 600; text-decoration: none; font-size: 0.9rem; }
        .btn-discard:hover { color: #e53e3e; }
        .btn-update { background: #334e5e; color: white; padding: 12px 25px; border-radius: 10px; border: none; font-weight: 600; cursor: pointer; transition: 0.2s; }
        .btn-update:hover { background: #2d3748; }
        .switch { position: relative; display: inline-block; width: 44px; height: 22px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e0; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #38a169; }
        input:checked + .slider:before { transform: translateX(22px); }
        .alert { padding: 12px 20px; border-radius: 10px; font-size: 0.9rem; margin-bottom: 20px; }
        .alert-success { background: #f0fff4; border: 1px solid #9ae6b4; color: #276749; }
        .alert-error { background: #fff5f5; border: 1px solid #feb2b2; color: #c53030; }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }

        .info-grid strong {
            display: block;
            font-size: 0.75rem;
            color: #718096;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .info-grid p {
            font-size: 1rem;
            color: #2d3748;
            font-weight: 500;
        }

        .edit-icon {
            font-size: 0.9rem;
            color: #718096;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: 0.2s;
        }

        .edit-icon:hover {
            background: #f1f5f9;
            color: #334e5e;
        }
        .profile-avatar { width: 100px; height: 100px; border-radius: 50%; background: #334e5e; color: white; display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 700; letter-spacing: 1px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); border: 4px solid white; }
        .header-search-wrap { position:relative; width:350px; }
        .header-search-wrap i { position:absolute; left:15px; top:50%;
            transform:translateY(-50%); color:white; }
        .header-search-wrap input { width:100%; padding:10px 15px 10px 45px;
            background:white; border:none; border-radius:10px;
            outline-color:white; font-size:0.9rem; font-family:inherit; }
    
    </style>
    <script>
        function enableEdit() {
            document.getElementById('viewMode').style.display = 'none';
            document.getElementById('editMode').style.display = 'block';
        }
    </script>
</head>
<body>
<nav class="nav-sidebar">
    <div class="nav-logo">Bayanihan</div>
    <a href="participant_dashboard.php" class="nav-item"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
    <a href="participant_program.php" class="nav-item"><i class="fa-solid fa-shapes"></i> Programs</a>
    <a href="participant_participation.php" class="nav-item"><i class="fa-solid fa-handshake-angle"></i> My Participation</a>
    <a href="participant_attendance.php" class="nav-item"><i class="fa-solid fa-calendar-check"></i> Attendance</a>
    <a href="participant_profile.php" class="nav-item active"><i class="fa-solid fa-circle-user"></i> Profile</a>
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
        </div>
    </header>

    <main class="content-scroll">
        <?php if ($message): ?>
            <div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="profile-intro">
            <div class="intro-text">
                <h1>Account Settings</h1>
                <p>Manage your personal details and communication preferences.</p>
            </div>
            <div class="avatar-container">
                <div class="profile-avatar"><?= strtoupper($initials) ?></div>
                <div class="edit-avatar-btn" title="Edit Picture">
                    <i class="fa-solid fa-camera"></i>
                </div>
            </div>
        </div>

        <div class="settings-grid">
            <section class="card">
                <form method="POST" action="participant_profile.php">
                    <input type="hidden" name="update_profile" value="1">

                    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                        <div><i class="fa-solid fa-id-card-clip"></i> Personal Information</div>
                        <i class="fa-solid fa-pen edit-icon" onclick="enableEdit()" title="Edit"></i>
                    </div>

                    <form method="POST" action="participant_profile.php" id="profileForm">
                    <input type="hidden" name="update_profile" value="1">

                    <!-- VIEW MODE -->
                    <div id="viewMode">

                        <div class="info-grid">
                            <div>
                                <strong>First Name</strong>
                                <p><?= htmlspecialchars($profile['first_name'] ?? '-') ?></p>
                            </div>
                            <div>
                                <strong>Middle Name</strong>
                                <p><?= htmlspecialchars($profile['middle_name'] ?? '-') ?></p>
                            </div>
                            <div>
                                <strong>Last Name</strong>
                                <p><?= htmlspecialchars($profile['last_name'] ?? '-') ?></p>
                            </div>

                            <div>
                                <strong>Age</strong>
                                <p><?= htmlspecialchars($profile['age'] ?? '-') ?></p>
                            </div>
                            <div>
                                <strong>Gender</strong>
                                <p><?= htmlspecialchars($profile['gender'] ?? '-') ?></p>
                            </div>

                            <div>
                                <strong>Email</strong>
                                <p><?= htmlspecialchars($profile['email'] ?? '-') ?></p>
                            </div>

                            <div style="grid-column: span 2;">
                                <strong>Address</strong>
                                <p><?= htmlspecialchars($profile['address'] ?? '-') ?></p>
                            </div>
                        </div>

                    </div>

                    <!-- EDIT MODE -->
                    <div id="editMode" style="display:none;">

                        <div class="form-row-names">
                            <div class="form-group">
                                <label>First Name</label>
                                <input type="text" name="first_name"
                                    value="<?= htmlspecialchars($profile['first_name'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Middle Name</label>
                                <input type="text" name="middle_name"
                                    value="<?= htmlspecialchars($profile['middle_name'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Last Name</label>
                                <input type="text" name="last_name"
                                    value="<?= htmlspecialchars($profile['last_name'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Age</label>
                                <input type="number" name="age"
                                    value="<?= htmlspecialchars($profile['age'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Gender</label>
                                <select name="gender">
                                    <option value="">Select...</option>
                                    <?php foreach (['Male','Female','Non-binary','Prefer not to say'] as $g): ?>
                                    <option value="<?= $g ?>" <?= ($profile['gender'] ?? '') === $g ? 'selected' : '' ?>>
                                        <?= $g ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email"
                                value="<?= htmlspecialchars($profile['email'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Address</label>
                            <textarea name="address"><?= htmlspecialchars($profile['address'] ?? '') ?></textarea>
                        </div>

                        <div class="btn-group">
                            <a href="participant_profile.php" class="btn-discard">Discard Changes</a>
                            <button type="submit" class="btn-update">Update Profile</button>
                        </div>

                    </div>

                    </form>
            </section>
        </div>
    </main>
</div>
</body>
</html>