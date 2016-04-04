<?php

if (!defined('_PS_VERSION_'))
	exit;

require_once('library/veritrans.php');
require_once 'library/lib/veritrans_notification.php';

class VeritransPay extends PaymentModule
{
	private $_html = '';
	private $_postErrors = array();

	public $veritrans_merchant_id;
	public $veritrans_merchant_hash;
	public $veritrans_kurs;
	public $veritrans_convenience_fee;
	public $veritrans_client_key;
	public $veritrans_server_key;
	public $veritrans_api_version;
	public $veritrans_installments;
	public $veritrans_3d_secure;
	public $veritrans_payment_type;
	public $veritrans_payment_success_status_mapping;
	public $veritrans_payment_failure_status_mapping;
	public $veritrans_payment_challenge_status_mapping;
	public $veritrans_environment;
	public $veritrans_bin_numbers;

	public $config_keys;

	public function __construct()
	{
		$this->name = 'veritranspay';
		$this->tab = 'payments_gateways';
		$this->version = '0.6';
		$this->author = 'Veritrans';
		$this->bootstrap = true;
		
		$this->currencies = true;
		$this->currencies_mode = 'checkbox';
		$this->veritrans_convenience_fee = 0;

		// key length must be between 0-32 chars to maintain compatibility with <= 1.5
		$this->config_keys = array(
			'VT_MERCHANT_ID', 
			'VT_MERCHANT_HASH',
			'VT_CLIENT_KEY',
			'VT_SERVER_KEY',
			'VT_API_VERSION',
			'VT_PAYMENT_TYPE',
			'VT_3D_SECURE',
			'VT_KURS',
			'VT_CONVENIENCE_FEE',
			'VT_PAYMENT_SUCCESS_STATUS_MAP',
			'VT_PAYMENT_FAILURE_STATUS_MAP',
			'VT_PAYMENT_CHALLENGE_STATUS_MAP',
			'VT_ENVIRONMENT',
			'VT_BIN_NUMBER', //add bin number
			'VT_BIN_NUMBER2', //add bin number
			'VT_DISCOUNT_BTN', //add discount btn
			'VT_TITLE_BTN', //add discount btn
			'VT_TITLE_BTN2', //add discount btn
			);

		foreach (array('BNI', 'MANDIRI', 'CIMB') as $bank) {
			foreach (array(3, 6, 9, 12, 18, 24) as $months) {
				array_push($this->config_keys, 'VT_INSTALLMENTS_' . $bank . '_' . $months);
			}
		}

		$config = Configuration::getMultiple($this->config_keys);

		foreach ($this->config_keys as $key) {
			if (isset($config[$key]))
				$this->{strtolower($key)} = $config[$key];
		}
		
		// if (isset($config['VT_MERCHANT_HASH']))
		// 	$this->veritrans_merchant_hash = $config['VT_MERCHANT_HASH'];
		if (isset($config['VT_KURS']))
			$this->veritrans_kurs = $config['VT_KURS'];
		else
			Configuration::set('VT_KURS', 10000);
		// else Configuration::set('VT_KURS',1);
		// if (isset($config['VT_CONVENIENCE_FEE']))
		// 	$this->veritrans_convenience_fee = $config['VT_CONVENIENCE_FEE'];
		// else Configuration::set('VT_CONVENIENCE_FEE',0);
		if (isset($config['VT_API_VERSION']) && in_array($config['VT_API_VERSION'], array(1, 2)))
			$this->veritrans_api_version = $config['VT_API_VERSION'];
		else
			Configuration::set('VT_API_VERSION', 2);

		parent::__construct();

		$this->displayName = $this->l('Veritrans Pay');
		$this->description = $this->l('Accept payments for your products via Veritrans.');
		$this->confirmUninstall = $this->l('Are you sure about uninstalling Veritrans pay?');
		
		if (!isset($this->veritrans_merchant_id) || !isset($this->veritrans_merchant_hash))
			$this->warning = $this->l('Merchant ID and Merchant Hash must be configured before using this module.');
		
		if (!count(Currency::checkPaymentCurrencies($this->id)))
			$this->warning = $this->l('No currency has been set for this module.');

		$this->extra_mail_vars = array(
			'{veritrans_merchant_id}' => Configuration::get('VT_MERCHANT_ID'),
			'{veritrans_merchant_hash}' => nl2br(Configuration::get('VT_MERCHANT_HASH'))
			);

		// Retrocompatibility
		$this->initContext();
	}

	public function install()
	{
		// create a new order state for Veritrans, since Prestashop won't assign order ID unless it is validated,
		// and no default order states matches the state we want. Assigning order_id with uniqid() will confuse
		// users in the future
		$order_state = new OrderStateCore();
		$order_state->name = array((int)Configuration::get('PS_LANG_DEFAULT') => 'Awaiting Veritrans payment');;
		$order_state->module_name = 'veritranspay';
		$order_state->color = '#0000FF';
		$order_state->unremovable = false;
		$order_state->add();

		Configuration::updateValue('VT_ORDER_STATE_ID', $order_state->id);
		Configuration::updateValue('VT_API_VERSION', 1);

		if (!parent::install() || 
			!$this->registerHook('payment') ||
			!$this->registerHook('header') ||
			!$this->registerHook('backOfficeHeader') ||
			!$this->registerHook('orderConfirmation'))
			return false;
		
		include_once(_PS_MODULE_DIR_ . '/' . $this->name . '/vtpay_install.php');
		$vtpay_install = new VeritransPayInstall();
		$vtpay_install->createTable();

		return true;
	}

