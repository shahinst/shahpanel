<?php

namespace Modules\ShahBot\Telegram;

/**
 * Builders for Telegram reply_markup arrays.
 */
class Keyboard
{
    public static function button(string $text, string $data): array
    {
        return ['text' => $text, 'callback_data' => mb_substr($data, 0, 64)];
    }

    public static function url(string $text, string $url): array
    {
        return ['text' => $text, 'url' => $url];
    }

    /**
     * @param  list<list<array>>  $rows
     */
    public static function inline(array $rows): array
    {
        return ['inline_keyboard' => array_values(array_filter($rows, fn ($row) => $row !== []))];
    }

    /**
     * Lays buttons out $perRow to a row.
     *
     * @param  list<array>  $buttons
     * @return list<list<array>>
     */
    public static function grid(array $buttons, int $perRow = 2): array
    {
        return array_chunk($buttons, max(1, $perRow));
    }

    /**
     * @param  list<list<string|array>>  $rows  plain labels, or full button arrays (e.g. a web_app button)
     */
    public static function reply(array $rows): array
    {
        return [
            'keyboard' => array_map(fn (array $row) => array_map(fn ($t) => is_array($t) ? $t : ['text' => (string) $t], $row), $rows),
            'resize_keyboard' => true,
        ];
    }

    public static function contact(string $label, string $cancel): array
    {
        return [
            'keyboard' => [[['text' => $label, 'request_contact' => true]], [['text' => $cancel]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }
}
