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
        // At most two buttons a row. A layout row holding three or more long
        // Persian labels made Telegram squeeze them into a strip that slid
        // sideways on phones; two keep every label whole and the grid steady.
        $grid = [];
        foreach ($rows as $row) {
            $buttons = array_map(fn ($t) => is_array($t) ? $t : ['text' => (string) $t], array_values($row));
            foreach (array_chunk($buttons, 2) as $chunk) {
                $grid[] = $chunk;
            }
        }

        return [
            'keyboard' => $grid,
            'resize_keyboard' => true,
            // Stays open instead of collapsing behind the input box after each tap.
            'is_persistent' => true,
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
