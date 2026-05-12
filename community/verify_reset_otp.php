<?php
// verify_reset_otp.php
session_start();
require_once 'database.php';

// Redirect back if no email is in session
if (empty($_SESSION['reset_email'])) {
    header('Location: forgot_password.php');
    exit();
}

$email = $_SESSION['reset_email'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_submit'])) {
    $digits    = array_map(fn($k) => $_POST[$k] ?? '', ['d1','d2','d3','d4','d5','d6']);
    $input_otp = implode('', $digits);

    if (strlen($input_otp) < 6 || !ctype_digit($input_otp)) {
        $error = 'Please enter all 6 digits.';
    } else {
        $input_hash = hash('sha256', $input_otp);
        $db = getDB();

        $stmt = $db->prepare(
            "SELECT user_id, token_expires_at FROM users 
             WHERE email = ? AND verification_token = ? LIMIT 1"
        );
        $stmt->bind_param('ss', $email, $input_hash);
        $stmt->execute();
        $stmt->bind_result($user_id, $expires_at);
        $stmt->fetch();
        $stmt->close();

        if (!$user_id) {
            $error = 'Invalid code. Please check and try again.';
        } elseif (strtotime($expires_at) < time()) {
            $error = 'This code has expired. Please request a new one.';
        } else {
            // Code is valid! Mark as verified in session to allow password change
            $_SESSION['otp_verified'] = true;
            header('Location: reset_password_form.php'); // Redirect to final step
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayanihan - Verify Reset Code</title>
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
        .card { background: white; padding: 3rem; border-radius: 30px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.05); width: 100%; text-align: center; }
        
        h2 { font-size: 1.8rem; color: #1a202c; margin-bottom: 0.5rem; }
        .subtitle { color: #718096; font-size: 0.95rem; margin-bottom: 2rem; line-height: 1.6; }

        /* OTP Input Styling */
        .otp-row { display: flex; gap: 10px; justify-content: center; margin-bottom: 1.5rem; }
        .otp-box { width: 52px; height: 60px; border: none; border-radius: 12px; font-size: 1.8rem; font-weight: 800; text-align: center; color: #1a202c; background: #e9eff2; outline: none; transition: 0.2s; }
        .otp-box:focus { background: #fff; box-shadow: 0 0 0 2px #334e5e; }
        .otp-box.error-box { background: #fff5f5; box-shadow: 0 0 0 2px #fc8181; animation: shake 0.3s ease; }
        
        @keyframes shake { 0%,100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        .btn-primary { width: 100%; padding: 16px; background: #334e5e; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.2s; margin-top: 1rem; }
        .btn-primary:hover { background: #2a3f4d; }

        .alert-error { background: #fff5f5; border: 1px solid #feb2b2; color: #c53030; padding: 12px 16px; border-radius: 8px; font-size: 0.9rem; margin-bottom: 1.5rem; }
        
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
    <section class="left-content">
        <div class="logo">Bayanihan</div>
        <h1>Security <br><span>Verification</span></h1>
        <p>Please enter the 6-digit code we sent to your email to verify your identity and reset your password.</p>
    </section>

    <section class="right-content">
        <div class="card">
            <h2>Enter Code</h2>
            <p class="subtitle">Enter the verification code sent to <br><strong><?= htmlspecialchars($email) ?></strong></p>

            <?php if ($error): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="verify_reset_otp.php" id="otpForm">
                <input type="hidden" name="otp_submit" value="1">
                <div class="otp-row">
                    <input class="otp-box" type="text" name="d1" maxlength="1" inputmode="numeric" required autofocus>
                    <input class="otp-box" type="text" name="d2" maxlength="1" inputmode="numeric" required>
                    <input class="otp-box" type="text" name="d3" maxlength="1" inputmode="numeric" required>
                    <input class="otp-box" type="text" name="d4" maxlength="1" inputmode="numeric" required>
                    <input class="otp-box" type="text" name="d5" maxlength="1" inputmode="numeric" required>
                    <input class="otp-box" type="text" name="d6" maxlength="1" inputmode="numeric" required>
                </div>
                <button type="submit" class="btn-primary">Verify Code</button>
            </form>

            <a href="forgot_password.php" class="back-link">← Use a different email</a>
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
});

<?php if ($error): ?>
boxes.forEach(b => { 
    b.classList.add('error-box'); 
    b.value = ''; 
    setTimeout(() => b.classList.remove('error-box'), 400); 
});
boxes[0].focus();
<?php endif; ?>
</script>
</body>
</html>