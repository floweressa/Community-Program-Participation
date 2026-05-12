<?php
// admin_login.php
session_start();
require_once 'database.php';

if (
    isset($_SESSION['user_id']) &&
    isset($_SESSION['role']) &&
    $_SESSION['role'] === 'admin'
) {
    header('Location: admin_dashboard.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']  ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $db = getDB();

        $stmt = $db->prepare(
            "SELECT user_id, first_name, last_name, password, role
             FROM users
             WHERE email = ? AND role = 'admin'
             LIMIT 1"
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']    = $user['user_id'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name']  = $user['last_name'];
            $_SESSION['role']       = $user['role'];

            header('Location: admin_dashboard.php');
            exit();
        } else {
            $error = 'Invalid credentials or insufficient access.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - Admin Login</title>
</head>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
    body { background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    .container { min-height: 650px; width: 100%; max-width: 1100px; margin: 0 auto; display: flex; padding: 2rem; gap: 4rem; align-items: center; }
    .left-content { flex: 1; padding-top: 10px; }
    .logo { font-weight: 700; color: #334e5e; font-size: 1.2rem; margin-bottom: 2.5rem; letter-spacing: 0.5px; }
    .badge { display: inline-block; padding: 4px 12px; background: #334e5e; color: white; border-radius: 20px; font-size: 0.75rem; margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 1px; }
    .left-content h1 { font-size: 3.5rem; color: #1a202c; line-height: 1.1; margin-bottom: 1.5rem; }
    .left-content h1 span { color: #334e5e; }
    .left-content p { font-size: 1.1rem; color: #718096; max-width: 450px; line-height: 1.6; }
    .right-content { flex: 0 1 600px; display: flex; flex-direction: column; align-items: center; }
    .login-card { background: white; padding: 3rem; border-radius: 30px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.05); width: 100%; }
    .login-card h2 { font-size: 1.8rem; color: #1a202c; margin-bottom: 0.5rem; text-align: center; }
    .subtitle { text-align: center; color: #718096; font-size: 0.95rem; margin-bottom: 2rem; }
    .input-group { margin-bottom: 1.5rem; }
    label { display: block; font-size: 0.85rem; font-weight: 600; color: #4a5568; margin-bottom: 8px; }
    input[type="text"], input[type="password"] { width: 100%; padding: 14px; background: #e9eff2; border: none; border-radius: 8px; font-size: 1rem; outline: none; transition: 0.2s; }
    input:focus { box-shadow: 0 0 0 2px #334e5e; background: #fff; }
    .forgot-link { display: block; text-align: right; margin-top: 8px; font-size: 0.75rem; color: #334e5e; text-decoration: none; font-weight: 700; }
    .btn-signin { width: 100%; padding: 16px; background: #334e5e; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.2s; }
    .btn-signin:hover { background: #2a3f4d; }
    .alert-error { background: #fff5f5; border: 1px solid #feb2b2; color: #c53030; padding: 12px 16px; border-radius: 8px; font-size: 0.9rem; margin-bottom: 1.5rem; text-align: center; }
    @media (max-width: 900px) { .container { flex-direction: column; text-align: center; } .left-content p { margin: 0 auto; } }
</style>
<body>
<div class="container">
    <section class="left-content">
        <div class="logo">Bayanihan</div>
        <div class="badge">Admin Portal</div>
        <h1>Manage the <br><span>system</span></h1>
        <p>Access the core administrative dashboard to manage databases, firewall settings, and high-level system configurations.</p>
    </section>

    <section class="right-content">
        <div class="login-card">
            <h2>Admin Login</h2>
            <p class="subtitle">Secure authentication area.</p>

            <?php if ($error): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="admin_login.php">
                <div class="input-group">
                    <label>Administrator Email</label>
                    <input type="text" name="username" placeholder="admin@bayanihan.org"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                </div>
                <div class="input-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="••••••••" required>
                    <a href="#" class="forgot-link">Emergency Reset?</a>
                </div>
                <button type="submit" class="btn-signin">Login</button>
            </form>

            <div style="text-align:center; margin-top:2rem; font-size:0.9rem; color:#718096;">
                Back to <a href="participant_login.php" style="color:#334e5e; font-weight:700; text-decoration:none;">Participant Login</a>
            </div>
        </div>
    </section>
</div>
</body>
</html>