	public function uninstall()
	{
		$status = true;

		$veritrans_payment_waiting_order_state_id = Configuration::get('VT_ORDER_STATE_ID');
		if ($veritrans_payment_waiting_order_state_id)
		{
			$order_state = new OrderStateCore($veritrans_payment_waiting_order_state_id);
			$order_state->delete();
		}
		
		// foreach ($this->config_keys as $key) {
		// 	if (!Configuration::deleteByName($key))
		// 		$status = false;
		// }
		if (!parent::uninstall())
			$status = false;
		return $status;
	}

	private function _postValidation()
	{
		if (Tools::isSubmit('btnSubmit'))
		{
			if (Tools::getValue('VT_API_VERSION') == 2)
			{
				if (!Tools::getValue('VT_CLIENT_KEY'))
					$this->_postErrors[] = $this->l('Client Key is required.');
				if (!Tools::getValue('VT_SERVER_KEY'))
					$this->_postErrors[] = $this->l('Server Key is required.');
			} else
			{
				if (!Tools::getValue('VT_MERCHANT_HASH'))
					$this->_postErrors[] = $this->l('Merchant Hash is required.');
				if (!Tools::getValue('VT_MERCHANT_ID'))
					$this->_postErrors[] = $this->l('Merchant ID is required.');
			}

			// validate conversion rate existence
			if (!Currency::exists('IDR', null) && !Tools::getValue('VT_KURS'))
			{
				$this->_postErrors[] = $this->l('Currency conversion rate must be filled when IDR is not installed in the system.');
			}
		}
	}

	private function _postProcess()
	{
		if (Tools::isSubmit('btnSubmit'))
		{
			foreach ($this->config_keys as $key) {
				Configuration::updateValue($key, Tools::getValue($key));
			}	
		}
		$this->_html .= '<div class="alert alert-success conf confirm"> '.$this->l('Settings updated').'</div>';
	}

	private function _displayVeritransPay()
	{
		if (version_compare(Configuration::get('PS_VERSION_DB'), '1.5') == -1)
		{
			$output = $this->context->smarty->fetch(__DIR__ . '/views/templates/hook/infos.tpl');
			$this->_html .= $output;
		} else
		{
			$this->_html .= $this->display(__FILE__, 'infos.tpl');
		}
	}

	private function _displayVeritransPayOld()
	{
		$this->_html .= '<img src="../modules/veritranspay/veritrans.jpg" style="float:left; margin-right:15px;"><b>'.$this->l('This module allows payment via veritrans.').'</b><br/><br/>
		'.$this->l('Payment via veritrans.').'<br /><br /><br />';
	}

	private function _displayForm()
	{
		if (version_compare(Configuration::get('PS_VERSION_DB'), '1.5') == -1) {
			// retrocompatibility with Prestashop 1.4
			$this->_displayFormOld();
		} else
		{
			$this->_displayFormNew();
		}
		
	}

	public function getConfigFieldsValues()
	{
		$result = array();
		foreach ($this->config_keys as $key) {
			$result[$key] = Tools::getValue($key, Configuration::get($key));
		}
		return $result;
	}

