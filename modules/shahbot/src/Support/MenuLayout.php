<?php

namespace Modules\ShahBot\Support;

/**
 * Order and visibility of the main menu buttons, set in the keyboard editor.
 * Button wording is edited with the other texts (the menu_* keys).
 */
class MenuLayout
{
    /** Button => [row, position]. "agency" stands for the agency/reseller button. */
    public const DEFAULTS = [
        'buy' => [1, 1],
        'services' => [1, 2],
        'wallet' => [2, 1],
        'account' => [2, 2],
        'test' => [3, 1],
        'referral' => [3, 2],
        'agency' => [4, 1],
        'gift' => [5, 1],
        'tutorials' => [5, 2],
        'wheel' => [6, 1],
        'lottery' => [6, 2],
        'support' => [7, 1],
        'app' => [7, 2],
        'language' => [8, 1],
    ];

    public function __construct(protected BotSettings $settings) {}

    /**
     * @return array<string, array{row: int, pos: int, on: bool}>
     */
    public function all(): array
    {
        $saved = json_decode($this->settings->main('menu_layout') ?: '[]', true);
        $saved = is_array($saved) ? $saved : [];
        $layout = [];

        foreach (self::DEFAULTS as $key => [$row, $pos]) {
            $entry = is_array($saved[$key] ?? null) ? $saved[$key] : [];
            $layout[$key] = [
                'row' => max(1, min(10, (int) ($entry['row'] ?? $row))),
                'pos' => max(1, min(10, (int) ($entry['pos'] ?? $pos))),
                'on' => (bool) ($entry['on'] ?? true),
            ];
        }

        return $layout;
    }

    public function save(array $input): void
    {
        $layout = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $entry = (array) ($input[$key] ?? []);
            $layout[$key] = [
                'row' => max(1, min(10, (int) ($entry['row'] ?? 1))),
                'pos' => max(1, min(10, (int) ($entry['pos'] ?? 1))),
                'on' => ! empty($entry['on']),
            ];
        }

        $this->settings->set(['menu_layout' => json_encode($layout)]);
    }

    /**
     * Rows of button keys, for the buttons that are available to this user.
     *
     * @param  array<string, bool>  $available
     * @return list<list<string>>
     */
    public function rows(array $available): array
    {
        $rows = [];

        foreach ($this->all() as $key => $entry) {
            if ($entry['on'] && ($available[$key] ?? true)) {
                $rows[$entry['row']][$entry['pos'] * 100 + count($rows[$entry['row']] ?? [])] = $key;
            }
        }

        ksort($rows);

        return array_values(array_map(function (array $row): array {
            ksort($row);

            return array_values($row);
        }, $rows));
    }
}
