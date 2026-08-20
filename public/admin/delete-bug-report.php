<?php
require_once __DIR__ . '/../../inc/auth.php';

require_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    db()->prepare('DELETE FROM bug_reports WHERE id=?')->execute([(int) ($_POST['id'] ?? 0)]);
}
header('Location: ' . APP_PATH . '/admin/bug-reports.php');
