<?php
declare(strict_types=1);

function admin_audit_log(mysqli $db, int $actorUserId, string $actionType, int $targetUserId, array $payload, string $result): void {
  $ip = '';
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ip = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
  } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
    $ip = (string)$_SERVER['REMOTE_ADDR'];
  }
  $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
  $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($payloadJson)) $payloadJson = '{}';

  $stmt = $db->prepare("
    INSERT INTO admin_action_audit
      (actor_user_id, action_type, target_user_id, payload_json, result_status, ip, ua, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
  ");
  if (!$stmt) return;
  $stmt->bind_param(
    'isissss',
    $actorUserId,
    $actionType,
    $targetUserId,
    $payloadJson,
    $result,
    $ip,
    $ua
  );
  $stmt->execute();
  $stmt->close();
}
