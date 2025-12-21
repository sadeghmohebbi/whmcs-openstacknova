<?php

/**
 * WHMCS Script to Get Client Products/Services and Their Metric Usage (e.g., bandwidth)
 */

require_once 'init.php';

use WHMCS\Service\Service;

// Fetch active services/products via Local API
$command = 'GetClientsProducts';
$postData = array(
    'stats' => true,          // Optional: includes basic stats
);
$adminUsername = 'sadeghmohebbi'; // Your admin username
$hourlyRatePerGB = 0.10; // $0.10 per GB it should load from product config
$totalCharge = 0;

$results = localAPI($command, $postData, $adminUsername);

if ($results['result'] !== 'success') {
    echo "API Error: " . $results['message'];
    exit;
}

echo "Total Services Found: " . $results['totalresults'] . "\n\n";

foreach ($results['products']['product'] as $product) {
    if ($product['status'] !== 'Active') {
        continue; // Skip non-active
    }

    $serviceId = $product['id'];
    $pid = $product['pid'];
    $domain = $product['domain'];
    $clientId = $product['clientid'];

    echo "Service ID: {$serviceId}\n";
    echo "Product ID: {$pid}\n";
    echo "Domain/Instance: {$domain}\n";

    try {
        $serviceModel = Service::find($serviceId);
        $latestMetrics = $serviceModel->metrics();

        foreach ($latestMetrics as $metric) {
            // Get the metric's usage object
            // Get the metric's usage object
            $usage = $metric->usage();

            if ($usage instanceof WHMCS\UsageBilling\Metrics\NoUsage) {
                echo "  No usage data for metric: " . $metric->systemName() . "\n";
                continue;
            }

            // Only process root.disk.size metric
            if ($metric->systemName() !== 'root.disk.size') {
                continue;
            }
            
            $usageValue = $usage->value();
            $unit = $metric->units()->name();
            
            // Calculate charge for this metric
            $charge = $usageValue * $hourlyRatePerGB;
            $totalCharge += $charge;
            
            $invoiceItems[] = [
                'description' => $metric->displayName() . " - " . $usageValue . " " . $unit,
                'amount' => $charge,
                'taxed' => false
            ];
            
            echo "  Metric: {$metric->systemName()}, Usage: {$usageValue}{$unit}, Charge: \${$charge}\n";
        }

        // Create invoice if there's a charge
        if ($totalCharge > 0) {
            echo "  Total Charge for Service: \${$totalCharge}\n";
            
            // Step 1: Create the invoice
            $command = 'CreateInvoice';
            $postData = [
                'userid' => $clientId,
                'date' => date('Y-m-d'),
                'duedate' => date('Y-m-d', strtotime('+3 days')),
                'sendinvoice' => 0, // Don't send email immediately
                'notes' => 'Generated from OpenStack usage metrics',
                'autoapplycredit' => 1, // auto-apply credit if available
            ];

            foreach ($invoiceItems as $index => $item) {
                $postData["itemdescription{$index}"] = $item['description'];
                $postData["itemamount{$index}"] = $item['amount'];
                $postData["itemtaxed{$index}"] = $item['taxed'] ? '1' : '0';
            }
            
            $invoiceResult = localAPI($command, $postData, $adminUsername);
            
            if ($invoiceResult['result'] === 'success') {
                $invoiceId = $invoiceResult['invoiceid'];
                echo "  Invoice created: #{$invoiceId}\n";
                
                // Step 2: Add line items to the invoice
                printf("  Invoice #%d created with total amount: $%.2f\n", $invoiceId, $totalCharge);
                // Log the transaction
                logActivity("OpenStack Usage Invoice Created - Service: {$serviceId}, Client: {$clientId}, Invoice: {$invoiceId}, Amount: \${$totalCharge}");
                
            } else {
                echo "  Error creating invoice: " . $invoiceResult['message'] . "\n";
            }
        } else {
            echo "  No chargeable usage found\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }

    // echo "----------------------------------------\n\n";
}

echo "Script completed.\n";
