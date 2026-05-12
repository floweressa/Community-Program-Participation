<?php
// staff_auth.php — Shared helper functions ONLY.
// Authentication is now handled individually on each staff page.
// This file no longer outputs any HTML or calls session_start().

require_once 'database.php';

/**
 * Safely HTML-escape a string.
 */
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Returns a time-based greeting string.
 */
function timeGreeting(): string {
    $h = (int) date('G');
    if ($h < 12) return 'Good morning';
    if ($h < 17) return 'Good afternoon';
    return 'Good evening';
}