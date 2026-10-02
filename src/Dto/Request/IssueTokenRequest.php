<?php

declare(strict_types=1);

namespace App\Dto\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(required: ['api_key'])]
final readonly class IssueTokenRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 128)]
        #[OA\Property(example: 'sk_0123456789ab_0123456789abcdef0123456789abcdef0123456789abcdef')]
        public string $apiKey,
    ) {
    }
}
