<?php

/*
 * Строки, формируемые в PHP (контроллеры, консольные команды, перечисления,
 * исключения), а не в Blade-шаблонах. Ключи плоские, с префиксом по разделу.
 */
return [

    // Автоматизация — пороги для истекающих аккаунтов
    'automation_days_min' => 'Не менее 1 дня.',
    'automation_days_max' => 'Не более 7 дней.',
    'automation_volume_min' => 'Не менее 100 МБ.',
    'automation_volume_max' => 'Не более 5120 МБ (5 GB).',
    'automation_expiring_saved' => 'Порог для истекающих аккаунтов сохранён.',

    // Режимы балансировки нагрузки туннеля
    'balancing_mode_pcc' => 'PCC (по соединению)',
    'balancing_mode_ecmp' => 'ECMP (по весу)',
    'balancing_mode_range_split' => 'Разделение по диапазону IP (между локациями)',

    // Аудитории рассылок (аудитория «продавцы» использует broadcasts.audience_sellers)
    'broadcast_audience_agents' => 'Агенты',
    'broadcast_audience_agent_sellers' => 'Продавцы этого агента',

    // Документация по cron на странице обслуживания
    'cron_schedule_label' => 'Запуск планировщика Laravel',
    'cron_schedule_description' => 'Обязательно — раз в минуту; из этой команды запускаются все задания ниже.',
    'cron_queue_label' => 'Обработка очереди (queue worker)',
    'cron_queue_description' => 'Обязательно для новой системы туннелирования — обрабатывает очередь в базе данных каждую минуту (apply / reconcile / metrics) и завершается через 55 секунд или как только очередь опустеет.',
    'cron_sync_usage_label' => 'Синхронизация использования VPN',
    'cron_sync_usage_description' => 'Считывает использование с серверов в базу данных; аккаунт отключается, как только его трафик заканчивается.',
    'cron_check_expiry_label' => 'Проверка срока действия аккаунтов',
    'cron_check_expiry_description' => 'Отключает истёкшие аккаунты на основе поля expiry_at в базе данных.',
    'cron_alerts_label' => 'Оповещения (эл. почта/уведомления)',
    'cron_alerts_description' => 'Приближение окончания срока, использование 90% трафика, ошибки синхронизации.',
    'cron_backup_label' => 'Резервное копирование базы данных',
    'cron_backup_description' => 'Каждую ночь в 02:00.',
    'cron_daily_report_label' => 'Ежедневный отчёт',
    'cron_daily_report_description' => 'Сводит ежедневную статистику в полночь.',
    'cron_auto_close_tickets_label' => 'Автозакрытие тикетов',
    'cron_auto_close_tickets_description' => 'Ежедневно.',

    // Названия валют (IRT использует packages.toman)
    'currency_try' => 'Турецкая лира',
    'currency_usd' => 'Доллар',
    'currency_eur' => 'Евро',

    // Проверка личности
    'kyc_package_not_required' => 'Для этого пакета проверка личности не требуется.',
    'kyc_status_draft' => 'Ожидает проверки',
    'kyc_status_verified' => 'Проверено',
    'kyc_status_locked' => 'Заблокировано (слишком много попыток)',
    'kyc_status_reset_requested' => 'Запрошен сброс',
    'kyc_status_used' => 'Использовано для аккаунта',

    // Модули
    'module_file_attribute' => 'файл модуля',
    'module_zip_only' => 'Допускаются только файлы с расширением .zip.',
    'module_install_failed' => 'Не удалось установить модуль: :message',
    'module_installed' => 'Модуль «:name» загружен и установлен. Включите его, чтобы начать использовать.',
    'module_activate_failed' => 'Не удалось включить модуль: :message',
    'module_activated' => 'Модуль «:name» включён.',
    'module_deactivated' => 'Модуль «:name» отключён.',
    'module_deleted' => 'Модуль «:name» полностью удалён.',

    // Страница «Панель ещё не установлена»
    'not_installed_title' => 'Ещё не установлено',
    'not_installed_heading' => 'Панель ещё не установлена',
    'not_installed_ssh_only' => 'Установка выполняется только через SSH:',
    'not_installed_manual_hint' => 'Если вы установили код вручную, выполните эту команду после :command:',

    // Уведомления панели, создаваемые заданиями по расписанию
    'notify_account_expired_title' => 'Окончание срока действия аккаунта',
    'notify_account_expired_body' => 'Срок действия аккаунта :username истёк.',
    'notify_expiry_reminder_title' => 'Напоминание об окончании срока',
    'notify_expiry_reminder_body' => 'Срок действия аккаунта :username истекает :date.',
    'notify_quota_warning_title' => 'Предупреждение о трафике',
    'notify_quota_warning_body' => 'Аккаунт :username использовал более 90% своего трафика.',
    'notify_server_sync_error_title' => 'Ошибка синхронизации сервера',
    'notify_server_sync_error_body' => 'Сервер :server — ошибок: :errors',

    // Массовое ценообразование пакетов
    'pricing_packages_required' => 'Выберите хотя бы один пакет.',
    'pricing_percent_required' => 'Укажите процент.',
    'pricing_no_price_changed' => 'Ни одна цена не изменена (у выбранных пакетов не было корректной цены).',
    'pricing_prices_updated' => 'Цены обновлены для :packages пакет(ов) (:durations ценовых записей).',
    'pricing_no_duration_changed' => 'Ни один срок не изменён (они либо уже заданы, либо не было цены за 1 месяц).',
    'pricing_durations_generated' => 'Цены по срокам рассчитаны, сохранены и включены для :packages пакет(ов) (:durations сроков).',

    // Отчёты — карточки KPI
    'report_revenue_total_admin' => 'Общий доход (счета)',
    'report_revenue_total_agent' => 'Оборот сети',
    'report_revenue_total_seller' => 'Ваши покупки всего',
    'report_revenue_hint' => 'Новые: :new | Продления: :renew',
    'report_new_accounts' => 'Новые аккаунты',
    'report_new_accounts_hint' => 'Продлений за период: :count',
    'report_refunds' => 'Возвраты',
    'report_admin_revenue' => 'Доход администратора',
    'report_admin_revenue_hint' => 'Комиссия агента: :amount',
    'report_new_agents' => 'Новые агенты',
    'report_new_sellers' => 'Новые продавцы',
    'report_new_clients' => 'Новые клиенты',
    'report_agent_profit' => 'Ваша комиссия (за период)',
    'report_my_wallet' => 'Баланс вашего кошелька',

    // Цены для продавцов, задаваемые агентом
    'reseller_pricing_not_allowed' => 'Администратор не разрешил вам устанавливать цены для продавцов.',
    'reseller_pricing_saved' => 'Цены для продавцов сохранены.',

    // Исправление бухгалтерии
    'seller_profit_clawback_description' => 'Корректировка неверно записанной комиссии с продажи — :marker',

    // Проверка подключения к серверу — подписи полей
    'server_detail_panel_url' => 'URL панели',
    'server_detail_api_url' => 'URL API',
    'server_detail_api_prefix' => 'Путь API',
    'server_detail_admin_username' => 'Пользователь панели',
    'server_detail_panel_version' => 'Версия панели',
    'server_detail_inbound_count' => 'Количество inbound',
    'server_detail_group_count' => 'Количество групп (хранится на сервере)',
    'server_detail_squad_count' => 'Количество squad',
    'server_detail_node_count' => 'Количество узлов',
    'server_detail_groups_synced_at' => 'Последняя синхронизация групп',
    'server_detail_user_count' => 'Количество пользователей (выборка)',
    'server_detail_error' => 'Ошибка',

    // Строки журнала операций с сервером
    'server_log_error' => 'Ошибка: :message',
    'server_log_status' => 'Статус: :status',
    'server_log_accounts_synced' => 'Синхронизировано аккаунтов: :count',
    'server_log_errors_count' => 'Ошибок: :count',
    'server_log_permissions_allowed' => 'Предоставленные права доступа: :list',
    'server_log_permissions_denied' => 'Ограниченные права доступа: :list',
    'server_log_tried_urls' => 'Опробованные URL: :list',
    'server_log_warnings' => 'Предупреждение: :list',
    'server_log_group_entry' => 'Группа: #:id — :name',
    'server_log_stored_groups' => 'Групп сохранено на сервере: :count — :at',
    'server_log_full_log' => 'Полный журнал: :file',

    // Кошелёк
    'wallet_insufficient_balance' => 'На вашем кошельке недостаточно средств. Пополните баланс через меню «Запросы на пополнение».',

];
