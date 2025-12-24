<?php
namespace WHMCS\Module\Server\Openstacknova;

use WHMCS\UsageBilling\Contracts\Metrics\MetricInterface;
use WHMCS\UsageBilling\Contracts\Metrics\ProviderInterface;
use WHMCS\UsageBilling\Metrics\Metric;
use WHMCS\UsageBilling\Metrics\Units\MegaBytes;
use WHMCS\UsageBilling\Metrics\Units\GigaBytes;
use WHMCS\UsageBilling\Metrics\Units\WholeNumber;
use WHMCS\UsageBilling\Metrics\Usage;
// Add Capsule for database queries
use WHMCS\Database\Capsule;

class OpenStackMetricsProvider implements ProviderInterface
{
    private $moduleParams = [];
    
    public function __construct($moduleParams) {
        $this->moduleParams = $moduleParams;
    }
    
    public function metrics() {
        // Define all metrics your module can provide.
        // TYPE_SNAPSHOT is for metrics that don't reset (like allocated resources).
        return [
            // new Metric(
            //     'memory', // system name
            //     'Memory Allocated', // display name
            //     MetricInterface::TYPE_SNAPSHOT,
            //     new MegaBytes('MB')
            // ),
            // new Metric(
            //     'vcpus',
            //     'vCPUs Allocated',
            //     MetricInterface::TYPE_SNAPSHOT,
            //     new WholeNumber('Cores')
            // ),
            new Metric(
                'root.disk.size',
                'Root Disk Size',
                MetricInterface::TYPE_SNAPSHOT,
                new GigaBytes('GB')
            ),
            // new Metric(
            //     'bandwidth',
            //     'Bandwidth Used',
            //     MetricInterface::TYPE_PERIOD_MONTH,
            //     new MegaBytes('MB')
            // ),
            // Bandwidth could also be tracked here as a periodic metric if preferred.
        ];
    }
    
    public function usage() {
        // Called by cron for ALL services on this server.
        $usageData = [];
        
        // 1. Get all services for this server from WHMCS database
        $serverId = $this->moduleParams['serverid']; // The WHMCS server ID
        $services = Capsule::table('tblhosting')
            ->where('server', $serverId)
            ->get();
            
        foreach ($services as $service) {
            $domain = $service->domain;
            $serviceId = $service->id;
            
            // 2. Get OpenStack server ID for this WHMCS service
            $openStackServerId = $this->getServerIdByDomain($domain);
            if (!$openStackServerId) {
                continue; // No OpenStack server linked to this WHMCS service
            }
            
            // 3. Fetch metrics for this OpenStack server
            try {
                $client = openstacknova_createClientByModuleParams($this->moduleParams);
                $compute = $client->computeV2();
                $server = $compute->getServer(['id' => $openStackServerId]);
                $serverMetrics = $this->getMetricsForServer($server, $client);
                
                // 4. Key the array by the DOMAIN (WHMCS tenant identifier)
                $usageData[$domain] = $this->wrapUserData($serverMetrics);
            } catch (\Exception $e) {
                logModuleCall('openstacknova', 'usage', "Domain: $domain", $e->getMessage(), [], []);
                continue;
            }
        }
        return $usageData;
    }
    
    public function tenantUsage($tenant) {
        // $tenant is the WHMCS service DOMAIN
        logModuleCall('openstacknova', __FUNCTION__, "Tenant/Domain: $tenant", '', '', []);
        
        // 1. Get OpenStack server ID for this domain
        $openStackServerId = $this->getServerIdByDomain($tenant);
        logModuleCall('openstacknova', __FUNCTION__, "OpenStack Server ID: $openStackServerId", '', '', []);
        
        if (!$openStackServerId) {
            logModuleCall('openstacknova', __FUNCTION__, "No OpenStack server found for domain: $tenant", '', '', []);
            return [];
        }
        
        // 2. Fetch the server and its metrics
        $client = openstacknova_createClientByModuleParams($this->moduleParams);
        $compute = $client->computeV2();
        
        try {
            $server = $compute->getServer(['id' => $openStackServerId]);
            $serverMetrics = $this->getMetricsForServer($server, $client);
            logModuleCall('openstacknova', __FUNCTION__, "Metrics fetched: " . json_encode($serverMetrics), '', '', []);
            
            return $this->wrapUserData($serverMetrics);
        } catch (\Exception $e) {
            logModuleCall('openstacknova', 'tenantUsage', $tenant, $e->getMessage(), [], []);
            return [];
        }
    }
    
    /**
     * NEW FUNCTION: Get OpenStack Server ID by WHMCS service domain
     * This is the critical lookup function for the Refresh Now button.
     */
    private function getServerIdByDomain($domain) {
        // 1. Find the WHMCS service ID for this domain on the current server
        $serverId = $this->moduleParams['serverid'];
        $service = Capsule::table('tblhosting')
            ->where('domain', $domain)
            ->where('server', $serverId)
            ->first();
            
        if (!$service) {
            return null; // No service found with this domain on this server
        }
        
        // 2. Now find the OpenStack Server ID custom field for this service
        $fieldId = $this->getCustomFieldId();
        if (!$fieldId) {
            return null;
        }
        
        $customField = Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', $fieldId)
            ->where('relid', $service->id) // relid is the WHMCS service ID
            ->first();
            
        return $customField ? $customField->value : null;
    }
    
