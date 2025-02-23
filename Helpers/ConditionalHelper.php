<?php

namespace Ibinet\Helpers;

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
}