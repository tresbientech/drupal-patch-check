<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Convention;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds catch blocks whose whole body is a return of a falsy literal.
 *
 * That shape answers "no" for a question the code could not read, which
 * is the defect this codebase keeps producing: a package with no known
 * release read as unsupported, a word that is not a version read as
 * satisfying nothing. A catch that records why, or rethrows, has a body
 * with more than the return in it and is not reported.
 *
 * Test-only. It uses PHP's own tokenizer, so a comment or a string
 * containing the word `catch` is never mistaken for one.
 */
final class SilentCatch
{
    /** Bodies that are exactly one of these are the shape being banned. */
    private const FALSY_RETURNS = [
        ['return', 'false', ';'],
        ['return', 'null', ';'],
        ['return', '0', ';'],
        ['return', '0.0', ';'],
        ['return', "''", ';'],
        ['return', '""', ';'],
        ['return', "'0'", ';'],
        ['return', '"0"', ';'],
        ['return', '[', ']', ';'],
        ['return', 'array', '(', ')', ';'],
        ['return', ';'],
    ];

    /**
     * Every offending catch under `$root`, as `<path>::<function>` keys
     * relative to `$root`.
     *
     * @return list<string>
     */
    public static function inTree(string $root): array
    {
        $found = [];
        foreach (self::files($root) as $path) {
            $label = \ltrim(\substr($path, \strlen($root)), \DIRECTORY_SEPARATOR);
            $source = \file_get_contents($path);
            if (false === $source) {
                continue;
            }
            foreach (self::inSource($source, $label) as $site) {
                $found[] = $site;
            }
        }
        \sort($found);

        return $found;
    }

    /**
     * Every offending catch in one piece of source.
     *
     * @return list<string>
     */
    public static function inSource(string $source, string $label): array
    {
        $tokens = \token_get_all($source);
        $count = \count($tokens);
        $found = [];
        $function = '';
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }
            if (\T_FUNCTION === $token[0]) {
                $function = self::nameAfter($tokens, $i);
                continue;
            }
            if (\T_CATCH !== $token[0]) {
                continue;
            }
            $body = self::body($tokens, $i);
            if (null !== $body && \in_array($body, self::FALSY_RETURNS, true)) {
                $found[] = $label.'::'.('' === $function ? 'closure' : $function);
            }
        }

        return $found;
    }

    /**
     * The catch block's body, as lowercased token text with whitespace
     * and comments dropped. Null when the block cannot be read.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return list<string>|null
     */
    private static function body(array $tokens, int $at): ?array
    {
        $count = \count($tokens);
        $i = self::skipParentheses($tokens, $at);
        while ($i < $count && '{' !== $tokens[$i]) {
            ++$i;
        }
        if ($i >= $count) {
            return null;
        }
        $depth = 0;
        $body = [];
        for (; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ('{' === $token) {
                ++$depth;
                if (1 === $depth) {
                    continue;
                }
            } elseif ('}' === $token) {
                --$depth;
                if (0 === $depth) {
                    return $body;
                }
            }
            if (\is_array($token)) {
                if (\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                $body[] = \strtolower($token[1]);
                continue;
            }
            $body[] = $token;
        }

        return null;
    }

    /**
     * The index just past the catch clause's parentheses.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private static function skipParentheses(array $tokens, int $at): int
    {
        $count = \count($tokens);
        $i = $at;
        while ($i < $count && '(' !== $tokens[$i]) {
            ++$i;
        }
        $depth = 0;
        for (; $i < $count; ++$i) {
            if ('(' === $tokens[$i]) {
                ++$depth;
            } elseif (')' === $tokens[$i]) {
                --$depth;
                if (0 === $depth) {
                    return $i + 1;
                }
            }
        }

        return $count;
    }

    /**
     * The name token after a `function` keyword, empty for a closure.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private static function nameAfter(array $tokens, int $at): string
    {
        $count = \count($tokens);
        for ($i = $at + 1; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (\is_array($token) && \T_WHITESPACE === $token[0]) {
                continue;
            }
            if (\is_array($token) && \T_STRING === $token[0]) {
                return $token[1];
            }

            return '';
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private static function files(string $root): array
    {
        $paths = [];
        /** @var iterable<SplFileInfo> $walk */
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $paths[] = $file->getPathname();
            }
        }
        \sort($paths);

        return $paths;
    }
}
