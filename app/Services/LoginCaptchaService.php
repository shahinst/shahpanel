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
    /**
     * No 0/o, 1/i/l: they look alike when drawn and only cost real users retries.
     */
    private const CHARSET = '23456789abcdefghjkmnpqrstuvwxyz';

    /**
     * Each character as strokes on a 10x14 grid (y down). The image draws these
     * instead of <text>: an SVG with the answer as text could be read by any
     * bot with a regular expression.
     *
     * @var array<string, list<list<array{0: int, 1: int}>>>
     */
    private const GLYPHS = [
        '2' => [[[0, 3], [2, 0], [8, 0], [10, 3], [10, 5], [0, 14], [10, 14]]],
        '3' => [[[0, 0], [10, 0], [5, 6], [9, 8], [10, 11], [7, 14], [2, 14], [0, 12]]],
        '4' => [[[8, 14], [8, 0], [0, 10], [10, 10]]],
        '5' => [[[10, 0], [1, 0], [0, 6], [6, 5], [10, 8], [10, 11], [7, 14], [1, 14], [0, 12]]],
        '6' => [[[9, 1], [6, 0], [3, 0], [0, 4], [0, 11], [3, 14], [7, 14], [10, 11], [10, 8], [7, 6], [3, 6], [0, 8]]],
        '7' => [[[0, 0], [10, 0], [4, 14]]],
        '8' => [[[5, 7], [1, 5], [1, 2], [4, 0], [6, 0], [9, 2], [9, 5], [5, 7], [0, 10], [1, 13], [4, 14], [6, 14], [9, 13], [10, 10], [5, 7]]],
        '9' => [[[10, 6], [7, 8], [3, 8], [0, 6], [0, 3], [3, 0], [7, 0], [10, 3], [10, 10], [7, 14], [3, 14], [1, 13]]],
        'a' => [[[0, 14], [5, 0], [10, 14]], [[2, 9], [8, 9]]],
        'b' => [[[0, 0], [0, 14], [7, 14], [10, 11], [7, 7], [0, 7]], [[0, 0], [7, 0], [9, 2], [7, 7]]],
        'c' => [[[10, 2], [7, 0], [3, 0], [0, 3], [0, 11], [3, 14], [7, 14], [10, 12]]],
        'd' => [[[0, 0], [0, 14], [6, 14], [10, 10], [10, 4], [6, 0], [0, 0]]],
        'e' => [[[10, 0], [0, 0], [0, 14], [10, 14]], [[0, 7], [7, 7]]],
        'f' => [[[10, 0], [0, 0], [0, 14]], [[0, 7], [7, 7]]],
        'g' => [[[10, 2], [7, 0], [3, 0], [0, 3], [0, 11], [3, 14], [7, 14], [10, 11], [10, 8], [6, 8]]],
        'h' => [[[0, 0], [0, 14]], [[10, 0], [10, 14]], [[0, 7], [10, 7]]],
        'j' => [[[10, 0], [10, 11], [7, 14], [3, 14], [0, 11]]],
        'k' => [[[0, 0], [0, 14]], [[10, 0], [0, 8]], [[3, 6], [10, 14]]],
        'm' => [[[0, 14], [0, 0], [5, 8], [10, 0], [10, 14]]],
        'n' => [[[0, 14], [0, 0], [10, 14], [10, 0]]],
        'p' => [[[0, 14], [0, 0], [7, 0], [10, 3], [10, 5], [7, 8], [0, 8]]],
        'q' => [[[3, 0], [0, 3], [0, 11], [3, 14], [7, 14], [10, 11], [10, 3], [7, 0], [3, 0]], [[6, 10], [10, 14]]],
        'r' => [[[0, 14], [0, 0], [7, 0], [10, 3], [10, 5], [7, 8], [0, 8]], [[5, 8], [10, 14]]],
        's' => [[[10, 2], [7, 0], [3, 0], [0, 3], [3, 6], [7, 8], [10, 11], [7, 14], [3, 14], [0, 12]]],
        't' => [[[0, 0], [10, 0]], [[5, 0], [5, 14]]],
        'u' => [[[0, 0], [0, 11], [3, 14], [7, 14], [10, 11], [10, 0]]],
        'v' => [[[0, 0], [5, 14], [10, 0]]],
        'w' => [[[0, 0], [2, 14], [5, 6], [8, 14], [10, 0]]],
        'x' => [[[0, 0], [10, 14]], [[10, 0], [0, 14]]],
        'y' => [[[0, 0], [5, 7], [10, 0]], [[5, 7], [5, 14]]],
        'z' => [[[0, 0], [10, 0], [0, 14], [10, 14]]],
    ];

    private const SESSION_KEY = 'login_captcha';

    /**
     * @return array{token: string, svg: string}
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

        // پاسخ کپچا هرگز از سرور بیرون نمی‌رود؛ فقط تصویر SVG و توکن. پیش از این
        // متن کد کنار توکن به مرورگر می‌رفت و هر اسکریپتی می‌توانست با یک
        // درخواست GET کد را بخواند و بدون هیچ OCR وارد شود.
        return [
            'token' => $token,
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

    public function renderSvg(string $code): string
    {
        $chars = str_split($code);
        $count = count($chars);
        $width = max(200, $count * 36 + 40);
        $height = 56;
        $noise = '';
        $glyphs = '';
        $jitter = static fn (): float => random_int(-60, 60) / 100;

        // Decoy strokes in the same width and colours as the characters, so the
        // strokes cannot be told apart from the answer by their style.
        for ($i = 0; $i < 5; $i++) {
            $points = [];
            $x = random_int(0, (int) ($width / 3));

            for ($k = 0; $k < 4; $k++) {
                $points[] = sprintf('%d,%d', $x, random_int(6, $height - 6));
                $x += random_int((int) ($width / 8), (int) ($width / 4));
            }

            $noise .= sprintf(
                '<polyline points="%s" fill="none" stroke="#%02x%02x%02x" stroke-width="%s" stroke-linecap="round" stroke-linejoin="round" opacity="0.55"/>',
                implode(' ', $points),
                random_int(0x5a, 0x94), random_int(0x6a, 0xa3), random_int(0x80, 0xb8),
                random_int(15, 25) / 10
            );
        }

        foreach ($chars as $index => $char) {
            $strokes = self::GLYPHS[$char] ?? [];
            $sx = random_int(170, 210) / 100;
            $sy = random_int(250, 290) / 100;
            $ox = 22 + ($index * 32) + random_int(-3, 3);
            $oy = 9 + random_int(-4, 4);
            $rotate = random_int(-16, 16);
            $cx = $ox + 5 * $sx;
            $cy = $oy + 7 * $sy;
            $fill = sprintf('#%02x%02x%02x', random_int(0x1a, 0x4a), random_int(0x1a, 0x55), random_int(0x2e, 0x68));
            $paths = '';

            foreach ($strokes as $stroke) {
                $points = array_map(
                    static fn (array $p): string => sprintf('%.1f,%.1f', $ox + ($p[0] + $jitter()) * $sx, $oy + ($p[1] + $jitter()) * $sy),
                    $stroke
                );
                $paths .= sprintf('<polyline points="%s"/>', implode(' ', $points));
            }

            $glyphs .= sprintf(
                '<g fill="none" stroke="%s" stroke-width="%s" stroke-linecap="round" stroke-linejoin="round" transform="rotate(%d %.1f %.1f)">%s</g>',
                $fill,
                random_int(26, 34) / 10,
                $rotate,
                $cx,
                $cy,
                $paths
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" style="max-width:100%%;height:auto" role="img" aria-label="%s"><rect width="100%%" height="100%%" fill="#f8fafc" rx="8"/>%s%s</svg>',
            $width,
            $height,
            $width,
            $height,
            htmlspecialchars(__('auth.captcha_aria'), ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            $glyphs,
            $noise
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
        return max(4, (int) config('shahpanel.login_captcha.min_length', 4));
    }

    protected function maxLength(): int
    {
        return min(6, max($this->minLength(), (int) config('shahpanel.login_captcha.max_length', 6)));
    }

    protected function ttlMinutes(): int
    {
        return max(3, (int) config('shahpanel.login_captcha.ttl_minutes', 10));
    }

    protected function failDecaySeconds(): int
    {
        return max(60, (int) config('shahpanel.login_captcha.fail_decay_seconds', 900));
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
        $max = max(5, (int) config('shahpanel.login_captcha.max_failures', 15));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'captcha' => [__('auth.captcha_throttle', ['seconds' => persian_digits($seconds)])],
            ]);
        }
    }
}
