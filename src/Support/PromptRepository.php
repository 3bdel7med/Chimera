<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Support;

use Abdelhmed\Chimera\Exceptions\ConfigurationException;

/** Loads prompt stubs; a copy in the host app's override directory wins over the bundled one. */
final class PromptRepository
{
    public function __construct(
        private readonly string $bundledPath,
        private readonly ?string $overridePath = null,
    ) {
    }

    /** @param array<string, scalar> $vars replaces {{key}} placeholders */
    public function render(string $name, array $vars = []): string
    {
        foreach ([$this->overridePath, $this->bundledPath] as $directory) {
            if ($directory === null) {
                continue;
            }

            $file = rtrim($directory, '/\\')."/{$name}.stub";

            if (is_file($file)) {
                $replace = [];
                foreach ($vars as $key => $value) {
                    $replace['{{'.$key.'}}'] = (string) $value;
                }

                return strtr((string) file_get_contents($file), $replace);
            }
        }

        throw new ConfigurationException("Prompt stub [{$name}] was not found.");
    }
}
