<?php

declare(strict_types=1);

namespace VastsverigeEvents;

/**
 * Outcome of one import run.
 */
class ImportReport
{
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $trashed = 0;
    public int $skipped = 0;

    /** @var list<string> */
    public array $messages = [];

    public bool $ok = true;

    public function add(string $message): void
    {
        $this->messages[] = $message;
    }

    public function fail(string $message): void
    {
        $this->ok = false;
        $this->add($message);
    }

    public function summary(): string
    {
        return sprintf(
            'created=%d updated=%d unchanged=%d trashed=%d skipped=%d',
            $this->created,
            $this->updated,
            $this->unchanged,
            $this->trashed,
            $this->skipped
        );
    }
}
