<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests;

use SplFileInfo;
use PHPUnit\Framework\TestCase;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Fixer\FixerInterface;

abstract class FixerTestCase extends TestCase
{
    protected function assertFixerOutput(FixerInterface $fixer, string $input, string $expected): void
    {
        $tokens = Tokens::fromCode($input);
        $fixer->fix(new SplFileInfo('example.php'), $tokens);
        $actual = $tokens->generateCode();

        $this->assertSame($expected, $actual);
        $this->assertSame($this->significantTokens($input), $this->significantTokens($actual));

        $again = Tokens::fromCode($actual);
        $fixer->fix(new SplFileInfo('example.php'), $again);
        $this->assertSame($actual, $again->generateCode(), 'The fixer must be idempotent.');
    }

    /**
     * @return list<array{int, string}|string>
     */
    private function significantTokens(string $code): array
    {
        $tokens = \array_filter(\token_get_all($code, \TOKEN_PARSE), static fn (array|string $token): bool => ! \is_array($token) || \T_WHITESPACE !== $token[0]);

        return \array_values(\array_map(static fn (array|string $token): array|string => \is_array($token) ? [
            $token[0],
            $token[1],
        ] : $token, $tokens));
    }
}
