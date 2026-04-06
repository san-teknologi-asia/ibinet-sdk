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
        // New dynamic assignment source: explicit project type.
        $typedProjectManager = UserProject::where('user_id', $userId)
            ->where('project_id', $projectId)
            ->where('type', UserProject::TYPE_PROJECT_MANAGER)
            ->exists();

        if ($typedProjectManager) {
            return true;
        }

        // Backward compatibility for legacy records without type.
        $user = User::with('role')->find($userId);

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

    /**
     * Check if user can assign technician for a ticket
     * User must be one of:
     * 1. Project Manager for this specific project (via user_projects)
     * 2. Supervisor role
     * 3. Coordinator role
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
        $isTechnicianRole = strpos($roleName, 'technician') !== false || strpos($roleName, 'engineering') !== false;
        if ($isTechnicianRole) {
            return false;
        }

        // Determine PM role(s) dynamically from project assignment type.
        $projectManagerUserIds = UserProject::where('project_id', $projectId)
            ->where('type', UserProject::TYPE_PROJECT_MANAGER)
            ->pluck('user_id')
            ->unique()
            ->values();

        $projectManagerRoleIds = User::whereIn('id', $projectManagerUserIds)
            ->pluck('role_id')
            ->unique()
            ->values();

        // Backward compatibility if type has not been populated yet.
        if ($projectManagerRoleIds->isEmpty()) {
            $projectManagerRoleIds = User::query()
                ->with('role:id,name')
                ->whereHas('project', function ($query) use ($projectId) {
                    $query->where('projects.id', $projectId);
                })
                ->get()
                ->filter(function ($projectUser) {
                    $roleName = strtolower($projectUser->role->name ?? '');
                    return strpos($roleName, 'project manager') !== false
                        || (bool) preg_match('/\bpm\b/', $roleName);
                })
                ->pluck('role_id')
                ->unique()
                ->values();
        }

        if ($projectManagerRoleIds->isEmpty()) {
            return false;
        }

        // User with PM role for this project can assign.
        if ($projectManagerRoleIds->contains($userRoleId)) {
            return true;
        }

        // User with any descendant role under project PM can assign (dynamic hierarchy).
        $allowedRoleIds = collect();
        foreach ($projectManagerRoleIds as $pmRoleId) {
            $pmRole = Role::find($pmRoleId);
            if (!$pmRole) {
                continue;
            }

            $childRoleIds = collect($pmRole->childrenRoles())->pluck('id');
            $allowedRoleIds = $allowedRoleIds->merge($childRoleIds);
        }

        return $allowedRoleIds->unique()->contains($userRoleId);
    }
}