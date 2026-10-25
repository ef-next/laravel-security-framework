<?php

declare(strict_types=1);

namespace Efnext\Security\Addressing\Generation;

use Efnext\Security\Addressing\Contracts\PublicIdGenerator;
use Efnext\Security\Addressing\Exceptions\InvalidPublicId;
use Efnext\Security\Addressing\Exceptions\PublicIdGenerationFailed;
use Efnext\Security\Addressing\Identifiers\PublicId;
use Illuminate\Database\ConnectionResolverInterface;

final class RandomPublicIdGenerator implements PublicIdGenerator
{
    public const int MINIMUM_ENTROPY_BYTES = 8;

    /** @var callable(int): string */
    private $entropySource;

    /**
     * @param  (callable(int): string)|null  $entropySource  Defaults to random_bytes(); injectable for tests only.
     */
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly Encoding $encoding = Encoding::Base32,
        private readonly int $defaultEntropyBytes = 10,
        private readonly int $defaultMaxAttempts = 5,
        ?callable $entropySource = null,
    ) {
        if ($defaultEntropyBytes < self::MINIMUM_ENTROPY_BYTES) {
            throw InvalidPublicId::insufficientEntropy($defaultEntropyBytes, self::MINIMUM_ENTROPY_BYTES);
        }

        $this->entropySource = $entropySource ?? static fn (int $bytes): string => random_bytes(max(1, $bytes));
    }

    public function generate(string $prefix, ?int $entropyBytes = null): PublicId
    {
        if (! PublicId::isValidPrefix($prefix)) {
            throw InvalidPublicId::invalidPrefix($prefix);
        }

        $bytes = $entropyBytes ?? $this->defaultEntropyBytes;

        if ($bytes < self::MINIMUM_ENTROPY_BYTES) {
            throw InvalidPublicId::insufficientEntropy($bytes, self::MINIMUM_ENTROPY_BYTES);
        }

        return PublicId::of($prefix, $this->encoding->encode(($this->entropySource)($bytes)));
    }

    public function generateUnique(
        string $prefix,
        string $table,
        string $column,
        ?string $connection = null,
        ?int $entropyBytes = null,
        ?int $maxAttempts = null,
    ): PublicId {
        $attempts = max(1, $maxAttempts ?? $this->defaultMaxAttempts);
        $query = $this->connections->connection($connection)->table($table);

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $candidate = $this->generate($prefix, $entropyBytes);

            if (! (clone $query)->where($column, $candidate->toString())->exists()) {
                return $candidate;
            }
        }

        throw PublicIdGenerationFailed::afterAttempts($table, $column, $attempts);
    }
}
