<?php
// export.php — Handles all CSV exports and the "Download Everything" ZIP.
// Accessible by both admin and staff roles.

/* ── AUTH ── */
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'staff'])) {
    header('Location: staff_login.php');
    exit();
}

require_once 'database.php';

$role = $_SESSION['role'];          // 'admin' | 'staff'
$type = trim($_GET['type'] ?? '');  // export type requested

// ─────────────────────────────────────────────────────────────
// Types available per role
// admin : users, programs, participants, attendance, logs, all
// staff : programs, participants, attendance, reports, all
// ─────────────────────────────────────────────────────────────
$admin_types = ['users', 'programs', 'participants', 'attendance', 'logs', 'all'];
$staff_types = ['programs', 'participants', 'attendance', 'reports', 'all'];

$allowed = $role === 'admin' ? $admin_types : $staff_types;

if (!in_array($type, $allowed)) {
    http_response_code(400);
    exit('Invalid export type.');
}

$db = getDB();

// ─────────────────────────────────────────────────────────────
// Helper: write one CSV to the output buffer and return the
//         string, or stream directly to a file handle.
// ─────────────────────────────────────────────────────────────

/**
 * Build a CSV string from a header array and a 2-D data array.
 *
 * @param  string[]   $headers  Column names for the first row.
 * @param  array[]    $rows     Rows of scalar values.
 * @return string               Raw CSV text (UTF-8, \r\n line endings).
 */
function build_csv(array $headers, array $rows): string
{
    $buf = fopen('php://temp', 'r+');
    fputcsv($buf, $headers);
    foreach ($rows as $row) {
        fputcsv($buf, array_values($row));
    }
    rewind($buf);
    $csv = stream_get_contents($buf);
    fclose($buf);
    return $csv;
}

/**
 * Stream a single CSV file directly to the browser.
 */
function stream_csv(string $filename, string $csv): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    // UTF-8 BOM so Excel opens it correctly
    echo "\xEF\xBB\xBF" . $csv;
}

// ─────────────────────────────────────────────────────────────
// Data-fetch functions — each returns [headers, rows]
// ─────────────────────────────────────────────────────────────

function fetch_users($db): array
{
    $headers = ['User ID', 'First Name', 'Last Name', 'Email', 'Role', 'Registered'];
    $rows = $db->query(
        "SELECT user_id, first_name, last_name, email, role,
                DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS created_at
         FROM users
         ORDER BY created_at DESC"
    )->fetch_all(MYSQLI_ASSOC);
    return [$headers, $rows];
}

function fetch_programs($db): array
{
    $headers = [
        'Program ID', 'Program Name', 'Description', 'Location',
        'Start Date', 'End Date', 'Status', 'Participant Count', 'Created'
    ];
    $rows = $db->query(
        "SELECT p.program_id, p.program_name, p.description, p.location,
                DATE_FORMAT(p.start_date,'%Y-%m-%d') AS start_date,
                DATE_FORMAT(p.end_date,'%Y-%m-%d')   AS end_date,
                p.status,
                COUNT(pr.participation_id) AS participant_count,
                DATE_FORMAT(p.created_at,'%Y-%m-%d') AS created_at
         FROM programs p
         LEFT JOIN participation pr ON pr.program_id = p.program_id
         GROUP BY p.program_id
         ORDER BY p.created_at DESC"
    )->fetch_all(MYSQLI_ASSOC);
    return [$headers, $rows];
}

function fetch_participants($db): array
{
    $headers = [
        'Participant ID', 'First Name', 'Last Name', 'Email',
        'Program', 'Participation Status', 'Date Joined'
    ];
    $rows = $db->query(
        "SELECT pt.participant_id,
                u.first_name, u.last_name, u.email,
                pg.program_name,
                pr.status AS participation_status,
                DATE_FORMAT(pr.participation_date,'%Y-%m-%d') AS participation_date
         FROM participants pt
         JOIN users u            ON u.user_id        = pt.user_id
         LEFT JOIN participation pr ON pr.participant_id = pt.participant_id
         LEFT JOIN programs pg   ON pg.program_id    = pr.program_id
         ORDER BY pr.participation_date DESC"
    )->fetch_all(MYSQLI_ASSOC);
    return [$headers, $rows];
}

function fetch_attendance($db): array
{
    $headers = [
        'Attendance ID', 'Participant', 'Email', 'Program',
        'Date', 'Status'
    ];
    $rows = $db->query(
        "SELECT a.attendance_id,
                CONCAT(u.first_name,' ',u.last_name) AS participant,
                u.email,
                pg.program_name,
                DATE_FORMAT(a.attendance_date,'%Y-%m-%d') AS attendance_date,
                a.status
         FROM attendance a
         JOIN participation pr ON pr.participation_id = a.participation_id
         JOIN participants pt  ON pt.participant_id   = pr.participant_id
         JOIN users u          ON u.user_id           = pt.user_id
         JOIN programs pg      ON pg.program_id       = pr.program_id
         ORDER BY a.attendance_date DESC"
    )->fetch_all(MYSQLI_ASSOC);
    return [$headers, $rows];
}

