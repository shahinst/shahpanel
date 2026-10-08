<?php

return [
    'page_title' => 'Event webhooks',
    'intro' => 'Every account event is sent to this address as a POST with a JSON body. The X-ShahPanel-Signature header is the HMAC-SHA256 of the body made with the secret, so the receiver can tell the request came from this panel; X-ShahPanel-Delivery is a unique id per event for dropping repeats. A receiver that does not answer 2xx gets the event again, up to six times at growing intervals.',
    'url' => 'Receiver address (https only)',
    'events' => 'Events to send',
    'event_account_created' => 'account created',
    'event_account_renewed' => 'renewed (expiry moved later)',
    'event_account_expired' => 'expired',
    'event_account_status_changed' => 'any other status change (disabled, out of data, active again)',
    'event_account_deleted' => 'account deleted',
    'secret' => 'Signing secret',
    'secret_hint' => 'Keep this secret on the receiver and check the signature of every request with it.',
    'new_secret' => 'Make a new secret (the old one stops working)',
    'test' => 'Send a test request',
    'test_ok' => 'The test request arrived (HTTP :status).',
    'test_failed' => 'The test request did not arrive (HTTP :status). Check the address and that the receiver is reachable.',
    'no_url' => 'Save a receiver address first.',
    'log' => 'Latest deliveries',
];
