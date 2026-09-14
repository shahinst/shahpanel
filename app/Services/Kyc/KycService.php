<?php

namespace App\Services\Kyc;

use App\Enums\KycProvider;
use App\Enums\KycVerificationStatus;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountKycVerification;
use App\Models\Package;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Support\KycSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class KycService
{
    public function __construct(
        protected ActivityLogService $activityLogService,
    ) {}

    public function client(): ApiIrClient
    {
        $key = KycSettings::apiKey();
        if ($key === null) {
            throw new ApiIrException('کلید API احراز هویت تنظیم نشده است.');
        }

        return new ApiIrClient($key);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        try {
            $client = $this->client();
            $echo = $client->echo('VPNPanel-KYC');
            $ok = (bool) ($echo['success'] ?? false);
            KycSettings::markTestResult($ok);

            return [
                'ok' => $ok,
                'message' => $ok
                    ? ((string) ($echo['message'] ?: __('kyc.test_ok')))
                    : ((string) ($echo['message'] ?? __('kyc.test_fail'))),
            ];
        } catch (ApiIrException $exception) {
            KycSettings::markTestResult(false);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array{
     *     first_name: string,
     *     last_name: string,
     *     national_code: string,
     *     birth_date: string,
     *     mobile: string,
     *     package_id?: int|null,
     *     owner_seller_id?: int|null
     * }  $data
     */
    public function submitDraft(User $actor, array $data, UploadedFile $document): AccountKycVerification
    {
        $this->assertKycReady();

        $nationalCode = IranIdentityValidator::normalizeNationalCode($data['national_code']);
        $mobile = IranIdentityValidator::normalizeMobile($data['mobile']);
        $birthDate = IranIdentityValidator::normalizeJalaliBirthDate($data['birth_date']);

        if (! IranIdentityValidator::isValidNationalCode($nationalCode)) {
            throw ValidationException::withMessages(['national_code' => ['کد ملی نامعتبر است.']]);
        }
        if (! IranIdentityValidator::isValidMobile($mobile)) {
            throw ValidationException::withMessages(['mobile' => [__('kyc.mobile_invalid')]]);
        }
        if ($birthDate === null) {
            throw ValidationException::withMessages(['birth_date' => [__('kyc.birth_date_invalid')]]);
        }

        $path = $this->storeDocument($document);

        $verification = AccountKycVerification::query()->create([
            'initiated_by_user_id' => $actor->id,
            'owner_seller_id' => $data['owner_seller_id'] ?? ($actor->role === UserRole::Seller ? $actor->id : null),
            'package_id' => $data['package_id'] ?? null,
            'status' => KycVerificationStatus::Draft,
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'national_code' => $nationalCode,
            'birth_date' => $birthDate,
            'mobile' => $mobile,
            'document_disk' => (string) config('kyc.document_disk', 'local'),
            'document_path' => $path,
            'document_original_name' => $document->getClientOriginalName(),
            'document_mime' => $document->getMimeType(),
            'document_size' => $document->getSize(),
            'has_document' => true,
            'verify_attempts' => 0,
            'max_verify_attempts' => (int) config('kyc.max_verify_attempts', 2),
        ]);

        $this->activityLogService->log($actor, 'kyc.draft_submitted', $verification, [
            'national_code_masked' => $verification->maskedNationalCode(),
            'has_document' => true,
        ]);

        return $verification;
    }

    public function verify(AccountKycVerification $verification, User $actor): AccountKycVerification
    {
        $this->assertKycReady();
        $this->assertCanManage($verification, $actor);

        if ($verification->account_id !== null || $verification->status === KycVerificationStatus::Used) {
            throw new InvalidArgumentException('این احراز قبلاً برای ساخت اکانت مصرف شده است.');
        }

        if ($verification->isLocked()) {
            throw new InvalidArgumentException(
                'احراز این اکانت قفل شده است. باید ادمین ریست کند (حداکثر ۲ تلاش ناموفق).'
            );
        }

        if ($verification->remainingAttempts() <= 0) {
            $verification->forceFill([
                'status' => KycVerificationStatus::Locked,
                'locked_at' => now(),
                'last_error' => 'تعداد تلاش‌های احراز به پایان رسیده است.',
            ])->save();

            throw new InvalidArgumentException('تعداد تلاش‌های احراز به پایان رسیده است.');
        }

        if (! $verification->has_document || ! $verification->document_path) {
            throw ValidationException::withMessages(['document' => ['آپلود تصویر کارت ملی الزامی است.']]);
        }

        $client = $this->client();
        $results = [];
        $errors = [];
        $answered = false;

        try {
            $shahkar = $client->shahkarMatch(
                (string) $verification->national_code,
                (string) $verification->mobile,
            );
            // api.ir replied (even with success:false) — this is a real, billable round-trip.
            $answered = true;

            $matched = $this->shahkarMatched($shahkar['data'] ?? null);
            $providerMessage = is_string($shahkar['message'] ?? null) ? trim($shahkar['message']) : '';
            $results['shahkar'] = [
                'success' => (bool) ($shahkar['success'] ?? false),
                'matched' => $matched,
                'message' => $shahkar['message'] ?? null,
                'code' => $shahkar['code'] ?? null,
            ];

            if (! ($shahkar['success'] ?? false)) {
                // Surface the genuine upstream reason (credit, service outage, access level)
                // instead of a generic sentence; api.ir answers HTTP 200 with success:false.
                $errors[] = 'استعلام شاهکار انجام نشد: '.($providerMessage !== ''
                    ? $providerMessage
                    : 'api.ir دلیلی برنگرداند (کد: '.(string) ($shahkar['code'] ?? '—').').');
            } elseif (! $matched) {
                $errors[] = __('kyc.shahkar_mismatch').($providerMessage !== '' ? ' ('.$providerMessage.')' : '');
            }
        } catch (ApiIrException $exception) {
            $errors[] = 'خطا در ارتباط با سامانه شاهکار: '.$exception->getMessage();
            $results['shahkar'] = ['error' => $exception->getMessage()];
        }

        if ($answered) {
            $verification->verify_attempts = (int) $verification->verify_attempts + 1;
            $verification->save();
        }

        $this->captureCreditFromApiResults($results);
        $this->refreshCreditQuietly();

        if ($errors === []) {
            $verification->forceFill([
                'status' => KycVerificationStatus::Verified,
                'verified_at' => now(),
                'last_api_result' => $results,
                'last_error' => null,
            ])->save();

            $this->activityLogService->log($actor, 'kyc.verified', $verification, [
                'attempts' => $verification->verify_attempts,
            ]);

            return $verification->fresh();
        }

        $locked = $verification->remainingAttempts() <= 0;
        $verification->forceFill([
            'status' => $locked ? KycVerificationStatus::Locked : KycVerificationStatus::Draft,
            'locked_at' => $locked ? now() : $verification->locked_at,
            'last_api_result' => $results,
            'last_error' => implode(' ', $errors),
        ])->save();

        $this->activityLogService->log($actor, $locked ? 'kyc.locked' : 'kyc.verify_failed', $verification, [
            'attempts' => $verification->verify_attempts,
            'errors' => $errors,
        ]);

        throw new InvalidArgumentException(implode(' ', $errors).(
            $locked
                ? ' احراز قفل شد؛ برای ادامه باید ادمین ریست کند.'
                : ' تلاش باقی‌مانده: '.$verification->remainingAttempts()
        ));
    }

    public function requestReset(AccountKycVerification $verification, User $actor): AccountKycVerification
    {
        $this->assertCanManage($verification, $actor);

        if (! $verification->isLocked() && $verification->status !== KycVerificationStatus::ResetRequested) {
            if ($verification->remainingAttempts() > 0) {
                throw new InvalidArgumentException('هنوز امکان تلاش مجدد وجود دارد؛ نیازی به ریست نیست.');
            }
        }

        $verification->forceFill([
            'status' => KycVerificationStatus::ResetRequested,
            'reset_requested_at' => now(),
        ])->save();

        $this->activityLogService->log($actor, 'kyc.reset_requested', $verification);

        return $verification->fresh();
    }

    public function adminReset(AccountKycVerification $verification, User $admin): AccountKycVerification
    {
        if ($admin->role !== UserRole::Admin) {
            throw new InvalidArgumentException('فقط ادمین می‌تواند احراز را ریست کند.');
        }

        $verification->forceFill([
            'status' => KycVerificationStatus::Draft,
            'verify_attempts' => 0,
            'verified_at' => null,
            'locked_at' => null,
            'reset_requested_at' => null,
            'reset_by_admin_id' => $admin->id,
            'reset_at' => now(),
            'last_error' => null,
        ])->save();

        $this->activityLogService->log($admin, 'kyc.reset_by_admin', $verification);

        return $verification->fresh();
    }

    public function assertPackageRequiresKyc(Package $package): void
    {
        if (! $package->kyc_required) {
            return;
        }

        $this->assertKycReady();
    }

    public function assertVerifiedForCreate(?AccountKycVerification $verification, Package $package, User $actor): void
    {
        if (! $package->kyc_required) {
            return;
        }

        if ($verification === null) {
            throw ValidationException::withMessages([
                'kyc_verification_id' => ['برای این پکیج ابتدا باید احراز هویت انجام شود.'],
            ]);
        }

        $this->assertCanManage($verification, $actor);

        if ($verification->package_id !== null && (int) $verification->package_id !== (int) $package->id) {
            throw ValidationException::withMessages([
                'kyc_verification_id' => ['احراز هویت متعلق به پکیج دیگری است.'],
            ]);
        }

        if (! $verification->isVerified() || $verification->account_id !== null) {
            throw ValidationException::withMessages([
                'kyc_verification_id' => ['احراز هویت معتبر و مصرف‌نشده یافت نشد.'],
            ]);
        }

        if (! $verification->has_document) {
            throw ValidationException::withMessages([
                'kyc_document' => ['مدارک کارت ملی برای این احراز ثبت نشده است.'],
            ]);
        }
    }

    public function attachToAccount(AccountKycVerification $verification, Account $account): void
    {
        DB::transaction(function () use ($verification, $account): void {
            $locked = AccountKycVerification::query()
                ->whereKey($verification->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->account_id !== null || $locked->status !== KycVerificationStatus::Verified) {
                throw ValidationException::withMessages([
                    'kyc_verification_id' => ['احراز هویت قابل مصرف نیست.'],
                ]);
            }

            $locked->forceFill([
                'account_id' => $account->id,
                'package_id' => $locked->package_id ?: $account->package_id,
                'status' => KycVerificationStatus::Used,
            ])->save();
        });
    }

    public function publicPayload(AccountKycVerification $verification, bool $isAdmin = false): array
    {
        $payload = [
            'id' => $verification->id,
            'status' => $verification->status->value,
            'status_label' => $verification->status->label(),
            'first_name' => $verification->first_name,
            'last_name' => $verification->last_name,
            'national_code_masked' => $verification->maskedNationalCode(),
            'birth_date' => $verification->birth_date,
            'mobile_masked' => $verification->maskedMobile(),
            'has_document' => (bool) $verification->has_document,
            'verify_attempts' => (int) $verification->verify_attempts,
            'remaining_attempts' => $verification->remainingAttempts(),
            'max_verify_attempts' => (int) $verification->max_verify_attempts,
            'verified_at' => optional($verification->verified_at)->toIso8601String(),
            'locked' => $verification->isLocked(),
            'last_error' => $verification->last_error,
            'can_verify' => $verification->canVerify(),
            'can_request_reset' => $verification->isLocked() || $verification->remainingAttempts() <= 0,
        ];

        if ($isAdmin) {
            $payload['national_code'] = $verification->national_code;
            $payload['mobile'] = $verification->mobile;
            $payload['document_original_name'] = $verification->document_original_name;
        }

        return $payload;
    }

    public function openDocumentStream(AccountKycVerification $verification): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! $verification->has_document || ! $verification->document_path) {
            abort(404);
        }

        $disk = Storage::disk($verification->document_disk ?: 'local');
        if (! $disk->exists($verification->document_path)) {
            abort(404);
        }

        $mime = $verification->document_mime ?: 'application/octet-stream';
        $name = $verification->document_original_name ?: 'kyc-document';

        return response()->streamDownload(function () use ($disk, $verification): void {
            echo $disk->get($verification->document_path);
        }, $name, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function storeDocument(UploadedFile $document): string
    {
        $disk = (string) config('kyc.document_disk', 'local');
        $directory = trim((string) config('kyc.document_directory', 'kyc-documents'), '/');
        $safeName = Str::uuid()->toString().'.'.$document->getClientOriginalExtension();
        $path = $document->storeAs($directory.'/'.date('Y/m'), $safeName, $disk);

        if (! is_string($path) || $path === '') {
            throw new InvalidArgumentException('ذخیره مدرک کارت ملی ناموفق بود.');
        }

        return $path;
    }

    protected function assertKycReady(): void
    {
        if (! KycSettings::enabled()) {
            throw new InvalidArgumentException('سامانه احراز هویت غیرفعال است.');
        }
        if (! KycSettings::hasApiKey()) {
            throw new InvalidArgumentException('کلید API احراز هویت تنظیم نشده است.');
        }
        if (KycSettings::provider() !== KycProvider::ApiIr) {
            throw new InvalidArgumentException('سامانه احراز انتخاب‌شده پشتیبانی نمی‌شود.');
        }
    }

    protected function assertCanManage(AccountKycVerification $verification, User $actor): void
    {
        if ($actor->role === UserRole::Admin) {
            return;
        }

        if ((int) $verification->initiated_by_user_id === (int) $actor->id) {
            return;
        }

        if ($actor->role === UserRole::Agent
            && $verification->owner_seller_id
            && (int) User::query()->whereKey($verification->owner_seller_id)->value('parent_id') === (int) $actor->id
        ) {
            return;
        }

        throw new InvalidArgumentException('دسترسی به این پرونده احراز مجاز نیست.');
    }

    /**
     * Shahkar answers with a plain boolean on most accounts, but some responses wrap it
     * in an object. Anything we do not positively recognise counts as "not matched".
     */
    protected function shahkarMatched(mixed $data): bool
    {
        if (is_bool($data)) {
            return $data;
        }

        if (is_int($data)) {
            return $data === 1;
        }

        if (is_string($data)) {
            return in_array(strtolower(trim($data)), ['1', 'true', 'yes'], true);
        }

        if (is_array($data)) {
            foreach (['result', 'isMatched', 'matched', 'isValid'] as $key) {
                if (array_key_exists($key, $data)) {
                    return $this->shahkarMatched($data[$key]);
                }
            }
        }

        return false;
    }

    protected function refreshCreditQuietly(): void
    {
        try {
            $credit = $this->client()->creditToman();
            if ($credit !== null) {
                KycSettings::setCreditTomanFromApi($credit);
            }
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * Best-effort: if api.ir embeds remaining credit in response payloads/messages, cache it.
     *
     * @param  array<string, mixed>  $results
     */
    protected function captureCreditFromApiResults(array $results): void
    {
        $candidates = [];
        $walker = function ($node) use (&$walker, &$candidates): void {
            if (is_string($node)) {
                if (preg_match_all('/(?:مانده|موجودی|شارژ|remain(?:ing)?|credit|balance)\D{0,12}([0-9۰-۹,]{3,})/ui', $node, $m)) {
                    foreach ($m[1] as $raw) {
                        $n = (float) str_replace([',', '،', ' '], '', western_digits($raw));
                        if ($n >= 0) {
                            $candidates[] = $n;
                        }
                    }
                }

                return;
            }
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $value) {
                $walker($value);
            }
        };
        $walker($results);

        if ($candidates !== []) {
            KycSettings::setCreditTomanFromApi(max($candidates));
        }
    }
}
