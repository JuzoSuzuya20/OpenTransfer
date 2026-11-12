<?php

namespace App\Services;

/**
 * PatternGenerator - Generates random strings based on Erugo Pattern Syntax
 * 
 * Special Random Tokens:
 *   # - Digit 0-9
 *   A - Uppercase letter A-Z
 *   a - Lowercase letter a-z
 *   * - Alphanumeric 0-9A-Za-z
 *   X - Hexadecimal 0-9A-F
 * 
 * Escape sequences (output literal characters):
 *   \# \A \a \* \X \\
 * 
 * Character classes (pick one random character from set):
 *   [ABC] - randomly A, B, or C
 *   [0-9] - any digit (range)
 *   [A-Z] - any uppercase letter (range)
 *   [a-z] - any lowercase letter (range)
 *   [A-Za-z0-9] - combined ranges
 *   [\#A] - literal # or A
 */
class PatternGenerator
{
    private const DIGITS = '0123456789';
    private const UPPERCASE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const LOWERCASE = 'abcdefghijklmnopqrstuvwxyz';
    private const HEX = '0123456789ABCDEF';
    private const ALPHANUMERIC = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    // Special tokens that generate random output
    private const SPECIAL_TOKENS = ['#', 'A', 'a', '*', 'X'];

    /**
     * The chance a share link must carry at least: anyone who has the link of a share without
     * a password reads it. 45 bits is eight alphanumeric characters; six (36 bits) can be
     * enumerated. The haiku mode carries about 66.
     */
    public const MIN_BITS = 45;

    // What a pattern too short is replaced by when one was saved before the minimum existed
    public const SAFE_PATTERN = '********';

    /**
     * The chance a pattern carries, in bits: what a person who does not know the link has to
     * guess. Literal characters and escapes carry none.
     */
    public function bits(string $pattern): float
    {
        $bits = 0.0;
        $length = strlen($pattern);
        $i = 0;

        while ($i < $length) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $i += 2;
                continue;
            }

            if ($char === '[') {
                $closePos = $this->findClosingBracket($pattern, $i);
                if ($closePos !== false) {
                    $choices = count($this->expandCharacterClass(substr($pattern, $i + 1, $closePos - $i - 1)));
                    $bits += $choices > 1 ? log($choices, 2) : 0;
                    $i = $closePos + 1;
                    continue;
                }
            }

