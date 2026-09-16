<?php

return [
    'title' => 'Security and firewall',
    'menu' => 'Security and firewall',
    'section' => 'Login firewall',

    'ip_blocked' => 'Too many failed attempts. The login page is closed to you for another :minutes minute(s).',

    'stat_active' => 'Active blocks',
    'stat_in_firewall' => 'In server firewall',
    'stat_failures' => 'Failed logins (24h)',
    'stat_total' => 'Total records',

    'firewall_state' => 'Firewall state',
    'fw_helper' => 'Firewall helper',
    'fw_ok' => 'Available',
    'fw_missing' => 'Unavailable',
    'fw_chain' => 'iptables chain',
    'fw_hooked' => 'Hooked',
    'fw_country_ranges' => 'Blocked country ranges',
    'fw_country_note' => 'China ranges, dropped on ports 80 and 443',
    'fw_policy' => 'Block policy',
    'fw_policy_note' => ':attempts wrong passwords in :window minutes closes the login page for :minutes minutes. Knocking after that escalates to the server firewall.',

    'by_country' => 'By country',
    'blocked_list' => 'Blocked addresses',
    'no_blocks' => 'Nothing here.',
    'search_placeholder' => 'IP or username',
    'filter' => 'Apply',
    'state_active' => 'Active',
    'state_lifted' => 'Lifted',
    'state_all' => 'All',

    'col_country' => 'Country',
    'col_ip' => 'IP',
    'col_reason' => 'Reason',
    'col_username' => 'Username tried',
    'col_attempts' => 'Attempts',
    'col_blocked_at' => 'Blocked',
    'col_expires' => 'Expires',
    'col_state' => 'State',

    'reason_login_bruteforce' => 'Failed logins',
    'reason_escalated' => 'Kept knocking after the block',
    'reason_manual' => 'Blocked by an admin',

    'in_firewall' => 'Server firewall',
    'login_only' => 'Login page only',
    'never_expires' => 'No expiry',

    'unblock' => 'Unblock',
    'unblock_confirm' => 'Unblock this address?',
    'unblocked' => ':ip unblocked.',
    'blocked' => ':ip blocked.',
    'block_refused' => 'That address cannot be blocked (invalid or whitelisted).',

    'manual_block' => 'Block manually',
    'block_now' => 'Block',
    'duration_minutes' => 'Duration (minutes)',
    'duration_hint' => 'Zero means no expiry until lifted by hand.',

    'whitelist' => 'Whitelist',
    'whitelist_hint' => 'These addresses are never blocked. Put your own here so a fumbled password cannot lock you out.',
    'whitelist_empty' => 'The whitelist is empty.',
    'whitelist_added' => ':ip added to the whitelist.',
    'whitelist_removed' => ':ip removed from the whitelist.',
    'note' => 'Note',
    'add' => 'Add',
    'remove' => 'Remove',
    'or' => 'or',
];
