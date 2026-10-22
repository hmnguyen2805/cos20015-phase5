<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

// POST only, with CSRF token, so another site cannot log the user out.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}
csrf_check();

if (current_user()) {
    audit('LOGOUT', 'AppUser', (string)current_user()['id']);
}
$_SESSION = [];
session_regenerate_id(true);
flash('info', 'You have been logged out.');
redirect('login.php');