function fetch_logs($db): array
{
    // Only called for admin
    $log_check = $db->query("SHOW TABLES LIKE 'audit_logs'");
    if (!$log_check || $log_check->num_rows === 0) {
        return [['Note'], [['audit_logs table does not exist']]];
    }
    $headers = ['Log ID', 'Action', 'Table', 'Performed By', 'Timestamp'];
    $rows = $db->query(
        "SELECT al.log_id, al.action, al.table_name,
                CONCAT(u.first_name,' ',u.last_name) AS performed_by,
                DATE_FORMAT(al.created_at,'%Y-%m-%d %H:%i') AS created_at
         FROM audit_logs al
         LEFT JOIN users u ON u.user_id = al.performed_by
         ORDER BY al.created_at DESC"
    )->fetch_all(MYSQLI_ASSOC);
    return [$headers, $rows];
}

function fetch_reports($db): array
{
    // Summary report — participation counts per program with attendance rates
    $headers = [
        'Program', 'Status', 'Total Enrolled',
        'Total Sessions', 'Present', 'Absent', 'Attendance Rate %'
    ];
    $rows = $db->query(
        "SELECT pg.program_name,
                pg.status,
                COUNT(DISTINCT pr.participation_id)                          AS total_enrolled,
                COUNT(a.attendance_id)                                       AS total_sessions,
                SUM(a.status = 'present')                                    AS present_count,
                SUM(a.status = 'absent')                                     AS absent_count,
                IF(COUNT(a.attendance_id) > 0,
                   ROUND(SUM(a.status='present') / COUNT(a.attendance_id) * 100, 1),
                   0)                                                        AS attendance_rate
         FROM programs pg
         LEFT JOIN participation pr ON pr.program_id       = pg.program_id
         LEFT JOIN attendance a     ON a.participation_id  = pr.participation_id
         GROUP BY pg.program_id, pg.program_name, pg.status
         ORDER BY pg.program_name"
    )->fetch_all(MYSQLI_ASSOC);
    return [$headers, $rows];
}

// ─────────────────────────────────────────────────────────────
// Route: single-file CSV export
// ─────────────────────────────────────────────────────────────

$ts = date('Y-m-d');  // timestamp suffix for filenames

if ($type !== 'all') {
    switch ($type) {
        case 'users':
            [$h, $r] = fetch_users($db);
            stream_csv("users_{$ts}.csv", build_csv($h, $r));
            break;
        case 'programs':
            [$h, $r] = fetch_programs($db);
            stream_csv("programs_{$ts}.csv", build_csv($h, $r));
            break;
        case 'participants':
            [$h, $r] = fetch_participants($db);
            stream_csv("participants_{$ts}.csv", build_csv($h, $r));
            break;
        case 'attendance':
            [$h, $r] = fetch_attendance($db);
            stream_csv("attendance_{$ts}.csv", build_csv($h, $r));
            break;
        case 'logs':
            [$h, $r] = fetch_logs($db);
            stream_csv("audit_logs_{$ts}.csv", build_csv($h, $r));
            break;
        case 'reports':
            [$h, $r] = fetch_reports($db);
            stream_csv("reports_{$ts}.csv", build_csv($h, $r));
            break;
    }
    exit();
}

// ─────────────────────────────────────────────────────────────
// Route: type=all — bundle every available CSV into a ZIP
// Uses PHP's ZipArchive (bundled with PHP ≥ 5.2).
// ─────────────────────────────────────────────────────────────

if (!class_exists('ZipArchive')) {
    // ZipArchive not available — fall back to a plain-text error page
    http_response_code(500);
    exit('ZIP export is unavailable on this server (ZipArchive extension missing).');
}

// Build each CSV in memory
$files = [];

if ($role === 'admin') {
    [$h, $r] = fetch_users($db);       $files["users_{$ts}.csv"]        = build_csv($h, $r);
    [$h, $r] = fetch_programs($db);    $files["programs_{$ts}.csv"]     = build_csv($h, $r);
    [$h, $r] = fetch_participants($db);$files["participants_{$ts}.csv"] = build_csv($h, $r);
    [$h, $r] = fetch_attendance($db);  $files["attendance_{$ts}.csv"]   = build_csv($h, $r);
    [$h, $r] = fetch_logs($db);        $files["audit_logs_{$ts}.csv"]   = build_csv($h, $r);
    [$h, $r] = fetch_reports($db);     $files["reports_{$ts}.csv"]      = build_csv($h, $r);
} else {
    // staff
    [$h, $r] = fetch_programs($db);    $files["programs_{$ts}.csv"]     = build_csv($h, $r);
    [$h, $r] = fetch_participants($db);$files["participants_{$ts}.csv"] = build_csv($h, $r);
    [$h, $r] = fetch_attendance($db);  $files["attendance_{$ts}.csv"]   = build_csv($h, $r);
    [$h, $r] = fetch_reports($db);     $files["reports_{$ts}.csv"]      = build_csv($h, $r);
}

// Write ZipArchive to a temp file, stream it, then clean up
$tmp = tempnam(sys_get_temp_dir(), 'bayanihan_export_');
$zip = new ZipArchive();

if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Could not create ZIP archive.');
}

foreach ($files as $name => $csv) {
    // Add UTF-8 BOM so Excel opens each CSV correctly
    $zip->addFromString($name, "\xEF\xBB\xBF" . $csv);
}

$zip->close();

// Stream the ZIP
$zipname = "bayanihan_export_{$ts}.zip";
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipname . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($tmp);
unlink($tmp);   // delete the temp file
exit();