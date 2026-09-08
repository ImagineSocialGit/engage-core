<?php

namespace App\Modules\Core\Support\Contacts;

use App\Models\User;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Contracts\Contacts\ContactResultActionContributor;
use App\Modules\Core\Data\Contacts\ContactResultAction;
use InvalidArgumentException;

final class ContactResultActionRegistry
{
    public const CONTRIBUTOR_TAG = 'core.contact_result_action_contributors';

    /** @var array<string, ContactResultAction> */
    private array $actions = [];

    /**
     * @param iterable<int, ContactResultActionContributor> $contributors
     */
    public function __construct(
        iterable $contributors,
        private readonly UserAccessService $access,
    ) {
        foreach ($contributors as $contributor) {
            if (! $contributor instanceof ContactResultActionContributor) {
                throw new InvalidArgumentException(
                    'Contact result action contributors must implement ContactResultActionContributor.',
                );
            }

            foreach ($contributor->actions() as $action) {
                if (! $action instanceof ContactResultAction) {
                    throw new InvalidArgumentException(
                        'Contact result action contributors must return ContactResultAction instances.',
                    );
                }

                if (isset($this->actions[$action->key])) {
                    throw new InvalidArgumentException(
                        "Contact result action [{$action->key}] is registered more than once.",
                    );
                }

                $this->actions[$action->key] = $action;
            }
        }
    }

    /**
     * @return array<int, ContactResultAction>
     */
    public function actionsFor(User $user): array
    {
        $actions = array_values(array_filter(
            $this->actions,
            fn (ContactResultAction $action): bool => $this->access->allows($user, $action->capability),
        ));

        usort(
            $actions,
            static fn (ContactResultAction $left, ContactResultAction $right): int => [
                $left->sort,
                $left->label,
                $left->key,
            ] <=> [
                $right->sort,
                $right->label,
                $right->key,
            ],
        );

        return $actions;
    }
}