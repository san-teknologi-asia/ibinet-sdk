<?php

namespace Ibinet\Helpers;

use Ibinet\Models\UserProject;

class ConditionalHelper{
    public static function checkHelpdeskDoneStatus($field)
    {
        if ($field == 'DONE' || $field == 'PENDING WITH PROBLEM' || $field == 'DISMANTLE') {
            return true;
        } else {
            return false;
        }
    }

    public static function checkSuperAdminRole($role)
    {
        if ($role == env('ROLE_SUPER_ADMIN')) {
            return true;
        } else{
            return false;
        }
    }

    public static function checkAdminRole($role)
    {
        if ($role == env('ROLE_ADMIN')) {
            return true;
        } else{
            return false;
        }
    }

    public static function checkHelpdeskRole($role)
    {
        if ($role == env('ROLE_HELPDESK')) {
            return true;
        } else{
            return false;
        }
    }

    public static function checkAdminSupervisorRole($role)
    {
        if ($role == env('ROLE_SUPERVISOR_ADMIN')) {
            return true;
        } else{
            return false;
        }
    }

    public static function checkHelpdeskSupervisorRole($role)
    {
        if ($role == env('ROLE_SUPERVISOR_HELPDESK')) {
            return true;
        } else{
            return false;
        }
    }

    public static function doneStatusArray()
    {
        return ['DONE', 'PENDING WITH PROBLEM', 'DISMANTLE'];
    }

    /**
     * Check if user is a project manager for a specific project
     * User must:
     * 1. Have a role containing "Project Manager" or "PM"
     * 2. Be assigned to the project via user_projects table
     *
     * @param string $userId
     * @param string $projectId
     * @return bool
     */
    public static function isProjectManagerForProject($userId, $projectId)
    {
        $user = \Ibinet\Models\User::with('role')->find($userId);

        if (!$user || !$user->role) {
            return false;
        }

        // Check if user has Project Manager role
        $roleName = strtolower($user->role->name ?? '');
        $isPmRole = strpos($roleName, 'project manager') !== false || strpos($roleName, 'pm') !== false;

        if (!$isPmRole) {
            return false;
        }

        // Check if user is assigned to this project
        $userProject = UserProject::where('user_id', $userId)
            ->where('project_id', $projectId)
            ->exists();

        return $userProject;
    }
}