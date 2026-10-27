<?php

declare(strict_types=1);

namespace Semitexa\Crud\Application\Payload\Request;

use Semitexa\Crud\Application\Service\Action\SendPasswordResetLink;
use Semitexa\Crud\Domain\Model\CrudAction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\PlatformUi\Application\Service\Access\UiPermissions;
use Semitexa\PlatformUi\Domain\Model\Field\Field;
use Semitexa\Platform\User\Application\Db\MySQL\Model\PlatformUserResourceModel;
use Semitexa\Platform\User\Auth\UserPrincipal;
use Semitexa\Platform\User\Domain\Enum\UserRole;

/**
 * The users of semitexa/platform-user as a screen: list, search, filter, and
 * edit a name, a role and whether the account may sign in; a row action mails
 * a password-reset link. A project declares it with its own route, layout and
 * permission:
 *
 *     #[AsCrud(id: 'admin.users', path: '/admin/users', model: PlatformUserResourceModel::class,
 *         label: 'User', permission: 'users', nav: 'Administration', icon: 'users')]
 *     final class UsersCrud extends PlatformUsersCrud {}
 *
 * What it holds is decided by who is signed in, never by the site the request
 * names (one session reaches several sites; HUG and KISS requests name none):
 * an owner holds every user; anyone else their own site's users, owners left
 * out; a visitor who is not a platform-user account, nothing. Nobody raises a
 * user above their own role, edits someone above it, or changes their own role
 * or status. An owner belongs to no site and stays one. Accounts are created
 * with `user:add` and switched off rather than deleted: the screen allows
 * neither.
 */
abstract class PlatformUsersCrud extends CrudDefinition
{
    /** No site id matches: tenant ids are letters, digits, '_' and '-'. */
    private const NOBODY = '#none';

    private const RANK = ['editor' => 1, 'admin' => 2, 'owner' => 3];

    public function fields(): array
    {
        return [
            Field::email('email')->readOnly()->searchable()->sortable(),
            Field::text('displayName')->label('Name')->set('max', 255)->searchable()->sortable(),
            Field::choice('role', [
                'owner' => ['label' => 'Owner', 'tone' => 'danger'],
                'admin' => ['label' => 'Admin', 'tone' => 'warning'],
                'editor' => ['label' => 'Editor', 'tone' => 'neutral'],
            ])->required()->filterable()->sortable(),
            Field::choice('status', [
                'active' => ['label' => 'Active', 'tone' => 'success'],
                'disabled' => ['label' => 'Disabled', 'tone' => 'neutral'],
            ])->required()->filterable()->sortable()->help('A disabled account cannot sign in; its sessions end on their next request.'),
            Field::text('tenantId')->label('Site')->readOnly()->filterable()->sortable(),
            Field::datetime('lastLoginAt')->label('Last sign-in')->readOnly()->sortable(),
            Field::datetime('createdAt')->label('Created')->readOnly()->sortable()->hideOnList(),
        ];
    }

    public function query(ResourceModelQuery $query): ResourceModelQuery
    {
        $visitor = self::visitor();
        if ($visitor === null) {
            return $query->where(PlatformUserResourceModel::column('tenantId'), Operator::Equals, self::NOBODY);
        }
        if ($visitor->isOwner()) {
            return $query;
        }

        return $query
            ->where(PlatformUserResourceModel::column('tenantId'), Operator::Equals, $visitor->getTenantId() !== '' ? $visitor->getTenantId() : self::NOBODY)
            ->where(PlatformUserResourceModel::column('role'), Operator::NotEquals, UserRole::Owner->value);
    }

    /**
     * Editing is offered only to someone who may change users: the form, its
     * save and the reset-link row action (an 'edit' of the screen) all ask
     * this, so an editor holding users.edit sees no form that every save
     * refuses, and mails no reset link to an admin above them.
     */
    public function allows(string $operation): bool
    {
        return $operation === 'edit' && (self::visitor()?->user->getRole()->canManageUsers() ?? false);
    }

    public function actions(): array
    {
        return [
            CrudAction::of('send-reset-link', 'Send password reset link', SendPasswordResetLink::class)
                ->onRows()
                ->confirm('Mail this user a link to choose a new password?'),
        ];
    }

    public function check(array $values, ?object $existing): array
    {
        $visitor = self::visitor();
        if ($existing === null) {
            return ['email' => 'Create accounts with bin/semitexa user:add: the password is chosen there.'];
        }
        if ($visitor === null || !$visitor->user->getRole()->canManageUsers()) {
            return ['role' => 'Only an owner or an admin changes users.'];
        }
        $mine = self::RANK[$visitor->user->getRole()->value] ?? 0;
        $theirs = self::RANK[(string) $existing->role] ?? 0;
        $wanted = self::RANK[(string) ($values['role'] ?? $existing->role)] ?? 0;
        $wantedRole = (string) ($values['role'] ?? $existing->role);

        if ((string) $existing->id === $visitor->getId()
            && ($wantedRole !== (string) $existing->role || (string) ($values['status'] ?? $existing->status) !== (string) $existing->status)) {
            return ['role' => 'Your own role and status are changed by another administrator, or with bin/semitexa.'];
        }
        if ($theirs > $mine) {
            return ['role' => 'This user\'s role is above yours.'];
        }
        if ($wanted > $mine) {
            return ['role' => 'You cannot give a role above your own.'];
        }
        // An owner administers every site and belongs to none: one bound to a
        // site, or a site-less admin, is a misconfiguration every check reads
        // differently. Asked only of a role being changed: an owner bound to a
        // site, from an install made before user:add refused one (SiteAccess
        // keeps them), was refused every save, a new name included.
        if ($wantedRole !== (string) $existing->role && ($wantedRole === UserRole::Owner->value) !== ((string) $existing->tenantId === '')) {
            return ['role' => (string) $existing->tenantId === ''
                ? 'An account of no site is an owner; to step one down, use bin/semitexa.'
                : 'An owner belongs to no site: make one with bin/semitexa user:add --role=owner.'];
        }

        return [];
    }

    /** The signed-in platform-user account; null for anyone else. */
    private static function visitor(): ?UserPrincipal
    {
        $visitor = UiPermissions::visitor();

        return $visitor instanceof UserPrincipal ? $visitor : null;
    }
}
