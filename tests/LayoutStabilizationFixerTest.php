<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests;

use SplFileInfo;
use PHPUnit\Framework\TestCase;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\WhitespacesFixerConfig;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use Noldors\PhpCsFixer\Internal\LayoutStabilizationFixer;

final class LayoutStabilizationFixerTest extends TestCase
{
    private const string SOURCE = "<?php\n\$value = 1;\n";

    public function testSkipsAnUnsupportedPlainFixerBeforeCheckingItsCandidate(): void
    {
        $calls = new EligibilityProbeCalls();
        $fixer = new EligibilityProbeFixer(false, true, $calls);

        $this->assertSame(self::SOURCE, $this->stabilize($fixer));
        $this->assertSame(1, $calls->supports);
        $this->assertSame(0, $calls->candidate);
        $this->assertSame(0, $calls->fix);
    }

    public function testSkipsAPlainFixerWhoseTokensAreNotACandidate(): void
    {
        $calls = new EligibilityProbeCalls();
        $fixer = new EligibilityProbeFixer(true, false, $calls);

        $this->assertSame(self::SOURCE, $this->stabilize($fixer));
        $this->assertSame(1, $calls->supports);
        $this->assertSame(1, $calls->candidate);
        $this->assertSame(0, $calls->fix);
    }

    public function testRunsAnEligiblePlainFixer(): void
    {
        $calls = new EligibilityProbeCalls();
        $fixer = new EligibilityProbeFixer(true, true, $calls);

        $this->assertSame(self::SOURCE, $this->stabilize($fixer));
        $this->assertSame(1, $calls->supports);
        $this->assertSame(1, $calls->candidate);
        $this->assertSame(1, $calls->fix);
    }

    public function testAcceptsARegisteredDisabledFixerWithAPrivateCloneMethod(): void
    {
        $calls = new EligibilityProbeCalls();
        $fixer = new UncloneableEligibilityProbeFixer(false, false, $calls);

        $this->assertSame(self::SOURCE, $this->stabilize($fixer, false));
        $this->assertSame(0, $calls->supports);
        $this->assertSame(0, $calls->candidate);
        $this->assertSame(0, $calls->fix);
    }

    public function testRunsAnEnabledFixerWithAPrivateCloneMethod(): void
    {
        $calls = new EligibilityProbeCalls();
        $fixer = new UncloneableEligibilityProbeFixer(true, true, $calls);

        $this->assertSame(self::SOURCE, $this->stabilize($fixer));
        $this->assertSame(1, $calls->supports);
        $this->assertSame(1, $calls->candidate);
        $this->assertSame(1, $calls->fix);
    }

    private function stabilize(EligibilityProbeFixer $fixer, bool $enabled = true): string
    {
        $coordinator = new LayoutStabilizationFixer(
            [$fixer->getName() => $enabled, 'Noldors/layout_stabilization' => true],
            [$fixer],
            new WhitespacesFixerConfig('    ', "\n"),
        );
        $tokens = Tokens::fromCode(self::SOURCE);
        $coordinator->fix(new SplFileInfo('example.php'), $tokens);

        return $tokens->generateCode();
    }
}

final class EligibilityProbeCalls
{
    public int $supports = 0;

    public int $candidate = 0;

    public int $fix = 0;
}

class EligibilityProbeFixer implements FixerInterface
{
    public function __construct(
        private readonly bool $supportsFile,
        private readonly bool $candidateTokens,
        private readonly EligibilityProbeCalls $calls,
    ) {}

    public function isCandidate(Tokens $tokens): bool
    {
        ++$this->calls->candidate;

        return $this->candidateTokens;
    }

    public function isRisky(): bool
    {
        return false;
    }

    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        ++$this->calls->fix;
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition('Test-only fixer eligibility probe.', [
            new CodeSample("<?php\n\$value = 1;\n"),
        ]);
    }

    public function getName(): string
    {
        return 'Testing/eligibility_probe';
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function supports(SplFileInfo $file): bool
    {
        ++$this->calls->supports;

        return $this->supportsFile;
    }
}

final class UncloneableEligibilityProbeFixer extends EligibilityProbeFixer
{
    private function __clone() {}
}
