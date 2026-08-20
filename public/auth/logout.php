<?php
require_once __DIR__ . '/../../inc/auth.php';
logout();
header('Location: ' . APP_PATH . '/auth/magic-request.php');
