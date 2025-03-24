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
     * @param string $title title of message
     * @param string $body body or content message
     * @param string $token user token to send notif
     */
    public static function send($title, $body, $data)
    {
        Notification::create([
            'user_id'           => $data['user_id'],
            'title'             => $title,
            'body'              => $body,
            'expense_report_id' => $data['expense_report_id'],
            'transaction_id'    => $data['transaction_id']
        ]);

        $userDevices = UserDevice::where('user_id', $data['user_id'])->pluck('device_id')->toArray();

        return self::sendNotification($userDevices, $data, $title, $body);
    }

    /**
     * Send notification by role
     *
     * @param String $title
     * @param String $body
     * @param array $data
     */
    public static function sendByRole($title, $body, $data)
    {
        $user = User::where('role_id', $data['role_id'])->get();
        $userIds = $user->pluck('id')->toArray();
        $userDevices = UserDevice::whereIn('user_id', $userIds)->pluck('device_id')->toArray();

        $timestamp = date('Y-m-d H:i:s');

        $notifications = [];
        foreach ($userIds as $item) {
            $notifications[] = [
                'id'                => (string) Uuid::uuid4(),
                'user_id'           => $item,
                'title'             => $title,
                'body'              => $body,
                'expense_report_id' => $data['expense_report_id'],
                'transaction_id'    => $data['transaction_id'],
                'is_read'           => 0,
                'created_at'        => $timestamp,
                'updated_at'        => $timestamp
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
        // Remove null values from device tokens
        $deviceTokens = array_filter($deviceTokens);

        if (empty($deviceTokens)) {
            return ['error' => 'No device tokens provided'];
        }

        $factory = (new Factory)->withServiceAccount(storage_path(env('FIREBASE_CREDENTIALS')));
        $messaging = $factory->createMessaging();

        $message = CloudMessage::new()->fromArray([
            "data" => [
                "title"             => $title,
                "body"              => $body,
                "image_url"         => null,
                "expense_report_id" => $data_arr['expense_report_id'],
                "transaction_id"    => $data_arr['transaction_id'],
                "timestamp"         => now()->toDateTimeString(),
            ],
            "android" => [
                "priority" => 'high',
            ]
        ]);

        // Send Multicast Notification
        $sendReport = $messaging->sendMulticast($message, $deviceTokens);

        // Save full log from fcm token until data
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
