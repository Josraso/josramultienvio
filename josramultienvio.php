<?php
/**
 * Envíos múltiples (pedidos partidos por transportista)
 *
 * Cuando los productos del carrito no pueden ir todos con el mismo transportista
 * (por ejemplo, frío y normal), PrestaShop parte la compra en varios pedidos,
 * uno por transportista. Este módulo:
 *
 * 1. Corrige el transporte guardado en cada pedido. PrestaShop guarda en cada uno
 *    el transporte de todo el carrito (10 + 50 = 60 en los dos), aunque el total
 *    del pedido sí está bien. Se recalcula con los productos y el transportista
 *    de ese pedido justo antes de guardarlo, así que el transportista del pedido,
 *    la factura, los emails y lo que se exporte salen bien.
 * 2. En el checkout nativo avisa de que la compra irá en varios envíos y muestra
 *    cada envío con sus productos, transportista, plazo y precio. Además corrige
 *    el nombre de las opciones combinadas, que PrestaShop muestra solo con el
 *    nombre del último transportista y el precio de todos.
 *
 * Sin overrides. Compatible con PrestaShop 1.7, 8 y 9.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/JosramultienvioUpdater.php';

class Josramultienvio extends Module
{
    const CFG_FIX = 'JOSRAMULTI_FIX';
    const CFG_FRONT = 'JOSRAMULTI_FRONT';
    const CFG_MSG = 'JOSRAMULTI_MSG';

    public function __construct()
    {
        $this->name = 'josramultienvio';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.1';
        $this->author = 'josra';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->l('Envíos múltiples por transportista');
        $this->description = $this->l('Corrige el transporte de los pedidos que PrestaShop parte por transportista (frío, normal...) y muestra en el checkout cada envío con sus productos y su precio.');
    }

    /* ------------------------------------------------------------------ */
    /* Instalación                                                         */
    /* ------------------------------------------------------------------ */

    public function install()
    {
        if (!parent::install()
            || !$this->registerHook('actionObjectOrderAddBefore')
            || !$this->registerHook('displayBeforeCarrier')
            || !$this->registerHook('actionFrontControllerSetMedia')
            || !$this->getUpdater()->registerHooks()) {
            return false;
        }

        Configuration::updateValue(self::CFG_FIX, 1);
        Configuration::updateValue(self::CFG_FRONT, 1);
        $msg = array();
        foreach (Language::getLanguages(false) as $lang) {
            $msg[(int) $lang['id_lang']] = $lang['iso_code'] === 'es'
                ? 'Tu pedido se enviará en {n} envíos, porque algunos productos van con transportistas distintos:'
                : 'Your order will be shipped in {n} parcels, because some products use different carriers:';
        }
        Configuration::updateValue(self::CFG_MSG, $msg);

        return true;
    }

    public function uninstall()
    {
        // Aviso al servidor de actualizaciones (no impide desinstalar si no responde)
        $this->getUpdater()->notifyUninstall();

        foreach (array(self::CFG_FIX, self::CFG_FRONT, self::CFG_MSG, 'JOSRAMULTIENVIO_UPD_TS', 'JOSRAMULTIENVIO_UPD_INFO') as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /* ------------------------------------------------------------------ */
    /* Actualizaciones                                                     */
    /* ------------------------------------------------------------------ */

    public function getUpdater()
    {
        return new JosramultienvioUpdater($this);
    }

    /** Aviso diario al servidor de actualizaciones (solo en el back-office) */
    public function hookActionAdminControllerSetMedia($params)
    {
        $this->getUpdater()->maybePing();
    }

    /** PS 8.1+: la versión nueva aparece en Gestor de módulos > Actualizaciones */
    public function hookActionListModules($params)
    {
        return $this->getUpdater()->getNativeModuleList();
    }

    /* ------------------------------------------------------------------ */
    /* 1. Transporte de cada pedido                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Justo antes de guardar cada pedido. PaymentModule::validateOrder calcula el
     * transporte sin indicar el transportista y guarda el de todo el carrito en
     * cada pedido. Aquí se recalcula con los productos y el transportista del
     * pedido. Solo se cambia si el resultado cuadra con el total del pedido.
     */
    public function hookActionObjectOrderAddBefore($params)
    {
        if (!Configuration::get(self::CFG_FIX) || empty($params['object']) || !($params['object'] instanceof Order)) {
            return;
        }

        try {
            $this->fixOrderShipping($params['object']);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('josramultienvio: ' . $e->getMessage(), 2, null, 'Cart', (int) $params['object']->id_cart, true);
        }
    }

    protected function fixOrderShipping(Order $order)
    {
        $idCarrier = (int) $order->id_carrier;
        if (!$idCarrier || empty($order->product_list) || !is_array($order->product_list)) {
            return;
        }

        $cart = new Cart((int) $order->id_cart);
        if (!Validate::isLoadedObject($cart) || !$this->isSplitCart($cart)) {
            return;
        }

        $precision = $this->precision();
        $incl = Tools::ps_round((float) $cart->getPackageShippingCost($idCarrier, true, null, $order->product_list), $precision);
        $excl = Tools::ps_round((float) $cart->getPackageShippingCost($idCarrier, false, null, $order->product_list), $precision);

        if (abs($incl - (float) $order->total_shipping_tax_incl) < 0.005 && abs($excl - (float) $order->total_shipping_tax_excl) < 0.005) {
            return;
        }

        // Comprobación: con el transporte corregido, productos + transporte + envoltorio - descuentos = total del pedido
        $expected = (float) $order->total_products_wt + $incl + (float) $order->total_wrapping_tax_incl - (float) $order->total_discounts_tax_incl;
        if (abs($expected - (float) $order->total_paid_tax_incl) > 0.02) {
            PrestaShopLogger::addLog(
                sprintf('josramultienvio: no se corrige el transporte del pedido del carrito %d (transportista %d): no cuadra con el total (%.2f calculado, %.2f del pedido).', (int) $cart->id, $idCarrier, $expected, (float) $order->total_paid_tax_incl),
                2, null, 'Cart', (int) $cart->id, true
            );

            return;
        }

        $old = (float) $order->total_shipping_tax_incl;
        $order->total_shipping_tax_incl = $incl;
        $order->total_shipping_tax_excl = $excl;
        $order->total_shipping = $incl;

        PrestaShopLogger::addLog(
            sprintf('josramultienvio: carrito %d partido, transportista %d: transporte %.2f -> %.2f', (int) $cart->id, $idCarrier, $old, $incl),
            1, null, 'Cart', (int) $cart->id, true
        );
    }

    /** ¿El carrito se reparte en más de un paquete (= más de un pedido)? */
    protected function isSplitCart(Cart $cart)
    {
        $packages = $cart->getPackageList();
        $count = 0;
        foreach ((array) $packages as $byAddress) {
            $count += count((array) $byAddress);
        }

        return $count > 1;
    }

    protected function precision()
    {
        $context = Context::getContext();
        if (method_exists($context, 'getComputingPrecision')) {
            return (int) $context->getComputingPrecision();
        }

        return defined('_PS_PRICE_COMPUTE_PRECISION_') ? (int) _PS_PRICE_COMPUTE_PRECISION_ : 2;
    }

    /* ------------------------------------------------------------------ */
    /* 2. Checkout nativo                                                  */
    /* ------------------------------------------------------------------ */

    public function hookActionFrontControllerSetMedia($params)
    {
        if (!Configuration::get(self::CFG_FRONT)) {
            return;
        }
        $controller = $this->context->controller;
        $page = isset($controller->php_self) ? (string) $controller->php_self : '';
        if (!in_array($page, array('order', 'checkout'), true) || !method_exists($controller, 'registerJavascript')) {
            return;
        }

        $controller->registerJavascript('modules-josramultienvio', 'modules/' . $this->name . '/views/js/front.js', array('position' => 'bottom', 'priority' => 200));
        $controller->registerStylesheet('modules-josramultienvio', 'modules/' . $this->name . '/views/css/front.css', array('media' => 'all', 'priority' => 200));
    }

    public function hookDisplayBeforeCarrier($params)
    {
        if (!Configuration::get(self::CFG_FRONT)) {
            return '';
        }

        $cart = isset($params['cart']) && $params['cart'] instanceof Cart ? $params['cart'] : $this->context->cart;
        if (!Validate::isLoadedObject($cart)) {
            return '';
        }

        $options = $this->buildOptions($cart);
        if (!$options) {
            return '';
        }

        $idAddress = (int) $cart->id_address_delivery;
        $selected = $cart->getDeliveryOption(null, false, false);
        $selected = isset($selected[$idAddress]) ? (string) $selected[$idAddress] : '';

        $labels = array();
        foreach ($options as $key => $option) {
            $labels[$key] = array(
                'name' => $option['names'],
                'delay' => $option['delays'],
                'breakdown' => $option['breakdown'],
            );
        }

        $this->context->smarty->assign(array(
            'jme_options' => $options,
            'jme_selected' => $selected,
            'jme_json' => json_encode($labels),
        ));

        return $this->display(__FILE__, 'views/templates/hook/before_carrier.tpl');
    }

    /**
     * Opciones de envío con varios transportistas, con el detalle de cada envío.
     *
     * @return array [clave de la opción => datos]
     */
    protected function buildOptions(Cart $cart)
    {
        $idAddress = (int) $cart->id_address_delivery;
        $list = $cart->getDeliveryOptionList();
        if (empty($list[$idAddress])) {
            return array();
        }

        $packages = $cart->getPackageList();
        $packages = isset($packages[$idAddress]) ? $packages[$idAddress] : array();
        $withTax = !Product::getTaxCalculationMethod((int) $cart->id_customer) && Configuration::get('PS_TAX');
        $idLang = (int) $this->context->language->id;
        $message = (string) Configuration::get(self::CFG_MSG, $idLang);

        $out = array();
        foreach ($list[$idAddress] as $key => $option) {
            if (empty($option['carrier_list']) || count($option['carrier_list']) < 2) {
                continue;
            }

            $carriers = array();
            $names = array();
            $delays = array();
            $prices = array();
            foreach ($option['carrier_list'] as $idCarrier => $data) {
                $carrier = isset($data['instance']) && $data['instance'] instanceof Carrier ? $data['instance'] : new Carrier((int) $idCarrier, $idLang);
                $name = (string) $carrier->name === '0' ? (string) Configuration::get('PS_SHOP_NAME') : (string) $carrier->name;
                $delay = is_array($carrier->delay) ? (isset($carrier->delay[$idLang]) ? (string) $carrier->delay[$idLang] : '') : (string) $carrier->delay;
                $amount = (float) ($withTax ? $data['price_with_tax'] : $data['price_without_tax']);
                $price = $amount > 0 ? $this->formatPrice($amount) : $this->l('Gratis');

                $products = array();
                foreach (isset($data['package_list']) ? (array) $data['package_list'] : array() as $idPackage) {
                    if (empty($packages[$idPackage]['product_list'])) {
                        continue;
                    }
                    foreach ($packages[$idPackage]['product_list'] as $p) {
                        $label = $p['name'] . (!empty($p['attributes_small']) ? ' (' . $p['attributes_small'] . ')' : '');
                        $products[] = ((int) $p['cart_quantity'] > 1 ? (int) $p['cart_quantity'] . ' × ' : '') . $label;
                    }
                }

                $carriers[] = array(
                    'name' => $name,
                    'delay' => $delay,
                    'price' => $price,
                    'products' => implode(', ', $products),
                );
                $names[] = $name;
                if ($delay !== '') {
                    $delays[] = $delay;
                }
                $prices[] = $price;
            }

            $out[$key] = array(
                'message' => str_replace('{n}', (string) count($carriers), $message),
                'carriers' => $carriers,
                'names' => implode(' + ', $names),
                'delays' => implode(' / ', $delays),
                'breakdown' => implode(' + ', $prices),
            );
        }

        return $out;
    }

    protected function formatPrice($amount)
    {
        $currency = $this->context->currency;
        if (method_exists('Tools', 'getContextLocale')) {
            try {
                return Tools::getContextLocale($this->context)->formatPrice((float) $amount, $currency->iso_code);
            } catch (Exception $e) {
            }
        }

        return call_user_func(array('Tools', 'displayPrice'), (float) $amount, $currency);
    }

    /* ------------------------------------------------------------------ */
    /* Configuración                                                       */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        $updater = $this->getUpdater();
        $output = $updater->processRequest();

        if (Tools::isSubmit('submitJosramultienvio')) {
            Configuration::updateValue(self::CFG_FIX, (int) Tools::getValue(self::CFG_FIX));
            Configuration::updateValue(self::CFG_FRONT, (int) Tools::getValue(self::CFG_FRONT));
            $msg = array();
            foreach (Language::getLanguages(false) as $lang) {
                $msg[(int) $lang['id_lang']] = Tools::substr(strip_tags((string) Tools::getValue(self::CFG_MSG . '_' . (int) $lang['id_lang'])), 0, 255);
            }
            Configuration::updateValue(self::CFG_MSG, $msg);
            $output .= $this->displayConfirmation($this->l('Ajustes guardados.'));
        }

        $formularios = $this->renderForm();
        $info = $updater->getInfo();
        $panel = $updater->renderPanel();

        return !empty($info['update_available'])
            ? $output . $panel . $formularios
            : $output . $formularios . $panel;
    }

    protected function renderForm()
    {
        $yesNo = array(
            array('id' => 'on', 'value' => 1, 'label' => $this->l('Sí')),
            array('id' => 'off', 'value' => 0, 'label' => $this->l('No')),
        );

        $form = array('form' => array(
            'legend' => array('title' => $this->l('Envíos múltiples'), 'icon' => 'icon-truck'),
            'description' => $this->l('Cuando un carrito lleva productos que no pueden ir con el mismo transportista (por ejemplo, frío y normal), PrestaShop crea un pedido por transportista.'),
            'input' => array(
                array(
                    'type' => 'switch',
                    'label' => $this->l('Corregir el transporte de cada pedido'),
                    'name' => self::CFG_FIX,
                    'is_bool' => true,
                    'values' => $yesNo,
                    'desc' => $this->l('PrestaShop guarda en cada pedido el transporte de todo el carrito (por ejemplo 60 € en los dos pedidos, en vez de 10 € y 50 €), aunque el total sí está bien. Con esto activado, cada pedido guarda solo su transporte. Solo actúa en carritos partidos y solo si el resultado cuadra con el total del pedido.'),
                ),
                array(
                    'type' => 'switch',
                    'label' => $this->l('Mostrar los envíos en el checkout'),
                    'name' => self::CFG_FRONT,
                    'is_bool' => true,
                    'values' => $yesNo,
                    'desc' => $this->l('En el paso de envío del checkout nativo: aviso con cada envío (productos, transportista, plazo y precio) y nombre completo de las opciones combinadas. Si el carrito no se parte, no se muestra nada.'),
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Texto del aviso'),
                    'name' => self::CFG_MSG,
                    'lang' => true,
                    'desc' => $this->l('{n} = número de envíos.'),
                ),
            ),
            'submit' => array('title' => $this->l('Guardar'), 'name' => 'submitJosramultienvio'),
        ));

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->table = $this->name;
        $helper->identifier = 'id_configuration';
        $helper->show_toolbar = false;
        $helper->submit_action = 'submitJosramultienvio';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        // URL completa: en PS 8/9 AdminController::$currentIndex da 404 (la configuración va por ruta Symfony)
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $values = array(
            self::CFG_FIX => (int) Configuration::get(self::CFG_FIX),
            self::CFG_FRONT => (int) Configuration::get(self::CFG_FRONT),
        );
        foreach (Language::getLanguages(false) as $lang) {
            $values[self::CFG_MSG][(int) $lang['id_lang']] = Configuration::get(self::CFG_MSG, (int) $lang['id_lang']);
        }
        $helper->tpl_vars = array(
            'fields_value' => $values,
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => (int) $this->context->language->id,
        );

        return $helper->generateForm(array($form));
    }
}
