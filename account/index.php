<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/functions.php';
redirect(url('account.php'), 301);
