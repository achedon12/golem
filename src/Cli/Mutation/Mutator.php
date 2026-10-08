<?php

declare(strict_types=1);

namespace Golem\Cli\Mutation;

/**
 * Finds the small changes golem mutate makes to a plugin's code, one at a time: each one is
 * a bug the tests should catch.
 */
final class Mutator
{
    /** token text => what it becomes */
    private const SWAPS = [
        '===' => '!==', '!==' => '===', '==' => '!=', '!=' => '==',
        '<=' => '<', '>=' => '>', '<' => '<=', '>' => '>=',
        '&&' => '||', '||' => '&&', 'and' => 'or', 'or' => 'and',
        '+' => '-', '-' => '+', '*' => '/', '/' => '*',
        'true' => 'false', 'false' => 'true',
    ];

    /**
     * The mutants of a file, on the lines some test runs.
     *
     * @param array<int, true> $coveredLines
     * @return list<Mutant>
     */
    public static function mutants(string $file, string $code, array $coveredLines): array
    {
        $tokens = token_get_all($code);
        $lines = explode("\n", $code);
        $mutants = [];
        $line = 1;
        foreach ($tokens as $index => $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token)) {
                $line = $token[2];
            }
            $replacement = self::replacement($tokens, $index, $text);
            if ($replacement !== null && isset($coveredLines[$line])) {
                $mutated = $tokens;
                $mutated[$index] = $replacement;
                $mutatedCode = implode('', array_map(static fn ($t) => is_array($t) ? $t[1] : $t, $mutated));
                $mutants[] = new Mutant(
                    $file,
                    $line,
                    $text === '!' ? 'remove !' : "$text → $replacement",
                    trim($lines[$line - 1] ?? ''),
                    trim(explode("\n", $mutatedCode)[$line - 1] ?? ''),
                    $mutatedCode,
                );
            }
            if (!is_array($token)) {
                $line += substr_count($token, "\n");
            } else {
                $line = $token[2] + substr_count($token[1], "\n");
            }
        }

        return $mutants;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function replacement(array $tokens, int $index, string $text): ?string
    {
        $token = $tokens[$index];
        if ($text === '!' && !is_array($token)) {
            return ''; // !$condition becomes $condition
        }
        $key = strtolower($text);
        if (!isset(self::SWAPS[$key])) {
            return null;
        }
        if (is_array($token) && !in_array($token[0], [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_STRING], true)) {
            return null; // the same text in a comment, a string, a name...
        }
        if (in_array($key, ['true', 'false'], true)) {
            // a value, not a type in a signature: "): bool" never says true, but "?true" and "|false" can
            $previous = self::previous($tokens, $index);
            if (in_array($previous, ['|', '?', ':'], true) && self::insideSignature($tokens, $index)) {
                return null;
            }
            $replacement = self::SWAPS[$key];

            return ctype_upper($text[0]) ? strtoupper($replacement) : $replacement;
        }
        if (in_array($text, ['-', '+'], true)) {
            // a sign, not an operation: "-1", "return -$x"
            $previous = self::previous($tokens, $index);
            if (in_array($previous, ['(', ',', '=', 'return', '[', '=>', '?', ':', '<', '>', '<=', '>=', '==', '===', '!=', '!=='], true)) {
                return null;
            }
        }

        return self::SWAPS[$key];
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function previous(array $tokens, int $index): string
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return strtolower(is_array($token) ? $token[1] : $token);
        }

        return '';
    }

    /**
     * Whether the token is in a function signature or property type, where true and false
     * are types.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function insideSignature(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if ($token === '{' || $token === ';' || $token === '}') {
                return false;
            }
            if (is_array($token) && in_array($token[0], [T_FUNCTION, T_FN, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY], true)) {
                return true;
            }
        }

        return false;
    }
}
