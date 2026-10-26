<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests;

use PHPUnit\Framework\TestCase;
use PhpCsFixer\Tokenizer\Tokens;
use Noldors\PhpCsFixer\Internal\Layout;

final class LayoutTest extends TestCase
{
    public function testMeasuresThePhysicalLineWithUnicodeAndTabStops(): void
    {
        $tokens = Tokens::fromCode("<?php\n\t\$x = 'я';\n");
        $this->assertSame(13, Layout::lineWidth($tokens, 2));
        $this->assertSame("\t", Layout::indentAt($tokens, 2));
    }

    public function testWrapsBoundariesWithoutMovingAnInlineComment(): void
    {
        $tokens = Tokens::fromCode("<?php\n\$x = [1, // note\n2];\n");
        $open = $tokens->getNextTokenOfKind(0, [[\PhpCsFixer\Tokenizer\CT::T_ARRAY_BRACKET_OPEN]]);
        $this->assertNotNull($open);
        $close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_ARRAY_BRACKET, $open);
        $comma = $tokens->getNextTokenOfKind($open, [',']);
        $this->assertNotNull($comma);
        Layout::wrapDelimitedList(
            $tokens,
            $open,
            $close,
            [$comma],
            '',
            '    ',
            "\n",
        );
        $this->assertSame("<?php\n\$x = [\n    1, // note\n    2\n];\n", $tokens->generateCode());
    }
}
