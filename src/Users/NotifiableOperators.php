<?php

declare(strict_types=1);

namespace Magna\Seo\Users;

use Illuminate\Support\Collection;
use Magna\Users\User;
use Magna\Users\UserStatus;

/**
 * Finds the people who should hear about an SEO finding.
 *
 * Membership is decided by asking the authorization layer rather than by
 * querying role tables directly, so super admins, wildcard grants and any future
 * change to how permissions resolve are all honoured without this class knowing
 * how any of it works.
 *
 * Capped, because a notification fan-out is not worth an unbounded query on a
 * site with fifty thousand accounts — and a site with that many accounts does
 * not want fifty thousand copies of an SEO warning either.
 */
final class NotifiableOperators
{
    public function __construct(private readonly int $limit = 50) {}

    /**
     * @return Collection<int, User>
     */
    public function withPermission(string $permission): Collection
    {
        /** @var Collection<int, User> $candidates */
        $candidates = User::query()
            ->where('status', UserStatus::Active->value)
            ->orderBy('id')
            ->limit($this->limit * 4)
            ->get();

        return $candidates
            ->filter(static fn (User $user): bool => $user->can($permission))
            ->take($this->limit)
            ->values();
    }
}
