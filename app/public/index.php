<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
redirect(current_user() ? 'dashboard.php' : 'login.php');