    private function wrapUserData($data) {
        $wrapped = [];
        foreach ($this->metrics() as $metric) {
            $key = $metric->systemName();
            if (isset($data[$key])) {
                $metric = $metric->withUsage(new Usage($data[$key]));
            }
            $wrapped[] = $metric;
        }
        return $wrapped;
    }

    
    /**
     * Fetch metrics for a specific OpenStack server object.
     */
    private function getMetricsForServer($server, $client) {
        $metrics = [];
        $serverId = $server->id;
        $computeService = $client->computeV2();

        logModuleCall('openstacknova - Get Metrics Called', __FUNCTION__, 'serverId=' . $serverId, '', '', []);
        try {
            $aggregateData = $this->makeAggregatesRequest($client, $serverId);
            logModuleCall('openstacknova - Aggregate Data', __FUNCTION__, $aggregateData, '', '', []);
            if (
                isset($aggregateData[0]) &&
                isset($aggregateData[0]['measures']) &&
                isset($aggregateData[0]['measures']['measures']) &&
                isset($aggregateData[0]['measures']['measures']['aggregated']) &&
                is_array($aggregateData[0]['measures']['measures']['aggregated']) &&
                count($aggregateData[0]['measures']['measures']['aggregated']) > 0
            ) {
                $aggregatedArray = $aggregateData[0]['measures']['measures']['aggregated'];
                // Get the first element of the aggregated array: [timestamp, granularity, value]
                $dataPoint = $aggregatedArray[0];
                $metrics['root.disk.size'] = $dataPoint[2]; // The value
            } else if (isset($server->flavor['id'])) {
                try {
                    $flavor = $computeService->getFlavor(['id' => $server->flavor['id']]);
                    // Convert flavor disk from GB to MB if needed, or use directly.
                    // OpenStack flavor disk is usually in GB.
                    $metrics['root.disk.size'] = $flavor->disk; // This is likely in GB
                } catch (\Exception $e) {
                    // Flavor not accessible
                    $metrics['root.disk.size'] = 0;
                }
            }
            // Add logic here for memory, vCPUs, etc., using $flavor->ram and $flavor->vcpus
            // $metrics['memory'] = $flavor->ram; // RAM in MB
            // $metrics['vcpus'] = $flavor->vcpus;
        } catch (\Exception $e) {
            // Log the error for debugging
            logModuleCall('openstacknova', 'getMetricsForServer', $serverId, $e->getMessage(), [], []);
            $metrics['root.disk.size'] = 0;
        }
        
        return $metrics;
    }

    /**
     * Helper: Makes an authenticated POST request to the Ceilometer/Gnocchi aggregates API.
     */
    private function makeAggregatesRequest($client, $serverId) {
        logModuleCall('openstacknova - Get AggregatedData Called', __FUNCTION__, 'serverId=' . $serverId, '', '', []);
        
        // Generate the authentication token
        $token = 'TOKEN'; // Placeholder for token

        // 2. Calculate time range (e.g., last 1 hour)
        $endTime = new \DateTime('now', new \DateTimeZone('UTC'));
        $startTime = clone $endTime;
        $startTime->sub(new \DateInterval('PT1H')); // Period of 1 hour

        // Format for API: 2025-12-16T05:39:16
        $startStr = $startTime->format('Y-m-d\TH:i:s');
        $endStr = $endTime->format('Y-m-d\TH:i:s');

        // 3. Build the API request URL and payload[citation:1]
        $url = 'http://172.16.76.230:8041/v1/aggregates';
        $url .= '?groupby=original_resource_id';
        $url .= '&granularity=300.0'; // 5 minutes in seconds[citation:1]
        $url .= '&start=' . urlencode($startStr);
        $url .= '&end=' . urlencode($endStr);

        $postData = [
            'operations' => '(aggregate max (metric disk.root.size mean))', // Your metric operation
            'resource_type' => 'instance',
            'search' => "id = '" . $serverId . "'" // Query to filter by instance ID[citation:1]
        ];

        logModuleCall('openstacknova - CURL Request', __FUNCTION__, $url . ' PAYLOAD ' . json_encode($postData), '', '', []);
        // 4. Execute the HTTP POST request
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'X-Auth-Token: ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($postData)
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('Curl error: ' . $error);
        }
        if ($httpCode >= 400) {
            throw new \Exception("API request failed ($httpCode): " . $response);
        }

        logModuleCall('openstacknova - CURL Response', __FUNCTION__, $response, '', '', []);

        return json_decode($response, true);
    }
        
    /**
     * Get the ID of our custom field (cached).
     * Same logic as openstacknova_getOrCreateCustomFieldId.
     */
    private function getCustomFieldId() {
        static $fieldId = null;
        if ($fieldId !== null) {
            return $fieldId;
        }
        
        $field = Capsule::table('tblcustomfields')
            ->where('fieldname', 'OpenStack Server ID')
            ->where('type', 'product')
            ->first();
            
        $fieldId = $field ? $field->id : null;
        return $fieldId;
    }
}
