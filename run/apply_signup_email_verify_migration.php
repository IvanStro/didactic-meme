<?php
declare(strict_types=1);
/**
 * Міграція таблиці signup_email_verifications.
 * php run/apply_signup_email_verify_migration.php
 */
$root = dirname(__DIR__);
require_once $root . '/engine_v2/bootstrap.php';
require_once $root . '/www/api/_email_verify.php';

$db = db();
email_verify_ensure_table($db);
echo "signup_email_verifications OK\n";
