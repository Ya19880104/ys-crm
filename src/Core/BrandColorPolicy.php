<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

/**
 * Keeps user-supplied board and job colors out of the forbidden green range.
 *
 * Storage keeps the original value only when it is a valid non-green #RRGGBB
 * color. Legacy values are normalized again at render time.
 */
final class BrandColorPolicy
{
    public const SAFE_CYAN = '#46caeb';
    public const SAFE_BLUE = '#1E40AF';

    public static function normalize(mixed $color, string $fallback = self::SAFE_BLUE): string
    {
        $value = trim((string) ($color ?? ''));
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value) !== 1) {
            return self::safeFallback($fallback);
        }

        return self::isGreen($value) ? self::SAFE_CYAN : $value;
    }

    public static function forDisplay(mixed $color): string
    {
        return self::normalize($color);
    }

    /**
     * Normalize a CSS hex color while preserving Quote's historical #RGB
     * return format. Invalid input stays distinguishable from a fallback.
     */
    public static function normalizeCssHex(mixed $color): ?string
    {
        $value = strtolower(trim((string) ($color ?? '')));
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $value) !== 1) {
            return null;
        }

        $expanded = $value;
        if (strlen($value) === 4) {
            $expanded = '#' . $value[1] . $value[1]
                . $value[2] . $value[2]
                . $value[3] . $value[3];
        }

        return self::isGreen($expanded) ? self::SAFE_CYAN : $value;
    }

    /** A fallback is policy input too; an invalid or green fallback must not escape. */
    private static function safeFallback(string $fallback): string
    {
        $fallback = trim($fallback);
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $fallback) !== 1 || self::isGreen($fallback)) {
            return self::SAFE_BLUE;
        }

        return $fallback;
    }

    private static function isGreen(string $color): bool
    {
        $red   = hexdec(substr($color, 1, 2)) / 255;
        $green = hexdec(substr($color, 3, 2)) / 255;
        $blue  = hexdec(substr($color, 5, 2)) / 255;

        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $delta = $max - $min;
        if ($delta <= 0.0) {
            return false;
        }

        if ($max === $red) {
            $hue = 60 * fmod((($green - $blue) / $delta), 6);
        } elseif ($max === $green) {
            $hue = 60 * ((($blue - $red) / $delta) + 2);
        } else {
            $hue = 60 * ((($red - $green) / $delta) + 4);
        }
        if ($hue < 0) {
            $hue += 360;
        }

        // Hue remains meaningful for a tinted near-neutral color; only exact
        // neutral gray has no hue and remains unchanged.
        return $hue >= 60 && $hue <= 180;
    }
}