	private function _displayFormNew()
	{
		$installments_options = array();
		foreach (array('BNI', 'MANDIRI', 'CIMB') as $bank) {
			$installments_options[$bank] = array();
			foreach (array(3, 6, 9, 12, 18, 24) as $months) {
				array_push($installments_options[$bank], array(
					'id_option' => $bank . '_' . $months,
					'name' => $months . ' Months'
					));
			}
		}

		$order_states = array();
		foreach (OrderState::getOrderStates($this->context->language->id) as $state) {
			array_push($order_states, array(
				'id_option' => $state['id_order_state'],
				'name' => $state['name']
				)
			);
		}

		$environments = array(
			array(
				'id_option' => 'development',
				'name' => 'Development'
				),
			array(
				'id_option' => 'production',
				'name' => 'Production'
				)
			);

		$discount_btns = array(
			array(
				'id_option' => 'disable',
				'name' => 'Disable'
				),
			array(
				'id_option' => 'enable',
				'name' => 'Enable'
				)
			);

		$fields_form = array(
			'form' => array(
				'legend' => array(
					'title' => 'Basic Information',
					'icon' => 'icon-cogs'
					),
				'input' => array(
					array(
						'type' => 'select',
						'label' => 'API Version',
						'required' => true,
						'name' => 'VT_API_VERSION',
						'is_bool' => false,
						'options' => array(
							'query' => array(
								array(
									'id_option' => 1,
									'name' => 'v1'
									),
								array(
									'id_option' => 2,
									'name' => 'v2'
									)
								),
							'id' => 'id_option',
							'name' => 'name'
							),
						'id' => 'veritransApiVersion'
						),
					array(
						'type' => 'select',
						'label' => 'Environment',
						'name' => 'VT_ENVIRONMENT',
						'required' => true,
						'options' => array(
							'query' => $environments,
							'id' => 'id_option',
							'name' => 'name'
							),
						'class' => 'v2_settings sensitive'
						),
					array(
						'type' => 'text',
						'label' => 'Merchant ID',
						'name' => 'VT_MERCHANT_ID',
						'required' => true,
						'desc' => 'Consult to your Merchant Administration Portal for the value of this field.',
						'class' => 'v1_vtweb_settings sensitive'
						),
					array(
						'type' => 'text',
						'label' => 'Merchant Hash Key',
						'name' => 'VT_MERCHANT_HASH',
						'required' => true,
						'desc' => 'Consult to your Merchant Administration Portal for the value of this field.',
						'class' => 'v1_vtweb_settings sensitive'
						),
					array(
						'type' => 'text',
						'label' => 'VT-Direct Client Key',
						'name' => 'VT_CLIENT_KEY',
						'required' => true,
						'desc' => 'Consult to your Merchant Administration Portal for the value of this field.',
						'class' => 'v1_vtdirect_settings v2_settings sensitive'
						),
					array(
						'type' => 'text',
						'label' => 'VT-Direct Server Key',
						'name' => 'VT_SERVER_KEY',
						'required' => true,
						'desc' => 'Consult to your Merchant Administration Portal for the value of this field.',
						'class' => 'v1_vtdirect_settings v2_settings sensitive'
						),
					array(
						'type' => 'select',
						'label' => 'Payment Type',
						'name' => 'VT_PAYMENT_TYPE',
						'required' => true,
						'is_bool' => false,
						'options' => array(
							'query' => array(
								array(
									'id_option' => 'vtweb',
									'name' => 'VT-Web'
									),
								array(
									'id_option' => 'vtdirect',
									'name' => 'VT-Direct'
									)
								),
							'id' => 'id_option',
							'name' => 'name'
							),
						'id' => 'veritransPaymentType'
						),
					array(
						'type' => 'checkbox',
						'label' => 'Enable BNI Installments?',
						'name' => 'VT_INSTALLMENTS',
						'values' => array(
							'query' => $installments_options['BNI'],
							'id' => 'id_option',
							'name' => 'name'
							),
						'class' => 'v1_vtweb_settings sensitive'
						),
					array(
						'type' => 'checkbox',
						'label' => 'Enable Mandiri Installments?',
						'name' => 'VT_INSTALLMENTS',
						'values' => array(
							'query' => $installments_options['MANDIRI'],
							'id' => 'id_option',
							'name' => 'name'
							),
						'class' => 'v1_vtweb_settings sensitive'
						),
					array(
						'type' => 'checkbox',
						'label' => 'Enable CIMB Installments?',
						'name' => 'VT_INSTALLMENTS',
						'values' => array(
							'query' => $installments_options['CIMB'],
							'id' => 'id_option',
							'name' => 'name'
							),
						'class' => 'v1_vtweb_settings sensitive'
						),
					array(
						'type' => 'radio',
						'label' => 'Enable 3D Secure?',
						'name' => 'VT_3D_SECURE',
						'required' => true,
						'is_bool' => true,
						'values' => array(
							array(
								'id' => '3d_secure_yes',
								'value' => 1,
								'label' => 'Yes'
								),
							array(
								'id' => '3d_secure_no',
								'value' => 0,
								'label' => 'No'
								)
							),
						'class' => 'v1_settings sensitive'
						),
					array(
						'type' => 'select',
						'label' => 'Map payment SUCCESS status to:',
						'name' => 'VT_PAYMENT_SUCCESS_STATUS_MAP',
						'required' => true,	
						'options' => array(
							'query' => $order_states,
							'id' => 'id_option',
							'name' => 'name'
							),
						'class' => ''
						),
					array(
						'type' => 'select',
						'label' => 'Map payment FAILURE status to:',
						'name' => 'VT_PAYMENT_FAILURE_STATUS_MAP',
						'required' => true,
						'options' => array(
							'query' => $order_states,
							'id' => 'id_option',
							'name' => 'name'
							),
						'class' => ''
						),
					array(
						'type' => 'select',
						'label' => 'Map payment CHALLENGE status to:',
						'name' => 'VT_PAYMENT_CHALLENGE_STATUS_MAP',
						'required' => true,
						'options' => array(
							'query' => $order_states,
							'id' => 'id_option',
							'name' => 'name'
							),
						'class' => ''
						),
					array(
						'type' => 'text',
						'label' => 'Payment button display title',
						'name' => 'VT_TITLE_BTN',
						'required' => false,
						'desc' => 'Displayed payment button text in checkout page',
						),
					array(
						'type' => 'text',
						'label' => 'BIN Filter numbers',
						'name' => 'VT_BIN_NUMBER',
						'desc' => 'Insert bin numbers to filter credit card payment.'
						),
					array(
						'type' => 'select',
						'label' => 'Enable additional payment button for discount',
						'name' => 'VT_DISCOUNT_BTN',
						'required' => true,
						'options' => array(
							'query' => $discount_btns,
							'id' => 'id_option',
							'name' => 'name'
							),
						'class' => 'v2_settings sensitive'
						),
					array(
						'type' => 'text',
						'label' => 'BIN Filter numbers for discounted payment button',
						'name' => 'VT_BIN_NUMBER2',
						'desc' => 'Insert bin numbers to filter for credit card payment in the discounted payment button.'
						),
					array(
						'type' => 'text',
						'label' => 'Discounted payment button display title',
						'name' => 'VT_TITLE_BTN2',
						'required' => false,
						'desc' => 'Displayed discounted payment button text in checkout page',
						),
					array(
						'type' => 'text',
						'label' => 'IDR Conversion Rate',
						'name' => 'VT_KURS',
						'desc' => 'Veritrans will use this rate to convert prices to IDR when there are no default conversion system.'
						),
					),
				'submit' => array(
					'title' => $this->l('Save'),
					)
				)
			);

		$helper = new HelperForm();
		$helper->show_toolbar = false;
		$helper->table =  $this->table;
		$lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$this->fields_form = array();
		$helper->id = (int)Tools::getValue('id_carrier');
		$helper->identifier = $this->identifier;
		$helper->submit_action = 'btnSubmit';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars = array(
			'fields_value' => $this->getConfigFieldsValues(),
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id
		);

		$this->_html .= $helper->generateForm(array($fields_form));
	}

