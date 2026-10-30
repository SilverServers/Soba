-- Migration 094: the Shop manager role, inside the security rules editors are under.
--
-- A shop manager ranks above an editor (Auth::ROLE_LEVELS). The security rules
-- that name roles are stored as lists of them, so a site that asked two-step
-- sign-in, or the inactive-account rule, of its editors would leave the new,
-- stronger role out of the rule until somebody noticed. Wherever a stored rule
-- covers editors, it covers shop managers too. A rule that is not stored is
-- read from its default, which already names them, and nothing is written.
UPDATE options
   SET option_value = CONCAT(option_value, ',shop_manager')
 WHERE option_key IN ('security_two_step_roles', 'security_inactive_roles')
   AND FIND_IN_SET('editor', option_value) > 0
   AND FIND_IN_SET('shop_manager', option_value) = 0;
