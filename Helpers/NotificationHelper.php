<?php

namespace Ibinet\Helpers;

use Ibinet\Models\Notification;
use Ibinet\Models\User;
use Ibinet\Models\UserDevice;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Ramsey\Uuid\Uuid;

class NotificationHelper
{
    /**
     * Send notification
     *
     * @param string $title title of message
     * @param string $body body or content message
     * @param array $data Required keys: user_id, expense_report_id, transaction_id
     */
    public static function send($title, $body, $data)
    {
        if (empty($data['user_id'])) {
            Log::channel('notification')->warning('NotificationHelper::send called without user_id', ['data' => $data]);
            return ['error' => 'user_id is required'];
        }

        $notificationId = Notification::create([
            'user_id'              => $data['user_id'],
            'title'                => $title,
            'body'                 => $body,
            'expense_report_id'    => $data['expense_report_id'] ?? null,
            'transaction_id'       => $data['transaction_id'] ?? null,
            'approval_activity_id' => $data['approval_activity_id'] ?? null,
        ])->id;

        $data['notification_id'] = $notificationId;

        $userDevices = UserDevice::where('user_id', $data['user_id'])
            ->distinct('device_id')
            ->pluck('device_id')
            ->toArray();

        return self::sendNotification($userDevices, $data, $title, $body);
    }

    /**
     * Send notification by role
     *
     * @param String $title
     * @param String $body
     * @param array $data Required keys: role_id
     */
    public static function sendByRole($title, $body, $data)
    {
        if (empty($data['role_id'])) {
            Log::channel('notification')->warning('NotificationHelper::sendByRole called without role_id', ['data' => $data]);
            return ['error' => 'role_id is required'];
        }

        $user = User::where('role_id', $data['role_id'])->get();
        $userIds = $user->pluck('id')->toArray();

        if (empty($userIds)) {
            return ['error' => 'No users found for role'];
        }

        $userDevices = UserDevice::whereIn('user_id', $userIds)
            ->distinct('device_id')
            ->pluck('device_id')
            ->toArray();

        $timestamp = date('Y-m-d H:i:s');

        $notifications = [];
        foreach ($userIds as $item) {
            $notifications[] = [
                'id'                   => (string) Uuid::uuid4(),
                'user_id'              => $item,
                'title'                => $title,
                'body'                 => $body,
                'expense_report_id'    => $data['expense_report_id'] ?? null,
                'transaction_id'       => $data['transaction_id'] ?? null,
                'approval_activity_id' => $data['approval_activity_id'] ?? null,
                'is_read'              => 0,
                'created_at'           => $timestamp,
                'updated_at'           => $timestamp
            ];
        }

        Notification::insert($notifications);

        return self::sendNotification($userDevices, $data, $title, $body);
    }

    /**
     * Send notification
     *
     * @param array $deviceTokens
     * @param array $data_arr
     * @param string $title
     * @param string $body
     * @return array
     */
    public static function sendNotification($deviceTokens, $data_arr, $title, $body)
    {
        $deviceTokens = array_filter($deviceTokens ?? [], fn($t) => !empty($t));

        if (empty($deviceTokens)) {
            return ['error' => 'No device tokens provided'];
        }

        $factory = (new Factory)->withServiceAccount(storage_path(env('FIREBASE_CREDENTIALS')));
        $messaging = $factory->createMessaging();

        $message = CloudMessage::new()->fromArray([
            "data" => [
                "title"                => $title,
                "body"                 => $body,
                "image_url"            => null,
                "expense_report_id"    => $data_arr['expense_report_id'] ?? null,
                "transaction_id"       => $data_arr['transaction_id'] ?? null,
                "approval_activity_id" => $data_arr['approval_activity_id'] ?? null,
                "notification_id"      => $data_arr['notification_id'] ?? null,
                "timestamp"            => now()->toDateTimeString(),
            ],
            "android" => [
                "priority" => 'high',
            ]
        ]);

        $sendReport = $messaging->sendMulticast($message, $deviceTokens);

        Log::channel('notification')->info('FCM Token: ' . json_encode($deviceTokens));
        Log::channel('notification')->info('Data: ' . json_encode($data_arr));
        Log::channel('notification')->info('Result: ' . json_encode($sendReport));

        return [
            'success_count' => $sendReport->successes()->count(),
            'failure_count' => $sendReport->failures()->count(),
            'errors'        => $sendReport->failures()->map(fn($failure) => $failure->error()->getMessage()),
        ];
    }
}
