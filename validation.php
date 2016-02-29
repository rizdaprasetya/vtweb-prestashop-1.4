<?php 

$useSSL = true;

$root_dir = str_replace('modules/veritranspay', '', dirname($_SERVER['SCRIPT_FILENAME']));

include_once($root_dir.'/config/config.inc.php');

$controller = new FrontController();

if (Tools::usingSecureMode())
  $useSSL = $controller->ssl = true;

$controller->init();

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

$veritransPay = new VeritransPay();
$keys = $veritransPay->execValidation($cart,$is_discount);

$veritrans_api_version = Configuration::get('VT_API_VERSION');
$veritrans_payment_method = Configuration::get('VT_PAYMENT_TYPE');

if ($keys['errors'])
{
	var_dump($keys['errors']);
	exit;
} else
{
	if ($veritrans_api_version == 1 && $veritrans_payment_method == 'vtweb')
	{
		$veritransPay->context->smarty->assign(array(
			'payment_redirect_url' => $keys['payment_redirect_url'],
			'order_id' => $keys['order_id'],
			'token_browser' => $keys['token_browser'],
			'merchant_id' => $keys['merchant_id'],
			'this_path' => $veritransPay->this_path,
			'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$veritransPay->name.'/'
			));
		echo $veritransPay->display(__FILE__, 'views/templates/front/v1_vtweb.tpl');

	} else if ($veritrans_api_version == 1 && $veritrans_payment_method == 'vtdirect')
	{

	} else if ($veritrans_api_version == 2 && $veritrans_payment_method == 'vtweb')
	{
		Tools::redirectLink($keys['redirect_url']);
		// echo "Added discount"; //debugan
	} else if ($veritrans_api_version == 2 && $veritrans_payment_method == 'vtdirect')
	{

	}
}