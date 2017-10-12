<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Handle new name of plugin file
$plugins = get_option('active_plugins', array());
foreach ($plugins as $key => $active_plugin) 
{
	if(strstr($active_plugin, '/bambora_payform.php'))
		$plugins[$key] = str_replace('/bambora_payform.php', '/bambora-payform-payment-gateway.php', $active_plugin);
}
update_option('active_plugins', $plugins);