<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

trait InteractsWithSubject
{
    /**
     * Gets the first subject of the EPUB.
     */
    public function getSubject(): string
    {
        return $this->getDcValue('subject');
    }

    /**
     * Sets the first subject of the EPUB, creating dc:subject when missing.
     */
    public function setSubject(string $subject): void
    {
        $this->setDcValue('subject', $subject);
    }

    /**
     * Gets all subjects of the EPUB, in document order.
     *
     * @return list<string>
     */
    public function getSubjects(): array
    {
        return $this->getDcValues('subject');
    }

    /**
     * Replaces all subjects of the EPUB.
     *
     * @param array<int, string> $subjects
     */
    public function setSubjects(array $subjects): void
    {
        $this->setDcValues('subject', array_values($subjects));
    }
}
