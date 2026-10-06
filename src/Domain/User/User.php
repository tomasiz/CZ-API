<?php

declare(strict_types=1);

namespace Casamiza\Domain\User;

use DateTimeImmutable;

final readonly class User
{
    /**
     * @param non-empty-string $id
     * @param string|null $passwordHash null tant qu'un membre invité n'a pas activé son compte
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $passwordHash,
        public Role $role,
        public ?DateTimeImmutable $lastLoginAt = null,
        public UserStatus $status = UserStatus::Active,
    ) {}

    public function withName(string $firstName, string $lastName): self
    {
        return new self($this->id, $this->tenantId, $firstName, $lastName, $this->email, $this->passwordHash, $this->role, $this->lastLoginAt, $this->status);
    }

    public function withRole(Role $role): self
    {
        return new self($this->id, $this->tenantId, $this->firstName, $this->lastName, $this->email, $this->passwordHash, $role, $this->lastLoginAt, $this->status);
    }
}
