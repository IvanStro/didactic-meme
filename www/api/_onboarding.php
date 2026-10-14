<?php
declare(strict_types=1);

/** Service state only: no credentials, third-party analytics or trade actions. */
function strim_ob_record(mysqli $db, int $uid, string $field, ?string $value = null): void {
    if ($uid <= 0) return;
    try {
        $s = $db->prepare('INSERT INTO strim_onboarding_state(user_id,first_seen_at,last_seen_at) VALUES(?,NOW(),NOW()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)');
        $s->bind_param('i',$uid); $s->execute(); $s->close();
        if ($field === 'visit') {
            $s = $db->prepare('UPDATE strim_onboarding_state SET last_seen_at=NOW() WHERE user_id=?');
            $s->bind_param('i',$uid);
        } elseif ($field === 'choice' && in_array($value,['explore','help','self','later'],true)) {
            $s = $db->prepare('UPDATE strim_onboarding_state SET choice=?,choice_at=NOW(),last_seen_at=NOW() WHERE user_id=?');
            $s->bind_param('si',$value,$uid);
        } elseif ($field === 'barrier' && in_array($value,['api_how','api_trust','no_binance','question'],true)) {
            $s = $db->prepare('UPDATE strim_onboarding_state SET barrier=?,last_seen_at=NOW() WHERE user_id=?');
            $s->bind_param('si',$value,$uid);
        } elseif ($field === 'help') {
            $s = $db->prepare('UPDATE strim_onboarding_state SET help_requested_at=NOW(),last_seen_at=NOW() WHERE user_id=?');
            $s->bind_param('i',$uid);
        } elseif ($field === 'telegram') {
            $s = $db->prepare('UPDATE strim_onboarding_state SET tg_linked_at=COALESCE(tg_linked_at,NOW()) WHERE user_id=?');
            $s->bind_param('i',$uid);
        } elseif ($field === 'api' && in_array($value,['NO_API','CONNECTED','INVALID','NETWORK_ERROR','API_ERROR'],true)) {
            $s = $db->prepare("UPDATE strim_onboarding_state SET api_status=?,api_checked_at=NOW(),api_verified_at=IF(?='CONNECTED',COALESCE(api_verified_at,NOW()),api_verified_at) WHERE user_id=?");
            $s->bind_param('ssi',$value,$value,$uid);
        } else return;
        $s->execute(); $s->close();
    } catch (Throwable $e) {
        error_log('strim_onboarding_state unavailable; code=' . (int)$e->getCode());
    }
}

function strim_ob_state(mysqli $db, int $uid): array {
    $s=$db->prepare('SELECT choice,barrier,first_seen_at,last_seen_at,help_requested_at,api_status,api_checked_at,api_verified_at,tg_linked_at FROM strim_onboarding_state WHERE user_id=?');
    $s->bind_param('i',$uid);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    return $row ?: [];
}
