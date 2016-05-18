<?php
include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/../../init.php');

$sql = "SELECT * 
		FROM (
				SELECT 	a.id_order
						,a.date_add
						,(SELECT id_order_state FROM "._DB_PREFIX_."order_history WHERE id_order=a.id_order ORDER BY date_add DESC LIMIT 0,1) AS current_state 
				FROM "._DB_PREFIX_."orders a
				WHERE 
					module = 'veritranspay'
			) b
		WHERE date_add < TIMESTAMPADD(MINUTE,(12*60)-15,now())
			AND current_state=(SELECT DISTINCT id_order_state FROM "._DB_PREFIX_."order_state_lang WHERE name='Awaiting Veritrans payment')
		";

$results = Db::getInstance()->ExecuteS($sql);
if (!$results)
{	
	error_log('result null');
}  
else
{
	foreach ($results as $row)
	{	
		$history = new OrderHistory();
		$history->id_order = $row['id_order'];
		$order_id_notif = $row['id_order'];
		$history->changeIdOrderState( Configuration::get('PS_OS_CANCELED'), $order_id_notif);
		$history->addWithemail(true);
		
		/*$sql2 = "	INSERT INTO "._DB_PREFIX_."order_history (id_employee,id_order,id_order_state,date_add) 
					VALUES ('0','".$row['id_order']."','".Configuration::get('PS_OS_CANCELED')."',TIMESTAMPADD(MINUTE,(12*60),now()))
				";
  		Db::getInstance()->Execute($sql2);*/
	}
}
?>