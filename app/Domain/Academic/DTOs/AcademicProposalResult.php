<?php

namespace App\Domain\Academic\DTOs;

use App\Models\AcademicProposal;

final readonly class AcademicProposalResult
{
    public function __construct(
        public AcademicProposal $proposal,
        public bool $created,
    ) {}
}
