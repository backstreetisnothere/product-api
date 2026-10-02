<?php

declare(strict_types=1);

namespace App\Dto\Response;

use OpenApi\Attributes as OA;

final readonly class TokenView
{
    public function __construct(
        public string $accessToken,
        #[OA\Property(example: 'Bearer')]
        public string $tokenType,
        #[OA\Property(description: 'Lifetime in seconds.', example: 900)]
        public int $expiresIn,
    ) {
    }
}
