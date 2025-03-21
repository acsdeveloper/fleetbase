<?php

namespace App\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController as BaseOrderController;
use Illuminate\Http\Request;
use Fleetbase\FleetOps\Events\OrderDispatchFailed;
use Fleetbase\FleetOps\Events\OrderReady;
use Fleetbase\FleetOps\Events\OrderStarted;
use Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Http\Requests\CreateOrderRequest;
use Fleetbase\FleetOps\Http\Requests\ScheduleOrderRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateOrderRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Http\Resources\v1\Proof as ProofResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Company;
use Fleetbase\Models\File;
use Fleetbase\Models\Setting;
use Fleetbase\Support\Auth;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderRejectionMail;
use App\Models\User;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\TrackingStatusController;
use Fleetbase\FleetOps\Models\TrackingStatus;

class CustomOrderController extends BaseOrderController
{
   
    /**
     * Example of overriding an existing method
     * Make sure to match the parent method signature
     */
    public function query(Request $request)
    {
        // Your custom implementation here
        // Or call parent method with modifications:
        return "welcome";
    }

    /**
     * Example of adding a new method
     */
    public function customEndpoint()
    {
        // Your custom implementation here
    }
    public function start(Request $request)
    {
        /**
         * @var Order
         */
        $order = Order::where('uuid', $request->input('order'))->withoutGlobalScopes()->first();

        if (!$order) {
            return response()->error('Unable to find order to start.');
        }

        if ($order->started) {
            return response()->error('Order has already been started.');
        }

        /**
         * @var Driver
         */
        $driver = Driver::where('uuid', $order->driver_assigned_uuid)->withoutGlobalScopes()->first();

        /**
         * @var Payload
         */
        $payload = Payload::where('uuid', $order->payload_uuid)->withoutGlobalScopes()->with(['waypoints', 'waypointMarkers', 'entities'])->first();

        if (!$driver) {
            return response()->error('No driver assigned to order.');
        }

        // set order to started
        $order->started    = true;
        $order->started_at = now();
        $order->save();

        // trigger start event
        event(new OrderStarted($order));

        // set order as drivers current order
        $driver->current_job_uuid = $order->uuid;
        $driver->save();

        // get the next order activity
        $flow = $activity = $order->config()->nextFirstActivity();

        /**
         * @var \Fleetbase\LaravelMysqlSpatial\Types\Point
         */
        $location = $order->getLastLocation();

        // if multi drop order set first destination
        if ($payload->isMultipleDropOrder) {
            $firstDestination = $payload->waypoints->first();

            if ($firstDestination) {
                $payload->current_waypoint_uuid = $firstDestination->uuid;
                $payload->save();
            }

            // update activity for each waypoint and entity
            foreach ($payload->waypointMarkers as $waypointMarker) {
                $waypointMarker->insertActivity($activity, $location);
            }

            foreach ($payload->entities as $entity) {
                $entity->insertActivity($activity, $location);
            }
        }

        // update order activity
        $updateActivityRequest = new Request(['activity' => $flow]);

        // update activity
        return $this->updateActivity($order->uuid, $updateActivityRequest);
    }
    public function driverAcceptance(string $id, Request $request)
{
    if (!in_array($request->is_approved, [0, 1])) {
        return response()->json([
            'status' => false,
            'message' => __('messages.invalid_approval_status'),
            'order' => null
        ], 400);
    }

    try {
        $order = Order::findRecordOrFail($id, ['payload.waypoints'], []);

        if ($order->started) {
            return response()->json(['status' => false, 'message' => __('messages.load_already_started')], 400);
        }

        if ($request->is_approved == 1 && $order->status === 'confirmed') {
            return response()->json([
                'status' => false,
                'message' => __('messages.invalid_operation', ['status' => $order->status]),
                'order' => null
            ], 400);
        }

        $driver = Driver::withoutGlobalScopes()->where('uuid', $order->driver_assigned_uuid)->first();

        if (!$driver) {
            return response()->json(['status' => false, 'message' => 'No driver assigned to order.'], 400);
        }

        $emailData = null;

        DB::beginTransaction();

        try {
            $oldStatus = $order->status;

            if ($request->is_approved == 1) {
                $order->update(['status' => 'confirmed']);
                $driver->update(['current_job_uuid' => $order->uuid]);

                $responseData = [
                    'status' => true,
                    'message' => __('messages.load_confirmed_success'),
                    'details' => [
                        'order_id' => $order->uuid,
                        'status' => 'confirmed',
                        'driver_name' => $driver->name ?? 'Not specified',
                        'previous_status' => $oldStatus
                    ],
                    'order' => $order
                ];
                $status = 'confirmed';
            } else {
                $driver_user = User::withoutGlobalScopes()->where('uuid', $driver->user_uuid)->first();
                if($driver_user){
                    $driver_name = $driver_user->name;
                    $driver_phone = $driver_user->phone;
                }
                else{
                    $driver_name = null;
                    $driver_phone = null;
                }
                $status = $order->dispatched === 1 ? 'dispatched' : 'created';
                $order->update(['status' => $status, 'driver_assigned_uuid' => null, 'vehicle_assigned_uuid' => null]);
                
                if ($driver->current_job_uuid === $order->uuid) {
                    $driver->update(['current_job_uuid' => null]);
                }

                $responseData = [
                    'status' => true,
                    'message' => __('messages.load_rejected'),
                    'details' => [
                        'order_id' => $order->uuid,
                        'status' => 'rejected',
                        'previous_status' => $oldStatus,
                        'rejection_time' => now()->toDateTimeString()
                    ],
                    'order' => $order
                ];
                
                $emailData = [
                    'order' => [
                        'public_id' => $order['public_id'],
                        'company_uuid' => $order['company_uuid'],
                    ],
                    'driver' => [
                        'name' => $driver_name ?? 'N/A',
                        'public_id' => $driver->public_id ?? 'N/A',
                        'phone' => $driver_phone ?? 'N/A',
                    ],
                ];
               
            }

            CustomOrderController::logOrderStatusChange($order, $order->status, $oldStatus);
            //insert record into tracking status:
            $createTrackingStatus = $this->createTrackingStatus($order, $status);
            DB::commit();
            if (!$createTrackingStatus) {
                return response()->json(['status' => false, 'message' => __('messages.duplicate_tracking_status')], 400);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => "Error: {$e->getMessage()}"], 500);
        }

        // If commit succeeded, send the email if needed
        if ($emailData) {
            $this->sendRejectionEmail($emailData);
        }

        return response()->json($responseData);

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json(['status' => false, 'message' => __('messages.load_not_found')], 404);
    } catch (\Exception $e) {
        return response()->json(['status' => false, 'message' => "Error: {$e->getMessage()}"], 500);
    }
}


    public static function logOrderStatusChange(Order $order, string $newStatus, string $oldStatus): bool
    {
        try {
            DB::table('activity')->insert([
                'uuid' => (string) Str::uuid(),
                'log_name' => 'default',
                'description' => 'updated',
                'subject_id' => $order->company_uuid,
                'company_id'   => session('company'),
                'subject_type' => 'Fleetbase\\FleetOps\\Models\\Order',
                'event' => 'updated',
                'properties' => json_encode([
                    'attributes' => [
                        'status' => $newStatus,
                        'updated_at' => now()->toIso8601String()
                    ],
                    'old' => [
                        'status' => $oldStatus,
                        'updated_at' => $order->getOriginal('updated_at')
                    ]
                ]),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to log activity: ' . $e->getMessage());
            return false;
        }
    }

    private function sendRejectionEmail($emailData)
    {
        try {
            $order = $emailData['order'];
            $company_uuid = $order['company_uuid'];
            $adminUsers = User::where('company_uuid', $company_uuid)
            ->where('type', 'admin')  // Assuming 'admin' is the user type for administrators
            ->where('status', 'active') 
            ->whereNotNull('email')   // Ensure email exists
            ->pluck('email')
            ->toArray();
            if (!empty($adminUsers)) {
                Mail::to($adminUsers)
                    ->send(new OrderRejectionMail($emailData['order'], $emailData['driver']));
            }
        } catch (\Exception $e) {
            Log::error("Failed to send rejection email: {$e->getMessage()}");
        }
    }

    private function createTrackingStatus(Order $order, $status)
    {
        $trackingnumber = $order->tracking_number_uuid;
        $existingTrackingSameStatus = TrackingStatus::where('tracking_number_uuid', $trackingnumber)
            ->where('code', $status)
            ->where('code', '!=', 'created')
            ->whereNull('deleted_at')
            ->first();

        if ($existingTrackingSameStatus) {
            return false; // Do not insert if a similar status exists
        }
        $existingTrackingStatus = TrackingStatus::where('tracking_number_uuid', $trackingnumber)
        ->whereNull('deleted_at') // Check if the status is also the same
        ->first();
        if ($existingTrackingStatus) {
            if ($status === 'confirmed') {
                $trackingData = [
                    'status'       => 'Order Accepted',
                    'details'      => 'Order Accepted by the driver.',
                    'code'         => str_replace('-', ' ', $status),
                    'location'     => $existingTrackingStatus['location'],
                    'tracking_number_uuid' => $existingTrackingStatus['tracking_number_uuid'],
                    'company_uuid' => $existingTrackingStatus['company_uuid'],
                ];
            } 
            elseif($status === 'created') {
                $trackingData = [
                    'status'       => 'Order Rejected',
                    'details'      => 'Order Rejected by the driver.Please assign a new driver.',
                    'code'         => 'created',
                    'location'     => $existingTrackingStatus['location'],
                    'tracking_number_uuid' => $existingTrackingStatus['tracking_number_uuid'],
                    'company_uuid' => $existingTrackingStatus['company_uuid'],
                ];
            } 
            else {
                $trackingData = [
                    'status'       => $status,
                    'details'      => 'Order status updated by the driver',
                    'code'         => str_replace('-', ' ', $status),
                    'location'     => $existingTrackingStatus['location'],
                    'tracking_number_uuid' => $existingTrackingStatus['tracking_number_uuid'],
                    'company_uuid' => $existingTrackingStatus['company_uuid'],
                ];
            }
            $trackingStatus = TrackingStatus::create($trackingData);
            return $trackingStatus;
        }
        return false;

    }

    public function driverActivity(string $id, Request $request)
    {
        try {
                $order = Order::findRecordOrFail($id);

                // Check if order exists and is in valid state
                if (!$order) {
                    return response()->json(['status' => false, 'message' => 'Order not found.'], 404);
                }
                
                DB::beginTransaction();
                $oldStatus = $order->status;
                $status = $request->input('status');
                
                // Validate status first
                $status_array = ['Shift Ended','On Break', 'Incident Reported'];
                if(!in_array($status, $status_array)){
                    return response()->json(['status' => false, 'message' => __('messages.invalid_activity_status')], 400);
                }
                
                // Update order status
                $order->update(['status' => $status]);
                
                // Log the status change
                CustomOrderController::logOrderStatusChange($order, $status, $oldStatus);
                // Create tracking status
                $createTrackingStatus = $this->createTrackingStatus($order, $status);
                
                DB::commit();

                if (!$createTrackingStatus) {
                    return response()->json([
                        'status' => false, 
                        'message' => __('messages.duplicate_tracking_status')
                    ], 400);
                }

                return response()->json([
                    'status' => true,
                    'message' => __('messages.status_updated_successfully'),
                    'details' => [
                        'order_id' => $order->uuid,
                        'status' => $status,
                        'previous_status' => $oldStatus,
                        'activity_started_time' => now()->toDateTimeString()
                    ],
                    'order' => $order
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json([
                    'status' => false, 
                    'message' => "Error: {$e->getMessage()}"
                ], 500);
            }

    }
}