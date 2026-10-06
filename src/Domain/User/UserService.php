<?php

declare(strict_types=1);

namespace Casamiza\Domain\User;

use Casamiza\Domain\Tenant\Tenant;
use Casamiza\Domain\Tenant\TenantRepositoryInterface;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\TenantContext;

final readonly class UserService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private TenantRepositoryInterface $tenants,
        private TenantContext $tenant,
    ) {}

    /** @return array{User, Tenant} */
    public function profile(string $userId): array
    {
        $user = $this->users->find($userId) ?? throw new NotFoundException('user_not_found');
        $tenant = $this->tenants->find($this->tenant->id()) ?? throw new NotFoundException('tenant_not_found');

        return [$user, $tenant];
    }

    /** @return array{User, Tenant} */
    public function updateProfile(string $userId, ?string $firstName, ?string $lastName): array
    {
        [$user, $tenant] = $this->profile($userId);
        $updated = $user->withName($firstName ?? $user->firstName, $lastName ?? $user->lastName);
        $this->users->updateName($userId, $updated->firstName, $updated->lastName);

        return [$updated, $tenant];
    }
}
