<?php
/**
 * 2007-2015 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2015 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$classes_to_load = array(
    'SfLogger',
    'SfDebugger',
);

foreach ($classes_to_load as $classname) {
    if (file_exists(dirname(__FILE__) . '/classes/' . $classname . '.php')) {
        require_once(dirname(__FILE__) . '/classes/' . $classname . '.php');
    }
}
include_once(dirname(__FILE__).'/sfpayment.php');

class ShoppingFluxExport extends Module
{
    private $default_country = null;
    private $_html = '';

    public function __construct()
    {
        $this->name = 'shoppingfluxexport';
        $this->tab = 'smart_shopping';
        $this->version = '4.6.1';
        $this->author = 'PrestaShop';
        $this->limited_countries = array('fr', 'us');
        $this->module_key = '08b3cf6b1a86256e876b485ff9bd4135';

        parent::__construct();

        $this->displayName = $this->l('Shopping Feed Official');
        $this->description = $this->l('Export all your products to Google Shopping, eBay, Amazon, Rakuten, etc...');
        $this->confirmUninstall = $this->l('Delete this plugin ?');

        $id_default_country = Configuration::get('PS_COUNTRY_DEFAULT');
        $this->default_country = new Country($id_default_country);
        
        // Set default passes if not existing
        $productsToBeTreated = Configuration::get('SHOPPING_FLUX_PASSES');
        if (empty($productsToBeTreated) || !isset($productsToBeTreated)) {
            Configuration::updateValue('SHOPPING_FLUX_PASSES', '200');
        }

        // Check if matching carrier have inconsistencies
        if (!$this->checkCarriersInconsistencies() || !$this->checkDefaultCarrierConsistency()) {
            // Show a warning message when there is an inconsistency with carriers
            $this->warning = $this->l('One of the carrier used for ShoppingFlux has been removed or disabled in your shop. Please check the module configuration.');
        }
    }

    public function install()
    {
        return (parent::install() && $this->_initHooks() && $this->_initConfig());
    }

    /* REGISTER HOOKS */
    protected function _initHooks()
    {
        $registerHookNewOrder = $this->registerHook('actionObjectAddAfter');
        
        if (!$this->registerHook('postUpdateOrderStatus') ||
            !$this->registerHook('backOfficeTop') ||
            !$registerHookNewOrder ||
            !$this->registerHook('top')) {
                return false;
        }
        return true;
    }

    /* SET DEFAULT CONFIGURATION */
    protected function _initConfig()
    {
        //Avoid servers IPs
        Db::getInstance()->Execute('
            CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'customer_ip` (
            `id_customer_ip` int(10) unsigned NOT null AUTO_INCREMENT,
            `id_customer` int(10) unsigned NOT null,
            `ip` varchar(32) DEFAULT null,
            PRIMARY KEY (`id_customer_ip`),
            KEY `idx_id_customer` (`id_customer`)
            ) ENGINE='._MYSQL_ENGINE_.'  DEFAULT CHARSET=utf8;');

        $imageName = $this->getMaxImageInformation();

        if (! method_exists('ImageType', 'getFormatedName')) {
            $imageName = '';
        }
        
        $installResult = true;
        if (version_compare(_PS_VERSION_, '1.5', '>') && Shop::isFeatureActive()) {
            foreach (Shop::getShops() as $shop) {
                if (!Configuration::updateValue('SHOPPING_FLUX_CANCELED', Configuration::get('PS_OS_CANCELED'), false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_SHIPPED', Configuration::get('PS_OS_SHIPPING'), false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_IMAGE', $imageName, false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_CARRIER', Configuration::get('PS_CARRIER_DEFAULT'), false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_TRACKING', 'checked', false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_ORDERS', 'checked', false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_STATUS_SHIPPED', 'checked', false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_STATUS_CANCELED', '', false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_REF', '', false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_LOGIN', '', false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_MULTITOKEN', 0, false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_INDEX', Tools::getCurrentUrlProtocolPrefix() . $shop['domain'] . $shop['uri'], false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_STOCKS', '', false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_PACKS', '', false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_PASSES', '300', false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_ORDERS_DEBUG', true, false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_DEBUG_ERRORS', false, false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_DEBUG', true, false, null, $shop['id_shop']) ||
                !Configuration::updateValue('SHOPPING_FLUX_STATE_MP_EXP', Configuration::get('PS_OS_SHIPPING'), false, null, $shop['id_shop'])
                ) {
                    $installResult = false;
                }
            }
        } else {
            if (!Configuration::updateValue('SHOPPING_FLUX_CANCELED', Configuration::get('PS_OS_CANCELED')) ||
                     !Configuration::updateValue('SHOPPING_FLUX_SHIPPED', Configuration::get('PS_OS_SHIPPING')) ||
                     !Configuration::updateValue('SHOPPING_FLUX_IMAGE', $imageName) ||
                     !Configuration::updateValue('SHOPPING_FLUX_CARRIER', Configuration::get('PS_CARRIER_DEFAULT')) ||
                     !Configuration::updateValue('SHOPPING_FLUX_TRACKING', 'checked') ||
                     !Configuration::updateValue('SHOPPING_FLUX_ORDERS', 'checked') ||
                     !Configuration::updateValue('SHOPPING_FLUX_STATUS_SHIPPED', 'checked') ||
                     !Configuration::updateValue('SHOPPING_FLUX_STATUS_CANCELED', '') ||
                     !Configuration::updateValue('SHOPPING_FLUX_LOGIN', '') ||
                     !Configuration::updateValue('SHOPPING_FLUX_REF', '') ||
                     !Configuration::updateValue('SHOPPING_FLUX_MULTITOKEN', 0) ||
                     !Configuration::updateValue('SHOPPING_FLUX_INDEX', Tools::getCurrentUrlProtocolPrefix().$shop['domain'].$shop['uri']) ||
                     !Configuration::updateValue('SHOPPING_FLUX_STOCKS', '') ||
                     !Configuration::updateValue('SHOPPING_FLUX_PACKS', '') ||
                     !Configuration::updateValue('SHOPPING_FLUX_PASSES', '300') ||
                     !Configuration::updateValue('SHOPPING_FLUX_ORDERS_DEBUG', true) ||
                     !Configuration::updateValue('SHOPPING_FLUX_DEBUG_ERRORS', false) ||
                     !Configuration::updateValue('SHOPPING_FLUX_DEBUG', true) ||
                     !Configuration::updateValue('SHOPPING_FLUX_STATE_MP_EXP', Configuration::get('PS_OS_SHIPPING'))
                 ) {
                $installResult = false;
            }
        }
        
        // Used to add the shop ID in the feed name (when generating the feed.xml file)
        // Not enabled by default
        if (! Configuration::updateGlobalValue('SHOPPING_FLUX_XML_SHOP_ID', false)) {
            $installResult = false;
        }

        // Generate multitoken default values
        if (version_compare(_PS_VERSION_, '1.5', '>')) {
            $shops = Shop::getShops();
            // Loop on shops for multiple token
            foreach ($shops as &$currentShop) {
                $tokenShop = md5(rand());
                if (! $this->getTokenValue($currentShop['id_shop'])) {
                    $this->setTokenValue($tokenShop, $currentShop['id_shop']);
                    
                    $shopLanguages = Language::getLanguages(true, $currentShop['id_shop']);
                    $shopCurrencies = Currency::getCurrenciesByIdShop($currentShop['id_shop']);

                    // Loop on languages
                    foreach ($shopLanguages as $currentLang) {
                        $idLang = $currentLang['id_lang'];
                        // Finally loop on currencies
                        foreach ($shopCurrencies as $currentCurrency) {
                            $idCurrency = $currentCurrency['id_currency'];
                            if (! $this->getTokenValue($currentShop['id_shop'], null, $idCurrency, $idLang)) {
                                $this->setTokenValue($tokenShop, $currentShop['id_shop'], $idCurrency, $idLang);
                            }
                        }
                    }
                }
            }
        } else {
            if (Shop::isFeatureActive()) {
                foreach (Shop::getShops() as $shop) {
                    if (! Configuration::updateValue('SHOPPING_FLUX_TOKEN', md5(rand()), false, null, $shop['id_shop'])) {
                        $installResult = false;
                    }
                }
            } else {
                if (! Configuration::updateValue('SHOPPING_FLUX_TOKEN', md5(rand()))) {
                    $installResult = false;
                }
            }
        }

        return $installResult;
    }

    public function uninstall()
    {

        if (!Configuration::deleteByName('SHOPPING_FLUX_TOKEN') ||
                !Configuration::deleteByName('SHOPPING_FLUX_CANCELED') ||
                !Configuration::deleteByName('SHOPPING_FLUX_SHIPPED') ||
                !Configuration::deleteByName('SHOPPING_FLUX_IMAGE') ||
                !Configuration::deleteByName('SHOPPING_FLUX_TRACKING') ||
                !Configuration::deleteByName('SHOPPING_FLUX_ORDERS') ||
                !Configuration::deleteByName('SHOPPING_FLUX_STATUS_SHIPPED') ||
                !Configuration::deleteByName('SHOPPING_FLUX_STATUS_CANCELED') ||
                !Configuration::deleteByName('SHOPPING_FLUX_LOGIN') ||
                !Configuration::deleteByName('SHOPPING_FLUX_REF') ||
                !Configuration::deleteByName('SHOPPING_FLUX_INDEX') ||
                !Configuration::deleteByName('SHOPPING_FLUX_STOCKS') ||
                !Configuration::deleteByName('SHOPPING_FLUX_PACKS') ||
                !Configuration::deleteByName('SHOPPING_FLUX_SHIPPING_MATCHING') ||
                !Configuration::deleteByName('SHOPPING_FLUX_PASSES') ||
                !Configuration::deleteByName('SHOPPING_FLUX_MULTITOKEN') ||
                !Configuration::deleteByName('SHOPPING_FLUX_ORDERS_DEBUG') ||
                !Configuration::deleteByName('SHOPPING_FLUX_DEBUG') ||
                !Configuration::deleteByName('SHOPPING_FLUX_XML_SHOP_ID') ||
                !Configuration::deleteByName('SHOPPING_FLUX_CRON_TIME') ||
                !$this->uninstallCustomConfiguration(array('SHOPPING_FLUX_CRON_TIME')) ||
                !$this->uninstallCustomConfiguration(array('SHOPPING_FLUX_TOKEN')) ||
                !Configuration::deleteByName('SHOPPING_FLUX_STATE_MP_EXP') ||
                !parent::uninstall()) {
            return false;
        }

        return true;
    }

    /**
     * Uninstall Custom Configuration records for compose name
     * Exemple : SHOPPING_FLUX_CRON_TIME_XX (FR, EN, ES..)
     * @param  array $keysBaseNames
     * @return bool uninstall statut
     */
    protected function uninstallCustomConfiguration($keysBaseNames)
    {
        $uninstallState = true;

        // Build the SQL where condition
        $sqlLike = "";
        foreach ($keysBaseNames as $aName) {
            $sqlLike = $sqlLike !== "" ? $sqlLike." OR " : "";
            $sqlLike .= "configuration.`name` LIKE '".$aName."%'";
        }

        // Get all configuration variables for CRON_TIME
        $sql = "SELECT * FROM " . _DB_PREFIX_ . "configuration configuration ".
            " WHERE ".$sqlLike;

        $configurationRecords = Db::getInstance()->ExecuteS($sql);

        if (!$configurationRecords) {
            // Nothing to delete
            return true;
        }

        // We remove each configuration keys corresponding to the base name
        foreach ($configurationRecords as $row) {
            if (!Configuration::deleteByName($row['name'])) {
                $uninstallState = false;
            }
        }

        return $uninstallState;
    }

    public function getContent()
    {
        $this->setREF();
        
        $status_xml = $this->_checkToken();
        $status = is_object($status_xml) ? $status_xml->Response->Status : '';
        
        switch ($status) {
            case 'Client':
                $this->_html .= $this->_clientView();
                //we do this here for retro compatibility
                $this->_setShoppingFeedId();
                break;
            case 'Prospect':
                $this->_html .= $this->displayConfirmation($this->l('You are now registered for your free trial. Our team will contact you soon.'));
                // No break, we want the code below to be executed
            case 'New':
            default:
                $this->_html .= $this->_defaultView();
                break;
        }
        //we do this here for retro compatibility
        $this->_setShoppingFeedId();

        if (!in_array('curl', get_loaded_extensions())) {
            $this->_html .= '<br/><strong>'.$this->l('You have to install Curl extension to use this plugin. Please contact your IT team.').'</strong>';
        } else {
            Configuration::updateValue('SHOPPINGFLUXEXPORT_CONFIGURED', true); // SHOPPINGFLUXEXPORT_CONFIGURATION_OK
        }
        
        if (version_compare(_PS_VERSION_, '1.5', '>') && Shop::isFeatureActive()) {
            foreach (Shop::getShops() as $shop) {
                $lockFile = dirname(__FILE__).'/cron_'.$shop['id_shop'].'.lock';
                if (file_exists($lockFile)) {
                    // Remove lock
                    $fp = fopen($lockFile, 'r+');
                    fflush($fp);
                    flock($fp, LOCK_UN);
                    fclose($fp);
                }
            }
        }
        
        return $this->_html;
    }

    /* Check wether the Token is known by Shopping Flux */
    protected function _checkToken()
    {
        return $this->_callWebService('IsClient');
    }

    /* Default view when site isn't in Shopping Flux DB */
    protected function _defaultView()
    {
        //uri feed
        if (version_compare(_PS_VERSION_, '1.5', '>') && Shop::isFeatureActive()) {
            $shop = Context::getContext()->shop;
            $base_uri = Tools::getCurrentUrlProtocolPrefix().$shop->domain.$shop->physical_uri.$shop->virtual_uri;
            $uri = Tools::getCurrentUrlProtocolPrefix().$shop->domain.$shop->physical_uri.$shop->virtual_uri.'modules/shoppingfluxexport/flux.php?token='.$this->getTokenValue();
        } else {
            $base_uri = Tools::getCurrentUrlProtocolPrefix().Tools::getHttpHost().__PS_BASE_URI__;
            $uri = Tools::getCurrentUrlProtocolPrefix().Tools::getHttpHost().__PS_BASE_URI__.'modules/shoppingfluxexport/flux.php?token='.$this->getTokenValue();
        }

        //post process
        $send_mail = Tools::getValue('send_mail');
        if (isset($send_mail) && $send_mail != null) {
            $this->sendMail();
        }

        //get fieldset depending on the country
        $country = $this->default_country->iso_code == 'FR' ? 'fr' : 'us';

        $html = '<p style="text-align:center"><img style="margin:20px; width: 250px" src="'.Tools::safeOutput($base_uri).'modules/shoppingfluxexport/views/img/logo_'.$country.'.jpg" /></p>';

        $html .= '<div style="width:50%; float:left"><fieldset>
        <legend>'.$this->l('Information(s)').'</legend>
        <p style="text-align:center; margin:10px 0 30px 0; font-weight: bold" ><a style="padding:10px 20px; font-size:1.5em" target="_blank" href="https://register.shopping-feed.com/sign/prestashop?phone='.urlencode(Tools::safeOutput(Configuration::get('PS_SHOP_PHONE'))).'&email='.Tools::safeOutput(Configuration::get('PS_SHOP_EMAIL')).'&feed='.Tools::safeOutput($uri).'&lang='.Tools::strtolower($this->default_country->iso_code).'" onclick="" value="'.$this->l('Send').'" class="button">'.$this->l('Register Now!').'</a></p>
        <h2><b>'.$this->l('Shopping Feed exports your products to the largest marketplaces in the world, all from a single intuitive platform.').'</b> '.$this->l('Through our free setup and expert support, we help thousands of storefronts increase their sales and visibility.').'</h2>
        <br/><p style="line-height:2em;">
        <b>'.$this->l('Put your feeds to work:').' </b>'.$this->l('A single platform to manage your products and sales on the world\'s marketplaces.').'<br />
        <b>'.$this->l('Set it and forget it:').' </b>'.$this->l('Automated order processes for each marketplace channel you sell on, quadrupling your revenue, and not your workload.').'<br />
        <b>'.$this->l('Try Before You Buy:'). '</b> '.$this->l('Expert Channel Setup is always free on Shopping Feed, giving you risk-free access to your brand new channel before becoming a member.').' <br />
        </p><br/>
        <ol style="line-height:2em; list-style-type:circle; padding: 0 0 0 20px">
        <li>'.$this->l('Optimize your channels, and calculate realtime Return On Investment for all the leading comparison shopping engines like Google Shopping, Ratuken, shopping.com, NextTag, ShopZilla and more.').'</li>
        <li>'.$this->l('Connect your storefront to all the major marketplaces like eBay, Amazon, Sears and 11 Main, while managing your pricing, inventory, and merchandising through a single, intuitive platform.').'</li>
        <li>'.$this->l('Prepare for an evolving ecosystem: New features, tools, and integrations are being created every month, at no extra cost.').'</li>
        <li>'.$this->l('Be seen: With over 50 different marketplaces and shopping engines under one roof, Shopping Feed helps you find your right audience.').'</li>
        </ol><br/>
        <h3>'.$this->l('With over 1400 Members worldwide, helping them achieve over $13 Million in monthly revenue,').' <b>'.$this->l('Lets us help you put your feeds to work.').'</b></h3>
        </fieldset></div>';

        $html .= '<div style="width:45%; float: left; padding: 10px 0 0 10px"><img src="'.Tools::getCurrentUrlProtocolPrefix().Tools::getHttpHost().__PS_BASE_URI__.'modules/shoppingfluxexport/views/img/ad.png"></div>';

        $html .= '<div style="clear:both"></div>';

        return $html;
    }

    /* View when site is client */
    protected function _clientView()
    {
        $this->_treatForm();

        $configuration = Configuration::getMultiple(array('SHOPPING_FLUX_TRACKING',
                    'SHOPPING_FLUX_ORDERS', 'SHOPPING_FLUX_STATUS_SHIPPED', 'SHOPPING_FLUX_STATUS_CANCELED', 'SHOPPING_FLUX_LOGIN',
                    'SHOPPING_FLUX_STOCKS', 'SHOPPING_FLUX_PACKS', 'SHOPPING_FLUX_INDEX', 'PS_LANG_DEFAULT', 'SHOPPING_FLUX_CARRIER',
                    'SHOPPING_FLUX_IMAGE', 'SHOPPING_FLUX_SHIPPED', 'SHOPPING_FLUX_CANCELED', 'SHOPPING_FLUX_SHIPPING_MATCHING',
                    'SHOPPING_FLUX_STATE_MP_EXP', 'SHOPPING_FLUX_PASSES'));
        
        $configuration['SHOPPING_FLUX_XML_SHOP_ID'] = Configuration::getGlobalValue('SHOPPING_FLUX_XML_SHOP_ID');
        
        // Retrieve custom fields from override that can be in products
        $fields = $this->getOverrideFields();
        foreach ($fields as $fieldname) {
            $configuration['SHOPPING_FLUX_CUSTOM_'.$fieldname] = Configuration::get('SHOPPING_FLUX_CUSTOM_'.$fieldname);
        }

        $html = $this->_getFeedContent();
        $html .= $this->_getParametersContent($configuration);
        $html .= $this->_getAdvancedParametersContent($configuration);
        $html .= $this->defaultAdvancedParameterInformationView($configuration);
        if (version_compare(_PS_VERSION_, '1.5', '>')) {
            $html .= $this->defaultTokenConfigurationView();
        }
        $html .= $this->defaultInformationView($configuration);

        return $html;
    }
    
    /* Fieldset for params */
    protected function _getParametersContent($configuration)
    {
        return '<form method="post" action="'.Tools::safeOutput($_SERVER['REQUEST_URI']).'">
                    <fieldset>
                        <legend>'.$this->l('Parameters').'</legend>
                        <p><label>Login '.$this->l('Shopping Feed').' : </label><input type="text" name="SHOPPING_FLUX_LOGIN" value="'.Tools::safeOutput($configuration['SHOPPING_FLUX_LOGIN']).'"/></p>
                        <p><label>Token '.$this->l('Shopping Feed').' : </label><input type="text" name="SHOPPING_FLUX_TOKEN" value="'.$this->getTokenValue().'" style="width:auto"/></p>
                        <p><label>'.$this->l('Order tracking').' : </label><input type="checkbox" name="SHOPPING_FLUX_TRACKING" '.Tools::safeOutput($configuration['SHOPPING_FLUX_TRACKING']).'/> '.$this->l('orders coming from shopbots will be tracked').'.</p>
                        <p><label>'.$this->l('Order importation').' : </label><input type="checkbox" name="SHOPPING_FLUX_ORDERS" '.Tools::safeOutput($configuration['SHOPPING_FLUX_ORDERS']).'/> '.$this->l('orders coming from marketplaces will be imported').'.</p>
                        <p><label>'.$this->l('Order shipment').' : </label><input type="checkbox" name="SHOPPING_FLUX_STATUS_SHIPPED" '.Tools::safeOutput($configuration['SHOPPING_FLUX_STATUS_SHIPPED']).'/> '.$this->l('orders shipped on your Prestashop will be shipped on marketplaces').'.</p>
                        <p><label>'.$this->l('Order cancellation').' : </label><input type="checkbox" name="SHOPPING_FLUX_STATUS_CANCELED" '.Tools::safeOutput($configuration['SHOPPING_FLUX_STATUS_CANCELED']).'/> '.$this->l('orders shipped on your Prestashop will be canceled on marketplaces').'.</p>
                        <p><label>'.$this->l('Sync stock and orders').' : </label><input type="checkbox" name="SHOPPING_FLUX_STOCKS" '.Tools::safeOutput($configuration['SHOPPING_FLUX_STOCKS']).'/> '.$this->l('every stock and price movement will be transfered to marletplaces').'.</p>
                        <p><label>'.$this->l('Load packs').' : </label><input type="checkbox" name="SHOPPING_FLUX_PACKS" '.Tools::safeOutput($configuration['SHOPPING_FLUX_PACKS']).'/> '.$this->l('Load Product packs too').'</p>
                        <p><label>'.$this->l('Shop ID in feed name').' : </label><input type="checkbox" name="SHOPPING_FLUX_XML_SHOP_ID" '.Tools::safeOutput($configuration['SHOPPING_FLUX_XML_SHOP_ID']).'/> '.$this->l('Add the shop ID to the name of the generated xml file (such as feed_1.xml)').'</p>
                        <p><label>'.$this->l('Default carrier').' : </label>'.$this->_getCarriersSelect($configuration, $configuration['SHOPPING_FLUX_CARRIER']).'</p>
                        <p><label>'.$this->l('Default image type').' : </label>'.$this->_getImageTypeSelect($configuration).'</p>
                        <p style="display: inline-block;"><label>' . $this->l('Default status for orders coming from marketplaces managing stocks and delivery') . ' : </label>' . $this->getContentMarketPlaceExpeditedOrderState($configuration) . '</p>
                        <p><label>'.$this->l('Call marketplace for shipping when order state become').' : </label>'.$this->_getOrderStateShippedSelect($configuration).'</p>
                        <p style="margin-top:20px"><label>'.$this->l('Call marketplace for cancellation when order state become').' : </label>'.$this->_getOrderStateCanceledSelect($configuration).'</p>'
                         .$this->getOverrideFieldsContent($configuration).'
                        <p style="margin-top:20px"><input type="submit" value="'.$this->l('Update').'" name="rec_config" class="button"/></p>
                    </fieldset>
                </form>';
    }

    /**
     * Bloc that displays in configurato
     * @param unknown $configuration
     */
    protected function _getAdvancedParametersContent($configuration)
    {
        if (!$this->isCurlInstalled(true)) {
            return;
        }

        $sf_carriers = $this->getCarriersFromWebService();
        if (!$sf_carriers) {
            return;
        }

        $html = '<h3>'.$this->l('Advanced Parameters').'</h3>
            <form method="post" action="'.Tools::safeOutput($_SERVER['REQUEST_URI']).'">
                <fieldset>
                    <legend>'.$this->l('Carriers Matching').'</legend>
                    <p>'.$this->l('Please see below carriers coming from your markeplaces managed on Shopping Feed. You can match them to your Prestashop carriers').'</p>';

        $actual_configuration = unserialize($configuration['SHOPPING_FLUX_SHIPPING_MATCHING']);

        foreach ($sf_carriers as $sf_carrier) {
            $actual_value = isset($actual_configuration[base64_encode(Tools::safeOutput($sf_carrier))]) ? $actual_configuration[base64_encode(Tools::safeOutput($sf_carrier))] : $configuration['SHOPPING_FLUX_CARRIER'];
            $html .= '<p><label>'.Tools::safeOutput($sf_carrier).' : </label>'.$this->_getCarriersSelect($configuration, $actual_value, 'MATCHING['.base64_encode(Tools::safeOutput($sf_carrier)).']').'</p>';
        }

        $html .= '<p style="margin-top:20px"><input type="submit" value="'.$this->l('Update').'" name="rec_shipping_config" class="button"/></p>
                </fieldset>
            </form>';

        return $html;
    }

    protected function _getCarriersSelect($configuration, $actual_value, $name = 'SHOPPING_FLUX_CARRIER')
    {
        $html = '<select name="'.Tools::safeOutput($name).'">';

        foreach (Carrier::getCarriers($configuration['PS_LANG_DEFAULT'], true, false, false, null, 5) as $carrier) {
            $selected = (int)$actual_value === (int)$carrier['id_reference'] ? 'selected = "selected"' : '';
            $html .= '<option value="'.(int)$carrier['id_reference'].'" '.$selected.'>'.Tools::safeOutput($carrier['name']).'</option>';
        }

        $html .= '</select>';

        return $html;
    }

    protected function _getImageTypeSelect($configuration)
    {
        $html = '<select name="SHOPPING_FLUX_IMAGE">';

        foreach (ImageType::getImagesTypes() as $imagetype) {
            $selected = $configuration['SHOPPING_FLUX_IMAGE'] == $imagetype['name'] ? 'selected = "selected"' : '';
            $html .= '<option value="'.$imagetype['name'].'" '.$selected.'>'.Tools::safeOutput($imagetype['name']).'</option>';
        }

        $html .= '</select>';

        return $html;
    }

    protected function _getOrderStateShippedSelect($configuration)
    {
        $html = '<select name="SHOPPING_FLUX_SHIPPED">';

        foreach (OrderState::getOrderStates($configuration['PS_LANG_DEFAULT']) as $orderState) {
            $selected = (int)$configuration['SHOPPING_FLUX_SHIPPED'] === (int)$orderState['id_order_state'] ? 'selected = "selected"' : '';
            $html .= '<option value="'.$orderState['id_order_state'].'" '.$selected.'>'.Tools::safeOutput($orderState['name']).'</option>';
        }

        $html .= '</select>';

        return $html;
    }

    protected function _getOrderStateCanceledSelect($configuration)
    {
        $html = '<select name="SHOPPING_FLUX_CANCELED">';

        foreach (OrderState::getOrderStates($configuration['PS_LANG_DEFAULT']) as $orderState) {
            $selected = (int)$configuration['SHOPPING_FLUX_CANCELED'] === (int)$orderState['id_order_state'] ? 'selected = "selected"' : '';
            $html .= '<option value="'.$orderState['id_order_state'].'" '.$selected.'>'.Tools::safeOutput($orderState['name']).'</option>';
        }

        $html .= '</select>';

        return $html;
    }

    /* Fieldset for feed URI */
    protected function _getFeedContent()
    {
        //uri feed
        if (version_compare(_PS_VERSION_, '1.5', '>') && Shop::isFeatureActive()) {
            $shop = Context::getContext()->shop;
            $base_uri = Tools::getCurrentUrlProtocolPrefix().$shop->domain.$shop->physical_uri.$shop->virtual_uri;
        } else {
            $base_uri = Tools::getCurrentUrlProtocolPrefix().Tools::getHttpHost().__PS_BASE_URI__;
        }

        $uri = $base_uri.'modules/shoppingfluxexport/flux.php?token='.$this->getTokenValue();
        $logo = $this->default_country->iso_code == 'FR' ? 'fr' : 'us';

        $html = '
        <img style="margin:10px; width:250px" src="'.Tools::safeOutput($base_uri).'modules/shoppingfluxexport/views/img/logo_'.$logo.'.jpg" />
        ';
        $html .= $this->_getWarnings();
        $html .= '
        <fieldset>
            <legend>'.$this->l('Your feeds').'</legend>
            <p>
                <a href="'.Tools::safeOutput($uri).'" target="_blank">
                    '.Tools::safeOutput($uri).'
                </a>
            </p>
        </fieldset>
        <br/>';
        return $html;
    }

    protected function _getWarnings()
    {
        $warnings = "";
        // Check if matching carriers have inconsistencies
        if (!$this->checkCarriersInconsistencies()) {
            // Show a warning message when there is an inconsistency with carriers
            $warnings .= $this->displayWarning($this->l('One of the carrier used for ShoppingFlux\'s orders has been removed or disabled. Please check and save the "Carriers Matching" configuration.'));
        }
        if (!$this->checkDefaultCarrierConsistency()) {
            // Show a warning message when there is an inconsistency with carriers
            $warnings .= $this->displayWarning($this->l('The default carrier used for ShoppingFlux\'s orders has been removed or disabled. Please check and save the "Parameters" configuration.'));
        }
        return $warnings;
    }

    /* Form record */
    protected function _treatForm()
    {
        $rec_config_adv = Tools::getValue('rec_config_adv');
        $rec_shipping_config = Tools::getValue('rec_shipping_config');

        $rec_config2 = Tools::getValue('SHOPPING_FLUX_TOKEN');
        if ((isset($rec_config2) && $rec_config2 != null)) {
            $configuration = Configuration::getMultiple(array('SHOPPING_FLUX_TRACKING',
                        'SHOPPING_FLUX_ORDERS', 'SHOPPING_FLUX_STATUS_SHIPPED', 'SHOPPING_FLUX_STATUS_CANCELED',
                        'SHOPPING_FLUX_LOGIN', 'SHOPPING_FLUX_STOCKS', 'SHOPPING_FLUX_CARRIER', 'SHOPPING_FLUX_IMAGE',
                        'SHOPPING_FLUX_PACKS', 'SHOPPING_FLUX_CANCELED', 'SHOPPING_FLUX_SHIPPED', 'SHOPPING_FLUX_STATE_MP_EXP'));

            $configuration['SHOPPING_FLUX_XML_SHOP_ID'] = Configuration::getGlobalValue('SHOPPING_FLUX_XML_SHOP_ID');

            foreach ($configuration as $key => $val) {
                $value = Tools::getValue($key, '');
                $value = $value == 'on' ? 'checked' : $value;
                if ($key === "SHOPPING_FLUX_XML_SHOP_ID") {
                    Configuration::updateGlobalValue($key, $value);
                } else {
                    Configuration::updateValue($key, $value == 'on' ? 'checked' : $value);
                }
            }
            
            // Check if they are custom fields (Product class override)
            $theyAreCustomFields = false;
            foreach ($_POST as $field => $value) {
                if (strpos($field, 'SHOPPING_FLUX_CUSTOM_') !== false) {
                    $theyAreCustomFields = true;
                    break;
                }
            }
            
            // If they are custom field, save their configuration
            if ($theyAreCustomFields) {
                $fields = $this->getOverrideFields();
                foreach ($fields as $key => $fieldname) {
                    $valueName = 'SHOPPING_FLUX_CUSTOM_'.$fieldname;
                    Configuration::updateValue($valueName, Tools::getValue($valueName) == '1' ? '1' : '0');
                }
            }
        } elseif (!empty($rec_shipping_config)) {
            Configuration::updateValue('SHOPPING_FLUX_SHIPPING_MATCHING', serialize(Tools::getValue('MATCHING')));
        } elseif (!empty($rec_config_adv)) {
            $configuration = Configuration::getMultiple(array('SHOPPING_FLUX_PASSES'));
            
            $passes = Tools::getValue('SHOPPING_FLUX_PASSES');
            if (empty($passes) ||
                $passes == 0) {
                    Configuration::updateValue('SHOPPING_FLUX_PASSES', '200');
            } elseif (!empty($passes) ||
                is_int($passes)) {
                $passValue = (int)Tools::getValue('SHOPPING_FLUX_PASSES');
                if ($passValue == 0) {
                    $passValue = 200;
                }
                Configuration::updateValue('SHOPPING_FLUX_PASSES', $passValue);
            }
        }
        
        // Tokens handling
        if (Tools::isSubmit('SHOPPING_FLUX_MULTITOKEN')) {
            $sfMultitokenActivation = (int)Tools::getValue('SHOPPING_FLUX_MULTITOKEN');
            if (version_compare(_PS_VERSION_, '1.5', '>')) {
                $id_shop = $this->context->shop->id;
                $id_shop_group = (int) $this->context->shop->id_shop_group;
                Configuration::updateValue('SHOPPING_FLUX_MULTITOKEN', $sfMultitokenActivation, false, $id_shop_group, $id_shop);
            } else {
                Configuration::updateValue('SHOPPING_FLUX_MULTITOKEN', $sfMultitokenActivation);
            }
     
            if (version_compare(_PS_VERSION_, '1.5', '>') && $sfMultitokenActivation) {
                $shops = Shop::getShops();
                // Loop on shops for multiple token
                foreach ($shops as &$currentShop) {
                    $shopLanguages = Language::getLanguages(true, $currentShop['id_shop']);
                    $shopCurrencies = Currency::getCurrenciesByIdShop($currentShop['id_shop']);
                
                    $tokenShop = Tools::getValue('token_'.$currentShop['id_shop']);
                    if ($tokenShop) {
                        $this->setTokenValue($tokenShop, $currentShop['id_shop']);
                    } else {
                        $this->setTokenValue('', $currentShop['id_shop']);
                    }
                
                    // Loop on languages
                    foreach ($shopLanguages as $currentLang) {
                        $idLang = $currentLang['id_lang'];
                        // Finally loop on currencies
                        foreach ($shopCurrencies as $currentCurrency) {
                            $idCurrency = $currentCurrency['id_currency'];
                            $token = Tools::getValue('token_'.$currentShop['id_shop'].'_'.$idLang.'_'.$idCurrency);
                            if ($token) {
                                $this->setTokenValue($token, $currentShop['id_shop'], $idCurrency, $idLang);
                            } else {
                                $this->setTokenValue('', $currentShop['id_shop'], $idCurrency, $idLang);
                            }
                        }
                    }
                }
                // Check and save default token value (main Token value)
                $mainToken = Tools::getValue('SHOPPING_FLUX_TOKEN');
                if ($mainToken) {
                    $this->setTokenValue($mainToken);
                }
            }
        }
    }

    /* Send mail to PS and Shopping Flux */
    protected function sendMail()
    {
        $this->_html .= $this->displayConfirmation($this->l('You are now registered for your free trial. Our team will contact you soon.')).'
            <img src="http://www.prestashop.com/partner/shoppingflux/image.php?site='.Tools::safeOutput(Tools::getValue('site')).'&nom='.Tools::safeOutput(Tools::getValue('nom')).'&prenom='.Tools::safeOutput(Tools::getValue('prenom')).'&email='.Tools::safeOutput(Tools::getValue('email')).'&telephone='.Tools::safeOutput(Tools::getValue('telephone')).'&flux='.Tools::safeOutput(Tools::getValue('flux')).'" border="0" />';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<AddProspect>';
        $xml .= '<LastName><![CDATA['.Tools::safeOutput(Tools::getValue('nom')).']]></LastName>';
        $xml .= '<FirstName><![CDATA['.Tools::safeOutput(Tools::getValue('prenom')).']]></FirstName>';
        $xml .= '<Site><![CDATA['.Tools::safeOutput(Tools::getValue('site')).']]></Site>';
        $xml .= '<Email><![CDATA['.Tools::safeOutput(Tools::getValue('email')).']]></Email>';
        $xml .= '<Phone><![CDATA['.Tools::safeOutput(Tools::getValue('telephone')).']]></Phone>';
        $xml .= '<Feed><![CDATA['.Tools::safeOutput(Tools::getValue('flux')).']]></Feed>';
        $xml .= '<Code><![CDATA['.Tools::safeOutput(Tools::getValue('code')).']]></Code>';
        $xml .= '<Lang><![CDATA['.$this->default_country->iso_code.']]></Lang>';
        $xml .= '</AddProspect>';

        if ($this->isCurlInstalled(true)) {
            $this->_callWebService('AddProspectPrestashop', $xml);
        }
    }

    /* Clean XML tags */
    protected function clean($string)
    {
        $string = str_replace(chr(25), ' ', $string);
        $string = str_replace(chr(28), ' ', $string);
        $string = str_replace(chr(29), ' ', $string);
        $string = str_replace(chr(30), ' ', $string);
        $string = str_replace(chr(31), ' ', $string);
        $string = str_replace(array('(', ')', '°', '&', '+', '/', "'", ':', ';', ',', '?', '²', '’', '´'), '', strip_tags($string));
        return str_replace("\r\n", '', $string);
    }

    /* Feed content */
    
    protected function getSimpleProducts($id_lang, $limit_from, $limit_to)
    {
        $packClause = '';
        if (Configuration::get('SHOPPING_FLUX_PACKS') !== 'checked') {
            $packClause = ' AND p.`cache_is_pack` = 0 ';
        }
            
        if (version_compare(_PS_VERSION_, '1.5', '>')) {
            $context = Context::getContext();

            if (!in_array($context->controller->controller_type, array('front', 'modulefront'))) {
                $front = false;
            } else {
                $front = true;
            }
            $fixedIdProduct = Tools::getValue('id');
            $fixedIdProductClause = '';
            if ($fixedIdProduct) {
                $fixedIdProductClause = "AND p.`id_product` = '".$fixedIdProduct."'";
            }

            $sql = 'SELECT p.`id_product`, pl.`name`
                FROM `'._DB_PREFIX_.'product` p
                '.Shop::addSqlAssociation('product', 'p').'
                LEFT JOIN `'._DB_PREFIX_.'product_lang` pl ON (p.`id_product` = pl.`id_product` '.Shop::addSqlRestrictionOnLang('pl').')
                WHERE pl.`id_lang` = '.(int)$id_lang.' AND product_shop.`active`= 1 
                AND product_shop.`available_for_order`= 1 
                ' . $packClause . '
                '.($front ? ' AND product_shop.`visibility` IN ("both", "catalog")' : '').'
                '.$fixedIdProductClause.'
                ORDER BY pl.`name`';

            if ($limit_from !== false) {
                $sql .= ' LIMIT '.(int)$limit_from.', '.(int)$limit_to;
            }
        } else {
            $sql = 'SELECT p.`id_product`, pl.`name`
                FROM `'._DB_PREFIX_.'product` p
                LEFT JOIN `'._DB_PREFIX_.'product_lang` pl ON (p.`id_product` = pl.`id_product`)
                WHERE pl.`id_lang` = '.(int)($id_lang).' 
                AND p.`active`= 1 AND p.`available_for_order`= 1
                ' . $packClause . '
                '.$fixedIdProductClause.'
                ORDER BY pl.`name`';
        }

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
    }

    protected function countProducts()
    {
        $getPack = '';
        if (Configuration::get('SHOPPING_FLUX_PACKS') !== 'checked') {
            $getPack = ' AND p.`cache_is_pack` = 0 ';
        }
            
        if (version_compare(_PS_VERSION_, '1.5', '>')) {
            $context = Context::getContext();

            if (!in_array($context->controller->controller_type, array('front', 'modulefront'))) {
                $front = false;
            } else {
                $front = true;
            }

            $sql_association = Shop::addSqlAssociation('product', 'p');
            $table = $sql_association ? 'product'.'_shop' : 'p';

            $sql = 'SELECT COUNT(p.`id_product`)
                FROM `'._DB_PREFIX_.'product` p
                '.$sql_association.'
                WHERE '.$table.'.`active`= 1 AND '.$table.'.`available_for_order`= 1 
                ' . $getPack . '
                '.($front ? ' AND '.$table.'.`visibility` IN ("both", "catalog")' : '');
        } else {
            $sql = 'SELECT COUNT(p.`id_product`)
                FROM `'._DB_PREFIX_.'product` p
                WHERE p.`active`= 1 AND p.`available_for_order`= 1
                ' . $getPack;
        }

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql);
    }

    public function generateFeed()
    {
        $token = Tools::getValue('token');
        if (version_compare(_PS_VERSION_, '1.5', '>') && Shop::isFeatureActive()) {
            $id_shop = $this->context->shop->id;
            $tokenInConfig = $this->getTokenValue($id_shop);
            
            $allTokens_raw = $this->getAllTokensOfShop();
            $allTokens = array();
            foreach ($allTokens_raw as $allTokens_subraw) {
                $allTokens[$allTokens_subraw['token']]=$allTokens_subraw['token'];
            }
        } else {
            $tokenInConfig = $this->getTokenValue();
            $allTokens[$tokenInConfig]=$tokenInConfig;
        }

        if ($token == '' ||( $token != $tokenInConfig && !in_array($token, $allTokens) )) {
            die("<?xml version='1.0' encoding='utf-8'?><error>Invalid Token</error>");
        }
        
        $configuration = Configuration::getMultiple(array('PS_TAX_ADDRESS_TYPE', 'PS_CARRIER_DEFAULT', 'PS_COUNTRY_DEFAULT',
            'PS_LANG_DEFAULT', 'PS_SHIPPING_FREE_PRICE', 'PS_SHIPPING_HANDLING', 'PS_SHIPPING_METHOD', 'PS_SHIPPING_FREE_WEIGHT',
            'SHOPPING_FLUX_IMAGE', 'SHOPPING_FLUX_REF'));
    
        $no_breadcrumb = Tools::getValue('no_breadcrumb');
    
        $lang = Tools::getValue('lang');
        $configuration['PS_LANG_DEFAULT'] = !empty($lang) ? Language::getIdByIso($lang) : $configuration['PS_LANG_DEFAULT'];
        $carrier = Carrier::getCarrierByReference((int)Configuration::get('SHOPPING_FLUX_CARRIER'));
    
        //manage case PS_CARRIER_DEFAULT is deleted
        $carrier = is_object($carrier) ? $carrier : new Carrier((int)Configuration::get('SHOPPING_FLUX_CARRIER'));
        $products = $this->getSimpleProducts($configuration['PS_LANG_DEFAULT'], false, 0);
        $link = new Link();
    
        echo '<?xml version="1.0" encoding="utf-8"?>';
        echo '<products version="'.$this->version.'" country="'.$this->default_country->iso_code.'">';
    
        foreach ($products as $productArray) {
            $product = new Product((int)($productArray['id_product']), true, $configuration['PS_LANG_DEFAULT']);
    
            echo '<'.$this->_translateField('product').'>';
            echo $this->_getBaseData($product, $configuration, $link, $carrier);
            echo $this->_getImages($product, $configuration, $link);
            echo $this->_getUrlCategories($product, $configuration, $link);
            echo $this->_getFeatures($product, $configuration);
            echo $this->_getCombinaisons($product, $configuration, $link, $carrier);
    
            if (empty($no_breadcrumb)) {
                echo $this->_getFilAriane($product, $configuration);
            }
    
            echo '<manufacturer><![CDATA['.$product->manufacturer_name.']]></manufacturer>';
            echo '<supplier><![CDATA['.$product->supplier_name.']]></supplier>';
    
            if (is_array($product->specificPrice)) {
                $fromValue = isset($product->specificPrice['from']) ? $product->specificPrice['from'] : "";
                $toValue = isset($product->specificPrice['to']) ? $product->specificPrice['to'] : "";
                echo '<from><![CDATA['.$fromValue.']]></from>';
                echo '<to><![CDATA['.$toValue.']]></to>';
            } else {
                echo '<from/>';
                echo '<to/>';
            }

            $specificPrices = SpecificPrice::getIdsByProductId($product->id);
            $specificPricesInFuture = array();
            foreach ($specificPrices as $idSpecificPrice) {
                $specificPrice = new SpecificPrice($idSpecificPrice['id_specific_price']);

                if (new DateTime($specificPrice->from) > new DateTime()) {
                    $specificPricesInFuture[] = $specificPrice;
                }
            }

            echo '<discounts>';
            $priceComputed = $product->getPrice(true, null, 2, null, false, true, 1);
            foreach ($specificPricesInFuture as $currentSpecificPrice) {
                echo '<discount>';
                // Reduction calculation
                $reduc = 0;
                if ($currentSpecificPrice->price == -1) {
                    if ($currentSpecificPrice->reduction_type == 'amount') {
                        $reduction_amount = $currentSpecificPrice->reduction;
                        $reduc = $reduction_amount;
                    } else {
                        $reduc = $priceComputed * $currentSpecificPrice->reduction;
                    }
                    $priceComputed -= $reduc;
                    $priceComputed = round($priceComputed, 2);
                } else {
                    $priceComputed = $currentSpecificPrice->price;
                }

                echo '<from><![CDATA['.$currentSpecificPrice->from.']]></from>';
                echo '<to><![CDATA['.$currentSpecificPrice->to.']]></to>';
                echo '<price><![CDATA['.$priceComputed.']]></price>';
                echo '</discount>';
            }
            echo '</discounts>';
    
            echo '<'.$this->_translateField('supplier_link').'><![CDATA['.$link->getSupplierLink($product->id_supplier, null, $configuration['PS_LANG_DEFAULT']).']]></'.$this->_translateField('supplier_link').'>';
            echo '<'.$this->_translateField('manufacturer_link').'><![CDATA['.$link->getManufacturerLink($product->id_manufacturer, null, $configuration['PS_LANG_DEFAULT']).']]></'.$this->_translateField('manufacturer_link').'>';
            echo '<'.$this->_translateField('on_sale').'>'.(int)$product->on_sale.'</'.$this->_translateField('on_sale').'>';
            echo '</'.$this->_translateField('product').'>';
        }
    
        echo '</products>';
    }
    
    public function initFeed($lang = null)
    {
        $langLock = empty($lang) ? "" : "_".Tools::strtoupper($lang);

        $id_shop = $this->context->shop->id;
        $lockFile = dirname(__FILE__).'/cron_'.$id_shop.$langLock.'.lock';
        if (!file_exists($lockFile)) {
            $fp = fopen($lockFile, 'w+');
        } else {
            $fp = fopen($lockFile, 'r+');
        }
        
        // Avoid simultaneous calls
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, "lock");
        } else {
            SfLogger::getInstance()->log(SF_LOG_CRON, 'Simultaneous CRON, lock activated, we stop execution');
            die();
        }
        
        // Write time when init for first time
        $today =  date('Y-m-d H:i:s');
        $configurationKey = empty($lang) ? 'SHOPPING_FLUX_CRON_TIME' : 'SHOPPING_FLUX_CRON_TIME_' . Tools::strtoupper($lang);
        Configuration::updateValue($configurationKey, $today, false, null, $id_shop);
                
        $file = fopen($this->getFeedName(), 'w+');
        $country = $lang ? $lang : $this->default_country->iso_code;
        fwrite($file, '<?xml version="1.0" encoding="utf-8"?><products version="'.$this->version.'" country="' . $country . '">');
        fclose($file);

        $totalProducts = $this->countProducts();
        
        SfLogger::getInstance()->log(SF_LOG_CRON, 'Starting generation of '.$totalProducts.' products');
        $this->writeFeed($totalProducts);
        
        // Release lock
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public function writeFeed($total, $current = 0)
    {
        $token = Tools::getValue('token');
        if (version_compare(_PS_VERSION_, '1.5', '>') && Shop::isFeatureActive()) {
            $id_shop = $this->context->shop->id;
            $tokenInConfig = $this->getTokenValue($id_shop);
            
            $allTokens_raw = $this->getAllTokensOfShop();
            $allTokens = array();
            foreach ($allTokens_raw as $allTokens_subraw) {
                $allTokens[$allTokens_subraw['token']]=$allTokens_subraw['token'];
            }
        } else {
            $tokenInConfig = $this->getTokenValue();
            $allTokens[$tokenInConfig]=$tokenInConfig;
        }

        if ($token == '' || ($token != $tokenInConfig && !in_array($token, $allTokens))) {
            die("<?xml version='1.0' encoding='utf-8'?><error>Invalid Token</error>");
        }
                
        $file = fopen($this->getFeedName(), 'a+');

        $configuration = Configuration::getMultiple(
            array(
                'PS_TAX_ADDRESS_TYPE', 'PS_CARRIER_DEFAULT', 'PS_COUNTRY_DEFAULT',
                'PS_LANG_DEFAULT', 'PS_SHIPPING_FREE_PRICE', 'PS_SHIPPING_HANDLING',
                'PS_SHIPPING_METHOD', 'PS_SHIPPING_FREE_WEIGHT', 'SHOPPING_FLUX_IMAGE',
                'SHOPPING_FLUX_REF'
            )
        );

        $no_breadcrumb = Tools::getValue('no_breadcrumb');
        
        $lang = Tools::getValue('lang');
        $configuration['PS_LANG_DEFAULT'] = !empty($lang) ? Language::getIdByIso($lang) : $configuration['PS_LANG_DEFAULT'];
        $carrier = Carrier::getCarrierByReference((int)Configuration::get('SHOPPING_FLUX_CARRIER'));
      
        $passes = Tools::getValue('passes');
        if (! ini_get('allow_url_fopen')) {
            // Max 20 redirections, technical restriction on mutualized hostings
            $configuration['PASSES'] = !empty($passes) ? $passes : (int)($total / 20) + 1;
        } else {
            if ($total < 100) {
                // If less than 100 products, make a product generation for each URL call
                $configuration['PASSES'] = !empty($passes) ? $passes : 1;
            } else {
                // else treat the number of products defined in $productsToBeTreated
                $productsToBeTreated = Configuration::get('SHOPPING_FLUX_PASSES');
                $configuration['PASSES'] = !empty($passes) ? $passes : $productsToBeTreated;
            }
        }

        //manage case PS_CARRIER_DEFAULT is deleted
        $carrier = is_object($carrier) ? $carrier : new Carrier((int)Configuration::get('SHOPPING_FLUX_CARRIER'));
        $products = $this->getSimpleProducts($configuration['PS_LANG_DEFAULT'], $current, $configuration['PASSES']);
        $link = new Link();

        $i = 0;
        SfLogger::getInstance()->log(SF_LOG_CRON, 'Last url - '.Configuration::get('PS_SHOPPINGFLUX_LAST_URL'));
        $logMessage = '-- URL call for products from '.($current+1).'/'.$total.' to ';
        $logMessage .= ($current+1+$configuration['PASSES']).'/'.$total.', current URL is: '.$_SERVER['REQUEST_URI'];
        SfLogger::getInstance()->log(SF_LOG_CRON, $logMessage);
        
        foreach ($products as $productArray) {
            $i++;
            $logMessage = '----- Product generation '.$i.' / '.$configuration['PASSES'];
            $logMessage .= '(for this URL call) (id_product = '.$productArray['id_product'].')';
            SfLogger::getInstance()->log(SF_LOG_CRON, $logMessage);
            
            $str = '';
            $product = new Product((int)($productArray['id_product']), true, $configuration['PS_LANG_DEFAULT']);

            
            $str .= '<'.$this->_translateField('product').'>';
            $str .= $this->_getBaseData($product, $configuration, $link, $carrier);
            $str .= $this->_getImages($product, $configuration, $link);
            $str .= $this->_getUrlCategories($product, $configuration, $link);
            $str .= $this->_getFeatures($product, $configuration);
            fwrite($file, $str);

            // This function writes directly to the file to avoid performances issues
            $this->_getCombinaisons($product, $configuration, $link, $carrier, $file);

            $str = '';
            if (empty($no_breadcrumb)) {
                $str .= $this->_getFilAriane($product, $configuration);
            }

            $str .= '<manufacturer><![CDATA['.$product->manufacturer_name.']]></manufacturer>';
            $str .= '<supplier><![CDATA['.$product->supplier_name.']]></supplier>';

            if (is_array($product->specificPrice)) {
                $fromValue = isset($product->specificPrice['from']) ? $product->specificPrice['from'] : "";
                $toValue = isset($product->specificPrice['to']) ? $product->specificPrice['to'] : "";
                $str .= '<from><![CDATA['.$fromValue.']]></from>';
                $str .= '<to><![CDATA['.$toValue.']]></to>';
            } else {
                $str .= '<from/>';
                $str .= '<to/>';
            }

            $str .= '<discounts>';
            $priceComputed = $product->getPrice(true, null, 2, null, false, true, 1);

            $specificPrices = SpecificPrice::getIdsByProductId($product->id);
            $specificPricesInFuture = array();
            foreach ($specificPrices as $idSpecificPrice) {
                $specificPrice = new SpecificPrice($idSpecificPrice['id_specific_price']);
                 
                 
                if (new DateTime($specificPrice->from) > new DateTime()) {
                    $specificPricesInFuture[] = $specificPrice;
                }
            }
            
            foreach ($specificPricesInFuture as $currentSpecificPrice) {
                $str .= '<discount>';
                // Reduction calculation
                $reduc = 0;
                if ($currentSpecificPrice->price == -1) {
                    if ($currentSpecificPrice->reduction_type == 'amount') {
                        $reduction_amount = $currentSpecificPrice->reduction;
                        $reduc = $reduction_amount;
                    } else {
                        $reduc = $priceComputed * $currentSpecificPrice->reduction;
                    }
                    $priceComputed -= $reduc;
                    $priceComputed = round($priceComputed, 2);
                } else {
                    $priceComputed = $currentSpecificPrice->price;
                }
                 
                $str .= '<from><![CDATA['.$currentSpecificPrice->from.']]></from>';
                $str .= '<to><![CDATA['.$currentSpecificPrice->to.']]></to>';
                $str .= '<price><![CDATA['.$priceComputed.']]></price>';
                $str .= '</discount>';
            }
            $str .= '</discounts>';
            
            $str .= '<'.$this->_translateField('supplier_link').'><![CDATA['.$link->getSupplierLink($product->id_supplier, null, $configuration['PS_LANG_DEFAULT']).']]></'.$this->_translateField('supplier_link').'>';
            $str .= '<'.$this->_translateField('manufacturer_link').'><![CDATA['.$link->getManufacturerLink($product->id_manufacturer, null, $configuration['PS_LANG_DEFAULT']).']]></'.$this->_translateField('manufacturer_link').'>';
            $str .= '<'.$this->_translateField('on_sale').'>'.(int)$product->on_sale.'</'.$this->_translateField('on_sale').'>';
            $str .= '</'.$this->_translateField('product').'>';

            fwrite($file, $str);
        }
        fclose($file);
       
        if ($current + $configuration['PASSES'] >= $total) {
            $this->closeFeed();
            
            // Remove previous feed an place the newly generated one
            $feed_name = $this->getFeedName(false);
            if (file_exists($feed_name)) {
                unlink($feed_name);
            }
            
            rename($this->getFeedName(), $feed_name);
            
            // Notify end of cron execution
            SfLogger::getInstance()->log(SF_LOG_CRON, 'EXPORT SUCCESSFULL');

            // Empty last known url
            Configuration::updateValue('PS_SHOPPINGFLUX_LAST_URL', '0');
        } else {
            $protocol_link = Tools::getCurrentUrlProtocolPrefix();
            $next_uri = $protocol_link.Tools::getHttpHost().__PS_BASE_URI__;
            $next_uri .= 'modules/shoppingfluxexport/cron.php?token='.$this->getTokenValue();
            $next_uri .= '&current='.($current + $configuration['PASSES']).'&total='.$total;
            $next_uri .= '&passes='.$configuration['PASSES'].(!empty($no_breadcrumb) ? '&no_breadcrumb=true' : '');
            $currency = Tools::getValue('currency');
            $next_uri .= (!empty($currency) ? '&currency='.Tools::getValue('currency') : '');
            $next_uri .= (!empty($lang) ? '&lang='.$lang : '');
            SfLogger::getInstance()->log(SF_LOG_CRON, '-- going to call URL: '.$next_uri);
            
        
            // Disconnect DB to avoid reaching max connections
            DB::getInstance()->disconnect();

            if ($this->isCurlInstalled(true)) {
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $next_uri);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($curl, CURLOPT_HEADER, false);
                curl_setopt($curl, CURLOPT_POST, false);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($curl, CURLOPT_TIMEOUT, 1);
                curl_exec($curl);
                curl_close($curl);
                die();
            } else {
                Tools::redirect($next_uri);
            }
        }
    }

    protected function closeFeed()
    {
        $file = fopen($this->getFeedName(), 'a+');
        fwrite($file, '</products>');
    }
    
    public function setREF()
    {
        $ref = Tools::getValue('ref');

        if ($ref == 'true') {
            Configuration::updateValue('SHOPPING_FLUX_REF', 'true');
            return 'ok';
        } elseif ($ref == 'false') {
            Configuration::updateValue('SHOPPING_FLUX_REF', 'false');
            return 'ko';
        } else {
            return 'ko';
        }
    }
    
    /* Default data, in Product Class */
    protected function _getBaseData($product, $configuration, $link, $carrier)
    {
        $ret = '';

        $titles = array(
            0 => 'id',
            1 => $this->_translateField('name'),
            2 => $this->_translateField('link'),
            4 => 'description',
            5 => $this->_translateField('short_description'),
            6 => $this->_translateField('price'),
            7 => $this->_translateField('old_price'),
            8 => $this->_translateField('shipping_cost'),
            9 => $this->_translateField('shipping_delay'),
            10 => $this->_translateField('brand'),
            11 => $this->_translateField('category'),
            13 => $this->_translateField('quantity'),
            14 => 'ean',
            15 => $this->_translateField('weight'),
            16 => $this->_translateField('ecotax'),
            17 => $this->_translateField('vat'),
            18 => $this->_translateField('mpn'),
            19 => $this->_translateField('supplier_reference'),
            20 => 'upc',
            21 => 'wholesale-price'
        );

        $data = array();
        $data[0] = ($configuration['SHOPPING_FLUX_REF'] != 'true') ? $product->id : $product->reference;
        $data[1] = $product->name;
        $data[2] = $link->getProductLink($product, null, null, null, $configuration['PS_LANG_DEFAULT']);
        $data[4] = $product->description;
        $data[5] = $product->description_short;
        
        $context = Context::getContext();
        $id_currency = Tools::getValue('currency');
        if ($id_currency) {
            $context->currency  = new Currency(Currency::getIdByIsoCode(Tools::getValue('currency')));
        }
        
        $data[6] = $product->getPrice(true, null, 2, null, false, true, 1);
        $data[7] = $product->getPrice(true, null, 2, null, false, false, 1);
        $data[8] = $this->_getShipping($product, $configuration, $carrier);
        $data[9] = $carrier->delay[$configuration['PS_LANG_DEFAULT']];
        $data[10] = $product->manufacturer_name;
        $data[11] = $this->_getCategories($product, $configuration);
        $data[13] = $product->quantity;
        $data[14] = $product->ean13;
        $data[15] = $product->weight;
        $data[16] = $product->ecotax;
        $data[17] = $product->tax_rate;
        $data[18] = $product->reference;
        $data[19] = $product->supplier_reference;
        $data[20] = $product->upc;
        $data[21] = $product->wholesale_price;

        foreach ($titles as $key => $balise) {
            $ret .= '<'.$balise.'><![CDATA['.htmlentities($data[$key], ENT_QUOTES, 'UTF-8').']]></'.$balise.'>';
        }

        return $ret;
    }

    /* Shipping prices */
    protected function _getShipping($product, $configuration, $carrier, $attribute_id = null, $attribute_weight = null)
    {
        $default_country = new Country($configuration['PS_COUNTRY_DEFAULT'], $configuration['PS_LANG_DEFAULT']);
        $id_zone = (int)$default_country->id_zone;
        $this->id_address_delivery = 0;
        $carrier_tax = Tax::getCarrierTaxRate((int)$carrier->id, (int)$this->{$configuration['PS_TAX_ADDRESS_TYPE']});

        $shipping = 0;

        $product_price = $product->getPrice(true, $attribute_id, 2, null, false, true, 1);
        $shipping_free_price = $configuration['PS_SHIPPING_FREE_PRICE'];
        $shipping_free_weight = isset($configuration['PS_SHIPPING_FREE_WEIGHT']) ? $configuration['PS_SHIPPING_FREE_WEIGHT'] : 0;

        if (!(((float)$shipping_free_price > 0) && ($product_price >= (float)$shipping_free_price)) &&
                !(((float)$shipping_free_weight > 0) && ($product->weight + $attribute_weight >= (float)$shipping_free_weight))) {
            if (isset($configuration['PS_SHIPPING_HANDLING']) && $carrier->shipping_handling) {
                $shipping = (float)($configuration['PS_SHIPPING_HANDLING']);
            }

            if ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT) {
                $shipping += $carrier->getDeliveryPriceByWeight($product->weight, $id_zone);
            } else {
                $shipping += $carrier->getDeliveryPriceByPrice($product_price, $id_zone);
            }

            $shipping *= 1 + ($carrier_tax / 100);
            $shipping = (float)(Tools::ps_round((float)($shipping), 2));
        }

        return (float)$shipping + (float)$product->additional_shipping_cost;
    }

    /* Product category */
    protected function _getCategories($product, $configuration)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
            SELECT cl.`name`
            FROM `'._DB_PREFIX_.'product` p
            '.Shop::addSqlAssociation('product', 'p').'
            LEFT JOIN `'._DB_PREFIX_.'category_lang` cl ON (product_shop.`id_category_default` = cl.`id_category`)
            WHERE p.`id_product` = '.(int)$product->id.'
            AND cl.`id_lang` = '.(int)$configuration['PS_LANG_DEFAULT']);
    }

    /* Images URIs */
    protected function getImages($id_product, $id_lang)
    {
        return Db::getInstance()->ExecuteS('
            SELECT i.`cover`, i.`id_image`, il.`legend`, i.`position`
            FROM `'._DB_PREFIX_.'image` i
            LEFT JOIN `'._DB_PREFIX_.'image_lang` il ON (i.`id_image` = il.`id_image` AND il.`id_lang` = '.(int)($id_lang).')
            WHERE i.`id_product` = '.(int)($id_product).'
            ORDER BY i.cover DESC, i.`position` ASC ');
    }

    protected function _getImages($product, $configuration, $link)
    {
        $images = $this->getImages($product->id, $configuration['PS_LANG_DEFAULT']);
        $ret = '<images>';

        if ($images != false) {
            foreach ($images as $image) {
                $ids = $product->id.'-'.$image['id_image'];
                $img_url = $link->getImageLink($product->link_rewrite, $ids, $configuration['SHOPPING_FLUX_IMAGE']);
                if (!substr_count($img_url, Tools::getCurrentUrlProtocolPrefix())) {
                    // make sure url has http or https
                    $img_url = Tools::getCurrentUrlProtocolPrefix() . $img_url;
                }
                $ret .= '<image><![CDATA[' . $img_url . ']]></image>';
            }
        }
        $ret .= '</images>';
        return $ret;
    }

    /* Categories URIs */
    protected function _getUrlCategories($product, $configuration, $link)
    {
        $ret = '<uri-categories>';

        foreach ($this->_getProductCategoriesFull($product->id, $configuration['PS_LANG_DEFAULT']) as $key => $categories) {
            $ret .= '<uri><![CDATA['.$link->getCategoryLink($key, null, $configuration['PS_LANG_DEFAULT']).']]></uri>';
        }

        $ret .= '</uri-categories>';
        return $ret;
    }

    /* All product categories */
    protected function _getProductCategoriesFull($id_product, $id_lang)
    {
        $row = Db::getInstance(_PS_USE_SQL_SLAVE_)->ExecuteS('
            SELECT cp.`id_category`, cl.`name`, cl.`link_rewrite` FROM `'._DB_PREFIX_.'category_product` cp
            LEFT JOIN `'._DB_PREFIX_.'category_lang` cl ON (cp.`id_category` = cl.`id_category`)
            WHERE cp.`id_product` = '.(int)$id_product.'
            AND cl.`id_lang` = '.(int)$id_lang.'
            ORDER BY cp.`position` DESC');

        $ret = array();

        foreach ($row as $val) {
            $ret[$val['id_category']] = $val;
        }

        return $ret;
    }

    /* Features */
    protected function _getFeatures($product, $configuration)
    {
        $ret = '<caracteristiques>';
        foreach ($product->getFrontFeatures($configuration['PS_LANG_DEFAULT']) as $feature) {
            $feature['name'] = $this->_clean($feature['name']);
            
            if (!empty($feature['name'])) {
                $ret .= '<'.$feature['name'].'><![CDATA['.$feature['value'].']]></'.$feature['name'].'>';
            }
        }

        $ret .= '<meta_title><![CDATA['.$product->meta_title.']]></meta_title>';
        $ret .= '<meta_description><![CDATA['.$product->meta_description.']]></meta_description>';
        $ret .= '<meta_keywords><![CDATA['.$product->meta_keywords.']]></meta_keywords>';

        $tabTags = Tag::getProductTags($product->id);
        
        if (empty($tabTags[$configuration['PS_LANG_DEFAULT']])) {
            $ret .= '<tags></tags>';
        } else {
            $ret .= '<tags><![CDATA['.implode("|", $tabTags[$configuration['PS_LANG_DEFAULT']]).']]></tags>';
        }
        

        $ret .= '<width><![CDATA['.$product->width.']]></width>';
        $ret .= '<depth><![CDATA['.$product->depth.']]></depth>';
        $ret .= '<height><![CDATA['.$product->height.']]></height>';

        $ret .= '<state><![CDATA['.$product->condition.']]></state>';
        $ret .= '<available_for_order><![CDATA['.$product->available_for_order.']]></available_for_order>';
        $ret .= '<out_of_stock><![CDATA['.$product->out_of_stock.']]></out_of_stock>';
        
        
        // Add the overrided fields if any
        $fields = $this->getOverrideFields();
        foreach ($fields as $fieldname) {
            if (Configuration::get('SHOPPING_FLUX_CUSTOM_'.$fieldname) == 1) {
                $ret .= '<'.$fieldname.'><![CDATA['.$product->$fieldname.']]></'.$fieldname.'>';
            }
        }
        
        $combination = $product->getAttributeCombinations($configuration['PS_LANG_DEFAULT']);
        if (count($combination) > 0) {
            $ret .= '<hierararchy><![CDATA[parent]]></hierararchy>';
        } else {
            $ret .= '<hierararchy><![CDATA[simple]]></hierararchy>';
        }
        
        $ret .= '</caracteristiques>';
        return $ret;
    }

    /* Product attributes */
    protected function _getAttributeImageAssociations($id_product_attribute)
    {
        $combinationImages = array();
        $data = Db::getInstance()->ExecuteS('
            SELECT pai.`id_image`
            FROM `'._DB_PREFIX_.'product_attribute_image` pai
            LEFT JOIN `'._DB_PREFIX_.'image` i ON pai.id_image = i.id_image
            WHERE pai.`id_product_attribute` = '.(int)($id_product_attribute).'
            ORDER BY i.cover DESC, i.position ASC
        ');

        foreach ($data as $row) {
            $combinationImages[] = (int)($row['id_image']);
        }

        return $combinationImages;
    }

    protected function _getCombinaisons($product, $configuration, $link, $carrier, $fileToWrite = 0)
    {
        $combinations = array();

        $ret = '<declinaisons>';

        if ($fileToWrite) {
            fwrite($fileToWrite, $ret);
        }
        
        foreach ($product->getAttributeCombinations($configuration['PS_LANG_DEFAULT']) as $combinaison) {
            $combinations[$combinaison['id_product_attribute']]['attributes'][$combinaison['group_name']] = $combinaison['attribute_name'];
            $combinations[$combinaison['id_product_attribute']]['ean13'] = $combinaison['ean13'];
            $combinations[$combinaison['id_product_attribute']]['upc'] = $combinaison['upc'];
            $combinations[$combinaison['id_product_attribute']]['quantity'] = $combinaison['quantity'];
            $combinations[$combinaison['id_product_attribute']]['weight'] = $combinaison['weight'] + $product->weight;
            $combinations[$combinaison['id_product_attribute']]['reference'] = $combinaison['reference'];
            $combinations[$combinaison['id_product_attribute']]['wholesale_price'] = $combinaison['wholesale_price'];
        }

        $j = 0;
        foreach ($combinations as $id => $combination) {
            // Add time limit to php execution in case of multiple combination
            set_time_limit(60);
            $j++;
            $logMessage = '---------- Attribute generation '.$j.' / '.count($combinations);
            $logMessage .= ' (id_product = '.$product->id.')';
            SfLogger::getInstance()->log(SF_LOG_CRON, $logMessage);
            
            if ($fileToWrite) {
                $ret = '';
            }
            
            if ($configuration['SHOPPING_FLUX_REF'] != 'true') {
                $ref = $id;
            } else {
                $ref = $combination['reference'];
            }

            $ret .= '<declinaison>';
            $ret .= '<id><![CDATA['.$ref.']]></id>';
            $ret .= '<ean><![CDATA['.$combination['ean13'].']]></ean>';
            $ret .= '<upc><![CDATA['.$combination['upc'].']]></upc>';
            $ret .= '<'.$this->_translateField('quantity').'><![CDATA['.$combination['quantity'].']]></'.$this->_translateField('quantity').'>';
            $ret .= '<'.$this->_translateField('weight').'><![CDATA['.$combination['weight'].']]></'.$this->_translateField('weight').'>';
            $ret .= '<'.$this->_translateField('price').'><![CDATA['.$product->getPrice(true, $id, 2, null, false, true, 1).']]></'.$this->_translateField('price').'>';
            $ret .= '<'.$this->_translateField('old_price').'><![CDATA['.$product->getPrice(true, $id, 2, null, false, false, 1).']]></'.$this->_translateField('old_price').'>';
            $ret .= '<'.$this->_translateField('shipping_cost').'><![CDATA['.$this->_getShipping($product, $configuration, $carrier, $id, $combination['weight']).']]></'.$this->_translateField('shipping_cost').'>';
            $ret .= '<wholesale-price><![CDATA['.$combination['wholesale_price'].']]></wholesale-price>';
            $ret .= '<images>';

            $image_child = true;

            foreach ($this->_getAttributeImageAssociations($id) as $image) {
                if (empty($image)) {
                    $image_child = false;
                    break;
                }
                $ret .= '<image><![CDATA['.Tools::getCurrentUrlProtocolPrefix().$link->getImageLink($product->link_rewrite, $product->id.'-'.$image, $configuration['SHOPPING_FLUX_IMAGE']).']]></image>';
            }

            if (!$image_child) {
                foreach ($product->getImages($configuration['PS_LANG_DEFAULT']) as $images) {
                    $ids = $product->id.'-'.$images['id_image'];
                    $ret .= '<image><![CDATA['.Tools::getCurrentUrlProtocolPrefix().$link->getImageLink($product->link_rewrite, $ids, $configuration['SHOPPING_FLUX_IMAGE']).']]></image>';
                }
            }

            $ret .= '</images>';
            $ret .= '<attributs>';
            
            $ret .= '<hierararchy><![CDATA[child]]></hierararchy>';
            
            asort($combination['attributes']);
            foreach ($combination['attributes'] as $attributeName => $attributeValue) {
                $attributeName = $this->_clean($attributeName);
                
                if (!empty($attributeName)) {
                    $ret .= '<'.$attributeName.'><![CDATA['.$attributeValue.']]></'.$attributeName.'>';
                }
            }

            $ret .= '<'.$this->_translateField('mpn').'><![CDATA['.$combination['reference'].']]></'.$this->_translateField('mpn').'>';
            $productLink = $link->getProductLink($product, null, null, null, $configuration['PS_LANG_DEFAULT']);
            $ret .= '<'.$this->_translateField('combination_link').'><![CDATA['.$productLink.$product->getAnchor($id, true).']]></'.$this->_translateField('combination_link').'>';

            $ret .= '</attributs>';
            $ret .= '</declinaison>';

            if ($fileToWrite) {
                fwrite($fileToWrite, $ret);
            }
        }

        if ($fileToWrite) {
            $ret = '</declinaisons>';
            fwrite($fileToWrite, $ret);
            return;
        } else {
            $ret .= '</declinaisons>';
            return $ret;
        }
    }

    /* Category tree XML */
    protected function _getFilAriane($product, $configuration)
    {
        $category = '';
        $ret = '<'.$this->_translateField('category_breadcrumb').'>';

        foreach ($this->_getProductFilAriane($product->id, $configuration['PS_LANG_DEFAULT']) as $categories) {
            $category .= $categories.' > ';
        }

        $ret .= '<![CDATA['.Tools::substr($category, 0, -3).']]></'.$this->_translateField('category_breadcrumb').'>';
        return $ret;
    }

    /* Category tree */
    protected function _getProductFilAriane($id_product, $id_lang, $id_category = 0, $id_parent = 0, $name = 0)
    {
        $ret = array();
        $id_parent = '';
    
        if ($id_category) {
            $ret[$id_category] = $name;
            $id_parent = $id_parent;
            $id_category = $id_category;
        } else {
            $row = Db::getInstance(_PS_USE_SQL_SLAVE_)->ExecuteS('
			SELECT cl.`name`, p.`id_category_default` as id_category, c.`id_parent` FROM `'._DB_PREFIX_.'product` p
			LEFT JOIN `'._DB_PREFIX_.'category_lang` cl ON (p.`id_category_default` = cl.`id_category`)
			LEFT JOIN `'._DB_PREFIX_.'category` c ON (p.`id_category_default` = c.`id_category`)
			WHERE p.`id_product` = '.(int)$id_product.'
			AND cl.`id_lang` = '.(int)$id_lang);
    
            foreach ($row as $val) {
                $ret[$val['id_category']] = $val['name'];
                $id_parent = $val['id_parent'];
                $id_category = $val['id_category'];
            }
        }
    
        while ($id_parent != 0 && $id_category != $id_parent) {
            $row = Db::getInstance(_PS_USE_SQL_SLAVE_)->ExecuteS('
				SELECT cl.`name`, c.`id_category`, c.`id_parent` FROM `'._DB_PREFIX_.'category_lang` cl
				LEFT JOIN `'._DB_PREFIX_.'category` c ON (c.`id_category` = '.(int)$id_parent.')
				WHERE cl.`id_category` = '.(int)$id_parent.'
				AND cl.`id_lang` = '.(int)$id_lang);
    
            if (! sizeof($row)) {
                // There is a problem with the category parent, let's try another category
                $productCategory = Db::getInstance(_PS_USE_SQL_SLAVE_)->ExecuteS('
            			SELECT DISTINCT c.`id_category`, cl.`name`, 
                            c.`id_parent` FROM `'._DB_PREFIX_.'category_product` cp
            			LEFT JOIN `'._DB_PREFIX_.'category_lang` cl ON (cp.`id_category` = cl.`id_category`)
            			LEFT JOIN `'._DB_PREFIX_.'category` c ON (cp.`id_category` = c.`id_category`)
            			WHERE cp.`id_product` = '.(int)$id_product.'
			            AND cp.`id_category` NOT IN ('.$id_category.')
            			AND cl.`id_lang` = '.(int)$id_lang.'
			            ORDER BY level_depth DESC');
                 
                if (! sizeof($productCategory)) {
                    return array();
                }
                 
                return $this->_getProductFilAriane($id_product, $id_lang, $productCategory[0]['id_category'], $productCategory[0]['id_parent'], $productCategory[0]['name']);
            }
            
            foreach ($row as $val) {
                $ret[$val['id_category']] = $val['name'];
                $id_parent = $val['id_parent'];
                $id_category = $val['id_category'];
            }
        }
    
        $ret = array_reverse($ret);
        return $ret;
    }

    public function hookbackOfficeTop($no_cron = true, $forcedOrder = false)
    {
        $minTimeDiff = 60;
        $now = time();
        $lastHookCalledTime = Configuration::get('SHOPPING_BACKOFFICE_CALL');
        $timeDiffFromLastCall = $now - $lastHookCalledTime;

        $orderDebugEnabled = SfDebugger::getInstance()->isDebugEnabled();
        $doEchoLog = $forcedOrder ? true : $orderDebugEnabled;

        if ($timeDiffFromLastCall > $minTimeDiff || $forcedOrder) {
            $controller = Tools::strtolower(Tools::getValue('controller'));
            $ordersConfig = Configuration::get('SHOPPING_FLUX_ORDERS');
            $curlInstalled = $this->isCurlInstalled(true);
            if (($controller == 'adminorders' &&
                $ordersConfig != '' &&
                $curlInstalled) ||
                $no_cron == false ||
                $forcedOrder) {
                SfDebugger::getInstance()->startDebug();

                // We update the flag stating that a call has been performed in order to respect
                // the minTimeDiff interval
                Configuration::updateValue('SHOPPING_BACKOFFICE_CALL', $now);

                // Get all tokens of this shop
                $allTokens = $this->getAllTokensOfShop();
                foreach ($allTokens as $currentToken) {
                    if (! $forcedOrder) {
                        $ordersXML = $this->_callWebService('GetOrders', false, null, $currentToken['token']);
                        if (count($ordersXML->Response->Orders) == 0) {
                            return;
                        }
                        $orders = $ordersXML->Response->Orders->Order;
                    } else {
                        $orders = array($forcedOrder);
                    }
                    
                    foreach ($orders as $order) {
                        set_time_limit(60);
                        if (! $forcedOrder) {
                            SfLogger::getInstance()->log(SF_LOG_ORDERS, '----------------- Order creation received for market place : '.Tools::strtolower($order->Marketplace), $doEchoLog);
                            $this->saveLastOrderTreated($order);
                        } else {
                            SfLogger::getInstance()->log(SF_LOG_ORDERS, '----------------- Replaying previously received order for market place : '.Tools::strtolower($order->Marketplace), $doEchoLog);
                        }
                        
                        try {

                            // By default the RelayID is in the "other" field
                            $mondialRelayID = isset($order->ShippingAddress->RelayID) ? $order->ShippingAddress->RelayID : $order->Other;
                            if ((
                                Tools::strtolower($order->Marketplace) == 'rdc' ||
                                Tools::strtolower($order->Marketplace) == 'rueducommerce') &&
                                strpos($order->ShippingMethod, 'Mondial Relay') !== false) {
                                // RDC is using RelayID from the delivery adresse and Other for the order number instead of the relay ID.
                                // The relay ID is located in the ShippingMethod
                                // Therefore we need to extract the relay ID from the ShiippingMethod and then rebuild the ShiippingMethod witjout the relay ID
                                $num = explode(' ', $order->ShippingMethod);
                                $mondialRelayID = end($num);
                                $stringImplode = array_slice($num, 0, count($num)-1);
                                $order->ShippingMethod = implode($stringImplode, " ");
                            }
        
                            // Check if the order already exists by lookig at the messages in the order
                            $orderExists = Db::getInstance()->getRow('SELECT m.id_message, m.id_order FROM '._DB_PREFIX_.'message m
                                WHERE m.message LIKE "%Numéro de commande '.pSQL($order->Marketplace).' :'.pSQL($order->IdOrder).'%"');
        
                            if (! $forcedOrder && isset($orderExists['id_message']) && isset($orderExists['id_order'])) {
                                SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Order already exists based on message content (id = '.$order->IdOrder.'): notifying ShoppingFlux', $doEchoLog);
                                $this->_validOrders((string)$order->IdOrder, (string)$order->Marketplace, (int)$orderExists['id_order'], false, $currentToken['token']);
                                continue;
                            }
                            
                            // Check if the order already exists by looking at the address alias
                            // This will look for orders having the email "do-not-send@alerts-shopping-flux.com", which means the order was not fully created
                            $aliasAddress = "Shipping-".(string)$order->IdOrder;
                            $orderExists = Db::getInstance()->getRow("SELECT * FROM " . _DB_PREFIX_ . "orders o, " . _DB_PREFIX_ . "customer c, " . _DB_PREFIX_ . "address a
                                WHERE o.id_customer = c.id_customer
                                AND c.email = 'do-not-send@alerts-shopping-flux.com'
                                AND a.id_address = o.id_address_delivery
                                AND a.alias LIKE '" . $aliasAddress . "'
                                ORDER BY o.id_order DESC
                            ");
                            
                            if (! $forcedOrder && isset($orderExists['id_order'])) {
                                // This is the second try of an order creation, last process could not be completed
                                SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Order already exists based on address alias (id = ' . $order->IdOrder . '): notifying ShoppingFlux', $doEchoLog);
                            
                                // Re set the carrier
                                $orderLoaded = new Order((int) $orderExists['id_order']);
                                $orderCartId = $orderLoaded->id_cart;
                                $cartLoaded = new Cart($orderCartId);
                                $cartCarrierId = $cartLoaded->id_carrier;
                            
                                $sql = "UPDATE " . _DB_PREFIX_ . "orders o
                            			SET o.id_carrier = " . $cartCarrierId . "
                            			WHERE o.id_order = " . $orderExists['id_order'];
                                Db::getInstance()->execute($sql);
                            
                                // Re set the prices, and notify ShoppingFlux
                                $this->_updatePrices($orderExists['id_order'], $order, $orderExists['reference']);
                                $this->_validOrders((string)$order->IdOrder, (string)$order->Marketplace, (int) $orderExists['id_order']);
                                continue;
                            }
        
                            $check = $this->checkData($order, $order->Marketplace);
                            if ($check !== true) {
                                SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Check data incorrect - '.$check, $doEchoLog);
                                $this->_validOrders((string)$order->IdOrder, (string)$order->Marketplace, false, $check, $currentToken['token']);
                                continue;
                            }
        
                            $mail = (string)$order->BillingAddress->Email;
                            $email = (empty($mail)) ? pSQL($order->IdOrder.'@'.$order->Marketplace.'.sf') : pSQL($mail);
        
                            $id_customer = $this->_getCustomer($email, (string)$order->BillingAddress->LastName, (string)$order->BillingAddress->FirstName);
                            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Id customer created or found : '.$id_customer, $doEchoLog);
                            //avoid update of old orders by the same merchant with different addresses
                            $id_address_billing = $this->_getAddress($order->BillingAddress, $id_customer, 'Billing-'.(string)$order->IdOrder, '', (string)$order->Marketplace, (string)$order->ShippingMethod);
                            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Id adress delivery created or found : '.$id_address_billing, $doEchoLog);
                            $id_address_shipping = $this->_getAddress($order->ShippingAddress, $id_customer, 'Shipping-'.(string)$order->IdOrder, $order->Other, (string)$order->Marketplace, (string)$order->ShippingMethod);
                            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Id adress shipping or found : '.$id_address_shipping, $doEchoLog);

                            $this->checkProductsQuantity($order->Products, $order->Marketplace, (int)$currentToken['id_shop']);
                            
                            $current_customer = new Customer((int)$id_customer);
        
                            if ($id_address_shipping && $id_address_billing && $id_customer) {
                                $cart = $this->_getCart($id_customer, $id_address_billing, $id_address_shipping, $order->Products, (string)$order->Currency, (string)$order->ShippingMethod, $order->TotalFees, $currentToken['id_lang'], $doEchoLog);
            
                                if ($cart) {
                                    SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Cart '.$cart->id.' successfully built', $doEchoLog);
                                  
                                    //compatibylity with socolissmo
                                    $this->context->cart = $cart;
                                    
                                    // Compatibility with socolissimo liberté module
                                    $module = Module::getInstanceByName('soliberte');
                                    if ($module && $module->active) {
                                        $addrSoColissimo = new Address((int)$id_address_shipping);
                                        $countrySoColissimo = new Country($addrSoColissimo->id_country);
                                        $socotable_name = 'socolissimo_delivery_info';
                                        $socovalues = array(
                                            'id_cart' => (int) $cart->id,
                                            'id_customer' => (int) $id_customer,
                                            'prfirstname' => pSQL($addrSoColissimo->firstname),
                                            'cename' => pSQL($addrSoColissimo->lastname),
                                            'cefirstname' => pSQL($addrSoColissimo->firstname),
                                            'cecountry' => pSQL($countrySoColissimo->iso_code),
                                            'ceemail' => pSQL($email),
                                        );
                                        Db::getInstance()->insert($socotable_name, $socovalues);
                                    }
                                    
                                    // Compatibility with socolissimo flexibilité module
                                    $soflexibilite = Module::getInstanceByName('soflexibilite');
                                    if ($soflexibilite && $soflexibilite->active && (class_exists('SoFlexibiliteDelivery') || class_exists('SoColissimoFlexibiliteDelivery'))) {
                                        SfLogger::getInstance()->log(SF_LOG_ORDERS, 'soflexibilite ACTIVE', $doEchoLog);
                                        $addrSoColissimo = new Address((int)$id_address_shipping);
                                        if ($addrSoColissimo->phone_mobile) {
                                            $phone = $addrSoColissimo->phone_mobile;
                                        } else {
                                            $phone = $addrSoColissimo->phone;
                                        }
                                        $delivery_country = new Country($addrSoColissimo->id_country);

                                        // SoFlexibilite module may use a different class name depending of the module's version.
                                        // Version 2.0 seems to be using the class SoColissimoFlexibiliteDelivery and versions 3.0 are using the class SoFlexibiliteDelivery
                                        if (class_exists('SoFlexibiliteDelivery')) {
                                            $so_delivery = new SoFlexibiliteDelivery();
                                        } else {
                                            $so_delivery = new SoColissimoFlexibiliteDelivery();
                                        }

                                        $so_delivery->id_cart = (int)$cart->id;
                                        $so_delivery->id_order = -time();
                                        $so_delivery->id_point = null;
                                        $so_delivery->id_customer = (int)$id_customer;
                                        $so_delivery->firstname = $addrSoColissimo->firstname;
                                        $so_delivery->lastname = $addrSoColissimo->lastname;
                                        $so_delivery->company = $addrSoColissimo->company;
                                        $so_delivery->telephone = $phone;
                                        $so_delivery->email = $current_customer->email;
                                        $so_delivery->postcode = $addrSoColissimo->postcode;
                                        $so_delivery->city = $addrSoColissimo->city;
                                        $so_delivery->country = $delivery_country->iso_code;
                                        $so_delivery->address1 = $addrSoColissimo->address1;
                                        $so_delivery->address2 = $addrSoColissimo->address2;
                                    
                                        // determine type
                                        $soflexibilite_conf_key = array(
                                            'SOFLEXIBILITE_DOM_ID',
                                            'SOFLEXIBILITE_DOS_ID',
                                            'SOFLEXIBILITE_BPR_ID',
                                            'SOFLEXIBILITE_A2P_ID'
                                        );
                                        $conf = Configuration::getMultiple($soflexibilite_conf_key, null, null, null);
                                        $carrier_obj = new Carrier($cart->id_carrier);
                                        if (isset($carrier_obj->id_reference)) {
                                            $id_reference = $carrier_obj->id_reference;
                                        } else {
                                            $id_reference = $carrier_obj->id;
                                        }
                                        if ($id_reference == $conf['SOFLEXIBILITE_DOM_ID'] ||
                                            $carrier_obj->id == $conf['SOFLEXIBILITE_DOM_ID']
                                        ) {
                                            $so_delivery->type = 'DOM';
                                        }
                                    
                                        if ($id_reference == $conf['SOFLEXIBILITE_DOS_ID'] ||
                                            $carrier_obj->id == $conf['SOFLEXIBILITE_DOS_ID']
                                        ) {
                                            $so_delivery->type = 'DOS';
                                        }
                                    
                                        if ($id_reference == $conf['SOFLEXIBILITE_BPR_ID'] ||
                                            $carrier_obj->id == $conf['SOFLEXIBILITE_BPR_ID']
                                        ) {
                                            $so_delivery->type = 'BPR';
                                        }
                            
                                        if ($id_reference == $conf['SOFLEXIBILITE_A2P_ID'] ||
                                            $carrier_obj->id == $conf['SOFLEXIBILITE_A2P_ID']
                                        ) {
                                            $so_delivery->type = 'A2P';
                                        }
                                    
                                        $log = 'SoFlexibilite > saveDelivery - start';
                                        SfLogger::getInstance()->log(SF_LOG_ORDERS, $log, $doEchoLog);
                    
                                        $status_soflexibilite = (bool)$so_delivery->saveDelivery();
                    
                                        $log = 'SoFlexibilite > saveDelivery = ' . $status_soflexibilite;
                                        SfLogger::getInstance()->log(SF_LOG_ORDERS, $log, $doEchoLog);
                                    }
    
                                    Db::getInstance()->update('customer', array('email' => 'do-not-send@alerts-shopping-flux.com'), '`id_customer` = '.(int)$id_customer);
                                    
                                    $customerClear = new Customer();
        
                                    if (method_exists($customerClear, 'clearCache')) {
                                        $customerClear->clearCache(true);
                                    }
        
                                    SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Calling validateOrder', $doEchoLog);
                                    $payment = $this->_validateOrder($cart, $order->Marketplace, $doEchoLog, $forcedOrder);
                                    $id_order = $payment->currentOrder;
                                    SfLogger::getInstance()->log(SF_LOG_ORDERS, 'validateOrder successfull, id_order = '.$id_order, $doEchoLog);

                                    // If this is a market place expedited order, then we change the order state to what was determined in the config
                                    if (self::isMarketplaceExpeditedOrder($order->Marketplace)) {
                                         self::changeMarketplaceExpeditedOrderStatut($id_order, $doEchoLog);
                                    }
        
                                    //we valid there
                                    SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Notifying ShoppingFlux of order creation', $doEchoLog);
                                    $orderCreation = $this->_validOrders((string)$order->IdOrder, (string)$order->Marketplace, $id_order, false, $currentToken['token']);
                                    SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Notify result of order creation : ' . $orderCreation, $doEchoLog);
        
                                    $reference_order = $payment->currentOrderReference;
                                    Db::getInstance()->update('customer', array('email' => pSQL($email)), '`id_customer` = '.(int)$id_customer);
                                    Db::getInstance()->insert('message', array('id_order' => (int)$id_order, 'message' => 'Numéro de commande '.pSQL($order->Marketplace).' :'.pSQL($order->IdOrder), 'date_add' => date('Y-m-d H:i:s')));

                                    SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Real customer email set again : ' . $email, $doEchoLog);
                                    
                                    $idOrdersList = $this->_updatePrices($id_order, $order, $reference_order);
                                    SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Prices of the order successfully set', $doEchoLog);
    
                                    // Avoid SoColissimo module to change the address by the one he created
                                    foreach ($idOrdersList as $anOrderId) {
                                        $sql_update = 'UPDATE '._DB_PREFIX_.'orders SET id_address_delivery = '.(int)$id_address_shipping.' WHERE id_order = '.(int)$anOrderId;
                                        Db::getInstance()->execute($sql_update);
                                    }
                                    
                                    // Sets the relay information to be able to print with mondial relay module
                                    // Before that, it's required to clear the Order object cache in order to make sure to get the updated carrier information
                                    $orderClear = new Order();
                                    if (method_exists($orderClear, 'clearCache')) {
                                        $orderClear->clearCache(true);
                                    }
                                    if ($order->ShippingMethod == 'REL' || strpos($order->ShippingMethod, 'Mondial Relay') !== false) {
                                        SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Mondial Relay ID : '.$mondialRelayID, $doEchoLog);
                                        if (!empty($mondialRelayID)) {
                                            $this->setMondialRelayData($mondialRelayID, $id_order);
                                        }
                                    }
                                    
                                    SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Order successfully created, Prestashop order id = ' . $id_order, $doEchoLog);
                                } else {
                                    SfLogger::getInstance()->log(SF_LOG_ORDERS, 'ERROR could not load cart', $doEchoLog);
                                }
            
                                $cartClear = new Cart();
            
                                if (method_exists($cartClear, 'clearCache')) {
                                    $cartClear->clearCache(true);
                                }
            
                                $addressClear = new Address();
            
                                if (method_exists($addressClear, 'clearCache')) {
                                    $addressClear->clearCache(true);
                                }
            
                                $customerClear = new Customer();
            
                                if (method_exists($customerClear, 'clearCache')) {
                                    $customerClear->clearCache(true);
                                }
                            }
                        } catch (Exception $pe) {
                            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Error on order creation : '.$pe->getMessage());
                            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Trace : '.print_r($pe->getTraceAsString(), true));
                            $this->_validOrders((string)$order->IdOrder, (string)$order->Marketplace, false, $pe->getMessage(), $currentToken['token']);
                        }
                        if ($forcedOrder) {
                            break;
                        }
                    }
                }
                // Stop the error debug
                SfDebugger::getInstance()->endDebug($forcedOrder);
            }
        }
    }
    

    /**
     * Check Data to avoid errors when creating the order
     * 
     * @return string|boolean : true if everything ok, error message if not
     */
    protected function checkData($order, $marketplace)
    {
        SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Checking order '.$order->IdOrder.' ('.$marketplace.') data');
        
        $id_shop = $this->context->shop->id;
        foreach ($order->Products->Product as $product) {
            if (Configuration::get('SHOPPING_FLUX_REF') == 'true') {
                $ids = $this->getIDs($product->SKU);
            } else {
                $ids = explode('_', $product->SKU);
            }
            if (!isset($ids[1]) || !$ids[1]) {
                $p = new Product($ids[0]);
                if (empty($p->id)) {
                    return 'Product ID don\'t exist, product_id = '.$ids[0];
                }

                $res = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow('
                SELECT active, available_for_order
                FROM '._DB_PREFIX_.'product_shop
                WHERE id_product ='.(int)$ids[0].' AND id_shop = '.(int)$id_shop);

                if ($res['active'] != 1) {
                    return 'Product is not active, product_id = ' . $p->id;
                }
                if ($res['available_for_order'] != 1) {
                    return 'Product is not available for order, product_id = ' . $p->id;
                }

                $minimalQuantity = $p->minimal_quantity;
            } else {
                $exist = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow('
                SELECT id_product_attribute
                FROM '._DB_PREFIX_.'product_attribute
                WHERE id_product = '.(int)$ids[0].'
                AND id_product_attribute ='.(int)$ids[1]);

                if ($exist === false) {
                    return 'Product ID don\'t exist, product_id = '.$ids[0];
                }

                $res = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow('
                SELECT active, available_for_order
                FROM '._DB_PREFIX_.'product_shop
                WHERE id_product ='.(int)$ids[0].' AND id_shop = '.(int)$id_shop);

                if ($res['active'] != 1) {
                    return 'Product is not active, product_id = '.$ids[0];
                }
                if ($res['available_for_order'] != 1) {
                    return 'Product is not available for order, product_id = '.$ids[0];
                }

                $minimalQuantity = (int)Attribute::getAttributeMinimalQty((int)$ids[1]);
            }

            // We check that we are ordering enough quantity for each product. (Based on the minimal quantity to buy)
            if ($minimalQuantity > $product->Quantity) {
                // There is not enough quantity to buy for this product
                return 'Minimal quantity to buy for product '.$product->SKU.' is '.$minimalQuantity.', SKU = '.$product->SKU.', ID = '.$product->id;
            }
        }

        return true;
    }

    public function hookActionObjectAddAfter($params)
    {
        if ($params['object'] instanceof Order && $params['cart'] instanceof Cart) {
            // We first check if the IP is existing from the guest/connections tables
            if ($ip = Db::getInstance()->getValue('
                SELECT c.`ip_address`
                FROM
                    `'._DB_PREFIX_.'guest` g
                JOIN
                    `'._DB_PREFIX_.'connections` c
                ON (
                    g.`id_guest` = c.`id_guest` AND
                    g.`id_customer` = '.(int)$params['object']->id_customer.'
                )
                ORDER BY
                    c.`date_add` DESC
            ')) {
                $ip = long2ip($ip);
            }

            // If not, we get the IP from the customer_ip table
            if (!$ip) {
                $ip = Db::getInstance()->getValue('
                    SELECT `ip`
                    FROM
                        `'._DB_PREFIX_.'customer_ip`
                    WHERE `id_customer` = '.(int)$params['object']->id_customer);
            }
            $ip = $this->getIp($ip);

            if (Configuration::get('SHOPPING_FLUX_TRACKING') != '' && Configuration::get('SHOPPING_FLUX_ID') != '' && $params['object']->module != 'sfpayment') {
                Tools::file_get_contents('https://tag.shopping-flux.com/order/'.base64_encode(Configuration::get('SHOPPING_FLUX_ID').'|'.$params['object']->id.'|'.$params['object']->total_paid).'?ip='.$ip);
            }
    
            if (Configuration::get('SHOPPING_FLUX_STOCKS') != '' && $params['object']->module != 'sfpayment') {
                foreach ($params['cart']->getProducts() as $product) {
                    $id = (isset($product['id_product_attribute'])) ? (int)$product['id_product'].'_'.(int)$product['id_product_attribute'] : (int)$product['id_product'];
                    $qty = (int)$product['stock_quantity'] - (int)$product['quantity'];
    
                    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
                    $xml .= '<UpdateProduct>';
                    $xml .= '<Product>';
                    $xml .= '<SKU>'.$id.'</SKU>';
                    $xml .= '<Quantity>'.$qty.'</Quantity>';
                    $xml .= '</Product>';
                    $xml .= '</UpdateProduct>';
    
                    $this->_callWebService('UpdateProduct', $xml);
                }
            }
        }
    }

    public function hookPostUpdateOrderStatus($params)
    {
        $order = new Order((int)$params['id_order']);

        //set the shop related to the order in the context to avoid empty configurations
        $this->context->shop = new Shop((int)$order->id_shop);

        SfLogger::getInstance()->log(SF_LOG_ORDERS, '------- Status change of order for order ' . $params['id_order']);
        if ((Configuration::get('SHOPPING_FLUX_STATUS_SHIPPED') != '' &&
                Configuration::get('SHOPPING_FLUX_SHIPPED') == '' &&
                $this->_getOrderStates(Configuration::get('PS_LANG_DEFAULT'), 'shipped') == $params['newOrderStatus']->name) &&
                $order->module == 'sfpayment' ||
                (Configuration::get('SHOPPING_FLUX_STATUS_SHIPPED') != '' &&
                (int)Configuration::get('SHOPPING_FLUX_SHIPPED') == $params['newOrderStatus']->id && $order->module == 'sfpayment')) {
            $shipping = $order->getShipping();
            $carrier = new Carrier((int)$order->id_carrier);
            
            $id_order_marketplace = $this->getMkpOrderIdFromMessage((int)$params['id_order']);
            if (!$id_order_marketplace) {
                // This can be the case when multiple orders are created in PrestaShop for the same MKP order (cas of
                // advanced stock management with products dispatched in multiple warehouses).
                // In this case only the last order created will have the MKP ID linked.
                return true;
            }

            $xml = '<?xml version="1.0" encoding="UTF-8"?>';
            $xml .= '<UpdateOrders>';
            $xml .= '<Order>';
            $xml .= '<IdOrder>'.$id_order_marketplace.'</IdOrder>';
            $xml .= '<Marketplace>'.$order->payment.'</Marketplace>';
            $xml .= '<MerchantIdOrder>'.(int)$params['id_order'].'</MerchantIdOrder>';
            $xml .= '<Status>Shipped</Status>';

            // Retrieve the carrier tracking number
            $trackingNumber = isset($shipping[0]) && !empty($shipping[0]['tracking_number']) ?
                $shipping[0]['tracking_number'] :
                $order->shipping_number;

            // Retrieve the carrier tracking URL
            $carrierUrl = isset($shipping[0]) && !empty($shipping[0]['url']) ?
                $shipping[0]['url'] :
                $carrier->url;

            $url = str_replace('http://http://', 'http://', $carrierUrl);
            $url = str_replace('@', $trackingNumber, $url);

            $xml .= '<TrackingNumber><![CDATA['.$trackingNumber.']]></TrackingNumber>';

            if (isset($shipping[0])) {
                $xml .= '<CarrierName><![CDATA['.$shipping[0]['state_name'].']]></CarrierName>';
            }

            $xml .= '<TrackingUrl><![CDATA['.$url.']]></TrackingUrl>';

            $xml .= '</Order>';
            $xml .= '</UpdateOrders>';

            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Sending change of status and tracking number (' . $trackingNumber . ') to ShoppingFlux for order ' . $params['id_order']);
            $responseXML = $this->_callWebService('UpdateOrders', $xml, (int)$order->id_shop, $this->getOrderToken((int)$params['id_order']));

            if (!$responseXML->Response->Error) {
                Db::getInstance()->insert('message', array('id_order' => pSQL((int)$order->id), 'message' => 'Statut mis à jour sur '.pSQL((string)$order->payment).' : '.pSQL((string)$responseXML->Response->Orders->Order->StatusUpdated), 'date_add' => date('Y-m-d H:i:s')));
            } else {
                Db::getInstance()->insert('message', array('id_order' => pSQL((int)$order->id), 'message' => 'Statut mis à jour sur '.pSQL((string)$order->payment).' : '.pSQL((string)$responseXML->Response->Error->Message), 'date_add' => date('Y-m-d H:i:s')));
            }
        } elseif ((Configuration::get('SHOPPING_FLUX_STATUS_CANCELED') != '' &&
                Configuration::get('SHOPPING_FLUX_CANCELED') == '' &&
                $this->_getOrderStates(Configuration::get('PS_LANG_DEFAULT'), 'order_canceled') == $params['newOrderStatus']->name) &&
                $order->module == 'sfpayment' ||
                (Configuration::get('SHOPPING_FLUX_STATUS_CANCELED') != '' &&
                (int)Configuration::get('SHOPPING_FLUX_CANCELED') == $params['newOrderStatus']->id && $order->module == 'sfpayment')) {
            $order = new Order((int)$params['id_order']);
            $shipping = $order->getShipping();

            $id_order_marketplace = $this->getMkpOrderIdFromMessage((int)$params['id_order']);
            if (!$id_order_marketplace) {
                // This can be the case when multiple orders are created in PrestaShop for the same MKP order (cas of
                // advanced stock management with products dispatched in multiple warehouses).
                // In this case only the last order created will have the MKP ID linked.
                return true;
            }

            $xml = '<?xml version="1.0" encoding="UTF-8"?>';
            $xml .= '<UpdateOrders>';
            $xml .= '<Order>';
            $xml .= '<IdOrder>'.$id_order_marketplace.'</IdOrder>';
            $xml .= '<Marketplace>'.$order->payment.'</Marketplace>';
            $xml .= '<MerchantIdOrder>'.(int)$params['id_order'].'</MerchantIdOrder>';
            $xml .= '<Status>Canceled</Status>';
            $xml .= '</Order>';
            $xml .= '</UpdateOrders>';

            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Sending change of status cancelled to ShoppingFlux for order ' . $params['id_order']);
            $responseXML = $this->_callWebService('UpdateOrders', $xml, (int)$order->id_shop);

            if (!$responseXML->Response->Error) {
                Db::getInstance()->insert('message', array('id_order' => (int)$order->id, 'message' => 'Statut mis à jour sur '.pSQL((string)$order->payment).' : '.pSQL((string)$responseXML->Response->Orders->Order->StatusUpdated), 'date_add' => date('Y-m-d H:i:s')));
            } else {
                Db::getInstance()->insert('message', array('id_order' => (int)$order->id, 'message' => 'Statut mis à jour sur '.pSQL((string)$order->payment).' : '.pSQL((string)$responseXML->Response->Error->Message), 'date_add' => date('Y-m-d H:i:s')));
            }
        }
    }

    public function hookTop()
    {
        
        $ip = $this->getIp();
        if ((int)Db::getInstance()->getValue('SELECT `id_customer_ip` FROM `'._DB_PREFIX_.'customer_ip` WHERE `id_customer` = '.(int)$this->context->cookie->id_customer) > 0) {
            $updateIp = array('ip' => pSQL($ip));
            Db::getInstance()->update('customer_ip', $updateIp, '`id_customer` = '.(int)$this->context->cookie->id_customer);
        } else {
            $insertIp = array('id_customer' => (int)$this->context->cookie->id_customer, 'ip' => pSQL($ip));
            Db::getInstance()->insert('customer_ip', $insertIp);
        }
    }

    /* Clean XML strings */
    protected function _clean($string)
    {
        $regexStr = preg_replace('/[^A-Za-z0-9]/', '', $string);
        return preg_replace("/^(\d+)/i", "car-$1", $regexStr);
    }

    /* Call Shopping Flux Webservices */
    protected function _callWebService($call, $xml = false, $id_shop = null, $forceToken = false)
    {
        SfLogger::getInstance()->log(SF_LOG_WEBSERVICE, '------- Start Call Webservice function = '.$call.' -------');

        if (empty($id_shop)) {
            $token = $this->getTokenValue();
        } else {
            $token = $this->getTokenValue($id_shop);
        }

        if (empty($token) && !$forceToken) {
            SfLogger::getInstance()->log(SF_LOG_WEBSERVICE, 'ERROR could not call webservice because of empty token (function = ' . $call . ')');
            return false;
        }

        $service_url = 'https://ws.shopping-feed.com';

        if ($forceToken) {
            $token = $forceToken;
        }
        
        $curl_post_data = array(
            'TOKEN' => $token,
            'CALL' => $call,
            'MODE' => 'Production',
            'REQUEST' => $xml
        );
        
        // Log datas
        SfLogger::getInstance()->log(SF_LOG_WEBSERVICE, $service_url.'?'.http_build_query($curl_post_data, '', '&amp;'));
        if ($xml) {
            SfLogger::getInstance()->log(SF_LOG_WEBSERVICE, 'XML request : ');
            SfLogger::getInstance()->log(SF_LOG_WEBSERVICE, $xml);
        }
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $service_url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $curl_post_data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 120);
        curl_setopt($curl, CURLOPT_TIMEOUT, 120);
        $curl_response = curl_exec($curl);

        // Log datas
        SfLogger::getInstance()->log(SF_LOG_WEBSERVICE, 'XML response : ');
        SfLogger::getInstance()->log(SF_LOG_WEBSERVICE, $curl_response);
        SfLogger::getInstance()->log(SF_LOG_WEBSERVICE, '------- End Call Webservice -------');

        curl_close($curl);
        
        return @simplexml_load_string($curl_response);
    }

    protected function _getOrderStates($id_lang, $type)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
            SELECT osl.name
            FROM `'._DB_PREFIX_.'order_state` os
            LEFT JOIN `'._DB_PREFIX_.'order_state_lang` osl
            ON (os.`id_order_state` = osl.`id_order_state`
            AND osl.`id_lang` = '.(int)$id_lang.')
            WHERE `template` = "'.pSQL($type).'"');
    }

    protected function _getAddress($addressNode, $id_customer, $type, $other, $marketPlace, $shippingMethod)
    {
        //alias is limited
        $type = Tools::substr($type, 0, 32);

        // case for markePlace
        $marketPlace = Tools::strtolower($marketPlace);

        $id_address = (int)Db::getInstance()->getValue('SELECT `id_address`
            FROM `'._DB_PREFIX_.'address` WHERE `id_customer` = '.(int)$id_customer.' AND `alias` = \''.pSQL($type).'\'');

        if ($id_address) {
            $address = new Address((int)$id_address);
        } else {
            $address = new Address();
        }

        $customer = new Customer((int)$id_customer);

        $street1 = '';
        $street2 = '';
        $line2 = false;
        $streets = explode(' ', (string)$addressNode->Street);

        foreach ($streets as $street) {
            if (Tools::strlen($street1) + Tools::strlen($street) + 1 < 32 && !$line2) {
                $street1 .= $street.' ';
            } else {
                $line2 = true;
                $street2 .= $street.' ';
            }
        }

        if ($marketPlace === "cdiscount" && ($shippingMethod === "SO1" || $shippingMethod === "REL" || $shippingMethod === "RCO")) {
            // Workaround for CDiscount usage of last name as pickup-point name
            $pickupPointName = (string)$addressNode->LastName;
            
            // Check if the company is already filled
            $isCompanyFilled = empty($addressNode->Company) ? false : true;

            $address->company = pSQL($pickupPointName);
            
            if ($isCompanyFilled) {
                // When the company is known, we are appending it to the second line of the adresse
                $address->address2 = pSQL($street2." ".$addressNode->Company);
            } else {
                $address->address2 = pSQL($street2);
            }

            // And now we decompose the fullname (in the FirstName field) by first name + last name
            // We consider that what's after the space is the last name
            $name = trim((string)$addressNode->FirstName);
            $nameParts = explode(" ", $name);
            $lastname = "";
            $firstname = "";
            if (isset($nameParts[0])) {
                $firstname = $nameParts[0];
                $lastname = '';
                foreach ($nameParts as $key => $particule) {
                    if ($key === 0) {
                        continue;
                    }
                    $lastname .= $particule.' ';
                }
                $lastname = trim($lastname);
            }
        } else {
            $lastname = (string)$addressNode->LastName;
            $firstname = (string)$addressNode->FirstName;
        }

        $lastname = preg_replace('/\-?\d+/', '', $lastname);
        $firstname = preg_replace('/\-?\d+/', '', $firstname);

        // Avoid Prestashop error on length
        $lastname = Tools::substr($lastname, 0, 32);
        $firstname = Tools::substr($firstname, 0, 32);

        $address->id_customer = (int)$id_customer;
        $address->id_country = (int)Country::getByIso(trim($addressNode->Country));
        $address->alias = pSQL($type);
        $address->lastname = (!empty($lastname)) ? pSQL($lastname) : $customer->lastname;
        $address->firstname = (!empty($firstname)) ? pSQL($firstname) : $customer->firstname;
        $address->address1 = pSQL($street1);
        $address->address2 = empty($address->address2) ? pSQL($street2) : $address->address2;
        $address->company = empty($address->company) ? pSQL($addressNode->Company) : $address->company;
        $address->other = pSQL($other);
        $address->postcode = pSQL($addressNode->PostalCode);
        $address->city = pSQL($addressNode->Town);
        if (empty($addressNode->Phone)) {
            $address->phone = Tools::substr(pSQL($addressNode->PhoneMobile), 0, 16);
        } else {
            $address->phone = Tools::substr(pSQL($addressNode->Phone), 0, 16);
        }

        if (empty($addressNode->PhoneMobile)) {
            $address->phone_mobile = Tools::substr(pSQL($addressNode->Phone), 0, 16);
        } else {
            $address->phone_mobile = Tools::substr(pSQL($addressNode->PhoneMobile), 0, 16);
        }
        
        // Check if country is active
        $country = new Country($address->id_country);
        if (! $country->active) {
            $langDefault = Configuration::get('PS_LANG_DEFAULT');
            $errorMessage = 'The country ' . $country->name[$langDefault] . ' (iso = ' . trim($addressNode->Country);
            $errorMessage .= ') is not active in your Prestashop, you must activate it in your localizaton settings';
            SfLogger::getInstance()->log(SF_LOG_ORDERS, $errorMessage);
            throw new Exception($errorMessage);
        }

        // Update state (needed for US)
        $state_iso_code = Tools::strtoupper(trim($addressNode->Street2));
        if ($id_state = State::getIdByIso($state_iso_code, $address->id_country)) {
            $address->id_state = $id_state;
        }
            
        if ($id_address) {
            $address->update();
        } else {
            $address->add();
        }

        return $address->id;
    }

    protected function _getCustomer($email, $lastname, $firstname)
    {
        $id_customer = (int)Db::getInstance()->getValue('SELECT `id_customer`
            FROM `'._DB_PREFIX_.'customer` WHERE `email` = \''.pSQL($email).'\'');

        if ($id_customer) {
            return $id_customer;
        }

        $lastname = preg_replace('/\-?\d+/', '', $lastname);
        $firstname = preg_replace('/\-?\d+/', '', $firstname);
        // Avoid Prestashop error on length
        $lastname = Tools::substr($lastname, 0, 32);
        $firstname = Tools::substr($firstname, 0, 32);

        $customer = new Customer();
        $customer->lastname = (!empty($lastname)) ? pSQL($lastname) : '-';
        $customer->firstname = (!empty($firstname)) ? pSQL($firstname) : '-';
        $customer->passwd = md5(pSQL(_COOKIE_KEY_.rand()));
        $customer->id_default_group = 1;
        $customer->email = pSQL($email);
        $customer->newsletter = 0;
        $customer->add();

        return $customer->id;
    }

    protected function getIDs($ref)
    {

        if (version_compare(_PS_VERSION_, '1.5', '>')) {
            $sql = 'SELECT pa.id_product, pa.id_product_attribute'.
            ' FROM '._DB_PREFIX_.'product_attribute pa '.
            Shop::addSqlAssociation("product_attribute", "pa").
            ' WHERE pa.reference = "'.  pSQL($ref).'" AND pa.id_product!=0 ';
        } else {
            $sql = 'SELECT pa.id_product, pa.id_product_attribute'.
            ' FROM '._DB_PREFIX_.'product_attribute pa '.
            ' WHERE pa.reference = "'.  pSQL($ref).'" AND pa.id_product!=0 ';
        }

        $row = Db::getInstance()->getRow($sql);

        if (isset($row['id_product_attribute'])) {
            return array($row['id_product'], $row['id_product_attribute']);
        }

        if (version_compare(_PS_VERSION_, '1.5', '>')) {
            $sql = 'SELECT p.id_product '.
            ' FROM '._DB_PREFIX_.'product p '.
            Shop::addSqlAssociation("product", "p").
            ' WHERE p.reference = "'.  pSQL($ref).'" AND p.id_product!=0 ';
        } else {
            $sql = 'SELECT p.id_product  FROM '._DB_PREFIX_.'product p
            WHERE p.reference = "'.  pSQL($ref).'" AND p.id_product!=0';
        }


        $row2 = Db::getInstance()->getRow($sql);

        return array($row2['id_product'], 0);
    }

    protected function _updatePrices($id_order, $order, $reference_order)
    {
        $tax_rate = 0;
        $total_products_tax_excl = 0;

        // We may have multiple orders created for the same reference, (advanced stock management)
        // therefore the total price of each orders needs to be calculated separately
        $priceByRef = array();
        $idOrdersList = array();

        // The id_tax linked to one of the product
        $id_tax = 0;

        $psOrder = new Order((int)$id_order);

        foreach ($order->Products->Product as $product) {
            if (Configuration::get('SHOPPING_FLUX_REF') == 'true') {
                $skus = $this->getIDs($product->SKU);
            } else {
                $skus = explode('_', $product->SKU);
            }

            $sql = 'SELECT t.rate, od.id_order_detail, od.id_order, t.id_tax FROM '._DB_PREFIX_.'tax t
                LEFT JOIN '._DB_PREFIX_.'order_detail_tax odt ON t.id_tax = odt.id_tax
                LEFT JOIN '._DB_PREFIX_.'order_detail od ON odt.id_order_detail = od.id_order_detail
                LEFT JOIN '._DB_PREFIX_.'orders o ON o.id_order = od.id_order
                WHERE o.reference LIKE "'.pSQL($reference_order).'" AND product_id = '.(int)$skus[0];
            if (isset($skus[1]) && $skus[1]) {
                $sql .= ' AND product_attribute_id = '.(int)$skus[1];
            }
            $row = Db::getInstance()->getRow($sql);

            $tax_rate = $row['rate'];
            
            $id_order_detail = $row['id_order_detail'];

            $id_tax = $row['id_tax'];

            // Retrive the id_order linked to the order_reference (as there might be multiple orders created 
            // from the same reference)
            $id_order_from_reference = (int)$row['id_order'];
            if (!in_array($id_order_from_reference, $idOrdersList)) {
                // We populate the list of orders with the same reference
                $idOrdersList[] = $id_order_from_reference;
            }

            $total_price_tax_excl = (float)(((float)$product->Price / (1 + ($tax_rate / 100))) * $product->Quantity);

            if (!isset($priceByRef[$id_order_from_reference])) {
                $priceByRef[$id_order_from_reference] = array();
            }
            if (!isset($priceByRef[$id_order_from_reference]['total_products_tax_excl'])) {
                $priceByRef[$id_order_from_reference]['total_products_tax_excl'] = 0;
            }
            if (!isset($priceByRef[$id_order_from_reference]['total_products_tax_incl'])) {
                $priceByRef[$id_order_from_reference]['total_products_tax_incl'] = 0;
            }
            $priceByRef[$id_order_from_reference]['total_products_tax_excl'] += $total_price_tax_excl;
            $priceByRef[$id_order_from_reference]['total_products_tax_incl'] += (float)((float)$product->Price * $product->Quantity);
            
            $id_product_attribute = (isset($skus[1]) && $skus[1]) ? (int)$skus[1] : 0;
            $original_product_price = Product::getPriceStatic((int)$skus[0], false, $id_product_attribute, 6);
            $updateOrderDetail = array(
                'product_price'        => (float)((float)$product->Price / (1 + ($tax_rate / 100))),
                'reduction_percent'    => 0,
                'reduction_amount'     => 0,
                'ecotax'               => 0,
                'total_price_tax_incl' => (float)((float)$product->Price * $product->Quantity),
                'total_price_tax_excl' => $total_price_tax_excl,
                'unit_price_tax_incl'  => (float)$product->Price,
                'unit_price_tax_excl'  => (float)((float)$product->Price / (1 + ($tax_rate / 100))),
                'original_product_price' => $original_product_price,
            );
            Db::getInstance()->update('order_detail', $updateOrderDetail, '`id_order` = '.(int)$id_order_from_reference.' AND `product_id` = '.(int)$skus[0].' AND `product_attribute_id` = '.$id_product_attribute);
            
            $updateOrderDetailTax = array(
                'unit_amount'  => Tools::ps_round((float)((float)$product->Price - ((float)$product->Price / (1 + ($tax_rate / 100)))), 2),
                'total_amount' => Tools::ps_round((float)(((float)$product->Price - ((float)$product->Price / (1 + ($tax_rate / 100)))) * $product->Quantity), 2),
            );
            Db::getInstance()->update('order_detail_tax', $updateOrderDetailTax, '`id_order_detail` = '.(int)$id_order_detail);
        }
        
        // Cdiscount fees handling
        if ((float) $order->TotalFees > 0) {
            // Retrieve the order invoice ID to associate it with the FDG.
            // This way, the FDG will appears in the invoice.
            $idOrderInvoice = Db::getInstance()->getValue('
            SELECT `id_order_invoice`
            FROM `'._DB_PREFIX_.'order_invoice`
            WHERE `id_order` =  '.(int)$id_order);

            $fdgInsertFields = array(
                'id_order' => (int) $id_order,
                'id_order_invoice' => empty($idOrderInvoice) ? 0 : (int)$idOrderInvoice,
                'id_warehouse' => 0,
                'id_shop' => (int) $psOrder->id_shop,
                'product_id' => 0,
                'product_attribute_id' => 0,
                'product_name' => 'CDiscount fees - ShoppingFlux',
                'product_quantity' => 1,
                'product_quantity_in_stock' => 1,
                'product_quantity_refunded' => 0,
                'product_quantity_return' => 0,
                'product_quantity_reinjected' => 0,
                'product_price' => (float) $order->TotalFees,
                'reduction_percent' => 0,
                'reduction_amount' => 0,
                'reduction_amount_tax_incl' => 0,
                'reduction_amount_tax_excl' => 0,
                'group_reduction' => 0,
                'product_quantity_discount' => 0,
                'product_ean13' => null,
                'product_upc' => null,
                'product_reference' => 'FDG-ShoppingFlux',
                'product_supplier_reference' => null,
                'product_weight' => 0,
                'tax_computation_method' => 0,
                'tax_name' => 0,
                'tax_rate' => 0,
                'ecotax' => 0,
                'ecotax_tax_rate' => 0,
                'discount_quantity_applied' => 0,
                'download_hash' => null,
                'download_nb' => 0,
                'download_deadline' => null,
                'total_price_tax_incl' => (float) $order->TotalFees,
                'total_price_tax_excl' => (float) $order->TotalFees,
                'unit_price_tax_incl' => (float) $order->TotalFees,
                'unit_price_tax_excl' => (float) $order->TotalFees,
                'total_shipping_price_tax_incl' => 0,
                'total_shipping_price_tax_excl' => 0,
                'purchase_supplier_price' => 0,
                'original_product_price' => 0
            );

            // Insert the FDG-ShoppingFlux in the order details
            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Inserting Cdiscount fees, total fees = ' . $order->TotalFees);
            Db::getInstance()->insert('order_detail', $fdgInsertFields);

            // insert doesn't return the id, we therefore need to make another request to find out the id_order_detail_fdg
            $sql = 'SELECT od.id_order_detail FROM '._DB_PREFIX_.'order_detail od
                WHERE od.id_order = '.(int)$id_order.' AND od.product_reference = "FDG-ShoppingFlux"';
            $id_order_detail_fdg = Db::getInstance()->getValue($sql);

            // Insert the FDG in the tax details
            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Inserting Cdiscount fees in order_detail_tax, id_order_detail_fdg = '.$id_order_detail_fdg.', fdg_tax_amount = 0');
            $insertOrderDetailTaxFgd = array(
                'id_order_detail' => $id_order_detail_fdg,
                'id_tax' => 0,
                'unit_amount'  => 0,
                'total_amount' => 0,
            );
            Db::getInstance()->insert('order_detail_tax', $insertOrderDetailTaxFgd);
        }
        
        $actual_configuration = unserialize(Configuration::get('SHOPPING_FLUX_SHIPPING_MATCHING'));
        $shipping_method = Tools::strtolower((string)$order->ShippingMethod);     // uniformise using lowercase only
        $carrier_to_load = isset($actual_configuration[base64_encode(Tools::safeOutput($shipping_method))]) ?
                (int)$actual_configuration[base64_encode(Tools::safeOutput($shipping_method))] :
                (int)Configuration::get('SHOPPING_FLUX_CARRIER');

        $carrier = Carrier::getCarrierByReference($carrier_to_load);

        //manage case PS_CARRIER_DEFAULT is deleted
        $carrier = is_object($carrier) ? $carrier : new Carrier($carrier_to_load);

        // FDG price is only added to the main order
        $priceByRef[$id_order]['total_products_tax_excl'] = Tools::ps_round($priceByRef[$id_order]['total_products_tax_excl'] + (float) $order->TotalFees, 2);
        $priceByRef[$id_order]['total_products_tax_incl'] = Tools::ps_round($priceByRef[$id_order]['total_products_tax_incl'] + (float) $order->TotalFees, 2);

        // Carrier tax calculation START
        if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_invoice') {
            $address = new Address($psOrder->id_address_invoice);
        } else {
            $address = new Address($psOrder->id_address_delivery);
        }
        $carrier_tax_rate = $carrier->getTaxesRate($address);
        $total_shipping_tax_excl = Tools::ps_round((float)((float)$order->TotalShipping / (1 + ($carrier_tax_rate / 100))), 2);

        foreach ($idOrdersList as $anOrderId) {
            $total_paid = (float)$priceByRef[$anOrderId]['total_products_tax_incl'];
            $total_paid_tax_excl = (float)$priceByRef[$anOrderId]['total_products_tax_excl'];
            // Only on main order
            if ($anOrderId == (int)$id_order) {
                // main order
                $total_paid = (float)($total_paid + (float)$order->TotalShipping);
                $total_paid_tax_excl = (float)($priceByRef[$id_order]['total_products_tax_excl'] + (float)$total_shipping_tax_excl);
                $priceByRef[$anOrderId]['total_shipping'] = (float)($order->TotalShipping);
                $priceByRef[$anOrderId]['total_shipping_tax_incl'] = (float)($order->TotalShipping);
                $priceByRef[$anOrderId]['total_shipping_tax_excl'] = $total_shipping_tax_excl;
            } else {
                $priceByRef[$anOrderId]['total_shipping'] = 0;
                $priceByRef[$anOrderId]['total_shipping_tax_incl'] = 0;
                $priceByRef[$anOrderId]['total_shipping_tax_excl'] = 0;
            }

            $priceByRef[$anOrderId]['total_paid'] = (float)$total_paid;
            $priceByRef[$anOrderId]['total_paid_tax_incl'] = (float)$total_paid;
            $priceByRef[$anOrderId]['total_paid_tax_excl'] = (float)$total_paid_tax_excl;
        
            $updateOrder = array(
                'total_paid'              => (float)$priceByRef[$anOrderId]['total_paid'],
                'total_paid_tax_incl'     => (float)$priceByRef[$anOrderId]['total_paid_tax_incl'],
                'total_paid_tax_excl'     => (float)$priceByRef[$anOrderId]['total_paid_tax_excl'],
                'total_paid_real'         => (float)$priceByRef[$anOrderId]['total_paid'],
                'total_products'          => (float)$priceByRef[$anOrderId]['total_products_tax_excl'],
                'total_products_wt'       => (float)$priceByRef[$anOrderId]['total_products_tax_incl'],
                'total_shipping'          => (float)$priceByRef[$anOrderId]['total_shipping'],
                'total_shipping_tax_incl' => (float)$priceByRef[$anOrderId]['total_shipping_tax_incl'],
                'total_shipping_tax_excl' => (float)$priceByRef[$anOrderId]['total_shipping_tax_excl'],
                'carrier_tax_rate'        => $carrier_tax_rate,
                'id_carrier'              => $carrier->id
            );

            $updateOrderInvoice = array(
                'total_paid_tax_incl'     => (float)$priceByRef[$anOrderId]['total_paid_tax_incl'],
                'total_paid_tax_excl'     => (float)$priceByRef[$anOrderId]['total_paid_tax_excl'],
                'total_products'          => (float)$priceByRef[$anOrderId]['total_products_tax_excl'],
                'total_products_wt'       => (float)$priceByRef[$anOrderId]['total_products_tax_incl'],
                'total_shipping_tax_incl' => (float)$priceByRef[$anOrderId]['total_shipping_tax_incl'],
                'total_shipping_tax_excl' => (float)$priceByRef[$anOrderId]['total_shipping_tax_excl'],
            );

            $updateOrderTracking = array(
                'shipping_cost_tax_incl' => (float)$priceByRef[$anOrderId]['total_shipping'],
                'shipping_cost_tax_excl' => (float)$priceByRef[$anOrderId]['total_shipping_tax_excl'],
                'id_carrier' => $carrier->id
            );
        
            Db::getInstance()->update('orders', $updateOrder, '`id_order` = '.(int)$anOrderId);
            Db::getInstance()->update('order_invoice', $updateOrderInvoice, '`id_order` = '.(int)$anOrderId);
            Db::getInstance()->update('order_carrier', $updateOrderTracking, '`id_order` = '.(int)$anOrderId);
        }

        $updatePayment = array('amount' => (float)$order->TotalAmount);
        Db::getInstance()->update('order_payment', $updatePayment, '`order_reference` = "'.$reference_order.'"');

        return $idOrdersList;
    }

    protected function _validateOrder($cart, $marketplace, $doEchoLog, $forcedOrder)
    {
        $payment = new sfpayment();
        $payment->name = 'sfpayment';
        $payment->active = true;

        //we need to flush the cart because of cache problems
        $cart->getPackageList(true);
        $cart->getDeliveryOptionList(null, true);
        $cart->getDeliveryOption(null, false, false);
        
        Context::getContext()->currency = new Currency((int)$cart->id_currency);
        
        if (! Context::getContext()->country->active) {
            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Current context country (' . Context::getContext()->country->id . ') not active');
            $addressDelivery = new Address($cart->id_address_delivery);
            if (Validate::isLoadedObject($addressDelivery)) {
                SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Setting context country to ' . $addressDelivery->id_country);
                Context::getContext()->country = new Country($addressDelivery->id_country);
            }
        }
        $amount_paid = (float)Tools::ps_round((float)$cart->getOrderTotal(true, Cart::BOTH), 2);
        SfLogger::getInstance()->log(SF_LOG_ORDERS, 'calling validateOrder, amount = '.$amount_paid.', currency = '.$cart->id_currency.', marketplace = '.Tools::strtolower($marketplace), $doEchoLog);
        SfDebugger::getInstance()->endDebug($forcedOrder);
        $payment->validateOrder((int)$cart->id, 2, $amount_paid, Tools::strtolower($marketplace), null, array(), $cart->id_currency, false, $cart->secure_key);
        SfDebugger::getInstance()->startDebug();
        SfLogger::getInstance()->log(SF_LOG_ORDERS, 'finished call to validateOrder, order_id = '.$payment->currentOrder, $doEchoLog);
        return $payment;
    }

    /*
     * Fake cart creation
     */

    protected function _getCart($id_customer, $id_address_billing, $id_address_shipping, $productsNode, $currency, $shipping_method, $fees, $id_lang = false, $doEchoLog = false)
    {
        $cart = new Cart();
        $cart->id_customer = $id_customer;
        $cart->id_address_invoice = $id_address_billing;
        $cart->id_address_delivery = $id_address_shipping;
        $cart->id_currency = Currency::getIdByIsoCode((string)$currency == '' ? 'EUR' : (string)$currency);
        if (! $id_lang) {
            $cart->id_lang = Configuration::get('PS_LANG_DEFAULT');
        } else {
            $cart->id_lang = $id_lang;
        }
        $cart->recyclable = 0;
        $cart->secure_key = md5(uniqid(rand(), true));

        $actual_configuration = unserialize(Configuration::get('SHOPPING_FLUX_SHIPPING_MATCHING'));
        
        SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Retrieving carrier, shipping method = '.$shipping_method.', configured carrier reference = '.Configuration::get('SHOPPING_FLUX_CARRIER'), $doEchoLog);
        $shipping_method = Tools::strtolower($shipping_method);     // uniformise using lowercase only
        $carrier_to_load = isset($actual_configuration[base64_encode(Tools::safeOutput($shipping_method))]) ?
            (int)$actual_configuration[base64_encode(Tools::safeOutput($shipping_method))] :
            (int)Configuration::get('SHOPPING_FLUX_CARRIER');
        SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Retrieved carrier reference = '.$carrier_to_load, $doEchoLog);
        
        $carrier = Carrier::getCarrierByReference($carrier_to_load);

        //manage case PS_CARRIER_DEFAULT is deleted
        $carrier = is_object($carrier) ? $carrier : new Carrier($carrier_to_load);

        $cart->id_carrier = $carrier->id;
        $cart->add();

        $useReference = Configuration::get('SHOPPING_FLUX_REF') == 'true';
        if ($useReference) {
            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Loading products by reference', $doEchoLog);
        } else {
            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Loading products by ID', $doEchoLog);
        }
        
        foreach ($productsNode->Product as $product) {
            if ($useReference) {
                $skus = $this->getIDs($product->SKU);
            } else {
                $skus = explode('_', $product->SKU);
            }
        
            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Loading product SKU '.(int)($skus[0]).' and adding it to cart');
            $p = new Product((int)($skus[0]), false, Configuration::get('PS_LANG_DEFAULT'), Context::getContext()->shop->id);

            if (!Validate::isLoadedObject($p)) {
                SfLogger::getInstance()->log(SF_LOG_ORDERS, '    Not a valid SKU', $doEchoLog);
                return false;
            }

            $added = $cart->updateQty((int)($product->Quantity), (int)($skus[0]), ((isset($skus[1])) ? $skus[1] : null));

            if ($added < 0 || $added === false) {
                SfLogger::getInstance()->log(SF_LOG_ORDERS, '    Could not add to cart', $doEchoLog);
                return false;
            }
            SfLogger::getInstance()->log(SF_LOG_ORDERS, '    Product successfully added to cart');
        }

        $cart->update();
        return $cart;
    }

    /**
     * Check and update product quantity
     *
     * Check if there is enough quantity associated to the products before creating the order. If
     * the quantity is not sufficient, when update the quantity of the product to avoid an error
     * during the creation of the order.
     *
     * For orders expedited directly from a marketplace, the product quantity should not be impacted,
     * therefore, we temporarily increase the quantity that will then be decrease by PrestaShop while
     * creating the order. So the final quantity will not change on the shop once the order has been
     * created.
     * 
     * @param  Object $productsNode the product node coming from the API
     * @param  string $marketplace name of the markeplace
     * @param  int $idShop associated with the current token
     */
    protected function checkProductsQuantity($productsNode, $marketplace, $idShop)
    {
        // Check if the order stock is managed by the market place
        $isMarketPlaceExpedited = self::isMarketplaceExpeditedOrder($marketplace);

        foreach ($productsNode->Product as $product) {
            if (Configuration::get('SHOPPING_FLUX_REF') == 'true') {
                $skus = $this->getIDs($product->SKU);
            } else {
                $skus = explode('_', $product->SKU);
            }

            // Check if the advanced stock management is enabled
            $isAdvStockEnabled = Configuration::get('PS_ADVANCED_STOCK_MANAGEMENT') == 1 ? true : false;
            if ($isAdvStockEnabled) {
                $productObj = new Product($skus[0], false, Configuration::get('PS_LANG_DEFAULT'), $idShop);
                if ($productObj->advanced_stock_management == 0) {
                    // Advanced stock management not enabled for this product
                    $isAdvStockEnabled = false;
                }
            }

            if ($isAdvStockEnabled && !$isMarketPlaceExpedited) {
                // When advanced stock management is enabled, we do not force the product quantity.
                $idAttribute = isset($skus[1]) ? $skus[1] : 0;
                $warehouseIds = $this->getAvailableWarehouses($skus[0], $idAttribute, (int)$product->Quantity, $idShop);
                if (empty($warehouseIds)) {
                    SfLogger::getInstance()->log(SF_LOG_ORDERS, "No warehouse found with a quantity available for product ".$skus[0].", attribute = ".$idAttribute);
                    // No warehouse found with a quantity available for this product
                    return "No warehouse found with a quantity available for product = ".$skus[0].", attribute = ".$idAttribute;
                }
            } else {
                if (isset($skus[1]) && $skus[1] !== false) {
                    $quantity = StockAvailable::getQuantityAvailableByProduct((int)$skus[0], (int)$skus[1], $idShop);
                    $idProduct = (int)$skus[0];
                    $idProductAttribute = (int)$skus[1];
                } else {
                    $quantity = StockAvailable::getQuantityAvailableByProduct((int)$product->SKU, null, $idShop);
                    $idProduct = (int)$product->SKU;
                    $idProductAttribute = 0;
                }

                if ($isMarketPlaceExpedited) {
                    // The stock is managed by the market place.
                    // We directly add the required quantity to the product that will be
                    // later on deduced when the order is validated by PrestaShop
                    $tmpQuantity = $quantity + ((int) $product->Quantity);
                    SfLogger::getInstance()->log(SF_LOG_ORDERS, $marketplace . ': '.
                        'Order expedited by the marketplace - '.
                        'Changing quantity of product (' . $idProduct . '_' . $idProductAttribute . ') '.
                        'from ' . $quantity . ' to ' . $tmpQuantity);
                    $deltaQuantity = (int)$tmpQuantity - (int)$quantity;
                    StockAvailable::updateQuantity($idProduct, $idProductAttribute, $deltaQuantity, $idShop);
                    // No need to continue
                    continue;
                }

                if ($quantity - $product->Quantity >= 0) {
                    // Enough quantity
                    continue;
                }

                // When there is not enough quantity for the product and that PrestaShop deny the order creation
                // in this case, then we force the product quantity.
                $outOfStockAuthorized = $this->isOutOfStockAuthorized($idProduct);
                if (!$outOfStockAuthorized) {
                    SfLogger::getInstance()->log(SF_LOG_ORDERS, $marketplace . ': '.
                        'Not enough quantity for product (' . $idProduct . '_' . $idProductAttribute . ') - '.
                        'Changing from ' . $quantity . ' to ' . (int)$product->Quantity);
                    $deltaQuantity = (int)$product->Quantity - (int)$quantity;
                    StockAvailable::updateQuantity($idProduct, $idProductAttribute, $deltaQuantity, $idShop);
                }
            }
        }
    }

    /**
     * Check whether or not an order can be created when a produit is out of stock.
     * Based on PrestaShop configuration
     * @param  int product ID
     * @return boolean            Is order allowed when produit out of stock
     */
    protected function isOutOfStockAuthorized($productId)
    {
        $outOfStockAuthorized = false;

        /**
         * Check if orders can be created when the product is out of stock
         * @var int|null
         * 0|null -> Deny orders
         * 1 -> Allow orders
         * 2 -> Default as set in the Products Preferences page
         */
        $checkOutOfStock = StockAvailable::outOfStock((int)$productId);

        if ($checkOutOfStock == 1) {
            $outOfStockAuthorized = true;
        } elseif ($checkOutOfStock == 2) {
            if (Configuration::get('PS_ORDER_OUT_OF_STOCK') == 1) {
                // 0|null -> Reject order
                // 1 -> Allow order
                $outOfStockAuthorized = true;
            }
        }

        return $outOfStockAuthorized;
    }

    /**
     * Retrieve warehouse IDs where the given product is available
     *
     * @param  integer $idProduct
     * @param  integer $idAttribute
     * @param  integer $quantityOrdered the quantity ordered
     * @param  integer $idShop
     * @return array               List of wharehouse ids with available quantity
     */
    protected function getAvailableWarehouses($idProduct, $idAttribute, $quantityOrdered, $idShop)
    {
        $selectedWarehouses = array();
        $warehouses = array();
        $stockManager = StockManagerFactory::getManager();

        $warehouseInfos = Warehouse::getWarehousesByProductId($idProduct, $idAttribute);

        if (count($warehouseInfos) == 0) {
            // No warehouses associated to this product
            return array();
        }
        
        SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Check stock in warehouses for id_product '.$idProduct.' and id_product_attribute '.$idAttribute);

        foreach ($warehouseInfos as $aWarehouse) {
            $id_warehouse = (int)$aWarehouse['id_warehouse'];

            $physicalQuantity = $stockManager->getProductPhysicalQuantities(
            $idProduct,
            $idAttribute,
            $id_warehouse);

            SfLogger::getInstance()->log(SF_LOG_ORDERS, '-- Warehouse #'.$id_warehouse.' - Physical quantity: '.$physicalQuantity);

            $warehouses[] = array('id_warehouse' => $id_warehouse, 'quantity' => $physicalQuantity);
            if($physicalQuantity - $quantityOrdered >= 0) {
                $selectedWarehouses[] = array('id_warehouse' => $id_warehouse, 'quantity' => $physicalQuantity);
            }
        }

        if (!empty($selectedWarehouses)) {
            return $selectedWarehouses;
        }

        // When none of the warehouse have enough stock for the selected product, we check if PrestaShop allow out of stock orders.
        // In which case we will return all warehouses
        $outOfStockAuthorized = $this->isOutOfStockAuthorized($idProduct);
        if ($outOfStockAuthorized) {
            return $warehouses;
        }

        // When PrestaShop doesn't allow out of stock orders, we force the product quantity in one of the warehouse
        $firstWarehouseFound = $warehouses[0];
        $id_warehouse = (int)$firstWarehouseFound['id_warehouse'];
        SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Not enough quantity for product (' . $idProduct . '_' . $idAttribute . ') - '.
            'Force in warehouse '.$id_warehouse.' - '.
            'Changing from ' . $firstWarehouseFound['quantity'] . ' to ' . $quantityOrdered);
        if ($this->addAdvancedStockToProduct($idProduct, $idAttribute, $id_warehouse, $quantityOrdered)) {
            $selectedWarehouses[] = array('id_warehouse' => $id_warehouse, 'quantity' => $quantityOrdered);
            return $selectedWarehouses;
        } else {
            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Failed adding stock to product (' . $idProduct . '_' . $idAttribute . ')');
            return array();
        }
    }

    /**
     * Add quantity for product/combination when advanced stock is being used.
     * It will record a stock movement.
     * @param integer $idProduct
     * @param integer $idProductAttribute
     * @param integer $idWarehouse
     * @param integer $quantity
     * @param integer $$idShop
     */
    protected function addAdvancedStockToProduct($idProduct, $idProductAttribute, $idWarehouse, $quantity, $idShop)
    {
        $stockManager = StockManagerFactory::getManager();
        $warehouse = new Warehouse($idWarehouse);
        $idStockMvtReason = 0; // Will then take the default reason as 0 does not exists

        // Retrieve the wholesale price (tax ecluded)
        $product = new Product($id_product, false, null, $idShop);
        $wholesalePrice = $product->wholesale_price;
        if ($idProductAttribute != 0) {
            $combination = new Combination($idProductAttribute);
            if ($combination && $combination->wholesale_price != '0.000000') {
                $wholesalePrice = $combination->wholesale_price;
            }
        }

        // Retrive an admin employee (required for the context of addProduct)
        $superAdmins = Employee::getEmployeesByProfile(_PS_ADMIN_PROFILE_);
        $employee = new Employee((int)$superAdmins[0]['id_employee']);

        if ($stockManager->addProduct(
            (int)$idProduct,
            $idProductAttribute,
            $warehouse,
            $quantity,
            $idStockMvtReason,
            $wholesalePrice,
            true,
            null,
            $employee
        )) {
            StockAvailable::synchronize((int)$idProduct);
            return true;
        }
        return false;
    }

    protected function _validOrders($id_order, $marketplace, $id_order_merchant = false, $error = false, $token = null)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<ValidOrders>';
        $xml .= '<Order>';
        $xml .= '<IdOrder>'.$id_order.'</IdOrder>';
        $xml .= '<Marketplace>'.$marketplace.'</Marketplace>';

        if ($id_order_merchant) {
            $xml .= '<MerchantIdOrder>'.$id_order_merchant.'</MerchantIdOrder>';
        }

        if ($error) {
            $xml .= '<ErrorOrder><![CDATA['.$error.']]></ErrorOrder>';
        }

        $xml .= '</Order>';
        $xml .= '</ValidOrders>';

        $token = empty($token) ? $this->getOrderToken($id_order_merchant) : $token;

        $this->_callWebService('ValidOrders', $xml, null, $token);
    }

    protected function _setShoppingFeedId()
    {
        $login = Configuration::get('SHOPPING_FLUX_LOGIN');
        $id = Configuration::get('SHOPPING_FLUX_ID');

        if (empty($login) || !empty($id)) {
            return;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<GetClientId>';
        $xml .= '<Login>'.$login.'</Login>';
        $xml .= '</GetClientId>';

        $getClientId = $this->_callWebService('GetClientId', $xml);

        if (!is_object($getClientId)) {
            return;
        }

        Configuration::updateValue('SHOPPING_FLUX_ID', (string)$getClientId->Response->ID);
    }

    protected function _translateField($field)
    {
        $translations = array(
            'FR' => array(
                'product' => 'produit',
                'supplier_link' => 'url-fournisseur',
                'manufacturer_link' => 'url-fabricant',
                'on_sale' => 'solde',
                'name' => 'nom',
                'link' => 'url',
                'short_description' => 'description-courte',
                'price' => 'prix',
                'old_price' => 'prix-barre',
                'shipping_cost' => 'frais-de-port',
                'shipping_delay' => 'delai-livraison',
                'brand' => 'marque',
                'category' => 'rayon',
                'quantity' => 'quantite',
                'weight' => 'poids',
                'ecotax' => 'ecotaxe',
                'vat' => 'tva',
                'mpn' => 'ref-constructeur',
                'supplier_reference' => 'ref-fournisseur',
                'category_breadcrumb' => 'fil-ariane',
                'combination_link' => 'url-declinaison',
            )
        );

        $iso_code = $this->default_country->iso_code;

        if (isset($translations[$iso_code][$field])) {
            return $translations[$iso_code][$field];
        }

        return $field;
    }

    /**
     * Get the user's IP handling if there is a proxy
     * @param String $ip optionnal IP comming from the order
     */
    protected function getIp($ip = null)
    {
        if (empty($ip)) {
            if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                // Cloudflare is directly providing the client IP in this server variable (when correctly set)
                $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
            } else if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                // We retrieve the proxy list
                $ipForwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'];
                // In case of multiple proxy, there values will be split by comma. It will list each server IP the request passed throug
                $proxyList = explode(",", $ipForwardedFor);
                // The first IP of the list is the client IP (the last IP is the last proxy)
                $ip = trim(reset($proxyList));
            } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
                $ip = $_SERVER['HTTP_CLIENT_IP'];
            } else {
                $ip = $_SERVER['REMOTE_ADDR'];
            }
        }
        return $ip;
    }
    
    /**
     * Get additional fields from Product.php override
     */
    protected function getOverrideFields()
    {
        // Load core Product info
        
        static $definition;
        
        // Load override Product info
        $overrideProductFields = Product::$definition['fields'];
        
        $newFields = array();
        
        $productCoreFields = ProductCore::$definition['fields'];
        $coreFields = array();
        
        foreach ($productCoreFields as $key => $value) {
            $coreFields[] = $key;
        }
        
        foreach ($overrideProductFields as $key => $value) {
            if (!in_array($key, $coreFields)) {
                $newFields[] = $key;
            }
        }
        
        return $newFields;
    }
    
    /**
     * Function to display fields found in Product class override
     * @param $configuration
     */
    protected function getOverrideFieldsContent($configuration)
    {
        $fields = $this->getOverrideFields();
        $message = '';
        if (count($fields) == 0) {
            $message = '<span style="display: block; padding: 3px 0 0 0;">';
            $message .= $this->l('No additional field found on your website').'</span>';
        }
        
        $html = '';
        $html .= '<p></p>';
        $html .= '<p><label>'.$this->l('Select additional fields to export').' :</label>'.$message.'</p>';
        $html .= '<p style="clear: both"></p>';

        foreach ($fields as $field) {
            $html .= '<p><label>'.$field.' : </label>';
            $html .= '<input type="checkbox" name="SHOPPING_FLUX_CUSTOM_'.$field.'" ';
            $html .= 'value="1" '.($configuration['SHOPPING_FLUX_CUSTOM_'.$field] == 1 ? 'checked="checked"' : '');
            $html .= '/></p>';
        }
        return $html;
    }
    
    /**
     * Function to display cron information
     * @param $configuration
     */
    protected function getCronDetails($configuration)
    {
        $id_shop = $this->context->shop->id;
        $html = '';
        $html .= '<p style="clear: both"><label>';
        $html .= '<p style="clear: both"><label>'.$this->l('Number of products to be exported');
        $html .= ' :</label><span style="display: block; padding: 3px 0 0 0;">';
        $html .= $this->countProducts();
        $html .= '<p style="clear: both"><label>';
        $html .= '<p style="clear: both"><label>'.$this->l('Cron last generated date');
        $html .= ' :</label><span style="display: block; padding: 3px 0 0 0;">';

        $lang = Tools::getValue('lang');
        $configName = 'SHOPPING_FLUX_CRON_TIME';
        if (!empty($lang) && Language::getIdByIso($lang) !== false) {
            $configName .= '_' . Tools::strtoupper($lang);
        }
        $configTimeValue = Configuration::get($configName, null, null, $id_shop);

        if ($configTimeValue != '') {
            $cronTime = $configTimeValue;
            $html .= Tools::displayDate($cronTime, $configuration['PS_LANG_DEFAULT'], true, '/');
        } else {
            $html .= 'Jamais';
        }
        $html .= '</span></p>';
        $html .= '<p style="clear: both"><label>';
        $html .= $this->l('Number of products to treat at each CRON\'s URL call (do not modify)');
        $html .= ' : </label><input type="text" name="SHOPPING_FLUX_PASSES" value="';
        $html .= Tools::safeOutput($configuration['SHOPPING_FLUX_PASSES']).'"/></p>';
        return $html;
    }
    
    /**
     * Function to display curl information
     *
     * @param $configuration
     *
     */
    protected function defaultAdvancedParameterInformationView($configuration)
    {
        $html = '<form method="post" action="'.Tools::safeOutput($_SERVER['REQUEST_URI']).'">';
        $html .= '<fieldset>';
        $html .= '<legend>'.$this->l('Advanced settings').'</legend>';
        $html .= $this->getCronDetails($configuration);
        $html .= '<p style="margin-top:20px"><input type="submit" value="'.$this->l('Update');
        $html .= '" name="rec_config_adv" class="button"/></p>';
        $html .= '</fieldset>';
        $html .= '</form>';
    
        return $html;
    }
    
    /**
     * Function to display curl information
     *
     * @param $configuration
     *
     */
    protected function defaultInformationView($configuration)
    {
        $html = '<fieldset>';
        $html .= '<legend>'.$this->l('Prerequisites').'</legend>';
        $html .= '<p style="clear: both"><label>'.$this->l('CURL Technology').' : </label>';
        $html .= '<span style="display: block; padding: 3px 0 0 0;">'.$this->isCurlInstalled().'</span></p>';
        $html .= '<p><label>'.$this->l('Open URL').' : </label><span style="display: block; padding: 3px 0 0 0;">';
        $html .= $this->isFopenAllowed().'</span></p>';
        
        // If module is not active on some shop, it can miss orders creations
        if (version_compare(_PS_VERSION_, '1.5', '>') && Shop::isFeatureActive()) {
            $html .= '<p style="clear: both"><label>'.$this->l('Module should be active in all shop\'s context');
            $html .= ' :</label><span style="display: block; padding: 3px 0 0 0;">';
            $isModInactive = false;
            foreach (Shop::getShops() as $shop) {
                // Loop on all shops
                if ($shop['active']) {
                    // Check only if in all shops the module is active
                    $sql = 'SELECT id_module FROM `' . _DB_PREFIX_ . 'module_shop` 
					        WHERE id_module=(SELECT id_module 
					        FROM `' . _DB_PREFIX_ . 'module` WHERE name="' . pSQL($this->name) . '")
				            AND id_shop=' . pSQL((int)$shop['id_shop']);
                    $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
                    if (!count($result)) {
                        $isModInactive = true;
                    }
                }
            }
            if ($isModInactive) {
                $html .= '<span style="color:red;">KO</span>';
                $html .= '<br/>'.$this->l('Module is not active on some shop, it can miss orders creations!');
            } else {
                $html .= '<span>OK</span>';
            }
        }
        $html .= '</fieldset>';
        return $html;
    }
    

    /**
     * Display the token configuration form
     */
    protected function defaultTokenConfigurationView()
    {
        $tokenTreeFilled = $this->getAllTokensOfShop(true, true);
        $tokenTree = array();

        // We prepare a list of token for each shop/currency/lang
        // Loop on shops
        $shops = Shop::getShops();
        foreach ($shops as $currentShop) {
            $id_shop = $currentShop['id_shop'];
            $values = array();

            // Get all languages for the shop
            $shopLanguages = Language::getLanguages(true, $id_shop);
            // Get all currencies for the shop
            $shopCurrencies = Currency::getCurrenciesByIdShop($id_shop);

            foreach ($shopLanguages as $currentLang) {
                $idLang = $currentLang['id_lang'];
                $nameLang = $currentLang['name'];

                // Loop on currencies
                foreach ($shopCurrencies as $currentCurrency) {
                    $idCurrency = $currentCurrency['id_currency'];
                    $nameCurrency = $currentCurrency['name'];
                    $values[] = array(
                        'name' => $nameLang.' / '.$nameCurrency,
                        'id' => $idLang.'_'.$idCurrency,
                        'id_currency' => $idCurrency,
                        'id_lang' => $idLang,
                        'token' => ''
                    );
                }
            }

            // General token
            $tokenTree[] = array(
                'id_shop' => $id_shop,
                'name' => $currentShop['name'],
                'token' => '',
                'values' => $values
            );
        }

        // We fill the existing token from the pre-built list
        if (!empty($tokenTreeFilled)) {
            foreach ($tokenTreeFilled as $aFilledTree) {
                foreach ($tokenTree as &$aTree) {
                    if ($aTree['id_shop'] != $aFilledTree['id_shop']) {
                        continue;
                    }
                    
                    $aTree['token'] = $aFilledTree['token'];

                    if (!isset($aFilledTree['values']) || empty($aFilledTree['values'])) {
                        continue;
                    }
                    
                    foreach ($aFilledTree['values'] as $aValue) {
                        foreach ($aTree['values'] as &$aTreeValue) {
                            if ($aValue['id_currency'] == $aTreeValue['id_currency'] && $aValue['id_lang'] == $aTreeValue['id_lang']) {
                                $aTreeValue['token'] = $aValue['token'];
                            }
                        }
                    }
                }
            }
        }

        $this->context->smarty->assign(array(
            'token_tree' => $tokenTree,
            'postUri' => Tools::safeOutput($_SERVER['REQUEST_URI'])
        ));
    
        $html = '<fieldset>';
        $html .= '<legend>'.$this->l('Shop\'s tokens').'</legend>';
        $sfMultitokenActivation = (int)Configuration::get('SHOPPING_FLUX_MULTITOKEN');
        $this->context->smarty->assign(array(
            'sfMultitokenActivation' => $sfMultitokenActivation,
        ));
        $html .= $this->display(__FILE__, 'views/templates/admin/tokens.tpl');
        $html .= '</fieldset>';
        return $html;
    }
    
    /**
     * Get the configured token
     *
     * When no parameters are set, the token associated to the current context will be returned (case
     * when multi-shop is not enabled
     *
     * @param int|bool $id_shop the shop context (optionnal)
     * @param int|null $id_shop_group (optionnal)
     * @param int|bool $id_currency (optionnal) the currency
     * @param int|bool $id_lang (optionnal) the lang
     */
    public function getTokenValue($id_shop = false, $id_shop_group = null, $id_currency = false, $id_lang = false)
    {
        if ($id_shop === false && version_compare(_PS_VERSION_, '1.5', '>')) {
            $id_shop = $this->context->shop->id;
        }

        $key = 'SHOPPING_FLUX_TOKEN';
        if ($id_currency && $id_lang) {
            $key .= '_'.$id_currency.'_'.$id_lang;
        }

        if ($id_shop) {
             return Configuration::get($key, false, $id_shop_group, $id_shop);
        } else {
            return Configuration::get($key);
        }
    }

    /**
     * Gets all token of shop(s)
     * @param int $id_shop
     */
    public function getAllTokensOfShop($allShops = false, $putChildrenInValues = false, $returnRawFormat = false)
    {
        if (! $allShops) {
            // Only get the current shop
            $id_shop = $this->context->shop->id;
            $id_shop_group = (int) $this->context->shop->id_shop_group;
            $res = $this->getAllTokensOfOneShop($id_shop, $id_shop_group, $putChildrenInValues);
        } else {
            $res = array();
            $shops = Shop::getShops();
            
            // Loop on shops
            foreach ($shops as &$currentShop) {
                $res = array_merge($res, $this->getAllTokensOfOneShop($currentShop['id_shop'], $currentShop['id_shop_group'], $putChildrenInValues));
            }
        }
        if ($returnRawFormat) {
            $rawFormat = array();
            foreach ($res as $currentToken) {
                if (! in_array($currentToken['token'], $rawFormat)) {
                    $rawFormat[] = $currentToken['token'];
                }
            }
            $res = $rawFormat;
        }
        return $res;
    }
    
    /**
     * Gets all token a specific shop
     * @param int $id_shop
     */
    public function getAllTokensOfOneShop($id_shop, $id_shop_group, $putChildrenInValues)
    {
        $shop = new Shop($id_shop);
        $res = array();
        $tokenGeneral = $this->getTokenValue($id_shop);
        if ($tokenGeneral) {
            $res[] = array(
                'id_shop' => $id_shop,
                'id_shop_group' => null,
                'token' => $tokenGeneral,
                'id_lang' => Configuration::get('PS_LANG_DEFAULT'),
                'id_currency' => false,
                'name' => $shop->name,
                'values' => array(),
            );
        }
    
        $shopLanguages = Language::getLanguages(true, $id_shop);
        $shopCurrencies = Currency::getCurrenciesByIdShop($id_shop);
        // Loop on languages
        foreach ($shopLanguages as $currentLang) {
            $idLang = $currentLang['id_lang'];
            // Finally loop on currencies
            foreach ($shopCurrencies as $currentCurrency) {
                $idCurrency = $currentCurrency['id_currency'];
                $token = $this->getTokenValue($id_shop, $id_shop_group, $idCurrency, $idLang);
                if ($token) {
                    $toAdd = array(
                        'id_shop' => $id_shop,
                        'id_shop_group' => $id_shop_group,
                        'token' => $token,
                        'id' => $idLang . '_' . $idCurrency,
                        'id_lang' => $idLang,
                        'id_currency' => $idCurrency,
                        'name' => $currentLang['name'] . ' / ' . $currentCurrency['name'],
                    );
                    if ($tokenGeneral && $putChildrenInValues) {
                        $res[0]['values'][] = $toAdd;
                    } else {
                        $res[] = $toAdd;
                    }
                }
            }
        }
    
        return $res;
    }
        
        
    /**
     * Set a token
     * @param int $id_shop the shop context
     * @param int $id_currency (optionnal) the currency
     * @param int $id_lang (optionnal) the lang
     */
    protected function setTokenValue($value, $id_shop = null, $id_currency = false, $id_lang = false)
    {
        $key = 'SHOPPING_FLUX_TOKEN';
        if ($id_currency && $id_lang) {
            $key .= '_'.$id_currency.'_'.$id_lang;
        }
        if (version_compare(_PS_VERSION_, '1.5', '>')) {
            return Configuration::updateValue($key, $value, false, null, $id_shop);
        } else {
            return Configuration::updateValue($key, $value);
        }
    }
        
    
    /**
     * Function to check if curl is installed
     */
    protected function isCurlInstalled($returnBoolean = false)
    {
        if ($returnBoolean) {
            return in_array('curl', get_loaded_extensions());
        }
        if (in_array('curl', get_loaded_extensions())) {
            $response = $this->l('Active (correct)');
        } else {
            $response = $this->l('Not installed (incorrect)');
        }
        return $response;
    }

    /**
     * Function to check if fopen is allowed
     */
    protected function isFopenAllowed()
    {
        if (ini_get('allow_url_fopen')) {
            $response = $this->l('Active (correct)');
        } else {
            $response = $this->l('Not installed (incorrect)');
        }
        return $response;
    }
    
    /**
     * Function to retrieve image with max width and height
     */
    protected function getMaxImageInformation()
    {
        $sql = 'SELECT it.name, (it.width * it.height) AS area
                FROM `'._DB_PREFIX_.'image_type` it
                WHERE it.products =1
                ORDER BY area DESC
                LIMIT 1';

        $result = Db::getInstance()->executeS($sql);
        return $result['0']['name'];
    }

    /**
     * Manage feed name depending on currency and lang
     */
    protected function getFeedName($tmp_file = true)
    {
        $lang = Tools::getValue('lang');
        $currency = Tools::getValue('currency');
        $name = '';

        if (version_compare(_PS_VERSION_, '1.5', '>')
            && Configuration::getGlobalValue('SHOPPING_FLUX_XML_SHOP_ID') === "checked") {
            $name .= '_'.$this->context->shop->id;
        }

        if ($lang != '') {
            $name .= '_'.$lang;
        }
        if ($currency != '') {
            $name .= '_'.$currency;
        }

        if ($tmp_file) {
            return dirname(__FILE__).'/feed'.$name.'_tmp.xml';
        } else {
            return dirname(__FILE__).'/feed'.$name.'.xml';
        }
    }
    
    /**
     * Saves last order received from ShoppingFlux in order to replay it for debug purposes
     */
    protected function saveLastOrderTreated($order)
    {
        $lastOrderstreated = $this->getLastOrdersTreated();

        // Check if already existing
        if (! in_array((string)$order->IdOrder, array_keys($lastOrderstreated))) {
            if (sizeof($lastOrderstreated) > 19) {
                $lastOrderstreated = array_slice($lastOrderstreated, 0, 19);
            }
            $lastOrderstreated[(string)$order->IdOrder] = $order->asXML();
            $fp = fopen($this->getLastOrderTreadFile(), 'w');
            fwrite($fp, serialize($lastOrderstreated));
            fclose($fp);
        }
    }

    /**
     * Gets the array of last orders received
     */
    public function getLastOrdersTreated()
    {
        $fromFile = file_get_contents($this->getLastOrderTreadFile(), 'w');
        if (trim($fromFile) == '') {
            $fromFile = array();
        } else {
            $fromFile = unserialize($fromFile);
        }
        return $fromFile;
    }
    
    /**
     * Get the filename where last orders received are stored
     */
    public function getLastOrderTreadFile()
    {
        return dirname(__FILE__) . '/lastOrdersTreated.debug';
    }
    
    /**
     * Replay on order previously received
     * For debug purpose only
     */
    public function replayOrder($orderId, $order = false)
    {
        if ($orderId) {
            $lastOrderstreated = $this->getLastOrdersTreated();
            if (in_array($orderId, array_keys($lastOrderstreated))) {
                $order = @simplexml_load_string($lastOrderstreated[$orderId]);
                $this->hookbackOfficeTop(true, $order);
            }
        } else {
            $this->hookbackOfficeTop(true, $order);
        }
    }
    
    /**
     * Returns (if existings) a prestashop order id from a SF order id
     */
    public function getPrestashopOrderIdFromSfOrderId($orderIdSf, $orderMarkerplace)
    {
        $orderExists = Db::getInstance()->getRow('SELECT m.id_order  FROM '._DB_PREFIX_.'message m
                            WHERE m.message LIKE "%Numéro de commande '.pSQL($orderMarkerplace).' :'.pSQL($orderIdSf).'%"');
        
        return $orderExists['id_order'];
    }

    /**
     * Get the MarketPlace order ID (ShoppingFlux Order ID) from the PrestaShop order ID.
     * This information is extracted from the message added to the order
     * @param  int $ps_id_order  The PrestaShop order ID
     * @return bool|string The MarketPlace order ID or false if not found
     */
    public function getMkpOrderIdFromMessage($ps_id_order)
    {

        // We cannot rely on the marketplace name as it may be in lower/upper case, so
        // we can all the messages containing Numéro de commande et we retrieve the first
        // one (With the ORDER BY). This eleminate the risk of other modules adding a
        // message that may contains the same string by taking the one that has been
        // created first (by ShoppingFlux)
        $message = Db::getInstance()->getValue('SELECT m.message, m.id_order '.
            'FROM '._DB_PREFIX_.'message m '.
            'WHERE m.message LIKE "Numéro de commande%" '.
            'AND id_order = '.(int)$ps_id_order.' '.
            'ORDER BY id_message ASC');

        if (empty($message)) {
            // Message not found
            return false;
        }

        $id_order_marketplace = explode(':', $message);

        // The trim() == "True" was in the previous version of the code when retrieving the
        // message with $order->getFirstMessage();. It's kept for compatibility reasons while
        // I'm not sure what's the purpose of this check.
        return trim($id_order_marketplace[1]) == 'True' ? '' : $id_order_marketplace[1];
    }

    /**
     * Get the correponding token for this order in multitoken mode
     */
    public function getOrderToken($id_order)
    {
        $order = new Order($id_order);
        
        if (version_compare(_PS_VERSION_, '1.5', '>')) {
            $id_shop = $order->id_shop;
            $id_shop_group = (int)$this->context->shop->id_shop_group;
        } else {
            $id_shop = false;
            $id_shop_group = null;
        }
        
        $tokenValue = $this->getTokenValue($id_shop, $id_shop_group, $order->id_currency, $order->id_lang);
        // Token is in id_shop of the order
        if ($tokenValue != '') {
            return $tokenValue;
        } else {
            return $this->getTokenValue($id_shop, $id_shop_group);
        }
    }
    
    /**
     * Gets relay data from webservice, and inserts into mondial relay module
     */
    protected function setMondialRelayData($idRelay, $idOrder)
    {
        SfLogger::getInstance()->log(SF_LOG_ORDERS, 'MondialRelay - Id Relay : '.$idRelay.',  Id Order : '.$idOrder);

        $order = new Order($idOrder);
        $carrier = new Carrier((int)$order->id_carrier);

        SfLogger::getInstance()->log(SF_LOG_ORDERS, 'MondialRelay - id_address_delivery: '.$order->id_address_delivery);
    
        $address = new Address($order->id_address_delivery);
        $isoCountry = Country::getIsoById($address->id_country);

        SfLogger::getInstance()->log(SF_LOG_ORDERS, 'MondialRelay - isoCountry: '.$isoCountry);

        // Get relay data
        $relayData = $this->getPointRelaisData($idRelay, $isoCountry);
        if ($relayData) {
            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'MondialRelay - carrier->id: '.$carrier->id);
            // Get corresonding method
            $method = Db::getInstance()->getValue("SELECT `id_mr_method`
                                                    FROM `" . _DB_PREFIX_ . "mr_method`
                                                    WHERE `id_carrier`=" . $carrier->id . "
                                                    ORDER BY `id_mr_method` DESC");
            if (!empty($method)) {
                // Depending of the marketplace, the length of the relay ID is not the same. (5 digits, 6 digits).
                // We force a 6 digits string required by Mondial Relay
                $lengthRelayId = strlen($idRelay);
                while ($lengthRelayId !== 6) {
                    $idRelay = "0".$idRelay;
                    $lengthRelayId = strlen($idRelay);
                }
                $idRelayFormatted = $idRelay;
                
                // Insert data into mondial relay module's table
                $query = "INSERT INTO `" . _DB_PREFIX_ . "mr_selected`
                            (`id_customer`, `id_method`, `id_cart`, `id_order`, `MR_Selected_Num`, `MR_Selected_LgAdr1`, `MR_Selected_LgAdr2`,
                             `MR_Selected_LgAdr3`, `MR_Selected_LgAdr4`, `MR_Selected_CP`, `MR_Selected_Ville`, `MR_Selected_Pays`)
                          VALUES (" . (int)$order->id_customer . ", " . (int)$method . ", " . (int)$order->id_cart . ", " .
                              (int)$idOrder . ", '" . pSQL($idRelayFormatted) . "', '" . pSQL($relayData->LgAdr1) . "', '" . pSQL($relayData->LgAdr2) . "', '".
                              pSQL($relayData->LgAdr3) . "', '" . pSQL($relayData->LgAdr4) . "', '" . pSQL($relayData->CP) . "', '" . pSQL($relayData->Ville) . "', '" .
                              pSQL($isoCountry) . "')";
                if (Db::getInstance()->execute($query)) {
                    SfLogger::getInstance()->log(SF_LOG_ORDERS, 'MondialRelay - Successfully added relay information');
                } else {
                    SfLogger::getInstance()->log(SF_LOG_ORDERS, 'MondialRelay - Could not add relay information');
                }
            } else {
                SfLogger::getInstance()->log(SF_LOG_ORDERS, 'MondialRelay - Could not find mondial relay method for carrier ID '.$carrier->id);
            }
        }
    }

    /**
     * Retrieve relay details from webservice
     */
    protected function getPointRelaisData($id_relay, $isoCountry)
    {
        $urlWebService = 'http://www.mondialrelay.fr/webservice/Web_Services.asmx?WSDL';
        $mondialRelayConfig = Configuration::get('MR_ACCOUNT_DETAIL');

        // Mondial relay module not configured
        if (! $mondialRelayConfig) {
            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'MondialRelay - Account is not configured');
            return false;
        }
        $mondialRelayConfig = unserialize($mondialRelayConfig);
        $client = new SoapClient($urlWebService);
        if (!is_object($client)) {
            // Error connecting to webservice
            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'MondialRelay - Could not create SOAP client for URL ' . $urlWebService);
            return false;
        }
        $client->soap_defencoding = 'UTF-8';
        $client->decode_utf8 = false;

        $enseigne = $mondialRelayConfig['MR_ENSEIGNE_WEBSERVICE'];
        $apiKey = $mondialRelayConfig['MR_KEY_WEBSERVICE'];
        $params = array (
            'Enseigne' => $enseigne,
            'Num' => $id_relay,
            'Pays' => $isoCountry,
            'Security' => Tools::strtoupper(md5($enseigne.$id_relay.$isoCountry.$apiKey))
        );

        $result = $client->WSI2_AdressePointRelais($params);

        if (!isset($result->WSI2_AdressePointRelaisResult->STAT) || $result->WSI2_AdressePointRelaisResult->STAT != 0 ) {
            // Web service did not return expected data
            SfLogger::getInstance()->log(SF_LOG_ORDERS, 'MondialRelay - Error '.$result->WSI2_AdressePointRelaisResult->STAT.' getting relay data, id relay = ' . $id_relay);
            return false;
        } else {
            return $result->WSI2_AdressePointRelaisResult;
        }
    }

    /**
     * Retrieve the list of carriers from the webservice
     * @param  boolean $to_lower return the list in lower string
     * @return boolean|array false if no carriers retrieved, otherwise a formated array of carriers
     */
    protected function getCarriersFromWebService($to_lower = true)
    {
        $sf_carriers_xml = $this->_callWebService('GetCarriers');
        if (!isset($sf_carriers_xml->Response->Carriers->Carrier[0])) {
            return false;
        }

        $sf_carriers = array();
        foreach ($sf_carriers_xml->Response->Carriers->Carrier as $carrier) {
            if ($to_lower) {
                $sf_carriers[] = Tools::strtolower((string)$carrier);    // uniformise using lowercase only
            } else {
                $sf_carriers[] = (string)$carrier;  // legacy used by migrateToNewCarrierMatching
            }
        }

        // Cache the updated carriers' ist in order to avoid webservice calls when used for consistencies check
        Configuration::updateValue('SHOPPING_FLUX_CARRIERS_LIST', serialize($sf_carriers));

        return $sf_carriers;
    }

    /**
     * 4.6.2 updgrade to migrate legacy matching carriers to new matching (all carrier names in lowercase)
     */
    public function migrateToNewCarrierMatching()
    {
        $matching_carriers     = unserialize(Configuration::get('SHOPPING_FLUX_SHIPPING_MATCHING'));
        $new_matching_carriers = array();
        $sf_carriers           = $this->getCarriersFromWebService(false);
        if (!empty($sf_carriers)) {
            foreach ($sf_carriers as $sf_carrier) {
                $existing_hash = base64_encode(Tools::safeOutput($sf_carrier));
                $new_hash      = base64_encode(Tools::safeOutput(Tools::strtolower($sf_carrier)));
                $id_carrier    = (int)Configuration::get('SHOPPING_FLUX_CARRIER');
                if (isset($matching_carriers[$existing_hash])) {
                    $id_carrier = $matching_carriers[$existing_hash];
                }
                $new_matching_carriers[$new_hash] = $id_carrier;
            }
            Configuration::updateValue('SHOPPING_FLUX_SHIPPING_MATCHING', serialize($new_matching_carriers));
        }
    }

    protected function getContentMarketPlaceExpeditedOrderState($configuration)
    {
        $html = '<select name="SHOPPING_FLUX_STATE_MP_EXP">';
        
        foreach (OrderState::getOrderStates($configuration['PS_LANG_DEFAULT']) as $orderState) {
            $selected = (int) $configuration['SHOPPING_FLUX_STATE_MP_EXP'] === (int) $orderState['id_order_state'] ? 'selected = "selected"' : '';
            $html .= '<option value="' . $orderState['id_order_state'] . '" ' . $selected . '>' . Tools::safeOutput($orderState['name']) . '</option>';
        }
        
        $html .= '</select>';
        
        return $html;
    }

    /**
     * Check if the marketplace is managing the stock and expedition
     * @param  string  $marketplace Name of the marketplace
     * @return boolean
     */
    protected static function isMarketplaceExpeditedOrder($marketplace)
    {
        $marketplace = Tools::strtolower($marketplace);
        
        // List of marketplaces managing expedition (lower-case)
        $listExpedited = array(
            'amazon fba',
            'epmm',
            'clogistique'
        );
        
        return in_array($marketplace, $listExpedited);
    }

    /**
     * Change the order state for an order for which expedition is managed by the marketplace
     * @param  int $orderId   ID of the order
     * @param  bool $doEchoLog
     */
    protected static function changeMarketplaceExpeditedOrderStatut($orderId, $doEchoLog)
    {
        $orderState = Configuration::get('SHOPPING_FLUX_STATE_MP_EXP');
        SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Marketplace expedited order - Changing order state to ' . $orderState, $doEchoLog);
        
        $order = new Order($orderId);
        $new_history = new OrderHistory();
        $new_history->id_order = (int) $orderId;
        $new_history->changeIdOrderState((int) $orderState, $order, true);
        $new_history->id_order_state = (int) $orderState;
        $new_history->add(true, false, false);
        
        SfLogger::getInstance()->log(SF_LOG_ORDERS, 'Marketplace expedited order - Change completed', $doEchoLog);
    }
    
    /**
     * Will check if there are inconsistencies in the matches between ShoppingFlux's carriers and PrestaShop's carriers.
     * An inconsistency may happen when a carrier, that has been matched with a market place's carrier, has been delete 
     * or disabled.
     * @return boolean
     */
    protected function checkCarriersInconsistencies()
    {
        // The list of carriers has been cached to avoid API call in the module list
        $cacheCarrierList = Configuration::get('SHOPPING_FLUX_CARRIERS_LIST');
        if (empty($cacheCarrierList)) {
            // The list of carriers is not cached yet, we do not process (we consider that the matching is correct)
            return true;
        }
        
        $sfCarriers = unserialize($cacheCarrierList);
        $actualConfiguration = unserialize(Configuration::get('SHOPPING_FLUX_SHIPPING_MATCHING'));

        // We go through each carrier comming from ShoppingFlux to find the PrestaShop match
        foreach ($sfCarriers as $sfCarrier) {
            $carrierReference = isset($actualConfiguration[base64_encode(Tools::safeOutput($sfCarrier))]) ?
                $actualConfiguration[base64_encode(Tools::safeOutput($sfCarrier))] :
                Configuration::get('SHOPPING_FLUX_CARRIER');
            // Check if the carrier is coherent (existing and active)
            if (!$this->isCarrierConsistent($carrierReference)) {
                // Inconsistency detected
                return false;
            }
        }
        
        // No inconsistencies
        return true;
    }

    /**
     * Will check if the default ShoppingFlux's carriers has been delete or disabled.
     * @return boolean
     */
    protected function checkDefaultCarrierConsistency()
    {
        // The list of carriers has been cached to avoid API call in the module list
        $cacheCarrierList = Configuration::get('SHOPPING_FLUX_CARRIERS_LIST');
        if (empty($cacheCarrierList)) {
            // The list of carriers is not cached yet, we do not process (we consider that the matching is correct)
            return true;
        }

        // Check if the default carrier is coherent (existing and active)
        if (!$this->isCarrierConsistent(Configuration::get('SHOPPING_FLUX_CARRIER'))) {
            // Inconsistency detected
            return false;
        }

        // No inconsistencies
        return true;
    }

    /**
     * Check if a carrier is existing and active based on its reference
     * @param  int  $carrierReference The reference of the carrier
     * @return boolean
     */
    public function isCarrierConsistent($carrierReference)
    {
        $carrierPs = Carrier::getCarrierByReference((int)$carrierReference);
        if (!Validate::isLoadedObject($carrierPs) || $carrierPs->active == 0 || $carrierPs->deleted == 1) {
            // Inconsistency detected
            return false;
        }

        // No inconsistencies
        return true;
    }
}
