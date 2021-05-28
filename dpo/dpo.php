<?php
/*
 * Copyright (c) 2021 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if ( ! defined('_PS_VERSION_')) {
    exit;
}

class Dpo extends PaymentModule
{

    const MODULES_DPO_ADMIN          = 'Modules.Dpo.Admin';
    const MODULES_CHECKPAYMENT_ADMIN = 'Modules.Checkpayment.Admin';
    private $_postErrors = array();

    public function __construct()
    {
        $this->name        = 'dpo';
        $this->tab         = 'payments_gateways';
        $this->version     = '1.0.0';
        $this->author      = 'DPO Group';
        $this->controllers = array('payment', 'validation');

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName            = $this->trans('DPO Group', array(), self::MODULES_DPO_ADMIN);
        $this->description            = $this->trans(
            'Accept payments via DPO Group.',
            array(),
            self::MODULES_DPO_ADMIN
        );
        $this->confirmUninstall       = $this->trans(
            'Are you sure you want to delete your details ?',
            array(),
            self::MODULES_DPO_ADMIN
        );
        $this->ps_versions_compliancy = array('min' => '1.7.1.0', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        return parent::install()
               && $this->registerHook('paymentOptions')
               && $this->registerHook('paymentReturn');
    }

    public function hookPaymentOptions($params)
    {
        if ( ! $this->active) {
            return [];
        }

        $paymentOption = new PaymentOption();
        $paymentOption->setModuleName($this->name)
                      ->setCallToActionText($this->trans('Pay via DPO Group', array(), self::MODULES_DPO_ADMIN))
                      ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/logo.png'))
                      ->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true));

        return [$paymentOption];
    }

    public function getContent()
    {
        $this->_html = '';

        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();
            if ( ! count($this->_postErrors)) {
                $this->_postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        }

        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Settings', array(), self::MODULES_DPO_ADMIN),
                    'icon'  => 'icon-envelope',
                ),
                'input'  => array(
                    array(
                        'type'     => 'text',
                        'label'    => $this->trans('Company Token', array(), self::MODULES_CHECKPAYMENT_ADMIN),
                        'name'     => 'DPO_COMPANY_TOKEN',
                        'required' => true,
                    ),
                    array(
                        'type'     => 'text',
                        'label'    => $this->trans('Service Type', array(), self::MODULES_DPO_ADMIN),
                        'name'     => 'DPO_SERVICE_TYPE',
                        'required' => true,
                    ),
                    array(
                        'type'   => 'switch',
                        'label'  => $this->trans('Test Mode', array(), self::MODULES_DPO_ADMIN),
                        'name'   => 'DPO_TESTMODE',
                        'values' => array(
                            array(
                                'id'    => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Yes', array(), self::MODULES_DPO_ADMIN),
                            ),
                            array(
                                'id'    => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('No', array(), self::MODULES_DPO_ADMIN),
                            ),
                        ),
                    ),
                    array(
                        'type'   => 'switch',
                        'label'  => $this->trans('Debug', array(), self::MODULES_DPO_ADMIN),
                        'name'   => 'DPO_LOGS',
                        'values' => array(
                            array(
                                'id'    => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Yes', array(), self::MODULES_DPO_ADMIN),
                            ),
                            array(
                                'id'    => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('No', array(), self::MODULES_DPO_ADMIN),
                            ),
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Admin.Actions'),
                ),
            ),
        );

        $helper                = new HelperForm();
        $helper->show_toolbar  = false;
        $helper->identifier    = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex  = $this->context->link->getAdminLink(
                'AdminModules',
                false
            ) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token         = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars      = array(
            'fields_value' => $this->getConfigFieldsValues(),
        );

        $this->fields_form = array();

        return $helper->generateForm(array($fields_form));
    }

    public function getConfigFieldsValues()
    {
        return array(
            'DPO_COMPANY_TOKEN' => Tools::getValue('DPO_COMPANY_TOKEN', Configuration::get('DPO_COMPANY_TOKEN')),
            'DPO_SERVICE_TYPE'  => Tools::getValue('DPO_SERVICE_TYPE', Configuration::get('DPO_SERVICE_TYPE')),
            'DPO_TESTMODE'      => Tools::getValue('DPO_TESTMODE', Configuration::get('DPO_TESTMODE')),
            'DPO_LOGS'          => Tools::getValue('DPO_LOGS', Configuration::get('DPO_LOGS')),
        );
    }

    public function logData($post_data)
    {
        if (Configuration::get('DPO_LOGS')) {
            $logFile = fopen(__DIR__ . '/dpo_prestashop_logs.txt', 'a+') or die('fopen failed');
            fwrite($logFile, $post_data) or die('fwrite failed');
            fclose($logFile);
        }
    }

    private function _postValidation()
    {
        if (Tools::isSubmit('btnSubmit')) {
            if ( ! Tools::getValue('DPO_COMPANY_TOKEN')) {
                $this->_postErrors[] = $this->trans(
                    'The "Company Token" field is required.',
                    array(),
                    self::MODULES_CHECKPAYMENT_ADMIN
                );
            } elseif ( ! Tools::getValue('DPO_SERVICE_TYPE')) {
                $this->_postErrors[] = $this->trans(
                    'The "Service Type" field is required.',
                    array(),
                    self::MODULES_CHECKPAYMENT_ADMIN
                );
            }
        }
    }

    private function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue('DPO_COMPANY_TOKEN', Tools::getValue('DPO_COMPANY_TOKEN'));
            Configuration::updateValue('DPO_SERVICE_TYPE', Tools::getValue('DPO_SERVICE_TYPE'));
            Configuration::updateValue('DPO_TESTMODE', Tools::getValue('DPO_TESTMODE'));
            Configuration::updateValue('DPO_LOGS', Tools::getValue('DPO_LOGS'));
        }
        $this->_html .= $this->displayConfirmation(
            $this->trans('Settings updated', array(), 'Admin.Notifications.Success')
        );
    }

}
