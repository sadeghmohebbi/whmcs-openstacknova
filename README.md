# whmcs-openstacknova
WHMCS server module to integrate openstack nova IaaS image instance full lifecycle

## Features

- [x] Create Instance
- [x] Openstack connection via keystone
- [ ] Usage Metrics and Pay-as-you-go Usage based billing support
- [ ] Termination and Suspending Instance
- [ ] Simple Clientarea

## How to install

First, Copy modules/servers/openstacknova to WHMCS:/var/www/whmcs/modules/servers/

```bash
cp -r modules/servers/openstacknova /var/www/whmcs/modules/servers/
```

Also, Copy custom cron script to whmcs root directory:
```bash
cp cron_billing_usage_automation.php  /var/www/whmcs/
```


Then, to install custom cronjob. you should modify local user crontab as below:
```bash
# run every 15 minutes
*/5 * * * * /usr/bin/php /var/www/whmcs/crons/cron.php > /var/log/whmcs-cron

# run every hours
0 * * * * /usr/bin/php /var/www/whmcs/cron_billing_usage_automation.php > /var/log/whmcs-cron
```