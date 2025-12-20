<?php
// modules/servers/openstacknova/openstacknova.php


if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Module\Server\Openstacknova\OpenStackMetricsProvider;
use WHMCS\Database\Capsule;

if (!class_exists('\OpenStack\OpenStack')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Define module metadata
function openstacknova_MetaData()
{
    return array(
        'DisplayName' => 'OpenStack Nova',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
    );
}

function openstacknova_MetricProvider($params) {
    return new OpenStackMetricsProvider($params);
}

// Configuration options for the product module
function openstacknova_ConfigOptions($params)
{
    return array(
        'Auth URL' => array(
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'https://keystone.example.com:5000/v3',
            'Description' => 'OpenStack Keystone authentication URL (v3)',
        ),
        'Username' => array(
            'Type' => 'text',
            'Size' => '30',
            'Default' => '',
            'Description' => 'OpenStack username',
        ),
        'Password' => array(
            'Type' => 'password',
            'Size' => '30',
            'Default' => '',
            'Description' => 'OpenStack password',
        ),
        'Image ID' => array(
            'Type' => 'text',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Default image ID (e.g., Ubuntu 20.04)',
        ),
        'Flavor ID' => array(
            'Type' => 'text',
            'Size' => '30',
            'Default' => '',
            'Description' => 'Default flavor ID (e.g., m1.small)',
        ),
        'Network ID' => array(
            'Type' => 'text',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Network ID for the instance (optional)',
        ),
    );
}

// Admin Services Tab - Displays admin.tpl
function openstacknova_AdminServicesTabFields($params)
{
    try {
        $serverId = openstacknova_getServerId($params['serviceid']);
        if (!$serverId) {
            return [
                'OpenStack Server' => 'Not provisioned yet',
            ];
        }

        $client  = openstacknova_createClient($params);
        $compute = $client->computeV2();
        $server  = $compute->getServer(['id' => $serverId]);

        // Build STRING output only
        $addresses = [];
        if (!empty($server->addresses)) {
            foreach ($server->addresses as $net => $ips) {
                foreach ($ips as $ip) {
                    $addresses[] = "{$net}: {$ip->addr}";
                }
            }
        }

        return [
            'OpenStack ID'   => $server->id,
            'Name'           => $server->name,
            'Status'         => $server->status,
            'Created'        => $server->created,
            'IP Addresses'   => implode('<br>', $addresses),
        ];
    } catch (Exception $e) {
        return [
            'OpenStack Error' => $e->getMessage(),
        ];
    }
}

// Client Area Output - Displays client.tpl
function openstacknova_ClientArea($params)
{
    $server_info = [];
    $openstack_error = '';
    
    try {
        $serverId = openstacknova_getServerId($params['serviceid']);
        if ($serverId) {
            $client = openstacknova_createClient($params);
            $compute = $client->computeV2();
            $server = $compute->getServer(['id' => $serverId]);
            
            // Get additional details if available
            $flavorName = '';
            $imageName = '';
            $primaryIp = '';
            
            if (isset($server->flavor['id'])) {
                $flavor = $compute->getFlavor(['id' => $server->flavor['id']]);
                $flavorName = $flavor->name;
            }
            
            if (isset($server->image['id'])) {
                $image = $compute->getImage(['id' => $server->image['id']]);
                $imageName = $image->name;
            }
            
            // Extract primary IP (first public IPv4)
            if (!empty($server->addresses)) {
                foreach ($server->addresses as $network => $addresses) {
                    foreach ($addresses as $address) {
                        if ($address->version == 4 && $address->type == 'floating') {
                            $primaryIp = $address->addr;
                            break 2;
                        }
                    }
                }
                // If no floating IP, use first fixed IP
                if (!$primaryIp) {
                    foreach ($server->addresses as $network => $addresses) {
                        foreach ($addresses as $address) {
                            if ($address->version == 4) {
                                $primaryIp = $address->addr;
                                break 2;
                            }
                        }
                    }
                }
            }
            
            $server_info = [
                'id' => $server->id,
                'name' => $server->name,
                'status' => $server->status,
                'created' => $server->created,
                'addresses' => $server->addresses,
                'flavor_name' => $flavorName,
                'image_name' => $imageName,
                'primary_ip' => $primaryIp,
            ];
        }
    } catch (Exception $e) {
        $openstack_error = $e->getMessage();
    }
    
    // Load the client template
    $templateFile = 'client.tpl';
    $templatePath = dirname(__FILE__) . '/templates/' . $templateFile;
    
    if (file_exists($templatePath)) {
        // WHMCS automatically handles template assignment
        // Just return the array with template info
        return [
            'tabOverviewReplacementTemplate' => $templateFile,
            'templateVariables' => [
                'server_info' => $server_info,
                'openstack_error' => $openstack_error,
                'serviceid' => $params['serviceid'],
            ],
        ];
    }
    
    return 'Template file not found';
}

function openstacknova_TestConnection($params)
{
    try {
        $client = openstacknova_createClientByModuleParams($params);
        $compute = $client->computeV2();
        // List servers to verify access
        $az = $compute->listAvailabilityZones();
        return array('success' => true, 'error' => '');
    } catch (Exception $e) {
        return array('success' => false, 'error' => 'Connection failed: ' . $e->getMessage());
    }
}

function openstacknova_createClientByModuleParams($moduleParams)
{
    $params = $moduleParams;
    if ($params['serverhostname']) {
        $params['serverport'] = $params['serverport'] ?: 5000;
    } else {
        throw new Exception('Server Hostname is required for connection test.');
    }
    $params['configoption1'] = 'http://' . $params['serverhostname'] . ':' . $params['serverport'] . '/v3';
    $params['configoption2'] = $params['serverusername'];
    $params['configoption3'] = $params['serverpassword'];
    return openstacknova_createClient($params);
}

function openstacknova_CreateAccount($params)
{
    logModuleCall(
        'openstacknova',
        __FUNCTION__,
        $params,
        '',
        '',
        array()
    );
    try {
        $client = openstacknova_createClient($params);
        $compute = $client->computeV2();

        $imageId = $params['configoption4']; // Image ID from config
        $flavorId = $params['configoption5']; // Flavor ID from config
        $networkId = $params['configoption6']; // Network ID from config

        // Build server creation array
        $serverOptions = [
            // 'name' => trim($params['clientsdetails']['firstname']) . '_' . trim($params['clientsdetails']['lastname']) . '-' . $params['userid'] . '_' . $params['serviceid'],
            'name' => 'openstack-iaas-' . $params['userid'] . '-' . $params['serviceid'],
            'imageId' => $imageId, // Image ID from config
            'flavorId' => $flavorId, // Flavor ID from config
        ];
        // Add network if configured
        if (!empty($networkId)) {
            $serverOptions['networks'] = [['uuid' => $networkId]];
        }

        $server = $compute->createServer($serverOptions);

        logModuleCall(
            'openstacknova',
            __FUNCTION__ . ' - Server Created',
            $server,
            '',
            '',
            array()
        );

        // Store the OpenStack server ID in the WHMCS service custom field
        openstacknova_saveServerId($params['serviceid'], $server->id);

        $domain = $serverOptions['name'] . '.whmcs.test';
        openstacknova_saveServerDomain($params['serviceid'], $domain);

        return 'success';
    } catch (Exception $e) {
        logModuleCall(
            'openstacknova',
            __FUNCTION__,
            $params,
            '',
            $e->getMessage(),
            array()
        );
        return 'Error creating instance: ' . $e->getMessage();
    }
}

function openstacknova_SuspendAccount($params)
{
    try {
        $serverId = openstacknova_getServerId($params['serviceid']);
        if (!$serverId) {
            return 'Server ID not found for this service.';
        }

        $client = openstacknova_createClient($params);
        $compute = $client->computeV2();
        $server = $compute->getServer(['id' => $serverId]);

        // Pause the server (alternative: $server->suspend())
        $server->pause();

        return 'success';
    } catch (Exception $e) {
        return 'Error suspending instance: ' . $e->getMessage();
    }
}

function openstacknova_TerminateAccount($params)
{
    try {
        $serverId = openstacknova_getServerId($params['serviceid']);
        if (!$serverId) {
            return 'Server ID not found for this service.';
        }

        $client = openstacknova_createClient($params);
        $compute = $client->computeV2();
        $server = $compute->getServer(['id' => $serverId]);

        // Delete the server
        $server->delete();

        // Remove the stored server ID
        openstacknova_deleteServerId($params['serviceid']);

        return 'success';
    } catch (Exception $e) {
        return 'Error terminating instance: ' . $e->getMessage();
    }
}

// Create OpenStack client using parameters from WHMCS
function openstacknova_createClient($params)
{
    logModuleCall(
        'openstacknova',
        __FUNCTION__,
        $params,
        '',
        '',
        array()
    );

    $authUrl = $params['configoption1']; // Auth URL
    $username = $params['configoption2']; // Username
    $password = $params['configoption3']; // Password

    return new \OpenStack\OpenStack([
        'authUrl' => $authUrl,
        'region'  => 'RegionOne',
        'user'    => [
            'name'     => $username,
            'password' => $password,
            'domain'   => ['name' => 'Default'],
        ],
        'scope' => [
            'project' => [
                'name'   => 'admin',
                'domain' => ['name' => 'Default'],
            ],
        ],
    ]);
}

function openstacknova_getServerDomainByName($serverName) {
    $result = null;
    if ($serverName && str_starts_with($serverName, 'openstack-iaas-')) {
        $result = $serverName . '.whmcs.test';
    }
    return $result;
}

// Store OpenStack server ID in a custom field
function openstacknova_saveServerId($serviceId, $serverId)
{
    // Get custom field ID for OpenStack Server ID
    // First, let's find or create the custom field
    $fieldId = openstacknova_getOrCreateCustomFieldId();
    
    // Check if a value already exists for this service
    $existing = Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', $fieldId)
        ->where('relid', $serviceId)
        ->first();
    
    if ($existing) {
        // Update existing value
        Capsule::table('tblcustomfieldsvalues')
            ->where('id', $existing->id)
            ->update(['value' => $serverId]);
    } else {
        // Insert new value
        Capsule::table('tblcustomfieldsvalues')
            ->insert([
                'fieldid' => $fieldId,
                'relid' => $serviceId,
                'value' => $serverId
            ]);
    }
}

function openstacknova_saveServerDomain($serviceId, $domainToSave)
{
    // This function can be implemented to save the server domain if needed
    // ---- NEW CODE START: Save the domain to WHMCS ----
    try {
        // Update the main service record in tblhosting
        Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->update(['domain' => $domainToSave]);
        
        logModuleCall(
            'openstacknova',
            __FUNCTION__ . ' - Domain Updated',
            ['serviceid' => $serviceId, 'domain' => $domainToSave],
            '',
            '',
            array()
        );
    } catch (\Exception $dbError) {
        // Log the error but don't fail the entire provisioning
        logModuleCall(
            'openstacknova',
            __FUNCTION__ . ' - Domain Update Failed',
            ['serviceid' => $serviceId, 'domain' => $domainToSave],
            '',
            $dbError->getMessage(),
            array()
        );
    }
    // ---- NEW CODE END ----
}

// Retrieve stored OpenStack server ID
function openstacknova_getServerId($serviceId)
{
    $fieldId = openstacknova_getOrCreateCustomFieldId();
    
    $result = Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', $fieldId)
        ->where('relid', $serviceId)
        ->first();
    
    return $result ? $result->value : null;
}

// Delete stored server ID
function openstacknova_deleteServerId($serviceId)
{
    $fieldId = openstacknova_getOrCreateCustomFieldId();
    
    Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', $fieldId)
        ->where('relid', $serviceId)
        ->delete();
}

// Get or create custom field for storing OpenStack server ID
function openstacknova_getOrCreateCustomFieldId()
{
    static $fieldId = null;
    
    if ($fieldId !== null) {
        return $fieldId;
    }
    
    // Check if the field already exists
    $field = Capsule::table('tblcustomfields')
        ->where('fieldname', 'OpenStack Server ID')
        ->where('type', 'product')
        ->first();
    
    if ($field) {
        $fieldId = $field->id;
        return $fieldId;
    }
    
    // Get the product ID for our module
    // This requires knowing which product uses our module
    // For simplicity, we'll create a generic field
    // In production, you might want to create fields per product
    
    // Create the custom field
    $fieldId = Capsule::table('tblcustomfields')->insertGetId([
        'type' => 'product',
        'relid' => 0, // 0 means available for all products
        'fieldname' => 'OpenStack Server ID',
        'fieldtype' => 'text',
        'description' => 'Stores the OpenStack instance ID',
        'fieldoptions' => '',
        'regexpr' => '',
        'adminonly' => 'on', // Only visible in admin area
        'required' => '',
        'showorder' => '',
        'showinvoice' => '',
        'sortorder' => 999,
    ]);
    
    return $fieldId;
}