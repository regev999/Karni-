<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
session_start_once();
$_SESSION = [];
session_destroy();
header('Location: index.php');
