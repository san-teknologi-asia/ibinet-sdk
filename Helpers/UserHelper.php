<?php

namespace Ibinet\Helpers;

use Ibinet\Models\UserProject;

class UserHelper
{

    public static function getUserRegionArray($auth)
    {
        if (!$auth) {
            return [];
        }

        $regions = [];
        foreach ($auth->region ?? [] as $region) {
            $regions[] = $region->id;
        }

        return $regions;
    }

    public static function getUserHomebaseArray($auth)
    {
        if (!$auth) {
            return [];
        }

        $homebases = [];
        foreach ($auth->homebase ?? [] as $homebase) {
            $homebases[] = $homebase->id;
        }

        return $homebases;
    }

    public static function getUserProjectArray($auth)
    {
        if (!$auth) {
            return [];
        }

        $projects = [];
        foreach ($auth->project ?? [] as $project) {
            $projects[] = $project->id;
        }

        return $projects;
    }

    /**
     * Get user projects by roles efficiently
     *
     * @param string $userId
     * @param array|string $modules
     * @return array
     */
    public static function getUserProjectByRoles($userId, $modules)
    {
        $modules = is_array($modules) ? $modules : [$modules];

        if (in_array('OMC', $modules)) {
            $rows = UserProject::where('user_id', $userId)
                ->where('type', UserProject::TYPE_HELPDESK)
                ->get();

            $userProjects = $rows->map(function ($row) {
                return $row->project_id;
            })->filter()->unique()->values()->toArray();
        } elseif (in_array('IFAS', $modules)) {
            $rows = UserProject::where('user_id', $userId)
                ->where('type', UserProject::TYPE_FINANCE)
                ->get();

            $userProjects = $rows->map(function ($row) {
                return $row->project_id;
            })->filter()->unique()->values()->toArray();
        } elseif (in_array('IBOS', $modules)) {
            $rows = UserProject::where('user_id', $userId)
                ->where('type', UserProject::TYPE_PROJECT_MANAGER)
                ->get();

            $userProjects = $rows->map(function ($row) {
                return $row->project_id;
            })->filter()->unique()->values()->toArray();
        } else {
            $userProjects = [];
        }

        return ['projects' => $userProjects];
    }
}
