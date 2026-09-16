<?php

return [
    'title' => '安全与防火墙',
    'menu' => '安全与防火墙',
    'section' => '登录防火墙',

    'ip_blocked' => '失败尝试次数过多。登录页面对你关闭，还需等待 :minutes 分钟。',

    'stat_active' => '生效中的封禁',
    'stat_in_firewall' => '已进入服务器防火墙',
    'stat_failures' => '登录失败（24 小时）',
    'stat_total' => '记录总数',

    'firewall_state' => '防火墙状态',
    'fw_helper' => '防火墙脚本',
    'fw_ok' => '可用',
    'fw_missing' => '不可用',
    'fw_chain' => 'iptables 链',
    'fw_hooked' => '已挂接',
    'fw_country_ranges' => '已封禁的国家 IP 段',
    'fw_country_note' => '中国 IP 段，在 80 和 443 端口丢弃',
    'fw_policy' => '封禁策略',
    'fw_policy_note' => ':window 分钟内输错 :attempts 次密码，登录页面将关闭 :minutes 分钟。此后仍继续试探的，将升级到服务器防火墙。',

    'by_country' => '按国家',
    'blocked_list' => '已封禁的地址',
    'no_blocks' => '暂无记录。',
    'search_placeholder' => 'IP 或用户名',
    'filter' => '应用',
    'state_active' => '生效中',
    'state_lifted' => '已解除',
    'state_all' => '全部',

    'col_country' => '国家',
    'col_ip' => 'IP',
    'col_reason' => '原因',
    'col_username' => '尝试的用户名',
    'col_attempts' => '尝试次数',
    'col_blocked_at' => '封禁时间',
    'col_expires' => '到期时间',
    'col_state' => '状态',

    'reason_login_bruteforce' => '登录失败',
    'reason_escalated' => '封禁后仍继续试探',
    'reason_manual' => '由管理员封禁',

    'in_firewall' => '服务器防火墙',
    'login_only' => '仅登录页面',
    'never_expires' => '无到期时间',

    'unblock' => '解除封禁',
    'unblock_confirm' => '要解除该地址的封禁吗？',
    'unblocked' => ':ip 已解除封禁。',
    'blocked' => ':ip 已封禁。',
    'block_refused' => '该地址无法封禁（无效或在白名单中）。',

    'manual_block' => '手动封禁',
    'block_now' => '封禁',
    'duration_minutes' => '时长（分钟）',
    'duration_hint' => '填 0 表示在手动解除之前不会到期。',

    'whitelist' => '白名单',
    'whitelist_hint' => '这些地址永远不会被封禁。把你自己的地址放进来，以免输错密码把自己锁在外面。',
    'whitelist_empty' => '白名单为空。',
    'whitelist_added' => ':ip 已加入白名单。',
    'whitelist_removed' => ':ip 已从白名单移除。',
    'note' => '备注',
    'add' => '添加',
    'remove' => '移除',
    'or' => '或',
];
