<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Plan;

/**
 * Reads one field out of a decoded JSON object.
 *
 * Every value the server sends arrives as mixed. Reading it goes through
 * here, so a field that is absent or of the wrong type becomes a default
 * in one place instead of a cast at every use.
 */
final class Value
{
    /**
     * @param array<string, mixed> $data
     */
    public static function str(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return \is_int($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function bool(array $data, string $key): bool
    {
        return ($data[$key] ?? null) === true;
    }

    /**
     * The nested object under a key, empty when there is none.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function object(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return \is_array($value) ? self::keyed($value) : [];
    }

    /**
     * The list under a key, keeping only its object entries.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public static function objects(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (\is_array($entry)) {
                $out[] = self::keyed($entry);
            }
        }

        return $out;
    }

    /**
     * The strings under a key, in order.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (\is_string($entry)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * The counts under a key: a name to a number.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, int>
     */
    public static function counts(array $data, string $key): array
    {
        $out = [];
        foreach (self::object($data, $key) as $name => $count) {
            if (\is_int($count)) {
                $out[$name] = $count;
            }
        }

        return $out;
    }

    /**
     * A decoded array with its string keys only, which is what a JSON
     * object always is and what the type system cannot know on its own.
     *
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function keyed(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (\is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
