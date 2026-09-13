<?php

namespace App\Traits;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

trait BelongsToHierarchy
{
    /** @var array<int, array<int, int>> */
    protected static array $subtreeUserIdsCache = [];

    /**
     * Restrict query to records owned by users in the viewer's subtree.
     *
     * @param  string|array<int, string>  $columns  User id column(s) to match against subtree ids.
     */
    public function scopeOwnedByHierarchy(Builder $query, User $viewer, string|array $columns = 'user_id'): Builder
    {
        if ($viewer->role === UserRole::Admin) {
            return $query;
        }

        $columns = (array) $columns;
        $userIds = static::subtreeUserIds($viewer);

        return $query->where(function (Builder $inner) use ($columns, $userIds): void {
            foreach ($columns as $column) {
                $inner->orWhereIn($column, $userIds);
            }
        });
    }

    /**
     * Restrict query to a single user id column within the viewer's subtree.
     */
    public function scopeForUserInHierarchy(Builder $query, User $viewer, string $column = 'user_id'): Builder
    {
        if ($viewer->role === UserRole::Admin) {
            return $query;
        }

        return $query->whereIn($column, static::subtreeUserIds($viewer));
    }

    /**
     * Collect all user ids visible within the given user's hierarchy subtree.
     *
     * @return array<int, int>
     */
    public static function subtreeUserIds(User $user): array
    {
        if (isset(static::$subtreeUserIdsCache[$user->id])) {
            return static::$subtreeUserIdsCache[$user->id];
        }

        return static::$subtreeUserIdsCache[$user->id] = static::collectSubtreeUserIds($user)->all();
    }

    /**
     * @return Collection<int, int>
     */
    protected static function collectSubtreeUserIds(User $user, array $visited = []): Collection
    {
        if (in_array($user->id, $visited, true)) {
            return collect();
        }

        $visited[] = $user->id;
        $ids = collect([$user->id]);

        if ($user->role === UserRole::Client) {
            return $ids;
        }

        $directChildren = User::query()
            ->where('parent_id', $user->id)
            ->pluck('id');

        foreach ($directChildren as $childId) {
            $child = User::query()->find($childId);

            if ($child !== null) {
                $ids = $ids->merge(static::collectSubtreeUserIds($child, $visited));
            }
        }

        return $ids->unique()->values();
    }
}
