<?php
namespace App\Support;
final class ProfileOptions
{
    public static function normalized(?array $options): array
    {
        return array_values(array_map(function ($option): array {
            if (is_array($option)) {
                return ['label' => (string) ($option['label'] ?? ''), 'value' => (string) ($option['value'] ?? '')];
            }
            return ['label' => (string) $option, 'value' => (string) $option];
        }, $options ?? []));
    }

    public static function values(?array $options): array
    {
        return array_column(self::normalized($options), 'value');
    }

    public static function label(?array $options, string $value): string
    {
        foreach (self::normalized($options) as $option) {
            if ($option['value'] === $value) return $option['label'];
        }
        return $value;
    }
}
