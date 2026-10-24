<?php

declare(strict_types=1);

namespace Abdelhmed\Chimera\Conventions;

use Abdelhmed\Chimera\Contracts\ConventionStore;
use Abdelhmed\Chimera\Exceptions\ChimeraException;

/** Keeps the learned conventions in a JSON file that is meant to be committed and edited by the team. */
final class FilesystemConventionStore implements ConventionStore
{
    public function __construct(private readonly string $file)
    {
    }

    public function load(): ?ConventionSet
    {
        if (! is_file($this->file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->file), true);

        return is_array($data) ? ConventionSet::fromArray($data) : null;
    }

    public function save(ConventionSet $set): void
    {
        $directory = dirname($this->file);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new ChimeraException("Could not create [{$directory}].");
        }

        $json = json_encode($set->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false || file_put_contents($this->file, $json."\n", LOCK_EX) === false) {
            throw new ChimeraException("Could not write [{$this->file}].");
        }
    }
}
