<?php

namespace Ibinet\Helpers;

use Ibinet\Models\Notification;
use Ibinet\Models\User;
use Ibinet\Models\UserDevice;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
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

        self::sendNotification($userDevices, $data, $title, $body);
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

        self::sendNotification($userDevices, $data, $title, $body);
    }

    /**
     * Send notification
     *
     * @param array $deviceTokens
     * @param array $data_arr
     * @param string $title
     * @param string $message
     */
    public static function sendNotification($deviceTokens, $data_arr, $title, $message)
    {
        // remove array value null
        $deviceTokens = array_filter($deviceTokens);

        if (empty($deviceTokens)) {
            return;
        }

        $factory = (new Factory)->withServiceAccount(storage_path(env('FIREBASE_CREDENTIALS')));

        $messaging = $factory->createMessaging();

        $config = AndroidConfig::fromArray([
            'priority' => 'high',
            'notification' => [
                'notification_priority' => 'PRIORITY_MAX'
            ],
        ]);

        $apnsConfig = ApnsConfig::fromArray([
            'headers' => [
                'apns-priority' => '10',
                'apns-push-type' => 'alert'
            ],
            'payload' => [
                'aps' => [
                    'alert' => [
                        'title' => $title,
                        'body' => $message,
                    ],
                    'sound' => 'NOTIF_BAIK_IOS.wav',
                    'mutable-content' => 1,
                    'content-available' => 1,
                    'category' => 'CONFIRMATION_CATEGORY',
                ],
            ],
        ]);

        $data_arr['title'] = $title;
        $data_arr['message'] = $message;

        // $message = new RawMessageFromArray([
        //     'data' => [
        //         "data_arr" => json_encode($data_arr)
        //     ],
        //     "android" => [
        //         "priority" => "high"
        //     ]
        // ]);

        $message = CloudMessage::new()
            ->withNotification(FirebaseNotification::create($title, $message))
            ->withData($data_arr);
        $message = $message->withAndroidConfig($config);

        // Save full log from fcm token until data
        Log::channel('notification')->info('FCM Token: ' . json_encode($deviceTokens));
        Log::channel('notification')->info('Data: ' . json_encode($data_arr));

        $messaging->sendMulticast($message, $deviceTokens);

        return true;
    }
}
