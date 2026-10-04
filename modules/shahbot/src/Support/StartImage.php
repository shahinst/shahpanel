<?php

namespace Modules\ShahBot\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Services\BotNotifier;

/**
 * The picture a reseller's bot sends with its welcome.
 *
 * The file is judged by what it is, not by what it says it is: the name,
 * extension and the type the browser reported are all chosen by the uploader.
 * The first bytes must be a PNG or JPEG signature and the whole file must
 * decode as an image of sane size. What is kept is a fresh re-encode of the
 * decoded pixels, never the uploaded bytes, so anything riding along in the
 * file (a script after the image data, a polyglot) does not survive.
 *
 * Anything refused is reported to the panel admins at once: a reseller
 * uploading something that is not an image is worth knowing about.
 *
 * Stored outside the web root, so it is only ever sent to Telegram.
 */
class StartImage
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public static function path(?BotInstance $bot): ?string
    {
        if ($bot === null) {
            return null;
        }

        $file = self::dir().'/bot-'.$bot->id.'.jpg';

        return is_file($file) ? $file : null;
    }

    public static function store(BotInstance $bot, UploadedFile $file, User $uploader): void
    {
        $reason = self::reject($file);

        if ($reason !== null) {
            self::report($uploader, $file, $reason);

            throw new InvalidArgumentException(__('shahbot::admin.start_image_rejected'));
        }

        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($image === false) {
            self::report($uploader, $file, 'does not decode as an image');

            throw new InvalidArgumentException(__('shahbot::admin.start_image_rejected'));
        }

        if (! is_dir(self::dir())) {
            mkdir(self::dir(), 0750, true);
        }

        imagejpeg($image, self::dir().'/bot-'.$bot->id.'.jpg', 88);
        imagedestroy($image);
    }

    public static function clear(BotInstance $bot): void
    {
        $file = self::path($bot);

        if ($file !== null) {
            @unlink($file);
        }
    }

    protected static function reject(UploadedFile $file): ?string
    {
        if (! $file->isValid()) {
            return 'upload failed';
        }

        if ($file->getSize() <= 0 || $file->getSize() > self::MAX_BYTES) {
            return 'size '.$file->getSize().' bytes';
        }

        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 8);
        $isPng = str_starts_with($head, "\x89PNG\r\n\x1a\n");
        $isJpeg = str_starts_with($head, "\xFF\xD8\xFF");

        if (! $isPng && ! $isJpeg) {
            return 'not a PNG or JPEG signature (starts with '.bin2hex(substr($head, 0, 4)).')';
        }

        $info = @getimagesize($file->getRealPath());

        if ($info === false || ! in_array($info[2] ?? 0, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return 'not a readable PNG or JPEG';
        }

        // A tiny file claiming huge dimensions is a decompression bomb.
        if ($info[0] > 6000 || $info[1] > 6000 || $info[0] < 1 || $info[1] < 1) {
            return 'dimensions '.$info[0].'x'.$info[1];
        }

        return null;
    }

    protected static function report(User $uploader, UploadedFile $file, string $reason): void
    {
        Log::warning('Rejected bot start image', [
            'user_id' => $uploader->id, 'name' => $file->getClientOriginalName(), 'size' => $file->getSize(), 'reason' => $reason,
        ]);

        $text = __('shahbot::admin.start_image_report', [
            'user' => e($uploader->username),
            'role' => $uploader->role->value,
            'name' => e(mb_substr($file->getClientOriginalName(), 0, 80)),
            'size' => (string) $file->getSize(),
            'reason' => e($reason),
        ], 'fa');

        // The main bot's admins are the panel's; a reseller's own bot would
        // report the reseller to themselves.
        rescue(fn () => app(BotContext::class)->run(null, fn () => app(BotNotifier::class)->admins($text)), null, false);
    }

    protected static function dir(): string
    {
        return storage_path('app/shahbot-start');
    }
}
