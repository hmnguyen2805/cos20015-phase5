<?php
// Copy to app\config\mail.php (git-ignored) to send report emails through a real
// SMTP server. Without mail.php, emails are saved as .eml files in storage\outbox.
//
// Gmail example: turn on 2-Step Verification, create an App Password
// (Google Account > Security > App passwords) and put it in 'pass'.
return [
    'transport' => 'smtp',              // 'smtp' or 'outbox'
    'host'      => 'smtp.gmail.com',
    'port'      => 587,
    'secure'    => 'tls',               // 'tls' = STARTTLS on 587, 'ssl' = implicit TLS on 465
    'user'      => 'you@gmail.com',
    'pass'      => 'your-16-letter-app-password',
    'from'      => 'you@gmail.com',     // Gmail requires From = the signed-in account
    'from_name' => 'Clinical Trial Tracker',
];
