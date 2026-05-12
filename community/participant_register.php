<?php
// participant_register.php
session_start();
require_once 'database.php';

if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'participant') {
    header('Location: participant_dashboard.php');
    exit();
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

// ── Send OTP email ─────────────────────────────────────────────────────────────
function sendOTPEmail(string $toEmail, string $toName, string $otp): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'jessaf805@gmail.com';   // <- your Gmail
        $mail->Password   = 'mptyvgfmzznuuoie';      // <- your App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('jessaf805@gmail.com', 'Bayanihan');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = 'Your Bayanihan Verification Code';
        $mail->Body    = "
        <div style='font-family:Segoe UI,Arial,sans-serif;max-width:480px;margin:auto;'>
            <div style='background:#334e5e;padding:32px;border-radius:16px 16px 0 0;text-align:center;'>
                <h1 style='color:#fff;margin:0;font-size:1.5rem;letter-spacing:1px;'>BAYANIHAN</h1>
            </div>
            <div style='background:#fff;padding:36px;border-radius:0 0 16px 16px;border:1px solid #e2e8f0;border-top:none;text-align:center;'>
                <p style='color:#718096;font-size:15px;margin-bottom:8px;'>Hello, <strong>{$toName}</strong>!</p>
                <p style='color:#4a5568;font-size:15px;line-height:1.6;margin-bottom:28px;'>
                    Use the code below to verify your email address. It expires in <strong>10 minutes</strong>.
                </p>
                <div style='background:#f7fafc;border:2px dashed #334e5e;border-radius:12px;padding:24px;display:inline-block;margin-bottom:28px;'>
                    <span style='font-size:2.8rem;font-weight:800;color:#334e5e;letter-spacing:12px;'>{$otp}</span>
                </div>
                <p style='color:#a0aec0;font-size:12px;'>If you did not request this, you can safely ignore this email.</p>
            </div>
        </div>";
        $mail->AltBody = "Hello {$toName},\n\nYour Bayanihan verification code is: {$otp}\n\nIt expires in 10 minutes.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer error: ' . $mail->ErrorInfo);
        return false;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name  = trim($_POST['first_name']  ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name']   ?? '');
    $suffix      = trim($_POST['suffix']      ?? '');
    $email       = trim($_POST['email']       ?? '');
    $password    = $_POST['password']         ?? '';
    $confirm     = $_POST['confirm_password'] ?? '';
    $terms       = isset($_POST['terms']);

    // Validation
    if (!$first_name || !$last_name || !$email || !$password || !$confirm) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!$terms) {
        $error = 'You must agree to the Terms of Service.';
    } else {
        $db = getDB();

        // Check for duplicate email
        $check = $db->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
        $check->bind_param('s', $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = 'An account with that email already exists.';
        } else {
            $hashed     = password_hash($password, PASSWORD_BCRYPT);
            $otp        = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $otp_hash   = hash('sha256', $otp);
            $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            // Insert user as unverified with OTP token
            $ins = $db->prepare(
                "INSERT INTO users (first_name, middle_name, last_name, email, password, role,
                                    is_verified, verification_token, token_expires_at)
                 VALUES (?, ?, ?, ?, ?, 'participant', 0, ?, ?)"
            );
            $ins->bind_param('sssssss',
                $first_name, $middle_name, $last_name,
                $email, $hashed, $otp_hash, $expires_at
            );

            if ($ins->execute()) {
                $new_user_id = $db->insert_id;

                // Create participant profile row
                $ins2 = $db->prepare("INSERT INTO participants (user_id) VALUES (?)");
                $ins2->bind_param('i', $new_user_id);
                $ins2->execute();
                $ins2->close();
                $ins->close();

                // Send OTP email
                if (sendOTPEmail($email, $first_name, $otp)) {
                    $_SESSION['pending_verification_email'] = $email;
                    $_SESSION['pending_verification_name']  = $first_name;
                    header('Location: verify_email.php');
                    exit();
                } else {
                    $error = 'Account created but we could not send the verification email. Please contact support.';
                }
            } else {
                $error = 'Registration failed. Please try again.';
                $ins->close();
            }
        }
        $check->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - Register</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        body { background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .container { min-height: 650px; width: 100%; max-width: 1100px; margin: 0 auto; display: flex; padding: 2rem; gap: 4rem; align-items: center; }
        .left-content { flex: 1; padding-top: 10px; }
        .logo { font-weight: 700; color: #334e5e; font-size: 1.2rem; margin-bottom: 2.5rem; letter-spacing: 0.5px; }
        .left-content h1 { font-size: 3.5rem; color: #1a202c; line-height: 1.1; margin-bottom: 1.5rem; }
        .left-content h1 span { color: #334e5e; }
        .left-content p { font-size: 1.1rem; color: #718096; max-width: 450px; line-height: 1.6; }
        .right-content { flex: 0 1 600px; display: flex; flex-direction: column; align-items: center; }
        .login-card { background: white; padding: 3rem; border-radius: 30px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.05); width: 100%; }
        .login-card h2 { font-size: 1.8rem; color: #1a202c; margin-bottom: 0.5rem; text-align: center; }
        .subtitle { color: #718096; font-size: 0.95rem; margin-bottom: 2rem; text-align: center; }
        .form-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 1rem; }
        .input-group { margin-bottom: 0; }
        .span-3 { grid-column: span 3; } .span-2 { grid-column: span 2; } .span-4 { grid-column: span 4; } .full-width { grid-column: span 6; }
        label { display: block; font-size: 0.85rem; font-weight: 600; color: #4a5568; margin-bottom: 8px; }
        input[type="email"], input[type="password"], input[type="text"] { width: 100%; padding: 13px; background: #e9eff2; border: none; border-radius: 8px; font-size: 0.95rem; outline: none; transition: 0.2s; }
        input:focus { box-shadow: 0 0 0 2px #334e5e; background: #fff; }
        .checkbox-group { display: flex; align-items: flex-start; gap: 10px; margin: 1.5rem 0; }
        .checkbox-group input[type="checkbox"] { width: 18px; height: 18px; min-width: 18px; accent-color: #334e5e; cursor: pointer; }
        .terms-text { font-size: 0.85rem; color: #718096; line-height: 1.4; }
        .btn-signin { width: 100%; padding: 16px; background: #334e5e; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        .btn-signin:hover { background: #2a3f4d; }
        .register-link { text-align: center; margin-top: 2rem; font-size: 0.9rem; color: #718096; }
        .register-link a { color: #334e5e; font-weight: 700; text-decoration: none; }
        .footer-links { margin-top: 2rem; display: flex; gap: 20px; justify-content: center; }
        .footer-links a { text-decoration: none; font-size: 0.7rem; color: #a0aec0; letter-spacing: 1px; }
        .alert-error { background: #fff5f5; border: 1px solid #feb2b2; color: #c53030; padding: 12px 16px; border-radius: 8px; font-size: 0.9rem; margin-bottom: 1.5rem; text-align: center; }
        @media (max-width: 900px) { .container { flex-direction: column; text-align: center; } .form-grid { grid-template-columns: 1fr; } .span-2,.span-3,.span-4,.full-width { grid-column: span 1; } .left-content p { margin: 0 auto; } .right-content { width: 100%; } .checkbox-group { text-align: left; } }
    </style>
</head>
<body>
<div class="container">
    <section class="left-content">
        <div class="logo">Bayanihan</div>
        <h1>Empowering <br><span>participation</span></h1>
        <p>Join a community of active citizens. Create your account to start tracking your impact and accessing local programs.</p>
    </section>

    <section class="right-content">
        <div class="login-card">
            <h2>Create an account</h2>
            <p class="subtitle">Join the Bayanihan today.</p>

            <?php if ($error): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="participant_register.php">
                <div class="form-grid">
                    <div class="input-group span-3">
                        <label>First Name <span style="color:#e53e3e">*</span></label>
                        <input type="text" name="first_name" placeholder="Juan"
                               value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
                    </div>
                    <div class="input-group span-3">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" placeholder="Optional"
                               value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
                    </div>
                    <div class="input-group span-4">
                        <label>Last Name <span style="color:#e53e3e">*</span></label>
                        <input type="text" name="last_name" placeholder="Dela Cruz"
                               value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
                    </div>
                    <div class="input-group span-2">
                        <label>Suffix</label>
                        <input type="text" name="suffix" placeholder="Jr. / III"
                               value="<?= htmlspecialchars($_POST['suffix'] ?? '') ?>">
                    </div>
                    <div class="input-group full-width">
                        <label>Email address <span style="color:#e53e3e">*</span></label>
                        <input type="email" name="email" placeholder="name@example.com"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                    </div>
                    <div class="input-group span-3">
                        <label>Password <span style="color:#e53e3e">*</span></label>
                        <input type="password" name="password" placeholder="••••••••" required>
                    </div>
                    <div class="input-group span-3">
                        <label>Confirm Password <span style="color:#e53e3e">*</span></label>
                        <input type="password" name="confirm_password" placeholder="••••••••" required>
                    </div>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="terms" name="terms" required>
                    <label for="terms" class="terms-text">
                        I agree to the <a href="#" style="color:#334e5e; font-weight:600; text-decoration:none;">Terms of Service</a> and Privacy Policy.
                    </label>
                </div>

                <button type="submit" class="btn-signin">Create Account &amp; Send Code</button>
            </form>

            <div class="register-link">
                Already a member? <a href="participant_login.php">Login</a>
            </div>
        </div>

        <footer class="footer-links">
            <a href="#">PRIVACY</a>
            <a href="#">TERMS</a>
            <a href="#">SUPPORT</a>
        </footer>
    </section>
</div>
</body>
</html>