<?php

namespace Ibinet\Helpers;

use Ibinet\Models\Role;
use Ibinet\Models\User;
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
     * Check if user is a project manager for a specific project.
     * Requires explicit user_project assignment with type = PROJECT_MANAGER.
     *
     * @param string $userId
     * @param string $projectId
     * @return bool
     */
    public static function isProjectManagerForProject($userId, $projectId)
    {
        return UserProject::where('user_id', $userId)
            ->where('project_id', $projectId)
            ->where('type', UserProject::TYPE_PROJECT_MANAGER)
            ->exists();
    }

    /**
     * Check if user can assign technician for a ticket.
     * User must be:
     * 1. Assigned to the project, and
     * 2. Have a role that is either the PM role on this project or any descendant role
     *    under that PM in the role hierarchy, and
     * 3. NOT be a technician/engineering role.
     *
     * @param string $userId
     * @param string $projectId
     * @return bool
     */
    public static function canAssignTechnician($userId, $projectId)
    {
        $user = User::with('role')->find($userId);

        if (!$user || !$user->role) {
            return false;
        }

        // Must be assigned to this project first.
        $isAssignedToProject = UserProject::where('user_id', $userId)
            ->where('project_id', $projectId)
            ->exists();

        if (!$isAssignedToProject) {
            return false;
        }

        $roleName = strtolower($user->role->name ?? '');
        $userRoleId = $user->role_id;

        // Explicitly prevent technician/engineering role from assigning.
        $isTechnicianRole = strpos($roleName, 'technician') !== false
            || strpos($roleName, 'engineering') !== false;
        if ($isTechnicianRole) {
            return false;
        }

        // Determine PM role(s) dynamically from project assignment type.
        $projectManagerUserIds = UserProject::where('project_id', $projectId)
            ->where('type', UserProject::TYPE_PROJECT_MANAGER)
            ->pluck('user_id')
            ->unique()
            ->values();

        if ($projectManagerUserIds->isEmpty()) {
            return false;
        }

        $projectManagerRoleIds = User::whereIn('id', $projectManagerUserIds)
            ->pluck('role_id')
            ->unique()
            ->values();

        // User with PM role for this project can assign.
        if ($projectManagerRoleIds->contains($userRoleId)) {
            return true;
        }

        // User with any descendant role under project PM can assign (dynamic hierarchy).
        $allowedRoleIds = [];
        foreach ($projectManagerRoleIds as $pmRoleId) {
            $pmRole = Role::find($pmRoleId);
            if (!$pmRole) {
                continue;
            }

            $children = $pmRole->childrenRoles();
            foreach ($children as $child) {
                $allowedRoleIds[] = $child->id;
            }
        }

        return in_array($userRoleId, array_unique($allowedRoleIds), true);
    }
}