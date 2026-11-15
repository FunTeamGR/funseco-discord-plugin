<?php
namespace Funseco\Discord;

/** Finds the first TMX track link in a message and maps its host prefix to a TMX section. */
final class TmxLink
{
    private const SECTIONS = [
        'tmnforever' => 'TMNF', 'tmnf' => 'TMNF',
        'united' => 'TMU', 'tmuf' => 'TMU',
        'nations' => 'TMN',
        'sunrise' => 'TMS',
        'original' => 'TMO',
    ];

    /** @return array{id: int, section: string}|null */
    public static function parse(string $text): ?array
    {
        $text = strtolower($text);
        if (!preg_match('#https?://([a-z0-9-]+)\.(?:tm-exchange\.com|tmexchange\.com|exchange)/trackshow/(\d+)#', $text, $m)) {
            return null;
        }

        // an unknown prefix is TMNF
        return ['id' => (int) $m[2], 'section' => self::SECTIONS[$m[1]] ?? 'TMNF'];
    }
}
