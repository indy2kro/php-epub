<?php

declare(strict_types=1);

namespace PhpEpub\Traits;

use PhpEpub\Exception;

trait InteractsWithRights
{
    /**
     * Gets the rights statement of the EPUB (the first non-empty dc:rights), e.g. a copyright line or a
     * licence; null when there is none.
     */
    public function getRights(): ?string
    {
        foreach ($this->getDcValues('rights') as $rights) {
            if (trim($rights) !== '') {
                return trim($rights);
            }
        }

        return null;
    }

    /**
     * Sets the rights statement, replacing every dc:rights; null or "" removes them.
     *
     * @throws Exception If the value is not valid XML text.
     */
    public function setRights(?string $rights): void
    {
        $this->setDcValues('rights', $rights === null || trim($rights) === '' ? [] : [$rights]);
    }
}
