<?php
declare(strict_types=1);

function strim_legal_manifest(): array {
    static $m;
    if ($m === null) {
        $m = json_decode((string)file_get_contents(__DIR__ . '/../legal/manifest.json'), true);
        if (!is_array($m) || empty($m['version']) || empty($m['documents'])) throw new RuntimeException('Legal manifest unavailable');
    }
    return $m;
}
function strim_legal_has_acceptance(mysqli $db, int $uid): bool {
    if ($uid <= 0) return false;
    $version = strim_legal_manifest()['version'];
    $s = $db->prepare('SELECT id FROM legal_acceptances WHERE user_id=? AND document_version=? LIMIT 1');
    $s->bind_param('is', $uid, $version); $s->execute();
    $ok = $s->get_result()->num_rows > 0; $s->close(); return $ok;
}
function strim_legal_record(mysqli $db, int $uid, string $postedVersion, string $context): void {
    $m = strim_legal_manifest();
    if ($uid <= 0 || !hash_equals($m['version'], $postedVersion)) throw new RuntimeException('Legal version changed');
    $documents = json_encode($m['documents'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $context = substr($context, 0, 32);
    // The account is authenticated/email-verified. No additional raw IP or fingerprint is collected.
    $s = $db->prepare('INSERT INTO legal_acceptances (user_id,document_version,documents_json,context,accepted_at) VALUES (?,?,?,?,UTC_TIMESTAMP())');
    $s->bind_param('isss', $uid, $postedVersion, $documents, $context);
    if (!$s->execute()) throw new RuntimeException('Acceptance could not be saved');
    $s->close();
}
function strim_legal_require_json(mysqli $db, int $uid): void {
    if (strim_legal_has_acceptance($db, $uid)) return;
    http_response_code(409); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'legal_acceptance_required','message'=>'Перегляньте й прийміть актуальні умови сервісу перед новою оплатою або запуском.','url'=>'/auth/legal.php'], JSON_UNESCAPED_UNICODE); exit;
}
