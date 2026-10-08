<?php

return [
    'page_title' => 'Server watchdog',
    'intro' => 'Every five minutes the servers, the panel disk, SSL certificate expiry and the IP capacity of AnyConnect servers are checked. Each problem is announced once on the Telegram backup bot (and every six hours until it is fixed), and so is its end.',
    'telegram_missing' => 'The Telegram backup bot is not set up: checks run but no alert is sent. Enter its token and chat id in the server backups section.',
    'run_now' => 'Check now',
    'ran' => 'Checked.',
    'last_run' => 'Last check: :time',
    'watchdog_disk_percent' => 'Alert when free disk space is under (percent)',
    'watchdog_cert_days' => 'Alert when an SSL certificate expires within (days)',
    'watchdog_pool_percent' => 'Alert when AnyConnect IP use is over (percent)',
    'label_server' => 'Server :name',
    'label_disk' => 'Panel disk space',
    'label_cert' => 'SSL certificate :host',
    'label_pool' => 'IP capacity of :name',
    'server_down' => 'The server :name (:host::port) cannot be reached from the panel.',
    'disk_low' => 'The panel disk has :free free of :total (:percent%).',
    'cert_expiring' => 'The SSL certificate of :host expires in :days days (:date).',
    'pool_full' => 'The AnyConnect server :name uses :used of its :size addresses (:percent%); once full, new users cannot connect.',
    'recovered' => 'Fixed: :label',
];
