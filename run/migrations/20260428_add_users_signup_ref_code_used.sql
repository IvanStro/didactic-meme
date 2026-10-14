ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `signup_ref_code_used` varchar(32) DEFAULT NULL AFTER `referred_by_user_id`,
  ADD KEY `idx_signup_ref_code_used` (`signup_ref_code_used`);

UPDATE `users`
SET `signup_ref_code_used` = COALESCE(`signup_ref_code_used`, `ref_code`)
WHERE `signup_ref_code_used` IS NULL;