	private function _displayFormOld()
	{
		$order_states = array();
		foreach (OrderState::getOrderStates($this->context->cookie->id_lang) as $state) {
			array_push($order_states, array(
				'id_option' => $state['id_order_state'],
				'name' => $state['name']
				)
			);
		}

		$discount_btns = array(
			array(
				'id_option' => 'disable',
				'name' => 'Disable'
				),
			array(
				'id_option' => 'enable',
				'name' => 'Enable'
				)
		);

		$this->context->smarty->assign(array(
			'form_url' => Tools::htmlentitiesUTF8($_SERVER['REQUEST_URI']),
			'merchant_id' => htmlentities(Configuration::get('VT_MERCHANT_ID'), ENT_COMPAT, 'UTF-8'),
			'merchant_hash_key' => htmlentities(Configuration::get('VT_MERCHANT_HASH'), ENT_COMPAT, 'UTF-8'),
			'api_version' => htmlentities(Configuration::get('VT_API_VERSION'), ENT_COMPAT, 'UTF-8'),
			'api_versions' => array(1 => 'v1', 2 => 'v2'),
			'payment_type' => htmlentities(Configuration::get('VT_PAYMENT_TYPE'), ENT_COMPAT, 'UTF-8'),
			'payment_types' => array('vtweb' => 'VT-Web', 'vtdirect' => 'VT-Direct'),
			'client_key' => htmlentities(Configuration::get('VT_CLIENT_KEY'), ENT_COMPAT, 'UTF-8'),
			'server_key' => htmlentities(Configuration::get('VT_SERVER_KEY'), ENT_COMPAT, 'UTF-8'),
			'environments' => array(Veritrans::ENVIRONMENT_DEVELOPMENT => 'Development', Veritrans::ENVIRONMENT_PRODUCTION => 'Production'),
			'environment' => htmlentities(Configuration::get('VT_ENVIRONMENT'), ENT_COMPAT, 'UTF-8'),
			'discount_btns' => $discount_btns,
			'discount_btn' => htmlentities(Configuration::get('VT_DISCOUNT_BTN'), ENT_COMPAT, 'UTF-8'),
			'bin_number' => htmlentities(Configuration::get('VT_BIN_NUMBER'), ENT_COMPAT, 'UTF-8'), // Add bin number
			'bin_number2' => htmlentities(Configuration::get('VT_BIN_NUMBER2'), ENT_COMPAT, 'UTF-8'), // Add bin number
			'title_btn' => htmlentities(Configuration::get('VT_TITLE_BTN'), ENT_COMPAT, 'UTF-8'),
			'title_btn2' => htmlentities(Configuration::get('VT_TITLE_BTN2'), ENT_COMPAT, 'UTF-8'),
			'statuses' => $order_states,
			'payment_success_status_map' => htmlentities(Configuration::get('VT_PAYMENT_SUCCESS_STATUS_MAP'), ENT_COMPAT, 'UTF-8'),
			'payment_challenge_status_map' => htmlentities(Configuration::get('VT_PAYMENT_CHALLENGE_STATUS_MAP'), ENT_COMPAT, 'UTF-8'),
			'payment_failure_status_map' => htmlentities(Configuration::get('VT_PAYMENT_FAILURE_STATUS_MAP'), ENT_COMPAT, 'UTF-8'),
			'kurs' => htmlentities(Configuration::get('VT_KURS', $this->veritrans_kurs), ENT_COMPAT, 'UTF-8'),
			'convenience_fee' => htmlentities(Configuration::get('VT_CONVENIENCE_FEE', $this->veritrans_convenience_fee), ENT_COMPAT, 'UTF-8'),
			'this_path' => $this->_path,
			'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->name.'/'
			));
		$output = $this->context->smarty->fetch(__DIR__ . '/views/templates/hook/admin_retro.tpl');
		$this->_html .= $output;
	}

