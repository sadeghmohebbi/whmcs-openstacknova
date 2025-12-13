<?php
// modules/servers/openstacknova/openstacknova.php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
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
        'Project ID' => array(
            'Type' => 'text',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Project ID (or Project Name)',
        ),
        'Region' => array(
            'Type' => 'text',
            'Size' => '20',
            'Default' => 'RegionOne',
            'Description' => 'OpenStack region',
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
    $server_info = [];
    $openstack_error = '';
    
    try {
        $serverId = openstacknova_getServerId($params['serviceid']);
        if ($serverId) {
            $client = openstacknova_createClient($params);
            $compute = $client->computeV2();
            $server = $compute->getServer(['id' => $serverId]);
            
            // Get server details
            $server_info = [
                'id' => $server->id,
                'name' => $server->name,
                'status' => $server->status,
                'created' => $server->created,
                'addresses' => $server->addresses,
            ];
        }
    } catch (Exception $e) {
        $openstack_error = $e->getMessage();
    }
    
    // Return the template variables
    return [
        'Template Variables' => 'OpenStack Info',
        'server_info' => $server_info,
        'openstack_error' => $openstack_error,
        'serviceid' => $params['serviceid'],
    ];
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
        $smarty = new Smarty();
        $smarty->assign([
            'server_info' => $server_info,
            'openstack_error' => $openstack_error,
            'serviceid' => $params['serviceid'],
        ]);
        
        return [
            'tabOverviewReplacementTemplate' => $templateFile,
            'templateVariables' => [
                'server_info' => $server_info,
                'openstack_error' => $openstack_error,
            ],
        ];
    }
    
    return 'Template file not found';
}

function openstacknova_TestConnection($params)
{
    try {
        $client = openstacknova_createClient($params);
        $compute = $client->computeV2();
        // List servers to verify access
        $servers = $compute->listServers();
        return array('success' => true, 'error' => '');
    } catch (Exception $e) {
        return array('success' => false, 'error' => 'Connection failed: ' . $e->getMessage());
    }
}

function openstacknova_CreateAccount($params)
{
    try {
        $client = openstacknova_createClient($params);
        $compute = $client->computeV2();

        // Build server creation array
        $serverOptions = [
            'name' => $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'] . ' - ' . $params['serviceid'],
            'imageId' => $params['configoption6'], // Image ID from config
            'flavorId' => $params['configoption7'], // Flavor ID from config
        ];
        // Add network if configured
        if (!empty($params['configoption8'])) {
            $serverOptions['networks'] = [['uuid' => $params['configoption8']]];
        }

        $server = $compute->createServer($serverOptions);

        // Wait for the server to become ACTIVE (optional)
        $server->waitFor('ACTIVE', 600);

        // Store the OpenStack server ID in the WHMCS service custom field
        openstacknova_saveServerId($params['serviceid'], $server->id);

        return 'success';
    } catch (Exception $e) {
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
    require_once __DIR__ . '/vendor/autoload.php';

    $authUrl = $params['configoption1']; // Auth URL
    $username = $params['configoption2']; // Username
    $password = $params['configoption3']; // Password
    $projectId = $params['configoption4']; // Project ID
    $region = $params['configoption5'];   // Region

    return new \OpenStack\OpenStack([
        'authUrl' => $authUrl,
        'region'  => $region,
        'user'    => [
            'name'     => $username,
            'password' => $password,
            'domain'   => ['id' => 'default'],
        ],
        'scope'   => ['project' => ['id' => $projectId]],
    ]);
}

// Store OpenStack server ID in a custom field
function openstacknova_saveServerId($serviceId, $serverId)
{
    $fieldId = 1; // Replace with your actual custom field ID
    update_query('tblcustomfieldsvalues', ['value' => $serverId], "fieldid = $fieldId AND relid = $serviceId");
}

// Retrieve stored OpenStack server ID
function openstacknova_getServerId($serviceId)
{
    $fieldId = 1; // Replace with your actual custom field ID
    $result = select_query('tblcustomfieldsvalues', 'value', "fieldid = $fieldId AND relid = $serviceId");
    $data = mysql_fetch_array($result);
    return $data['value'] ?? null;
}

// Delete stored server ID
function openstacknova_deleteServerId($serviceId)
{
    $fieldId = 1;
    delete_query('tblcustomfieldsvalues', "fieldid = $fieldId AND relid = $serviceId");
}