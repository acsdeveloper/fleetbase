<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\Models\Company;
use Illuminate\Support\Str;

class FleetOps
{
    /**
     * Creates or retrieves an existing transport configuration for a given company.
     *
     * This method attempts to find a transport configuration (`OrderConfig`) for the specified company.
     * If such a configuration exists, it's returned. Otherwise, a new configuration is created with default values,
     * such as the company UUID, key, core service flag, status, version, tags, and predefined workflow steps.
     * These steps include 'created', 'enroute', 'started', 'completed', and 'dispatched', each with specific attributes.
     *
     * @param Company $company the company for which the transport configuration is being created or retrieved
     *
     * @return OrderConfig the transport configuration associated with the specified company
     */
    public static function createTransportConfig(Company $company): OrderConfig
    {
        $pod_method = 'scan';

        return OrderConfig::firstOrCreate(
            [
                'company_uuid' => $company->uuid,
                'key'          => 'transport',
                'namespace'    => 'system:order-config:transport',
            ],
            [
                'name'         => 'Transport',
                'key'          => 'transport',
                'namespace'    => 'system:order-config:transport',
                'description'  => 'Default order configuration for transport',
                'core_service' => 1,
                'status'       => 'private',
                'version'      => '0.0.1',
                'tags'         => ['transport', 'delivery'],
                'entities'     => [],
                'meta'         => [],
                'flow'         => [
                    'created' => [
                        'key'         => 'created',
                        'code'        => 'created',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Order Created',
                        'actions'     => [],
                        'details'     => 'New order was created.',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 0,
                        'activities'  => ['confirmed'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => $pod_method,
                        'require_pod' => false,
                    ],
                    'confirmed' => [
                        'key' => 'confirmation',
                        'code' => 'confirmed',
                        'color' => '#1f2937',
                        'logic' => [],
                        'events' => [],
                        'status' => 'Confirmed',
                        'actions' => [],
                        'details' => 'The driver confirms the order',
                        'options' => [],
                        'complete' => false,
                        'entities' => [],
                        'sequence' => 1,
                        'activities' => ['dispatched'],
                        'internalId' => 'c69770f9-2a8b-4654-a83c-e29ae1dedb7d',
                        'pod_method' => $pod_method,
                        'require_pod' => false,
                    ],
                    'dispatched' => [
                        'key'         => 'dispatched',
                        'code'        => 'dispatched',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Order Dispatched',
                        'actions'     => [],
                        'details'     => 'Order has been dispatched.',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 2,
                        'activities'  => ['started'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => $pod_method,
                        'require_pod' => false,
                    ],
                    'started' => [
                        'key'         => 'started',
                        'code'        => 'started',
                        'color'       => '#1f2937',
                        'logic'       => [],
                        'events'      => [],
                        'status'      => 'Order Started',
                        'actions'     => [],
                        'details'     => 'Order has been started',
                        'options'     => [],
                        'complete'    => false,
                        'entities'    => [],
                        'sequence'    => 3,
                        'activities'  => ['completed'],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => $pod_method,
                        'require_pod' => false,
                    ],
                    'completed' => [
                        'key'         => 'completed',
                        'code'        => 'completed',
                        'color'       => '#1f2937',
                        'logic'       => [
                            [
                                'type'       => 'if',
                                'conditions' => [
                                    [
                                        'field'    => 'completed',
                                        'value'    => '1',
                                        'operator' => 'equal',
                                    ],
                                ],
                            ],
                        ],
                        'events'      => [],
                        'status'      => 'Order Completed',
                        'actions'     => [],
                        'details'     => 'Order has been completed.',
                        'options'     => [],
                        'complete'    => true,
                        'entities'    => [],
                        'sequence'    => 4,
                        'activities'  => [],
                        'internalId'  => Str::uuid(),
                        'pod_method'  => $pod_method,
                        'require_pod' => false,
                    ],
                ],
            ]
        );
    }
}
