<?php
declare(strict_types=1);

/**
 * What each role may do.
 *
 * A single login is not enough on its own. A login grants the whole of api.php,
 * and several of its actions are not safe in an employee's hands: ?action=start
 * and ?action=enrich spend real money at Apify, ?action=dump returns the entire
 * dataset, and ?action=migrate and ?action=reset-cursor change how the importer
 * behaves.
 *
 * The allowlist below is therefore positive and closed. An action that is not
 * named is denied to the operator role. That direction matters: an action added
 * to api.php next month is denied by default rather than exposed by having been
 * forgotten here.
 */
final class Acl
{
    /**
     * The only api.php actions the operator role may call.
     *
     * accounts-search and accounts-facets read the queue and populate the
     * filters. accounts-bulk marks accounts, subject to the extra limits in
     * operatorBulkLimits() below. queue-stats is read-only and lets the employee
     * see how much of the queue is left, which is the one number they need to
     * judge their own progress.
     */
    private const OPERATOR_ACTIONS = [
        'accounts-search',
        'accounts-facets',
        'accounts-bulk',
        'queue-stats',
    ];

    /**
     * The only pages the operator role may open.
     */
    private const OPERATOR_PAGES = [
        'accounts.php',
        'login.php',
    ];

    /**
     * May this role call this api.php action?
     */
    public static function allowsAction(string $role, string $action): bool
    {
        if ($role === Auth::ROLE_ADMIN) {
            return true;
        }

        if ($role === Auth::ROLE_OPERATOR) {
            return in_array($action, self::OPERATOR_ACTIONS, true);
        }

        return false;
    }

    /**
     * May this role open this page? $page is a bare file name such as
     * 'accounts.php'.
     */
    public static function allowsPage(string $role, string $page): bool
    {
        if ($role === Auth::ROLE_ADMIN) {
            return true;
        }

        if ($role === Auth::ROLE_OPERATOR) {
            return in_array($page, self::OPERATOR_PAGES, true);
        }

        return false;
    }

    /**
     * Extra limits applied to accounts-bulk for the operator role, beyond the
     * allowlist above. Returns null for a role that is not limited.
     *
     * Two limits, for two different reasons.
     *
     * operations restricts the operator to skip and revert. Those are the two
     * operations AccountsBrowser::bulk already implements, so this is currently
     * a restatement rather than a restriction. It is written down anyway,
     * because a third operation added to bulk() later would otherwise become
     * available to the operator the moment it existed.
     *
     * max_rows caps one bulk operation at 500 rows. Without it a select-all with
     * no filter applied marks the entire queue of roughly 10,800 accounts as
     * skipped in one request, and the only evidence that it happened would be
     * the follow script quietly running out of work.
     */
    public static function bulkLimits(string $role): ?array
    {
        if ($role !== Auth::ROLE_OPERATOR) {
            return null;
        }

        return [
            'operations' => ['skip', 'revert'],
            'max_rows'   => (int) Config::get('auth.operator_bulk_max_rows', 500),
        ];
    }
}
