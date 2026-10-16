<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An uploaded sheet names the same assessment item in more than one column
 * ("Quiz 1", "Quiz 1"). Both columns would land on ONE stored item, the
 * later silently overwriting the earlier score — so the upload is refused,
 * naming the duplicates, before anything is written.
 */
class DuplicateAssessmentHeaderException extends RuntimeException
{
    /** @param list<string> $names */
    public function __construct(private readonly array $names)
    {
        parent::__construct(self::messageFor($names));
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->names;
    }

    /** @param list<string> $names */
    public static function messageFor(array $names): string
    {
        return 'This file has more than one column named ' . implode(', ', array_map(fn($n) => '"' . $n . '"', $names))
            . '. Each assessment item must appear once — rename or remove the duplicate column and upload again. Nothing was imported.';
    }
}
