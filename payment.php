<?php 

$useSSL = true;

$root_dir = str_replace('modules/veritranspay', '', dirname($_SERVER['SCRIPT_FILENAME']));

include_once($root_dir.'/config/config.inc.php');
include_once($root_dir.'/header.php');
include_once($root_dir.'/modules/veritranspay/veritranspay.php');

// added discount GET query
$is_discount = 0;
if (isset($_GET['is_discount'])) {
	$is_discount = $_GET['is_discount'];
}
// end of additon

if (!$cookie->isLogged(true))
  Tools::redirect('authentication.php?back=order.php');
elseif (!Customer::getAddressesTotalById((int)($cookie->id_customer)))
  Tools::redirect('address.php?back=order.php?step=1');

$veritranspay = new VeritransPay();
echo $veritranspay->execPayment($cart,$is_discount);  // add is_discount param

include_once($root_dir.'/footer.php');