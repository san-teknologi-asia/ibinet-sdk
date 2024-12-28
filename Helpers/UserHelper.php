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