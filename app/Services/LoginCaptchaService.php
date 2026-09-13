<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Session-backed login CAPTCHA (lowercase a-z + digits, max 6 chars).
 * Display uses Persian digits for numeric characters only.
 */
final class LoginCaptchaService
{
    private const CHARSET = '0123456789abcdefghijklmnopqrstuvwxyz';

    private const SESSION_KEY = 'login_captcha';

    /**
     * @return array{token: string, display: string, svg: string}
     */
    public function issue(Request $request): array
    {
        $code = $this->generateCode();
        $token = Str::random(40);

        $svg = $this->renderSvg($code);

        $request->session()->put(self::SESSION_KEY, [
            'token' => $token,
            'hash' => $this->hashAnswer($code),
            'svg' => $svg,
            'expires' => now()->addMinutes($this->ttlMinutes())->timestamp,
        ]);

        return [
            'token' => $token,
            'display' => $this->toDisplayString($code),
            'svg' => $svg,
        ];
    }

    public function svgForToken(Request $request, string $token): ?string
    {
        if ($token === '' || strlen($token) !== 40) {
            return null;
        }

        $payload = $request->session()->get(self::SESSION_KEY);

        if (! is_array($payload)) {
            return null;
        }

        if (! hash_equals((string) ($payload['token'] ?? ''), $token)) {
            return null;
        }

        if (now()->timestamp > (int) ($payload['expires'] ?? 0)) {
            return null;
        }

        $svg = (string) ($payload['svg'] ?? '');

        return $svg !== '' ? $svg : null;
    }

    /**
     * @throws ValidationException
     */
    public function assertValid(Request $request, ?string $answer, ?string $token): void
    {
        $this->assertNotRateLimited($request);

        if (! $this->validate($request, $answer, $token)) {
            RateLimiter::hit($this->failLimiterKey($request), $this->failDecaySeconds());

            throw ValidationException::withMessages([
                'captcha' => [__('auth.captcha_invalid')],
            ]);
        }

        RateLimiter::clear($this->failLimiterKey($request));
    }

    public function validate(Request $request, ?string $answer, ?string $token): bool
    {
        $payload = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($payload) || ! is_string($token) || $token === '') {
            return false;
        }

        if (! hash_equals((string) ($payload['token'] ?? ''), $token)) {
            return false;
        }

        if (now()->timestamp > (int) ($payload['expires'] ?? 0)) {
            return false;
        }

        $normalized = $this->normalizeAnswer($answer ?? '');

        if ($normalized === '' || strlen($normalized) > $this->maxLength()) {
            return false;
        }

        if (! preg_match('/^[0-9a-z]+$/', $normalized)) {
            return false;
        }

        return hash_equals((string) ($payload['hash'] ?? ''), $this->hashAnswer($normalized));
    }

    public function generateCode(): string
    {
        $length = random_int($this->minLength(), $this->maxLength());
        $maxIndex = strlen(self::CHARSET) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= self::CHARSET[random_int(0, $maxIndex)];
        }

        return $code;
    }

    public function toDisplayString(string $code): string
    {
        return $code;
    }

    public function renderSvg(string $code): string
    {
        $chars = str_split($code);
        $count = count($chars);
        $width = max(200, $count * 36 + 40);
        $height = 56;
        $noise = '';
        $text = '';

        for ($i = 0; $i < 6; $i++) {
            $x1 = random_int(0, $width);
            $y1 = random_int(0, $height);
            $x2 = random_int(0, $width);
            $y2 = random_int(0, $height);
            $noise .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#94a3b8" stroke-width="1" opacity="0.35"/>',
                $x1,
                $y1,
                $x2,
                $y2
            );
        }

        foreach ($chars as $index => $char) {
            $x = 24 + ($index * 32);
            $y = 38 + random_int(-4, 4);
            $rotate = random_int(-18, 18);
            $fill = sprintf('#%06x', random_int(0x1a1a2e, 0x4a5568));
            $text .= sprintf(
                '<text x="%d" y="%d" fill="%s" font-family="Tahoma,Arial,sans-serif" font-size="26" font-weight="700" transform="rotate(%d %d %d)" direction="ltr">%s</text>',
                $x,
                $y,
                $fill,
                $rotate,
                $x,
                $y,
                htmlspecialchars($char, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-label="%s"><rect width="100%%" height="100%%" fill="#f8fafc" rx="8"/>%s%s</svg>',
            $width,
            $height,
            $width,
            $height,
            htmlspecialchars(__('auth.captcha_aria'), ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            $noise,
            $text
        );
    }

    protected function normalizeAnswer(string $answer): string
    {
        $answer = western_digits(trim($answer));
        $answer = Str::lower($answer);
        $answer = preg_replace('/[^0-9a-z]/u', '', $answer) ?? '';

        return $answer;
    }

    protected function hashAnswer(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    protected function minLength(): int
    {
        return max(4, (int) config('vpnpanel.login_captcha.min_length', 4));
    }

    protected function maxLength(): int
    {
        return min(6, max($this->minLength(), (int) config('vpnpanel.login_captcha.max_length', 6)));
    }

    protected function ttlMinutes(): int
    {
        return max(3, (int) config('vpnpanel.login_captcha.ttl_minutes', 10));
    }

    protected function failDecaySeconds(): int
    {
        return max(60, (int) config('vpnpanel.login_captcha.fail_decay_seconds', 900));
    }

    protected function failLimiterKey(Request $request): string
    {
        return 'login-captcha-fail|'.$request->ip();
    }

    /**
     * @throws ValidationException
     */
    protected function assertNotRateLimited(Request $request): void
    {
        $key = $this->failLimiterKey($request);
        $max = max(5, (int) config('vpnpanel.login_captcha.max_failures', 15));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'captcha' => [__('auth.captcha_throttle', ['seconds' => persian_digits($seconds)])],
            ]);
        }
    }
}
