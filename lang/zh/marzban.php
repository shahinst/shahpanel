<?php

/*
 * Marzban 兼容接口的提示文本（routes/marzban.php）。
 *
 * 这些文本都会放进 {"detail": "..."} 响应体，Mirza 与 WizWiz 机器人读取该字段，
 * 代理商会在 Telegram 里直接看到原文。
 */

return [
    'ip_not_allowed' => '该令牌不允许从您的 IP 地址使用。',
    'credentials_required' => '必须提供用户名和密码。',
    'login_failed' => '用户名或密码错误。',
    'login_role_not_allowed' => '只有代理和销售账户可以连接机器人。',
    'login_suspended' => '该账户未启用。',
    'login_throttled' => '失败次数过多，请在 :seconds 秒后重试。',
    'two_factor_not_supported' => '该账户已启用两步验证，而机器人协议无法传递验证码。请为机器人使用未开启两步验证的独立账户。',
    'rate_limited' => '已达到请求上限，请在 :seconds 秒后重试。',
    'ability_missing' => '该令牌缺少此请求所需的权限（:ability）。',

    'unknown_status' => '未知的状态筛选条件。',
    'username_taken' => '同名用户已存在。',
    'inbound_tag_required' => '未收到有效的 inbound 标签。请在面板中刷新 inbound 列表后重新选择套餐。',
    'package_not_sellable' => '该套餐无法通过机器人销售：其服务没有配置链接。',
    'package_unavailable' => '该套餐当前不可用于新建账户。',
    'insufficient_balance' => '您的钱包余额不足以完成本次购买。',
    'create_failed' => '创建用户失败，详细信息见面板日志。',
    'renew_failed' => '续费失败，详细信息见面板日志。',
    'status_failed' => '修改用户状态失败，详细信息见面板日志。',
    'delete_failed' => '删除用户失败，详细信息见面板日志。',
    'user_deleted' => 'User successfully deleted',
];
