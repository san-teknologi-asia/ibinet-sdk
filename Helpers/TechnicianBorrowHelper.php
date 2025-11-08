<?php

namespace Ibinet\Helpers;

use Ibinet\Models\TechnicianBorrow;
use Ibinet\Models\TechnicianBorrowRemote;
use Ibinet\Models\User;
use Ibinet\Models\UserProject;

class TechnicianBorrowHelper
{
    /**
     * Generate borrowing code
     * Format: TBR-YYYYMMDD-XXXX
     *
     * @return String
     */
    public static function generateBorrowCode()
    {
        $borrowCount = TechnicianBorrow::whereDate('created_at', date('Y-m-d'))
            ->count();

        $borrowCode = "TBR-" . date('Ymd') . "-" . str_pad($borrowCount + 1, 4, '0', STR_PAD_LEFT);

        return $borrowCode;
    }

    /**
     * Check if technician can be borrowed
     *
     * @param String $technician_id
     * @return Boolean
     */
    public static function canBorrow($technician_id)
    {
        $activeBorrow = TechnicianBorrow::where('technician_id', $technician_id)
            ->whereIn('status', ['APPROVED', 'IN_PROGRESS'])
            ->first();

        return $activeBorrow == null;
    }

    /**
     * Get available technicians from a project
     *
     * @param String $project_id
     * @return Collection
     */
    public static function getAvailableTechnicians($project_id)
    {
        // Get all technicians in the project
        $projectTechnicians = User::whereHas('project', function($query) use ($project_id) {
                $query->where('project_id', $project_id);
            })
            ->whereHas('role', function($query) {
                $query->where('name', 'LIKE', '%Technician%')
                    ->orWhere('name', 'LIKE', '%Teknisi%');
            })
            ->get();

        // Filter out those currently borrowed
        $availableTechnicians = $projectTechnicians->filter(function($technician) {
            return self::canBorrow($technician->id);
        });

        return $availableTechnicians;
    }

    /**
     * Calculate total estimated cost for borrow remotes
     * Since pricing fields are removed, returns a default value
     *
     * @param Array $remotes
     * @return Integer
     */
    public static function calculateEstimatedCost($remotes)
    {
        // Return default cost since we removed pricing fields
        return count($remotes) * 10000; // 10k per remote as default
    }

    /**
     * Calculate total actual cost for borrow remotes
     * Since pricing fields are removed, returns a default value
     *
     * @param String $borrow_id
     * @return Integer
     */
    public static function calculateActualCost($borrow_id)
    {
        // Return default cost based on remote count
        $remoteCount = TechnicianBorrowRemote::where('technician_borrow_id', $borrow_id)
            ->where('is_removed', false)
            ->count();
        
        return $remoteCount * 10000; // 10k per remote as default
    }

    /**
     * Check if user is project manager
     *
     * @param String $user_id
     * @return Boolean
     */
    public static function isProjectManager($user_id)
    {
        $user = User::with('role')->find($user_id);
        
        if (!$user || !$user->role) {
            return false;
        }
        
        return stripos($user->role->name, 'Project Manager') !== false 
            || stripos($user->role->name, 'PM') !== false;
    }

    /**
     * Check if user manages a specific project
     *
     * @param String $user_id
     * @param String $project_id
     * @return Boolean
     */
    public static function managesProject($user_id, $project_id)
    {
        $userProject = UserProject::where('user_id', $user_id)
            ->where('project_id', $project_id)
            ->exists();
        
        return $userProject && self::isProjectManager($user_id);
    }

    /**
     * Get borrow status label with color
     *
     * @param String $status
     * @return Array
     */
    public static function getStatusLabel($status)
    {
        $labels = [
            'DRAFT' => ['label' => 'Draft', 'color' => 'secondary'],
            'PENDING_LENDER_APPROVAL' => ['label' => 'Pending Lender Approval', 'color' => 'warning'],
            'PENDING_BORROWER_APPROVAL' => ['label' => 'Pending Borrower Approval', 'color' => 'warning'],
            'APPROVED' => ['label' => 'Approved', 'color' => 'success'],
            'IN_PROGRESS' => ['label' => 'In Progress', 'color' => 'primary'],
            'COMPLETED' => ['label' => 'Completed', 'color' => 'info'],
            'CANCELLED' => ['label' => 'Cancelled', 'color' => 'dark'],
            'REJECTED' => ['label' => 'Rejected', 'color' => 'danger'],
        ];

        return $labels[$status] ?? ['label' => $status, 'color' => 'secondary'];
    }

    /**
     * Get remote status label with color
     *
     * @param String $status
     * @return Array
     */
    public static function getRemoteStatusLabel($status)
    {
        $labels = [
            'PENDING' => ['label' => 'Pending', 'color' => 'secondary'],
            'IN_PROGRESS' => ['label' => 'In Progress', 'color' => 'primary'],
            'COMPLETED' => ['label' => 'Completed', 'color' => 'success'],
            'CANCELLED' => ['label' => 'Cancelled', 'color' => 'danger'],
        ];

        return $labels[$status] ?? ['label' => $status, 'color' => 'secondary'];
    }

    /**
     * Validate borrow dates
     *
     * @param String $start_date
     * @param String $end_date
     * @return Array
     */
    public static function validateDates($start_date, $end_date)
    {
        $start = strtotime($start_date);
        $end = strtotime($end_date);
        $today = strtotime(date('Y-m-d'));

        if ($start < $today) {
            return [
                'valid' => false,
                'message' => 'Start date cannot be in the past'
            ];
        }

        if ($end <= $start) {
            return [
                'valid' => false,
                'message' => 'End date must be after start date'
            ];
        }

        return [
            'valid' => true,
            'message' => 'Dates are valid'
        ];
    }

    /**
     * Calculate duration in days
     *
     * @param String $start_date
     * @param String $end_date
     * @return Integer
     */
    public static function calculateDuration($start_date, $end_date)
    {
        $start = strtotime($start_date);
        $end = strtotime($end_date);
        
        return ceil(($end - $start) / (60 * 60 * 24));
    }
}