            $choices = match ($char) {
                '#' => strlen(self::DIGITS),
                'A' => strlen(self::UPPERCASE),
                'a' => strlen(self::LOWERCASE),
                '*' => strlen(self::ALPHANUMERIC),
                'X' => strlen(self::HEX),
                default => 1,
            };
            $bits += log($choices, 2);
            $i++;
        }

        return $bits;
    }

    public function isStrongEnough(string $pattern): bool
    {
        return $this->validate($pattern) === null && $this->bits($pattern) >= self::MIN_BITS;
    }

    /**
     * Generate a string based on the provided pattern
     */
    public function generate(string $pattern): string
    {
        $result = '';
        $length = strlen($pattern);
        $i = 0;

        while ($i < $length) {
            $char = $pattern[$i];

            // Handle escape sequences
            if ($char === '\\' && $i + 1 < $length) {
                $nextChar = $pattern[$i + 1];
                // Escaped special characters output themselves literally
                if (in_array($nextChar, self::SPECIAL_TOKENS) || $nextChar === '\\') {
                    $result .= $nextChar;
                    $i += 2;
                    continue;
                }
                // Unknown escape - treat backslash as literal
                $result .= $char;
                $i++;
                continue;
            }

            // Handle character classes [...]
            if ($char === '[') {
                $closePos = $this->findClosingBracket($pattern, $i);
                if ($closePos !== false) {
                    $classContent = substr($pattern, $i + 1, $closePos - $i - 1);
                    $chars = $this->expandCharacterClass($classContent);
                    if (!empty($chars)) {
                        $result .= $chars[random_int(0, count($chars) - 1)];
                    }
                    $i = $closePos + 1;
                    continue;
                }
                // No closing bracket found, treat [ as literal
                $result .= $char;
                $i++;
                continue;
            }

            // Handle special tokens
            if (in_array($char, self::SPECIAL_TOKENS)) {
                $result .= $this->generateFromToken($char);
                $i++;
                continue;
            }

            // All other characters are literal
            $result .= $char;
            $i++;
        }

        return $result;
    }

    /**
     * Generate a random character based on the token type
     */
    private function generateFromToken(string $token): string
    {
        $charset = match ($token) {
            '#' => self::DIGITS,
            'A' => self::UPPERCASE,
            'a' => self::LOWERCASE,
            '*' => self::ALPHANUMERIC,
            'X' => self::HEX,
            default => '',
        };

        if (empty($charset)) {
            return '';
        }

        return $charset[random_int(0, strlen($charset) - 1)];
    }

    /**
     * Find the position of the closing bracket for a character class
     */
    private function findClosingBracket(string $pattern, int $openPos): int|false
    {
        $length = strlen($pattern);
        $i = $openPos + 1;

        while ($i < $length) {
            $char = $pattern[$i];

            // Handle escapes inside character class
            if ($char === '\\' && $i + 1 < $length) {
                $i += 2;
                continue;
            }

            if ($char === ']') {
                return $i;
            }

            $i++;
        }

        return false;
    }

    /**
     * Expand a character class content into an array of individual characters
     * Handles ranges (a-z, 0-9, A-Z) and escapes
     */
    private function expandCharacterClass(string $content): array
    {
        $chars = [];
        $length = strlen($content);
        $i = 0;

        while ($i < $length) {
            $char = $content[$i];

            // Handle escape sequences inside character class
            if ($char === '\\' && $i + 1 < $length) {
                $nextChar = $content[$i + 1];
                $chars[] = $nextChar;
                $i += 2;
                continue;
            }

            // Check for range (x-y)
            if ($i + 2 < $length && $content[$i + 1] === '-') {
                $startChar = $char;
                $endChar = $content[$i + 2];
                
                // Handle escape at end of range
                if ($endChar === '\\' && $i + 3 < $length) {
                    $endChar = $content[$i + 3];
                    $i += 4;
                } else {
                    $i += 3;
                }

                // Expand the range
                $rangeChars = $this->expandRange($startChar, $endChar);
                $chars = array_merge($chars, $rangeChars);
                continue;
            }

            // Regular character
            $chars[] = $char;
            $i++;
        }

        return array_unique($chars);
    }

    /**
     * Expand a character range (e.g., a-z, 0-9, A-Z)
     */
    private function expandRange(string $start, string $end): array
    {
        $startOrd = ord($start);
        $endOrd = ord($end);

        // Ensure start <= end
        if ($startOrd > $endOrd) {
            [$startOrd, $endOrd] = [$endOrd, $startOrd];
        }

        $chars = [];
        for ($i = $startOrd; $i <= $endOrd; $i++) {
            $chars[] = chr($i);
        }

        return $chars;
    }

    /**
     * Validate a pattern and return any errors
     * Returns null if valid, or an error message if invalid
     */
    public function validate(string $pattern): ?string
    {
        if (empty($pattern)) {
            return 'Pattern cannot be empty';
        }

        $length = strlen($pattern);
        $i = 0;
        $bracketDepth = 0;

        while ($i < $length) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $length) {
                // Skip escape sequence
                $i += 2;
                continue;
            }

            if ($char === '[') {
                $bracketDepth++;
                if ($bracketDepth > 1) {
                    return 'Nested character classes are not supported';
                }
            } elseif ($char === ']') {
                $bracketDepth--;
                if ($bracketDepth < 0) {
                    return 'Unmatched closing bracket';
                }
            }

            $i++;
        }

        if ($bracketDepth > 0) {
            return 'Unclosed character class bracket';
        }

        return null;
    }

    /**
     * Get a preview of what a pattern might generate (for UI display)
     * Generates a sample output from the pattern
     */
    public function preview(string $pattern): string
    {
        $error = $this->validate($pattern);
        if ($error !== null) {
            return "Error: {$error}";
        }

        return $this->generate($pattern);
    }
}