	public function getContent()
	{
		// $this->_html = '<h2>'.$this->displayName.'</h2>';

		if (Tools::isSubmit('btnSubmit'))
		{
			$this->_postValidation();
			if (!count($this->_postErrors))
				$this->_postProcess();
			else
				foreach ($this->_postErrors as $err)
					$this->_html .= '<div class="alert alert-danger error">'. $err . '</div>';
		}
		else
			$this->_html .= '<br />';

		$this->_displayVeritransPay();
		$this->_displayForm();

		return $this->_html;
	}

	public function hookDisplayHeader($params)
	{
		// $this->context->controller->addJS($this->_path . 'js/veritrans_admin.js', 'all');
		// exit;
		// is this supposed to work in 1.4?
	}

	// working in 1.5 and 1.6
	public function hookDisplayBackOfficeHeader($params)
	{
		$this->context->controller->addJS($this->_path . 'js/veritrans_admin.js', 'all');
	}

	public function hookPayment($params)
	{
		return $this->hookDisplayPayment($params);				
	}

	public function hookDisplayPayment($params)
	{
		if (!$this->active)
			return;

		if (!$this->checkCurrency($params['cart']))
			return;

		$cart = $this->context->cart;

		$discount_btn_enabled = false;  //check if btn enabled
		if(Configuration::get('VT_DISCOUNT_BTN')=='enable')
			$discount_btn_enabled = true;

		if(strlen(Configuration::get('VT_TITLE_BTN')) < 1)
			$title_btn = 'Pay using Veritrans (credit card and others)';
		else
			$title_btn = Configuration::get('VT_TITLE_BTN');
		if(strlen(Configuration::get('VT_TITLE_BTN2')) < 1)
			$title_btn2 = 'Pay with promo discount';
		else
			$title_btn2 = Configuration::get('VT_TITLE_BTN2');

		$this->context->smarty->assign(array(
			'cart' => $cart,
			'title_btn' => $title_btn,
			'title_btn2' => $title_btn2,
			'discount_btn_enabled' => $discount_btn_enabled,
			'this_path' => $this->_path,
			'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->name.'/'
		));

		// 1.4 compatibility
		if (version_compare(Configuration::get('PS_VERSION_DB'), '1.5') == -1) {
			return $this->display(__FILE__, 'views/templates/hook/payment.tpl');
		} else
		{
			return $this->display(__FILE__, 'payment.tpl');	
		}
	}

	public function hookOrderConfirmation($params)
	{
		if (!$this->active)
			return;

		if (!$this->checkCurrency($params['cart']))
			return;

		$order = new Order(Tools::getValue('id_order'));
		$history = $order->getHistory($this->context->cookie->id_lang);
		$history = $history[0];

		$this->context->smarty->assign(array(
			'transaction_status' => $history['id_order_state'],
			'cart' => $this->context->cart,
			'this_path' => $this->_path,
			'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->name.'/'
		));
	
		// 1.4 compatibility
		if (version_compare(Configuration::get('PS_VERSION_DB'), '1.5') == -1) {
			return $this->display(__FILE__, 'views/templates/hook/order_confirmation.tpl');
		} else
		{
			return $this->display(__FILE__, 'order_confirmation.tpl');	
		}
	}
	
	public function checkCurrency($cart)
	{
		$currency_order = new Currency($cart->id_currency);
		$currencies_module = $this->getCurrency($cart->id_currency);

		if (is_array($currencies_module))
			foreach ($currencies_module as $currency_module)
				if ($currency_order->id == $currency_module['id_currency'])
					return true;
		return false;
	}

	// Retrocompatibility 1.4/1.5
	private function initContext()
	{
	  if (class_exists('Context'))
	    $this->context = Context::getContext();
	  else
	  {
	    global $smarty, $cookie;
	    $this->context = new StdClass();
	    $this->context->smarty = $smarty;
	    $this->context->cookie = $cookie;
	    if (array_key_exists('cart', $GLOBALS))
	    {
	    	global $cart;
	    	$this->context->cart = $cart;
	    }
	    if (array_key_exists('link', $GLOBALS))
	    {
	    	global $link;
	    	$this->context->link = $link;
	    }
	  }
	}

	// Retrocompatibility 1.4
	public function execPayment($cart,$is_discount)
	{
	    if($is_discount && Configuration::get('VT_DISCOUNT_BTN') == 'enable'){
	    	// $veritrans->payment_methods = array('credit_card');
			
			// add cart voucher
			$discount = new Discount((int)(Discount::getIdByName('veritrans')));
			if(!$cart->addDiscount((int)($discount->id)))
				error_log('--------------- discount failed to be added -----------------');
			$cart->update();
			$discount_value = $discount->getValue($nb_discounts = 0, $cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING), $shipping_fees = 0, $cart->id, $use_tax = true);
			if ($tmpError = $cart->checkDiscountValidity($discount, $cart->getDiscounts(), $cart->getOrderTotal(), $cart->getProducts(), true))
				error_log("################## error ################ ".print_r($tmpError,true)); //debugan
			// error_log('is discount @execValidation: '.$is_discount." | discount code: ".$code.' | $Cart: '.print_r($cart,true)); //debugan
			// end of addition
	    }

