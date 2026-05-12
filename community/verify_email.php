<?php
// verify_email.php
session_start();
require_once 'database.php';

if (empty($_SESSION['pending_verification_email'])) {
    header('Location: participant_register.php');
    exit();
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

$pending_email = $_SESSION['pending_verification_email'];
$pending_name  = $_SESSION['pending_verification_name'] ?? 'there';
$masked_email  = preg_replace('/(?<=.{2}).(?=.*@)/u', '*', $pending_email);

$error  = '';
$resent = false;
$success = false;

// ── Resend OTP ────────────────────────────────────────────────────────────────
function resendOTP(string $email, string $name): bool
{
    $db         = getDB();
    $otp        = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otp_hash   = hash('sha256', $otp);
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $upd = $db->prepare(
        "UPDATE users SET verification_token = ?, token_expires_at = ? WHERE email = ? AND is_verified = 0"
    );
    $upd->bind_param('sss', $otp_hash, $expires_at, $email);
    $upd->execute();
    $upd->close();

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
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = 'Your New Bayanihan Verification Code';
        $mail->Body    = "
        <div style='font-family:Segoe UI,Arial,sans-serif;max-width:480px;margin:auto;'>
            <div style='background:#334e5e;padding:32px;border-radius:16px 16px 0 0;text-align:center;'>
                <h1 style='color:#fff;margin:0;font-size:1.5rem;letter-spacing:1px;'>BAYANIHAN</h1>
            </div>
            <div style='background:#fff;padding:36px;border-radius:0 0 16px 16px;border:1px solid #e2e8f0;border-top:none;text-align:center;'>
                <p style='color:#4a5568;font-size:15px;margin-bottom:28px;'>Your new verification code (expires in <strong>10 minutes</strong>):</p>
                <div style='background:#f7fafc;border:2px dashed #334e5e;border-radius:12px;padding:24px;display:inline-block;margin-bottom:28px;'>
                    <span style='font-size:2.8rem;font-weight:800;color:#334e5e;letter-spacing:12px;'>{$otp}</span>
                </div>
            </div>
        </div>";
        $mail->AltBody = "Your new Bayanihan verification code: {$otp} (expires in 10 minutes)";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer resend error: ' . $mail->ErrorInfo);
        return false;
    }
}

// ── Handle resend ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    if (resendOTP($pending_email, $pending_name)) {
        $resent = true;
    } else {
        $error = 'Failed to resend code. Please try again.';
    }
}

