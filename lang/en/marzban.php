<?php

/*
 * Messages for the Marzban-compatible façade (routes/marzban.php).
 *
 * Every one of these ends up in a {"detail": "..."} body, which is what the
 * Mirza and WizWiz bots read. Keep them short and actionable: a reseller sees
 * them raw inside Telegram.
 */

return [
    'ip_not_allowed' => 'This token is not allowed from your IP address.',
    'credentials_required' => 'Username and password are required.',
    'login_failed' => 'Incorrect username or password.',
    'login_role_not_allowed' => 'Only agent and seller accounts can connect a bot.',
    'login_suspended' => 'This account is not active.',
    'login_throttled' => 'Too many failed attempts. Try again in :seconds seconds.',
    'two_factor_not_supported' => 'Two-factor authentication is enabled on this account and the bot protocol cannot carry a code. Use a dedicated account without two-factor authentication for the bot.',
    'rate_limited' => 'Rate limit reached. Try again in :seconds seconds.',

    'unknown_status' => 'Unknown status filter.',
    'username_taken' => 'A user with this username already exists.',
    'inbound_tag_required' => 'No valid inbound tag was sent. Refresh the inbound list from the panel and pick a plan again.',
    'package_not_sellable' => 'This package cannot be sold through a bot: its service has no config link.',
    'package_unavailable' => 'This package is not available for new accounts.',
    'insufficient_balance' => 'Your wallet balance is not enough for this purchase.',
    'create_failed' => 'Creating the user failed. The panel log has the details.',
    'renew_failed' => 'Renewing the user failed. The panel log has the details.',
    'status_failed' => 'Changing the user status failed. The panel log has the details.',
    'delete_failed' => 'Deleting the user failed. The panel log has the details.',
    'user_deleted' => 'User successfully deleted',
];
