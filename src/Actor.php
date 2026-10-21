<?php

declare(strict_types=1);

namespace VastsverigeEvents;

/**
 * Runs imports as a user who can create events on this site.
 */
class Actor
{
    /**
     * Set the current user to an administrator, or the first super admin.
     */
    public static function set(): bool
    {
        $users = get_users(
            [
                'role'   => 'administrator',
                'number' => 1,
                'fields' => 'ID',
            ]
        );
        $userId = isset($users[0]) ? (int) $users[0] : 0;
        if ($userId === 0 && is_multisite()) {
            $superAdmins = get_super_admins();
            $login       = $superAdmins[0] ?? '';
            $user        = $login !== '' ? get_user_by('login', $login) : false;
            $userId      = $user instanceof \WP_User ? (int) $user->ID : 0;
        }
        if ($userId === 0 || ! get_userdata($userId)) {
            return false;
        }

        wp_set_current_user($userId);
        return true;
    }
}
