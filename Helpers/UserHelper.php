<?php

function getUserRegionArray($auth)
{
    $regions = [];

    foreach ($auth->region as $region) {
        $regions[] = $region->id;
    }

    return $regions;
}

function getUserHomebaseArray($auth)
{
    $homebases = [];

    foreach ($auth->homebase as $homebase) {
        $homebases[] = $homebase->id;
    }

    return $homebases;
}

function getUserProjectArray($auth)
{
    $projects = [];

    foreach ($auth->project as $project) {
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
function getUserProjectByRoles($userId, $modules)
{
    $modules = is_array($modules) ? $modules : [$modules];

    $query = \Ibinet\Models\UserProject::where('user_id', $userId)
        ->where(function ($q) use ($modules) {
            if (in_array('OMC', $modules)) {
                $q->orWhere('project_id_helpdesk', '!=', null);
            }
            if (in_array('IFAS', $modules)) {
                $q->orWhere('project_id_finance', '!=', null);
            }
            if (in_array('IBOS', $modules)) {
                $q->orWhere('project_id', '!=', null);
            }
        });
    if (in_array('OMC', $modules)) {
       $userProjects = $query->pluck('project_id_helpdesk')->toArray();
    }elseif (in_array('IFAS', $modules)) {
       $userProjects = $query->pluck('project_id_finance')->toArray();
    }elseif (in_array('IBOS', $modules)) {
       $userProjects = $query->pluck('project_id')->toArray();
    }else{
        $userProjects = [];
    }
    return ['projects' => $userProjects];
}