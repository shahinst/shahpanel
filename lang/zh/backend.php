<?php

/*
 * 由 PHP（控制器、控制台命令、枚举、异常）生成的字符串，
 * 而非来自 Blade 视图。键为扁平结构，并按功能区加前缀。
 */
return [

    // 自动化 —— 即将到期账号的阈值
    'automation_days_min' => '至少 1 天。',
    'automation_days_max' => '最多 7 天。',
    'automation_volume_min' => '至少 100 MB。',
    'automation_volume_max' => '最多 5120 MB（5 GB）。',
    'automation_expiring_saved' => '即将到期账号的阈值已保存。',

    // 隧道负载均衡模式
    'balancing_mode_pcc' => 'PCC（按连接）',
    'balancing_mode_ecmp' => 'ECMP（按权重）',
    'balancing_mode_range_split' => '按 IP 段划分（跨地区）',

    // 通知接收对象（「销售商」对象复用 broadcasts.audience_sellers）
    'broadcast_audience_agents' => '代理商',
    'broadcast_audience_agent_sellers' => '该代理商的销售商',

    // 维护页面中显示的 cron 说明
    'cron_schedule_label' => 'Laravel 计划任务运行',
    'cron_schedule_description' => '必需 —— 每分钟一次；下面所有任务都由该命令驱动。',
    'cron_queue_label' => '队列处理（queue worker）',
    'cron_queue_description' => '新版隧道系统所必需 —— 每分钟处理一次数据库队列（apply / reconcile / metrics），并在 55 秒后或队列清空后退出。',
    'cron_sync_usage_label' => 'VPN 用量同步',
    'cron_sync_usage_description' => '从服务器读取用量写入数据库；流量用尽后账号会被停用。',
    'cron_check_expiry_label' => '账号到期检查',
    'cron_check_expiry_description' => '依据数据库中的 expiry_at 停用已过期的账号。',
    'cron_alerts_label' => '告警（邮件/通知）',
    'cron_alerts_description' => '临近到期、流量已用 90%、同步错误。',
    'cron_backup_label' => '数据库备份',
    'cron_backup_description' => '每晚 02:00。',
    'cron_daily_report_label' => '每日报表',
    'cron_daily_report_description' => '每天零点汇总当日统计数据。',
    'cron_auto_close_tickets_label' => '自动关闭工单',
    'cron_auto_close_tickets_description' => '每天一次。',

    // 货币名称（IRT 复用 packages.toman）
    'currency_try' => '土耳其里拉',
    'currency_usd' => '美元',
    'currency_eur' => '欧元',

    // 实名认证
    'kyc_package_not_required' => '该套餐不需要实名认证。',
    'kyc_status_draft' => '待认证',
    'kyc_status_verified' => '已认证',
    'kyc_status_locked' => '已锁定（尝试次数过多）',
    'kyc_status_reset_requested' => '已申请重置',
    'kyc_status_used' => '已用于某个账号',

    // 模块
    'module_file_attribute' => '模块文件',
    'module_zip_only' => '只允许扩展名为 .zip 的文件。',
    'module_install_failed' => '模块安装失败：:message',
    'module_installed' => '模块「:name」已上传并安装。启用后即可使用。',
    'module_activate_failed' => '模块启用失败：:message',
    'module_activated' => '模块「:name」已启用。',
    'module_deactivated' => '模块「:name」已停用。',
    'module_deleted' => '模块「:name」已彻底删除。',

    // 「面板尚未安装」页面
    'not_installed_title' => '尚未安装',
    'not_installed_heading' => '面板尚未安装',
    'not_installed_ssh_only' => '安装只能通过 SSH 完成：',
    'not_installed_manual_hint' => '如果您是手动部署代码的，请在 :command 之后运行以下命令：',

    // 由计划任务写入的面板通知
    'notify_account_expired_title' => '账号到期',
    'notify_account_expired_body' => '账号 :username 已过期。',
    'notify_expiry_reminder_title' => '到期提醒',
    'notify_expiry_reminder_body' => '账号 :username 将于 :date 到期。',
    'notify_quota_warning_title' => '流量告警',
    'notify_quota_warning_body' => '账号 :username 已使用超过 90% 的流量。',
    'notify_server_sync_error_title' => '服务器同步错误',
    'notify_server_sync_error_body' => '服务器 :server —— :errors 个错误',

    // 套餐批量定价
    'pricing_packages_required' => '请至少选择一个套餐。',
    'pricing_percent_required' => '请输入百分比。',
    'pricing_no_price_changed' => '没有价格被修改（所选套餐没有有效价格）。',
    'pricing_prices_updated' => '已更新 :packages 个套餐的价格（:durations 条价格记录）。',
    'pricing_no_duration_changed' => '没有时长被修改（这些时长要么已设置，要么没有 1 个月的价格）。',
    'pricing_durations_generated' => '已为 :packages 个套餐计算、保存并启用时长定价（:durations 个时长）。',

    // 报表 —— KPI 卡片
    'report_revenue_total_admin' => '总收入（账单）',
    'report_revenue_total_agent' => '网络流水',
    'report_revenue_total_seller' => '您的采购总额',
    'report_revenue_hint' => '新购：:new | 续费：:renew',
    'report_new_accounts' => '新增账号',
    'report_new_accounts_hint' => '本期续费 :count 次',
    'report_refunds' => '退款',
    'report_admin_revenue' => '管理员收入',
    'report_admin_revenue_hint' => '代理商佣金：:amount',
    'report_new_agents' => '新增代理商',
    'report_new_sellers' => '新增销售商',
    'report_new_clients' => '新增客户',
    'report_agent_profit' => '您的佣金（本期）',
    'report_my_wallet' => '您的钱包余额',

    // 代理商设定的销售商价格
    'reseller_pricing_not_allowed' => '管理员尚未为您开启销售商定价权限。',
    'reseller_pricing_saved' => '销售商价格已保存。',

    // 会计修复
    'seller_profit_clawback_description' => '错误记录的销售佣金更正 —— :marker',

    // 服务器连接测试 —— 详情标签
    'server_detail_panel_url' => '面板 URL',
    'server_detail_api_url' => 'API URL',
    'server_detail_api_prefix' => 'API 路径',
    'server_detail_admin_username' => '面板用户',
    'server_detail_panel_version' => '面板版本',
    'server_detail_inbound_count' => 'inbound 数量',
    'server_detail_group_count' => '分组数量（存储在服务器上）',
    'server_detail_squad_count' => 'squad 数量',
    'server_detail_node_count' => '节点数量',
    'server_detail_groups_synced_at' => '最后一次分组同步',
    'server_detail_user_count' => '用户数量（抽样）',
    'server_detail_error' => '错误',

    // 服务器操作日志行
    'server_log_error' => '错误：:message',
    'server_log_status' => '状态：:status',
    'server_log_accounts_synced' => '已同步账号：:count',
    'server_log_errors_count' => '错误：:count',
    'server_log_permissions_allowed' => '已授予的权限：:list',
    'server_log_permissions_denied' => '受限的权限：:list',
    'server_log_tried_urls' => '已尝试的 URL：:list',
    'server_log_warnings' => '警告：:list',
    'server_log_group_entry' => '分组：#:id —— :name',
    'server_log_stored_groups' => '服务器上存储的分组：:count —— :at',
    'server_log_full_log' => '完整日志：:file',

    // 钱包
    'wallet_insufficient_balance' => '您的钱包余额不足。请在「充值申请」菜单中增加余额。',

];
