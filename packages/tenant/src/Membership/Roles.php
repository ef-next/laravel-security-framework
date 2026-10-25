<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Membership;

use InvalidArgumentException;

/**
 * Role vocabulary and (optional) hierarchy. Roles are lowercase strings.
 *
 * Hierarchical mode: roles are configured highest first; a required role is
 * satisfied by that role or any higher one ("admin" is satisfied by "owner").
 * Flat mode: the actor's role must be one of the required roles.
 */
final class Roles
{
    /** @var array<string, int> role => rank (higher is more powerful) */
    private readonly array $ranks;

    /**
     * @param  list<string>  $roles  highest first
     */
    public function __construct(
        array $roles,
        private readonly bool $hierarchical = true,
    ) {
        $normalized = [];

        foreach (array_values($roles) as $index => $role) {
            $role = self::normalize($role);

            if ($role === null) {
                throw new InvalidArgumentException('Role names must be non-empty lowercase strings.');
            }

            $normalized[$role] = count($roles) - $index;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('At least one role must be configured.');
        }

        $this->ranks = $normalized;
    }

    public function isKnown(string $role): bool
    {
        $normalized = self::normalize($role);

        return $normalized !== null && isset($this->ranks[$normalized]);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->ranks);
    }

    public function highest(): string
    {
        return array_key_first($this->ranks) ?? throw new InvalidArgumentException('No roles configured.');
    }

    /**
     * @param  list<string>  $required
     */
    public function satisfies(?string $actual, array $required): bool
    {
        $actual = self::normalize($actual);

        if ($actual === null || ! isset($this->ranks[$actual])) {
            return false;
        }

        foreach ($required as $role) {
            $role = self::normalize($role);

            if ($role === null || ! isset($this->ranks[$role])) {
                continue;
            }

            if ($actual === $role) {
                return true;
            }

            if ($this->hierarchical && $this->ranks[$actual] > $this->ranks[$role]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function parse(string $roles): array
    {
        $parsed = [];

        foreach (explode(',', $roles) as $role) {
            $normalized = self::normalize($role);

            if ($normalized !== null) {
                $parsed[] = $normalized;
            }
        }

        return array_values(array_unique($parsed));
    }

    public static function normalize(?string $role): ?string
    {
        if ($role === null) {
            return null;
        }

        $role = strtolower(trim($role));

        return $role === '' ? null : $role;
    }
}