// ── Handle OTP submission ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp'])) {
    $digits    = array_map(fn($k) => $_POST[$k] ?? '', ['d1','d2','d3','d4','d5','d6']);
    $input_otp = implode('', $digits);

    if (strlen($input_otp) < 6 || !ctype_digit($input_otp)) {
        $error = 'Please enter all 6 digits.';
    } else {
        $input_hash = hash('sha256', $input_otp);
        $db         = getDB();

        $stmt = $db->prepare(
            "SELECT user_id, token_expires_at FROM users
             WHERE email = ? AND verification_token = ? AND is_verified = 0 LIMIT 1"
        );
        $stmt->bind_param('ss', $pending_email, $input_hash);
        $stmt->execute();
        $stmt->bind_result($user_id, $expires_at);
        $stmt->fetch();
        $stmt->close();

        if (!$user_id) {
            $error = 'Invalid code. Please check and try again.';
        } elseif (strtotime($expires_at) < time()) {
            $error = 'This code has expired. Please request a new one below.';
        } else {
            $upd = $db->prepare(
                "UPDATE users SET is_verified = 1, verification_token = NULL, token_expires_at = NULL WHERE user_id = ?"
            );
            $upd->bind_param('i', $user_id);
            $upd->execute();
            $upd->close();

            unset($_SESSION['pending_verification_email'], $_SESSION['pending_verification_name']);
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
    <title>Bayanihan - Verify Email</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        body { background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        
        /* Consistent Container with Register Page */
        .container { min-height: 650px; width: 100%; max-width: 1100px; margin: 0 auto; display: flex; padding: 2rem; gap: 4rem; align-items: center; }
        .left-content { flex: 1; padding-top: 10px; }
        .logo { font-weight: 700; color: #334e5e; font-size: 1.2rem; margin-bottom: 2.5rem; letter-spacing: 0.5px; }
        .left-content h1 { font-size: 3.5rem; color: #1a202c; line-height: 1.1; margin-bottom: 1.5rem; }
        .left-content h1 span { color: #334e5e; }
        .left-content p { font-size: 1.1rem; color: #718096; max-width: 450px; line-height: 1.6; }
        
        .right-content { flex: 0 1 500px; display: flex; flex-direction: column; align-items: center; }
        
        /* Card Style Matching Register Page */
        .card { background: white; padding: 3rem; border-radius: 30px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.05); width: 100%; text-align: center; }
        h2 { font-size: 1.8rem; color: #1a202c; margin-bottom: 0.5rem; }
        .subtitle { color: #718096; font-size: 0.95rem; margin-bottom: 2rem; line-height: 1.6; }
        .subtitle strong { color: #334e5e; }

        /* OTP Specific Styling */
        .otp-row { display: flex; gap: 10px; justify-content: center; margin-bottom: 1.5rem; }
        .otp-box { width: 54px; height: 62px; border: none; border-radius: 12px; font-size: 1.8rem; font-weight: 800; text-align: center; color: #1a202c; background: #e9eff2; outline: none; transition: 0.2s; }
        .otp-box:focus { background: #fff; box-shadow: 0 0 0 2px #334e5e; }
        .otp-box.error-box { background: #fff5f5; box-shadow: 0 0 0 2px #fc8181; animation: shake 0.3s ease; }
        @keyframes shake { 0%,100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        /* Button Matching Register Page */
        .btn-primary { width: 100%; padding: 16px; background: #334e5e; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.2s; margin-bottom: 1.5rem; }
        .btn-primary:hover { background: #2a3f4d; }

        .resend-row { font-size: 0.9rem; color: #718096; }
        .resend-btn { background: none; border: none; color: #334e5e; font-weight: 700; cursor: pointer; text-decoration: underline; }
        .resend-btn:disabled { color: #a0aec0; cursor: not-allowed; text-decoration: none; }
        
        .alert { padding: 12px 16px; border-radius: 8px; font-size: 0.9rem; margin-bottom: 1.5rem; text-align: center; }
        .alert-error { background: #fff5f5; border: 1px solid #feb2b2; color: #c53030; }
        .alert-info { background: #ebf8ff; border: 1px solid #90cdf4; color: #2b6cb0; }
        
        .back-link { display: block; margin-top: 2rem; color: #a0aec0; font-size: 0.85rem; text-decoration: none; }
        .back-link:hover { color: #718096; }

        @media (max-width: 900px) { 
            .container { flex-direction: column; text-align: center; } 
            .left-content p { margin: 0 auto; }
            .right-content { width: 100%; }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Left Section matching Register Page -->
    <section class="left-content">
        <div class="logo">Bayanihan</div>
        <h1>Almost <br><span>there</span></h1>
        <p>Your security is our priority. We just need to verify your email address to ensure your account remains safe and active.</p>
    </section>

    <!-- Right Section matching Register Page -->
    <section class="right-content">
        <div class="card">
            <?php if ($success): ?>
                <h2>Email Verified!</h2>
                <p class="subtitle">Your account has been successfully verified. You can now log in to Bayanihan.</p>
                <a href="participant_login.php" class="btn-primary" style="display:block; text-decoration:none; margin-top:1rem;">Go to Login</a>

            <?php else: ?>
                <h2>Check your email</h2>
                <p class="subtitle">
                    We sent a 6-digit verification code to<br>
                    <strong><?= htmlspecialchars($masked_email) ?></strong>
                </p>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($resent): ?>
                    <div class="alert alert-info">A new code has been sent to your email.</div>
                <?php endif; ?>

                <form method="POST" action="verify_email.php" id="otpForm">
                    <input type="hidden" name="otp" value="1">
                    <div class="otp-row">
                        <input class="otp-box" type="text" name="d1" maxlength="1" inputmode="numeric" required>
                        <input class="otp-box" type="text" name="d2" maxlength="1" inputmode="numeric" required>
                        <input class="otp-box" type="text" name="d3" maxlength="1" inputmode="numeric" required>
                        <input class="otp-box" type="text" name="d4" maxlength="1" inputmode="numeric" required>
                        <input class="otp-box" type="text" name="d5" maxlength="1" inputmode="numeric" required>
                        <input class="otp-box" type="text" name="d6" maxlength="1" inputmode="numeric" required>
                    </div>
                    <button type="submit" class="btn-primary">Verify Code</button>
                </form>

                <div class="resend-row">
                    Didn't receive it? 
                    <form method="POST" action="verify_email.php" style="display:inline;">
                        <input type="hidden" name="resend" value="1">
                        <button type="submit" class="resend-btn" id="resendBtn">Resend code</button>
                    </form>
                    <span id="timerDisplay"></span>
                </div>

                <a href="participant_register.php" class="back-link">← Back to Register</a>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
const boxes = document.querySelectorAll('.otp-box');
boxes.forEach((box, i) => {
    box.addEventListener('input', e => {
        const val = e.target.value.replace(/\D/g, '');
        e.target.value = val.slice(-1);
        if (val && i < boxes.length - 1) boxes[i + 1].focus();
    });
    box.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !box.value && i > 0) {
            boxes[i - 1].value = '';
            boxes[i - 1].focus();
        }
    });
    box.addEventListener('paste', e => {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
        pasted.split('').forEach((ch, idx) => {
            if (boxes[idx]) { boxes[idx].value = ch; }
        });
        boxes[Math.min(pasted.length, boxes.length - 1)].focus();
    });
});

const resendBtn = document.getElementById('resendBtn');
const timerDisplay = document.getElementById('timerDisplay');

function startCooldown(seconds) {
    if(!resendBtn) return;
    resendBtn.disabled = true;
    let remaining = seconds;
    const tick = () => {
        timerDisplay.innerHTML = remaining > 0 ? `(${remaining}s)` : '';
        if (remaining <= 0) { resendBtn.disabled = false; return; }
        remaining--;
        setTimeout(tick, 1000);
    };
    tick();
}
startCooldown(60);

<?php if ($error): ?>
boxes.forEach(b => { b.classList.add('error-box'); b.value = ''; setTimeout(() => b.classList.remove('error-box'), 400); });
boxes[0].focus();
<?php endif; ?>
</script>
</body>
</html>