		// error_log('is discount, execPayment'.$is_discount); //debugan
		if (!$this->active)
			return ;
		if (!$this->checkCurrency($cart))
			Tools::redirectLink(__PS_BASE_URI__.'order.php');

		$link = new Link();

		global $cookie, $smarty;

		$smarty->assign(array(
			'is_discount' => $is_discount,
			'payment_type' => Configuration::get('VT_PAYMENT_TYPE'),
      'api_version' => Configuration::get('VT_API_VERSION'),
			'error_message' => '',
			'link' => $link,
			'nbProducts' => $cart->nbProducts(),
			'cust_currency' => $cart->id_currency,
			'currencies' => $this->getCurrency((int)$cart->id_currency),
			'total' => $cart->getOrderTotal(true, Cart::BOTH),
			'this_path' => $this->_path,
			'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.((int)Configuration::get('PS_REWRITING_SETTINGS') && count(Language::getLanguages()) > 1 && isset($smarty->ps_language) && !empty($smarty->ps_language) ? $smarty->ps_language->iso_code.'/' : '').'modules/'.$this->name.'/'
		));

		return $this->display(__FILE__, 'views/templates/front/payment_execution.tpl');
	}

	// Retrocompatibility 1.4
	public function execValidation($cart,$is_discount)
	{
		global $cookie;

		if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->active)
			Tools::redirect('index.php?controller=order&step=1');

		// Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
		$authorized = false;
		foreach (Module::getPaymentModules() as $module)
			if ($module['name'] == 'veritranspay')
			{
				$authorized = true;
				break;
			}
		if (!$authorized)
			die($this->module->l('This payment method is not available.', 'validation'));

		$customer = new Customer($cart->id_customer);
    if (!Validate::isLoadedObject($customer))
     Tools::redirect('index.php?controller=order&step=1');

   	$usd = Configuration::get('VT_KURS');
    $cf = Configuration::get('VT_CONVENIENCE_FEE') * 0.01;
    $veritrans = new Veritrans();
    $url = Veritrans::PAYMENT_REDIRECT_URL;

    if (version_compare(Configuration::get('PS_VERSION_DB'), '1.5') == -1)
    {
    	$shipping_cost = $cart->getOrderShippingCost();
    } else
    {
    	$shipping_cost = $cart->getTotalShippingCost();
    }

    $currency = new Currency($cookie->id_currency);
    $total = $cart->getOrderTotal(true, Cart::BOTH);
    $mailVars = array(
     '{merchant_id}' => Configuration::get('MERCHANT_ID'),
     '{merchant_hash}' => nl2br(Configuration::get('MERCHANT_HASH'))
    );

    $billing_address = new Address($cart->id_address_invoice);
    $delivery_address = new Address($cart->id_address_delivery);

    $veritrans->version = Configuration::get('VT_API_VERSION');
    $veritrans->environment = Configuration::get('VT_ENVIRONMENT');
    $veritrans->payment_type = Configuration::get('VT_PAYMENT_TYPE') == 'vtdirect' ? Veritrans::VT_DIRECT : Veritrans::VT_WEB;
    $veritrans->merchant_id = Configuration::get('VT_MERCHANT_ID');
    $veritrans->merchant_hash_key = Configuration::get('VT_MERCHANT_HASH');
    $veritrans->client_key = Configuration::get('VT_CLIENT_KEY');
    $veritrans->server_key = Configuration::get('VT_SERVER_KEY');
    // $veritrans->enable_3d_secure = Configuration::get('VT_3D_SECURE');
    $veritrans->enable_3d_secure = true;
    $veritrans->force_sanitization = true;
    
    // Billing Address
    $veritrans->first_name = $billing_address->firstname;
    $veritrans->last_name = $billing_address->lastname;
    $veritrans->address1 = $billing_address->address1;
    $veritrans->address2 = $billing_address->address2;
    $veritrans->city = $billing_address->city;
    // $veritrans->country_code = $billing_address->id_country;
    $veritrans->country_code = "IDN";
    $veritrans->postal_code = $billing_address->postcode;
    $veritrans->phone = $this->determineValidPhone($billing_address->phone, $billing_address->phone_mobile);
    $veritrans->email = $customer->email;

    // add bin promo
    $bins = null;
    if(strlen(Configuration::get('VT_BIN_NUMBER')) > 0)
    	$bins = explode(',',Configuration::get('VT_BIN_NUMBER'));
    
    if($is_discount){
    	if(strlen(Configuration::get('VT_BIN_NUMBER2')) > 0)
    		$bins = explode(',',Configuration::get('VT_BIN_NUMBER2'));
    	$veritrans->payment_methods = array('credit_card');
		// error_log("===========  discount_value : ".print_r($discount_value,true)); //debugan
		// error_log("===========  cart->getDiscounts : ".print_r($cart->getDiscounts(),true)); //debugan
		// error_log("===========  Order : ".print_r($order,true)); //debugan
    }
    $veritrans->promo_bins = $bins;
    // error_log("=============== ".print_r($veritrans->promo_bins,true)." ==============="); //debugan

    if($cart->isVirtualCart()) {
     $veritrans->required_shipping_address = 0;
     $veritrans->billing_different_with_shipping = 0;
    } else {
     $veritrans->required_shipping_address = 1;
     if ($cart->id_address_delivery != $cart->id_address_invoice)
     {
       $veritrans->billing_different_with_shipping = 1;
       $veritrans->shipping_first_name = $delivery_address->firstname;
       $veritrans->shipping_last_name = $delivery_address->lastname;
       $veritrans->shipping_address1 = $delivery_address->address1;
       $veritrans->shipping_address2 = $delivery_address->address2;
       $veritrans->shipping_city = $delivery_address->city;
       // $veritrans->shipping_country_code = $delivery_address->id_country;
       $veritrans->shipping_country_code = "IDN";
       $veritrans->shipping_postal_code = $delivery_address->postcode;
       $veritrans->shipping_phone = $this->determineValidPhone($delivery_address->phone, $delivery_address->phone_mobile);
     } else
     {
       $veritrans->billing_different_with_shipping = 0;
     }
    }  
    
    $items = $this->addCommodities($cart, $shipping_cost, $usd);
    
    // convert the currency
    $cart_currency = new Currency($cart->id_currency);
    if ($cart_currency->iso_code != 'IDR')
    {
	    // check whether if the IDR is installed or not
	    if (Currency::exists('IDR', null))
	    {
	      // use default rate
	      if (version_compare(Configuration::get('PS_VERSION_DB'), '1.5') == -1)
	      {
	      	$conversion_func = function($input) use($cart_currency) { return Tools::convertPrice($input, new Currency(Currency::getIdByIsoCode('IDR')), true); };
	      } else
	      {
	      	$conversion_func = function($input) use($cart_currency) { return Tools::convertPriceFull($input, $cart_currency, new Currency(Currency::getIdByIsoCode('IDR'))); };
	      }
	    } else
	    {
	      // use rate
	      $conversion_func = function($input) { return $input * intval(Configuration::get('VT_KURS')); };
	    }
	    foreach ($items as &$item) {
	      $item['price'] = intval(round(call_user_func($conversion_func, $item['price'])));
	    }
    }
    $veritrans->items = $items;

    $this->validateOrder($cart->id, Configuration::get('VT_ORDER_STATE_ID'), $cart->getOrderTotal(true, Cart::BOTH), $this->displayName, NULL, $mailVars, (int)$currency->id, false, $customer->secure_key);
    $veritrans->order_id = $this->currentOrder;

    if ($veritrans->version == 1 && $veritrans->payment_type == Veritrans::VT_WEB)
    {
     	$keys = $veritrans->getTokens();
      if ($keys)
      {
      	$keys['payment_redirect_url'] = Veritrans::PAYMENT_REDIRECT_URL;
      	$keys['order_id'] = $veritrans->order_id;
      	$keys['merchant_id'] = $veritrans->merchant_id;
      	$keys['errors'] = NULL;
      	$this->insertTransaction($cart->id_customer, $cart->id, $currency->id, $veritrans->order_id, $keys['token_merchant']);        
     } else
     {
       	$keys['errors'] = $veritrans->errors;
     }

     return $keys;
      
    } else if ($veritrans->version == 1 && $veritrans->payment_type == Veritrans::VT_DIRECT)
    {
     // handle v1's VTDirect, v2's VTWEB, and v2's VTDIRECT here
    } else if ($veritrans->version == 2 && $veritrans->payment_type == Veritrans::VT_WEB)
    {
    	$keys = $veritrans->getTokens();
     	// error_log('promo bins: '.print_r($veritrans->promo_bins,true));  //debugan
     	// error_log('keys : '.print_r($keys,true)); //debugan
    	if (!in_array($keys['status_code'], array(200, 201, 202)))
    	{
    		$keys['errors'] = array(
    			'status_code' => $keys['status_code'],
    			'status_message' => $keys['status_message']);
    	} else
    	{
    		$keys['errors'] = NULL;
    	}
    	return $keys;
    	
    } else if ($veritrans->version == 2 && $veritrans->payment_type == Veritrans::VT_DIRECT)
    {

    } else
    {
    	echo 'The Veritrans API versions and the payment type is not valid.';
    	exit;
    }
	}

	public function setMedia()
	{
		Tools::addJs('function onloadEvent() { document.form_auto_post.submit(); }');
	}

	public function addCommodities($cart, $shipping_cost, $usd)
	{
		
		$products = $cart->getProducts();
		$discount = -1 * $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS);

		if (version_compare(Configuration::get('PS_VERSION_DB'), '1.5') == -1){ // for 1.4 version, voucher is negative
			$discount *= -1;
		}

		$commodities = array();
		$price = 0;

		foreach ($products as $aProduct) {
			$commodities[] = array(
				"item_id" => $aProduct['id_product'],
				"price" =>  $aProduct['price_wt'],
				"quantity" => $aProduct['cart_quantity'],
				"item_name1" => substr($aProduct['name'], 0, 49),
				"item_name2" => substr($aProduct['name'], 0, 49)
			);
		}

		if($shipping_cost != 0){
			$commodities[] = array(
				"item_id" => 'SHIPPING_FEE',
				"price" => $shipping_cost, // defer currency conversion until the very last time
				"quantity" => '1',
				"item_name1" => 'Shipping Cost',
				"item_name2" => 'Biaya Pengiriman'
			);			
		}

		if($discount != 0){
			$commodities[] = array(
				"item_id" => 'DISCOUNT_VOUCHER',
				"price" => $discount, // defer currency conversion until the very last time
				"quantity" => '1',
				"item_name1" => 'discount from voucher',				
			);	
		}
		// error_log('$cart : '.print_r($cart,true));//debugan
		// error_log('$discount : '.$discount);//debugan
		// error_log(print_r($commodities,true));//debugan
		
		return $commodities;
	}

	function insertTransaction($customer_id, $id_cart, $id_currency, $request_id, $token_merchant)
	{
		$sql = 'INSERT INTO `'._DB_PREFIX_.'vt_transaction`
				(`id_customer`, `id_cart`, `id_currency`, `request_id`, `token_merchant`)
				VALUES ('.(int)$customer_id.',
					'.(int)$id_cart.',
					'.(int)$id_currency.',
						\''.$request_id.'\',
						\''.$token_merchant.'\')';
		Db::getInstance()->Execute($sql);
	}

	function getTransaction($request_id)
  {
    $sql = 'SELECT *
        FROM `'._DB_PREFIX_.'vt_transaction`
        WHERE `request_id` = \''.$request_id.'\'';
    $result = Db::getInstance()->getRow($sql);
    return $result; 
  }

	// determine the phone number to make Veritrans happy
	function determineValidPhone($home_phone = '', $mobile_phone = '')
	{
		if (empty($home_phone) && !empty($mobile_phone))
		{
			return $mobile_phone;
		} else if (!empty($home_phone) && empty($mobile_phone))
		{
			return $home_phone;
		} else if (!empty($home_phone) && !empty($mobile_phone))
		{
			return $mobile_phone;
		} else
		{
			return '081111111111';
		}
	}

	public function execNotification()
	{
		// $mailVars = array(
		//   '{merchant_id}' => Configuration::get('VT_MERCHANT_ID'),
		//   '{merchant_hash}' => nl2br(Configuration::get('VT_MERCHANT_HASH'))
		// );

		// $veritrans_notification = new VeritransNotification();
		$history = new OrderHistory();

		/** Validating order*/
		if (Configuration::get('VT_API_VERSION') == 2)
		{
		  
		  $raw_notification = json_decode(file_get_contents("php://input"), true);
		  error_log(" Array notification from veritrans :");
		  error_log(print_r($raw_notification,true));
		  error_log("========================================================");
		  // exit;
		  $history->id_order = (int)$raw_notification['order_id'];

		  // confirm back to Veritrans server
		  // $veritrans = new Veritrans();
		  // $veritrans->server_key = Configuration::get('VT_SERVER_KEY');
		  // $confirmation = $veritrans->confirm($veritrans_notification->order_id);

		  if (true)
		  {
		    if ($raw_notification['transaction_status'] == 'capture')
		    {
		      $history->changeIdOrderState(Configuration::get('VT_PAYMENT_SUCCESS_STATUS_MAP'), (int)$raw_notification['order_id']);
		      echo 'Valid success notification accepted.';
		    } else if ($raw_notification['transaction_status'] == 'challenge')
		    {
		      $history->changeIdOrderState(Configuration::get('VT_PAYMENT_CHALLENGE_STATUS_MAP'), (int)$raw_notification['order_id']);
		      echo 'Valid challenge notification accepted.';
		    } else
		    {
		      $history->changeIdOrderState(Configuration::get('VT_PAYMENT_FAILURE_STATUS_MAP'), (int)$raw_notification['order_id']);
		      echo 'Valid failure notification accepted';
		    }
		    $history->add(true);
		  } else
		  {
		  	echo 'There is an error contacting the Veritrans server when validating the notification.';
		  }

		} else if (Configuration::get('VT_API_VERSION') == 1)
		{
		  $history->id_order = (int)$veritrans_notification->orderId; 
		  $transaction = $this->getTransaction($veritrans_notification->orderId);
		  $token_merchant = $transaction['token_merchant'];
		  
		  if ($veritrans_notification->status != 'fatal')
		  {
		    if($token_merchant == $veritrans_notification->TOKEN_MERCHANT)
		    {
		      if ($veritrans_notification->mStatus == 'success')
		      { 
		        $history->changeIdOrderState(Configuration::get('VT_PAYMENT_SUCCESS_STATUS_MAP'), (int)$veritrans_notification->orderId);
		      }
		      elseif ($veritrans_notification->mStatus == 'failure')
		      {
		        $history->changeIdOrderState(Configuration::get('VT_PAYMENT_FAILURE_STATUS_MAP'), (int)$veritrans_notification->orderId);
		      }
		      elseif ($veritrans_notification->mStatus == 'challenge')
		      {
		        $history->changeIdOrderState(Configuration::get('VT_PAYMENT_FAILURE_STATUS_MAP'), (int)$veritrans_notification->orderId);
		      }
		      $history->add(true);
		    }
		    else
		    {
		    	echo 'no transaction<br/>';
		    }
		  }
		}
		exit;
	}
}
