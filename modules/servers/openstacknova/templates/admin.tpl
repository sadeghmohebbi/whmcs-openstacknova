<div class="openstacknova-admin-template">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">
                <i class="fas fa-cloud"></i> OpenStack Instance Details
            </h3>
        </div>
        <div class="panel-body">
            {if $openstack_error}
                <div class="alert alert-danger">
                    <strong>Error:</strong> {$openstack_error}
                </div>
            {elseif $server_info}
                <div class="row">
                    <div class="col-md-6">
                        <h4>Server Information</h4>
                        <table class="table table-striped">
                            <tr>
                                <td><strong>Server ID:</strong></td>
                                <td>{$server_info.id}</td>
                            </tr>
                            <tr>
                                <td><strong>Name:</strong></td>
                                <td>{$server_info.name}</td>
                            </tr>
                            <tr>
                                <td><strong>Status:</strong></td>
                                <td>
                                    <span class="label 
                                        {if $server_info.status == 'ACTIVE'}label-success
                                        {elseif $server_info.status == 'PAUSED' || $server_info.status == 'SUSPENDED'}label-warning
                                        {elseif $server_info.status == 'ERROR'}label-danger
                                        {else}label-default{/if}">
                                        {$server_info.status}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Created:</strong></td>
                                <td>{$server_info.created|date_format:"%Y-%m-%d %H:%M:%S"}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h4>Instance Actions</h4>
                        <div class="btn-group" role="group">
                            <a href="#" class="btn btn-sm btn-default" onclick="refreshServerInfo(); return false;">
                                <i class="fas fa-sync-alt"></i> Refresh Status
                            </a>
                            {if $server_info.status == 'ACTIVE'}
                                <a href="clientarea.php?action=productdetails&id={$serviceid}&modop=custom&a=suspend" 
                                   class="btn btn-sm btn-warning" 
                                   onclick="return confirm('Are you sure you want to suspend this instance?');">
                                    <i class="fas fa-pause"></i> Suspend
                                </a>
                            {elseif $server_info.status == 'PAUSED' || $server_info.status == 'SUSPENDED'}
                                <a href="clientarea.php?action=productdetails&id={$serviceid}&modop=custom&a=unsuspend" 
                                   class="btn btn-sm btn-success" 
                                   onclick="return confirm('Are you sure you want to unsuspend this instance?');">
                                    <i class="fas fa-play"></i> Unsuspend
                                </a>
                            {/if}
                            <a href="clientarea.php?action=productdetails&id={$serviceid}&modop=custom&a=reboot" 
                               class="btn btn-sm btn-info" 
                               onclick="return confirm('Are you sure you want to reboot this instance?');">
                                <i class="fas fa-redo"></i> Reboot
                            </a>
                            <a href="clientarea.php?action=productdetails&id={$serviceid}&modop=custom&a=terminate" 
                               class="btn btn-sm btn-danger" 
                               onclick="return confirm('WARNING: This will permanently delete the instance! Are you sure?');">
                                <i class="fas fa-trash"></i> Terminate
                            </a>
                        </div>
                    </div>
                </div>
                
                {if $server_info.addresses}
                    <h4>Network Information</h4>
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Network</th>
                                <th>IP Address</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $server_info.addresses as $network_name => $addresses}
                                {foreach $addresses as $address}
                                    <tr>
                                        <td>{$network_name}</td>
                                        <td>{$address.addr}</td>
                                        <td>{$address.version} ({$address.type})</td>
                                    </tr>
                                {/foreach}
                            {/foreach}
                        </tbody>
                    </table>
                {/if}
            {else}
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> No server information available. The instance may not be provisioned yet.
                </div>
            {/if}
        </div>
    </div>
</div>

<script>
function refreshServerInfo() {
    location.reload();
}
</script>