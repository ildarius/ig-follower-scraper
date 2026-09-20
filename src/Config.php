<?php
declare(strict_types=1);

final class Config
{
    private static array $values = [];

    public static function load(array $values): void
    {
        self::$values = $values;
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        $value = self::$values;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone((string) self::get('timezone', 'America/New_York'));
    }

    public static function now(): string
    {
        return (new DateTimeImmutable('now', self::timezone()))->format('Y-m-d H:i:s');
    }
}
