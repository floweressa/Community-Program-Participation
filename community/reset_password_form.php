<?php
// reset_password_form.php
session_start();
require_once 'database.php';

// Security: Redirect if OTP hasn't been verified yet
if (empty($_SESSION['reset_email']) || empty($_SESSION['otp_verified'])) {
    header('Location: forgot_password.php');
    exit();
}

$error = '';
$success = false;
$email = $_SESSION['reset_email'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $db = getDB();
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

        // Update the password and clear the verification tokens
        $stmt = $db->prepare(
            "UPDATE users SET password = ?, verification_token = NULL, token_expires_at = NULL 
             WHERE email = ?"
        );
        $stmt->bind_param('ss', $hashed_password, $email);

        if ($stmt->execute()) {
            $success = true;
            // Clear the session so the reset flow cannot be reused
            session_destroy();
        } else {
            $error = 'Something went wrong. Please try again later.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - Reset Password</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        body { background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        
        .container { min-height: 650px; width: 100%; max-width: 1100px; margin: 0 auto; display: flex; padding: 2rem; gap: 4rem; align-items: center; }
        .left-content { flex: 1; padding-top: 10px; }
        .logo { font-weight: 700; color: #334e5e; font-size: 1.2rem; margin-bottom: 2.5rem; letter-spacing: 0.5px; }
        .left-content h1 { font-size: 3.5rem; color: #1a202c; line-height: 1.1; margin-bottom: 1.5rem; }
        .left-content h1 span { color: #334e5e; }
        .left-content p { font-size: 1.1rem; color: #718096; max-width: 450px; line-height: 1.6; }
        
        .right-content { flex: 0 1 500px; display: flex; flex-direction: column; align-items: center; }
        .card { background: white; padding: 3rem; border-radius: 30px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.05); width: 100%; }
        
        h2 { font-size: 1.8rem; color: #1a202c; margin-bottom: 0.5rem; text-align: center; }
        .subtitle { color: #718096; font-size: 0.95rem; margin-bottom: 2rem; text-align: center; line-height: 1.6; }

        .input-group { margin-bottom: 1.2rem; }
        label { display: block; font-size: 0.85rem; font-weight: 600; color: #4a5568; margin-bottom: 8px; }
        input[type="password"] { width: 100%; padding: 13px; background: #e9eff2; border: none; border-radius: 8px; font-size: 0.95rem; outline: none; transition: 0.2s; }
        input:focus { box-shadow: 0 0 0 2px #334e5e; background: #fff; }

        .btn-primary { width: 100%; padding: 16px; background: #334e5e; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.2s; margin-top: 1rem; }
        .btn-primary:hover { background: #2a3f4d; }

        .alert-error { background: #fff5f5; border: 1px solid #feb2b2; color: #c53030; padding: 12px 16px; border-radius: 8px; font-size: 0.9rem; margin-bottom: 1.5rem; text-align: center; }
        .alert-success { background: #f0fff4; border: 1px solid #9ae6b4; color: #276749; padding: 12px 16px; border-radius: 8px; font-size: 0.9rem; margin-bottom: 1.5rem; text-align: center; }
        
        @media (max-width: 900px) { 
            .container { flex-direction: column; text-align: center; } 
            .left-content p { margin: 0 auto; }
            .right-content { width: 100%; }
        }
    </style>
</head>
<body>

<div class="container">
    <section class="left-content">
        <div class="logo">Bayanihan</div>
        <h1>Create new <br><span>password</span></h1>
        <p>Choose a strong password to protect your account. We recommend a mix of letters, numbers, and symbols.</p>
    </section>

    <section class="right-content">
        <div class="card">
            <?php if ($success): ?>
                <h2>Password Reset!</h2>
                <p class="subtitle">Your password has been updated successfully. You can now log in with your new credentials.</p>
                <a href="participant_login.php" class="btn-primary" style="display:block; text-decoration:none; text-align:center;">Back to Login</a>
            <?php else: ?>
                <h2>Set Password</h2>
                <p class="subtitle">Resetting password for <br><strong><?= htmlspecialchars($email) ?></strong></p>

                <?php if ($error): ?>
                    <div class="alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="reset_password_form.php">
                    <div class="input-group">
                        <label>New Password</label>
                        <input type="password" name="password" placeholder="••••••••" required>
                    </div>
                    <div class="input-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" placeholder="••••••••" required>
                    </div>
                    <button type="submit" class="btn-primary">Update Password</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
</div>

</body>
</html>
