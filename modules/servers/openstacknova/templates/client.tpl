<div class="openstacknova-client-template">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">
                <i class="fas fa-server"></i> Your Cloud Server
            </h3>
        </div>
        <div class="panel-body">
            {if $openstack_error}
                <div class="alert alert-danger">
                    <strong>Connection Error:</strong> {$openstack_error}<br>
                    Please contact support if this problem persists.
                </div>
            {elseif $server_info}
                <div class="row">
                    <div class="col-md-4">
                        <div class="well text-center">
                            <h4>Server Status</h4>
                            <div class="server-status-icon" style="font-size: 48px; margin: 15px 0;">
                                {if $server_info.status == 'ACTIVE'}
                                    <i class="fas fa-check-circle text-success"></i>
                                {elseif $server_info.status == 'PAUSED' || $server_info.status == 'SUSPENDED'}
                                    <i class="fas fa-pause-circle text-warning"></i>
                                {elseif $server_info.status == 'ERROR'}
                                    <i class="fas fa-exclamation-circle text-danger"></i>
                                {else}
                                    <i class="fas fa-question-circle text-muted"></i>
                                {/if}
                            </div>
                            <h3>
                                <span class="label 
                                    {if $server_info.status == 'ACTIVE'}label-success
                                    {elseif $server_info.status == 'PAUSED' || $server_info.status == 'SUSPENDED'}label-warning
                                    {elseif $server_info.status == 'ERROR'}label-danger
                                    {else}label-default{/if}">
                                    {$server_info.status}
                                </span>
                            </h3>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <h4>Server Details</h4>
                        <dl class="dl-horizontal">
                            <dt>Server Name:</dt>
                            <dd>{$server_info.name}</dd>
                            
                            <dt>Server ID:</dt>
                            <dd><code>{$server_info.id|truncate:20}</code></dd>
                            
                            <dt>Created:</dt>
                            <dd>{$server_info.created|date_format:"%B %d, %Y"}</dd>
                            
                            {if $server_info.flavor_name}
                                <dt>Flavor:</dt>
                                <dd>{$server_info.flavor_name}</dd>
                            {/if}
                            
                            {if $server_info.image_name}
                                <dt>Image:</dt>
                                <dd>{$server_info.image_name}</dd>
                            {/if}
                        </dl>
                        
                        {if $server_info.primary_ip}
                            <div class="alert alert-success">
                                <h5><i class="fas fa-network-wired"></i> Primary IP Address</h5>
                                <h3 style="margin: 10px 0; font-family: monospace;">{$server_info.primary_ip}</h3>
                                <p>
                                    <a href="http://{$server_info.primary_ip}" target="_blank" class="btn btn-xs btn-default">
                                        <i class="fas fa-external-link-alt"></i> Open HTTP
                                    </a>
                                    <button class="btn btn-xs btn-default" onclick="copyToClipboard('{$server_info.primary_ip}')">
                                        <i class="fas fa-copy"></i> Copy IP
                                    </button>
                                </p>
                            </div>
                        {/if}
                    </div>
                </div>
                
                {if $server_info.addresses}
                    <h5><i class="fas fa-network-wired"></i> Network Interfaces</h5>
                    <table class="table table-striped table-condensed">
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
                                        <td><code>{$address.addr}</code></td>
                                        <td>
                                            <span class="label label-primary">IPv{$address.version}</span>
                                            <span class="label label-info">{$address.type}</span>
                                        </td>
                                    </tr>
                                {/foreach}
                            {/foreach}
                        </tbody>
                    </table>
                {/if}
            {else}
                <div class="jumbotron text-center">
                    <h2><i class="fas fa-cloud-upload-alt"></i> Server Not Ready</h2>
                    <p>Your cloud server is being provisioned. This usually takes a few minutes.</p>
                    <div class="progress" style="width: 70%; margin: 20px auto;">
                        <div class="progress-bar progress-bar-striped active" style="width: 100%;"></div>
                    </div>
                    <p>You'll receive an email notification when your server is ready.</p>
                </div>
            {/if}
        </div>
        <div class="panel-footer">
            <div class="pull-right">
                <small class="text-muted">
                    <i class="fas fa-info-circle"></i> Powered by OpenStack Nova
                </small>
            </div>
            <div class="clearfix"></div>
        </div>
    </div>
</div>

<script>
function copyToClipboard(text) {
    var input = document.createElement('textarea');
    input.innerHTML = text;
    document.body.appendChild(input);
    input.select();
    document.execCommand('copy');
    document.body.removeChild(input);
    
    // Show a temporary notification
    alert('IP address copied to clipboard: ' + text);
}
</script>