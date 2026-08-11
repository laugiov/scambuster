<?php

declare(strict_types=1);

namespace App\Application\Ttp\Audit;

/**
 * Adjudicated verdict counts for one slice of an audit sample.
 *
 * Precision is `correct / (correct + incorrect)`. `unclear` rows sit outside both
 * terms on purpose: a row a human could not judge is not evidence for or against
 * the extractor, and folding it either way would inflate or deflate the figure.
 * It is reported as its own count so a reader can see how much of the sample was
 * undecidable.
 */
final readonly class AuditTally
{
    public function __construct(
        public int $correct,
        public int $incorrect,
        public int $unclear,
        public int $unscored = 0,
    ) {
    }

    public function decided(): int
    {
        return $this->correct + $this->incorrect;
    }

    public function total(): int
    {
        return $this->correct + $this->incorrect + $this->unclear + $this->unscored;
    }

    /**
     * Null when no row in the slice was decided — reported as "not computable"
     * rather than as 0% or 100%.
     */
    public function precision(): ?float
    {
        $decided = $this->decided();

        return $decided === 0 ? null : $this->correct / $decided;
    }
}
