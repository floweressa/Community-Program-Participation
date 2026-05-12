<?php
// forgot_password.php
session_start();
require_once 'database.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $db = getDB();
        
        // Check if email exists
        $stmt = $db->prepare("SELECT user_id, first_name FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->bind_result($user_id, $first_name);
        $stmt->fetch();
        $stmt->close();

        if ($user_id) {
            // Generate OTP for password reset
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $otp_hash = hash('sha256', $otp);
            $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            // Update user with reset token
            $upd = $db->prepare("UPDATE users SET verification_token = ?, token_expires_at = ? WHERE user_id = ?");
            $upd->bind_param('ssi', $otp_hash, $expires_at, $user_id);
            $upd->execute();
            $upd->close();

            // Send Email
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'jessaf805@gmail.com'; 
                $mail->Password   = 'mptyvgfmzznuuoie'; 
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom('jessaf805@gmail.com', 'Bayanihan');
                $mail->addAddress($email, $first_name);
                $mail->isHTML(true);
                $mail->Subject = 'Reset Your Bayanihan Password';
                $mail->Body    = "
                <div style='font-family:Segoe UI,Arial,sans-serif;max-width:480px;margin:auto;'>
                    <div style='background:#334e5e;padding:32px;border-radius:16px 16px 0 0;text-align:center;'>
                        <h1 style='color:#fff;margin:0;font-size:1.5rem;letter-spacing:1px;'>BAYANIHAN</h1>
                    </div>
                    <div style='background:#fff;padding:36px;border-radius:0 0 16px 16px;border:1px solid #e2e8f0;border-top:none;text-align:center;'>
                        <p style='color:#718096;font-size:15px;margin-bottom:8px;'>Hello, <strong>{$first_name}</strong>!</p>
                        <p style='color:#4a5568;font-size:15px;line-height:1.6;margin-bottom:28px;'>
                            We received a request to reset your password. Use the code below to proceed.
                        </p>
                        <div style='background:#f7fafc;border:2px dashed #334e5e;border-radius:12px;padding:24px;display:inline-block;margin-bottom:28px;'>
                            <span style='font-size:2.8rem;font-weight:800;color:#334e5e;letter-spacing:12px;'>{$otp}</span>
                        </div>
                        <p style='color:#a0aec0;font-size:12px;'>If you did not request this, please ignore this email.</p>
                    </div>
                </div>";
                
                $mail->send();
                
                // Set session to track who is resetting
                $_SESSION['reset_email'] = $email;
                header('Location: verify_reset_otp.php'); // You would create this next
                exit();
                
            } catch (Exception $e) {
                $error = "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
            }
        } else {
            // For security, don't explicitly say the email doesn't exist. 
            // But here we show a success message to prevent user enumeration.
            $success = true; 
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - Forgot Password</title>
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

        .input-group { margin-bottom: 1.5rem; }
        label { display: block; font-size: 0.85rem; font-weight: 600; color: #4a5568; margin-bottom: 8px; }
        input[type="email"] { width: 100%; padding: 13px; background: #e9eff2; border: none; border-radius: 8px; font-size: 0.95rem; outline: none; transition: 0.2s; }
        input:focus { box-shadow: 0 0 0 2px #334e5e; background: #fff; }

        .btn-primary { width: 100%; padding: 16px; background: #334e5e; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        .btn-primary:hover { background: #2a3f4d; }

        .alert-error { background: #fff5f5; border: 1px solid #feb2b2; color: #c53030; padding: 12px 16px; border-radius: 8px; font-size: 0.9rem; margin-bottom: 1.5rem; text-align: center; }
        .alert-success { background: #f0fff4; border: 1px solid #9ae6b4; color: #276749; padding: 12px 16px; border-radius: 8px; font-size: 0.9rem; margin-bottom: 1.5rem; text-align: center; }
        
        .back-to-login { text-align: center; margin-top: 2rem; font-size: 0.9rem; color: #718096; }
        .back-to-login a { color: #334e5e; font-weight: 700; text-decoration: none; }

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
        <h1>Recover your <br><span>account</span></h1>
        <p>Don't worry, it happens to the best of us. Enter your email and we'll help you get back into your account.</p>
    </section>

    <section class="right-content">
        <div class="card">
            <h2>Forgot Password?</h2>
            <p class="subtitle">Enter your email address to receive a verification code.</p>

            <?php if ($error): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert-success">
                    If that email is in our system, we have sent a reset code. Please check your inbox.
                </div>
            <?php endif; ?>

            <form method="POST" action="forgot_password.php">
                <div class="input-group">
                    <label>Email address</label>
                    <input type="email" name="email" placeholder="name@example.com" required>
                </div>
                <button type="submit" class="btn-primary">Send Reset Code</button>
            </form>

            <div class="back-to-login">
                Remember your password? <a href="participant_login.php">Login</a>
            </div>
        </div>
    </section>
</div>

</body>
</html>