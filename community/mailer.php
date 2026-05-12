<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'you@gmail.com';        // ← Replace with your Gmail
    $mail->Password   = 'mptyvgfmzznuuoie';     // ← App Password (no spaces)
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // Recipients
    $mail->setFrom('jessaf805@gmail.com', 'PHPMailer');           // ← Replace with your Gmail
    $mail->addAddress('recipient@example.com', 'Recipient Name'); // ← Replace with recipient's email
    $mail->addReplyTo('jessaf805@gmail.com', 'PHPMailer');        // ← Replace with your Gmail

    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Subject Here';
    $mail->Body    = '<b>HTML email body here</b>';
    $mail->AltBody = 'Plain text fallback';

    $mail->send();
    echo 'Message sent successfully!';

} catch (Exception $e) {
    echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
}   