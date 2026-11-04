<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Internal;

use PhpCsFixer\WhitespacesFixerConfig;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;

/**
 * @internal
 */
abstract class AbstractWhitespaceAwareFixer implements WhitespacesAwareFixerInterface
{
    protected WhitespacesFixerConfig $whitespacesConfig;

    public function __construct()
    {
        $this->whitespacesConfig = new WhitespacesFixerConfig('    ', "\n");
    }

    final public function setWhitespacesConfig(WhitespacesFixerConfig $config): void
    {
        $this->whitespacesConfig = $config;
    }
}
