
<?php
// logout.php — shared for all roles
session_start();
$role = $_SESSION['role'] ?? 'participant';
session_unset();
session_destroy();

if ($role === 'staff') {
    header('Location: staff_login.php');
} elseif ($role === 'admin') {
    header('Location: admin_login.php');
} else {
    header('Location: participant_login.php');
}
exit();