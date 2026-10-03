<?php

namespace App\Services\Sms;

use App\Models\Account;
use App\Support\SmsSettings;

/**
 * Sends through whichever provider the admin picked. sms.ir sends the account
 * login text through its Verify template; IdehPayam sends it as a plain
 * message from the configured line.
 */
final class SmsGateway
{
    public function __construct(protected SmsIrService $smsIr) {}

    public function provider(): string
    {
        return SmsSettings::provider();
    }

    /**
     * @return array{messageId: int|string|null, cost: float|null}
     */
    public function sendAccountLoginInfo(string $mobile, Account $account): array
    {
        if ($this->provider() === SmsSettings::PROVIDER_IDEHPAYAM) {
            return $this->sendText([$mobile], SmsIrService::previewAccountMessage($account));
        }

        return $this->smsIr->sendAccountLoginInfo($mobile, $account);
    }

    /**
     * A plain text message to one or more numbers.
     *
     * @param  list<string>  $mobiles
     * @return array{messageId: int|string|null, cost: float|null}
     */
    public function sendText(array $mobiles, string $message): array
    {
        $mobiles = array_values(array_map([SmsIrService::class, 'normalizeMobile'], $mobiles));

        if ($this->provider() === SmsSettings::PROVIDER_IDEHPAYAM) {
            $result = $this->idehPayam()->send(
                (string) SmsSettings::idehPayamFrom(),
                $mobiles,
                $message,
                SmsSettings::idehPayamType(),
            );

            return ['messageId' => $result['ids'][0] ?? null, 'cost' => null];
        }

        $result = $this->smsIr->sendTest($mobiles[0], $message);

        return ['messageId' => $result['messageIds'][0] ?? null, 'cost' => $result['cost']];
    }

    public function idehPayam(): IdehPayamClient
    {
        $username = SmsSettings::idehPayamUsername();
        $password = SmsSettings::idehPayamPassword();

        if ($username === null || $password === null) {
            throw new SmsApiException(__('sms.idehpayam_credentials_missing'));
        }

        if (SmsSettings::idehPayamFrom() === null) {
            throw new SmsApiException(__('sms.idehpayam_from_missing'));
        }

        return new IdehPayamClient(SmsSettings::idehPayamBaseUrl(), $username, $password);
    }
}
