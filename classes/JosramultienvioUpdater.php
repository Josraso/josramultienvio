<?php
/**
 * Actualizaciones del módulo desde el servidor de actualizaciones de josra.
 *
 * - Una vez al día, cuando un empleado entra en el back-office, avisa al
 *   servidor de que el módulo está instalado (módulo, versión, dominio de la
 *   tienda y versiones de PrestaShop y PHP) y recibe la última versión.
 * - En la configuración del módulo muestra si hay versión nueva y permite
 *   actualizar desde la propia tienda, con copia de seguridad previa y
 *   ejecución de los scripts upgrade/.
 * - En PrestaShop 8.1 o superior, la versión nueva aparece además en la pestaña
 *   nativa "Actualizaciones" del gestor de módulos (hook actionListModules).
 *
 * Para usarlo en otro módulo:
 * 1. Copia este archivo a classes/ y cambia el nombre de la clase
 *    (NombremoduloUpdater, para que no choque con la de otros módulos).
 * 2. En el módulo: require_once de este archivo, registrar getHooks() al instalar
 *    y añadir estos tres métodos:
 *      hookActionAdminControllerSetMedia() -> $updater->maybePing()
 *      hookActionListModules()             -> return $updater->getNativeModuleList()
 *      getContent(): $updater->processRequest() al principio y $updater->renderPanel()
 * 3. Un script upgrade/upgrade-X.php que llame a $updater->registerHooks().
 *
 * Compatible con PrestaShop 1.6 a 9 y PHP 5.6 a 8.x.
 *
 * @author josra
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosramultienvioUpdater
{
    /** URL del servidor de actualizaciones, con barra final (Ajustes del panel) */
    const SERVER_URL = 'https://actualizaciones.donxarope.com/';

    /** Clave de API del servidor de actualizaciones (Ajustes del panel) */
    const API_KEY = 'a04b4dcf6c807ca24c3a5083b7f855fed91476c0';

    /** Segundos entre avisos automáticos */
    const CHECK_EVERY = 86400;

    /** @var Module */
    protected $module;

    /** @var string prefijo de las claves de configuración */
    protected $key;

    public function __construct($module)
    {
        $this->module = $module;
        $this->key = Tools::strtoupper($module->name) . '_UPD';
    }

    public function isConfigured()
    {
        return trim(self::SERVER_URL) !== '' && trim(self::API_KEY) !== '';
    }

    /**
     * Hooks que necesita el actualizador en esta versión de PrestaShop.
     * actionListModules solo desde 8.1: en 8.0 el gestor no usa download_url
     * y mostraría un botón "Actualizar" que no descarga nada.
     */
    public function getHooks()
    {
        $hooks = array('actionAdminControllerSetMedia');
        if (version_compare(_PS_VERSION_, '8.1.0', '>=')) {
            $hooks[] = 'actionListModules';
        }

        return $hooks;
    }

    /**
     * Registra los hooks que falten (instalación, upgrade y autorreparación).
     */
    public function registerHooks()
    {
        $ok = true;
        foreach ($this->getHooks() as $hook) {
            if (!$this->module->isRegisteredInHook($hook)) {
                $ok = $this->module->registerHook($hook) && $ok;
            }
        }

        return $ok;
    }

    /**
     * Respuesta para actionListModules (8.1+): la versión nueva y su descarga,
     * para que salga en la pestaña "Actualizaciones" del gestor de módulos.
     * Usa lo guardado en el último aviso: no llama al servidor.
     */
    public function getNativeModuleList()
    {
        if (!$this->isConfigured()) {
            return array();
        }

        $info = $this->getInfo();
        if (empty($info['update_available']) || empty($info['latest']) || empty($info['download_url'])) {
            return array();
        }

        return array(array(
            'name' => $this->module->name,
            'version_available' => $info['latest'],
            'download_url' => $info['download_url'],
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Aviso al servidor                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Aviso diario desde el back-office. Nunca lanza errores.
     */
    public function maybePing()
    {
        if (!$this->isConfigured()) {
            return;
        }

        try {
            $last = (int) Configuration::getGlobalValue($this->key . '_TS');
            if ($last > time() - self::CHECK_EVERY) {
                return;
            }
            // Se marca antes de llamar: si el servidor no responde, no se reintenta en cada página
            Configuration::updateGlobalValue($this->key . '_TS', time());
            $this->check(4);
        } catch (Exception $e) {
            // El back-office nunca debe fallar por esto
        } catch (Throwable $e) {
        }
    }

    /**
     * Pregunta al servidor por la última versión y guarda la respuesta.
     * Con $force el servidor lee GitHub en ese momento (comprobación a mano).
     *
     * @return array|string datos recibidos, o el motivo del fallo
     */
    public function check($timeout = 10, $force = false)
    {
        if (!$this->isConfigured()) {
            return 'El servidor de actualizaciones no está configurado en este módulo.';
        }

        $shopUrl = Tools::getShopDomainSsl(true) . __PS_BASE_URI__;
        $site = Tools::strtolower(Tools::getShopDomainSsl(false) . __PS_BASE_URI__);

        $response = $this->httpPost(rtrim(self::SERVER_URL, '/') . '/api.php?action=check', array(
            'key' => self::API_KEY,
            'module' => $this->module->name,
            'version' => $this->module->version,
            'site' => $site,
            'shop_url' => $shopUrl,
            'ps_version' => _PS_VERSION_,
            'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
            'force' => $force ? '1' : '0',
        ), $timeout);

        if (!is_array($response)) {
            return $response;
        }
        if (empty($response['ok'])) {
            return 'El servidor de actualizaciones respondió con un error: ' . (isset($response['error']) ? $response['error'] : 'sin detalle') . '.';
        }

        $info = array(
            'latest' => isset($response['latest']) ? (string) $response['latest'] : '',
            'update_available' => !empty($response['update_available']),
            'changelog' => isset($response['changelog']) ? (string) $response['changelog'] : '',
            'download_url' => isset($response['download_url']) ? (string) $response['download_url'] : '',
            'checked_at' => time(),
            'for_version' => $this->module->version,
        );
        // En base64 para que Configuration no toque el contenido
        Configuration::updateGlobalValue($this->key . '_INFO', base64_encode(json_encode($info)));

        return $info;
    }

    /**
     * Última respuesta guardada (o array vacío).
     */
    public function getInfo()
    {
        $raw = (string) Configuration::getGlobalValue($this->key . '_INFO');
        $info = $raw !== '' ? json_decode(base64_decode($raw), true) : null;
        if (!is_array($info)) {
            return array();
        }
        // Si ya se actualizó por otra vía, el aviso guardado ya no vale
        if (!isset($info['for_version']) || $info['for_version'] !== $this->module->version) {
            $info['update_available'] = !empty($info['latest']) && version_compare($info['latest'], $this->module->version, '>');
            $info['download_url'] = '';
        }

        return $info;
    }

    /* ------------------------------------------------------------------ */
    /* Configuración del módulo: acciones y panel                          */
    /* ------------------------------------------------------------------ */

    /**
     * Procesa los botones del panel. Devuelve el HTML de mensajes.
     */
    public function processRequest()
    {
        $html = '';

        // Autorreparación: hooks del actualizador (por si el módulo se subió por FTP)
        $this->registerHooks();

        if (Tools::getValue('josraupd_done')) {
            $html .= $this->module->displayConfirmation(sprintf('Módulo actualizado a la versión %s.', $this->module->version));
        }

        // Scripts upgrade/ pendientes tras cambiar los archivos
        $upgrade = $this->runPendingUpgrades();
        if ($upgrade !== true) {
            $html .= $upgrade;
        }

        if (!$this->isConfigured()) {
            return $html;
        }

        if (Tools::isSubmit('josraupd_check')) {
            $result = $this->check(10, true);
            if (is_array($result)) {
                $html .= $result['update_available']
                    ? $this->module->displayWarning(sprintf('Hay una versión nueva: %s.', $result['latest']))
                    : $this->module->displayConfirmation('Tienes la última versión.');
            } else {
                $html .= $this->module->displayError($result);
            }
        }

        if (Tools::isSubmit('josraupd_install')) {
            $result = $this->install();
            if ($result === true) {
                // Recarga para que entre en juego el código nuevo y se ejecuten sus scripts upgrade/
                Tools::redirectAdmin($this->configUrl() . '&josraupd_done=1');

                return $html;
            }
            $html .= $this->module->displayError($result);
        }

        return $html;
    }

    /**
     * Bloque de actualizaciones para la configuración del módulo.
     */
    public function renderPanel()
    {
        if (!$this->isConfigured()) {
            return '';
        }

        $info = $this->getInfo();
        $action = Tools::safeOutput($this->configUrl());
        $checked = !empty($info['checked_at']) ? date('d/m/Y H:i', (int) $info['checked_at']) : '';

        $body = '';
        if (!empty($info['update_available'])) {
            $body .= '<div class="alert alert-warning"><p><strong>'
                . sprintf('Hay una versión nueva: %s (tienes la %s).', Tools::safeOutput($info['latest']), Tools::safeOutput($this->module->version))
                . '</strong></p>';
            if (!empty($info['changelog'])) {
                $body .= '<p>' . nl2br(Tools::safeOutput($info['changelog'])) . '</p>';
            }
            $body .= '<p>Antes de actualizar se guarda una copia del módulo actual en la carpeta de caché de PrestaShop.</p></div>';
        } else {
            $body .= '<p>Versión instalada: <strong>' . Tools::safeOutput($this->module->version) . '</strong>'
                . ($checked !== '' ? '. Última comprobación: ' . $checked . '.' : '. Aún no se ha comprobado si hay versiones nuevas.')
                . '</p>';
        }

        $buttons = '<button type="submit" name="josraupd_check" value="1" class="btn btn-default"><i class="icon-refresh"></i> Comprobar actualizaciones</button>';
        if (!empty($info['update_available'])) {
            $buttons .= ' <button type="submit" name="josraupd_install" value="1" class="btn btn-primary" onclick="return confirm(\'¿Actualizar el módulo ahora?\');"><i class="icon-download"></i> Actualizar ahora</button>';
        }

        return '<form method="post" action="' . $action . '" class="defaultForm form-horizontal">'
            . '<div class="panel"><div class="panel-heading"><i class="icon-cloud-download"></i> Actualizaciones</div>'
            . $body
            . '<div class="panel-footer">' . $buttons . '</div></div></form>';
    }

    protected function configUrl()
    {
        // URL completa: en PS 8/9 una relativa o AdminController::$currentIndex da 404
        // porque la configuración se abre por ruta Symfony
        $adminDir = defined('_PS_ADMIN_DIR_') ? basename(_PS_ADMIN_DIR_) : '';

        return Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . ($adminDir !== '' ? $adminDir . '/' : '')
            . 'index.php?controller=AdminModules&configure=' . $this->module->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules');
    }

    /* ------------------------------------------------------------------ */
    /* Instalación de la versión nueva                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Descarga e instala la última versión.
     *
     * @return true|string true o el motivo del fallo
     */
    public function install()
    {
        if (!class_exists('ZipArchive')) {
            return 'No se puede actualizar: falta la extensión zip de PHP en este servidor.';
        }

        // Enlace de descarga recién firmado
        $info = $this->check(15, true);
        if (!is_array($info)) {
            return $info;
        }
        if (empty($info['update_available']) || empty($info['download_url'])) {
            return 'No hay ninguna versión nueva que instalar.';
        }

        $name = $this->module->name;
        $work = _PS_CACHE_DIR_ . 'josraupd/';
        if (!is_dir($work) && !@mkdir($work, 0755, true)) {
            return 'No se puede crear la carpeta ' . $work . ' (permisos).';
        }

        $zipFile = $work . $name . '-' . $info['latest'] . '.zip';
        $download = $this->httpDownload($info['download_url'], $zipFile);
        if ($download !== true) {
            return $download;
        }

        // Comprobar el contenido antes de tocar nada
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            @unlink($zipFile);

            return 'El zip descargado está dañado.';
        }
        $hasMain = false;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entry = $zip->getNameIndex($i);
            if (strpos($entry, $name . '/') !== 0 || strpos($entry, '..') !== false) {
                $zip->close();
                @unlink($zipFile);

                return 'El zip descargado no es de este módulo (contiene ' . $entry . ').';
            }
            if ($entry === $name . '/' . $name . '.php') {
                $hasMain = true;
            }
        }
        if (!$hasMain) {
            $zip->close();
            @unlink($zipFile);

            return 'El zip descargado no contiene ' . $name . '/' . $name . '.php.';
        }

        $tmpDir = $work . 'tmp-' . $name . '-' . time() . '/';
        if (!$zip->extractTo($tmpDir)) {
            $zip->close();
            @unlink($zipFile);
            $this->removeDir($tmpDir);

            return 'No se pudo descomprimir el zip en ' . $work . ' (permisos o espacio en disco).';
        }
        $zip->close();
        @unlink($zipFile);

        $source = $tmpDir . $name . '/';
        $target = _PS_MODULE_DIR_ . $name . '/';
        $files = $this->listFiles($source);

        // Comprobar permisos de escritura de todo antes de copiar nada
        foreach ($files as $relative) {
            $dest = $target . $relative;
            $check = file_exists($dest) ? $dest : $this->firstExistingParent(dirname($dest));
            if (!is_writable($check)) {
                $this->removeDir($tmpDir);

                return 'No se puede escribir en ' . $check . ': revisa los permisos de la carpeta del módulo. No se ha cambiado nada.';
            }
        }

        $backup = $this->backup($work);
        if ($backup !== true) {
            $this->removeDir($tmpDir);

            return $backup;
        }

        foreach ($files as $relative) {
            $dest = $target . $relative;
            if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) {
                $this->removeDir($tmpDir);

                return 'No se pudo crear la carpeta ' . dirname($dest) . '. La copia de seguridad está en ' . $work . '.';
            }
            if (!@copy($source . $relative, $dest)) {
                $this->removeDir($tmpDir);

                return 'No se pudo copiar ' . $relative . '. La actualización ha quedado a medias: restaura la copia de seguridad que está en ' . $work . '.';
            }
            if (function_exists('opcache_invalidate') && substr($dest, -4) === '.php') {
                @opcache_invalidate($dest, true);
            }
        }

        $this->removeDir($tmpDir);
        if (method_exists('Tools', 'clearSmartyCache')) {
            Tools::clearSmartyCache();
        }

        return true;
    }

    /**
     * Ejecuta los scripts upgrade/ que falten entre la versión registrada y la de los archivos.
     *
     * @return true|string true, o HTML con el resultado
     */
    public function runPendingUpgrades()
    {
        $name = $this->module->name;
        $dbVersion = (string) Db::getInstance()->getValue('SELECT `version` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = \'' . pSQL($name) . '\'');
        $fileVersion = (string) $this->module->version;

        if ($dbVersion === '' || !version_compare($fileVersion, $dbVersion, '>')) {
            return true;
        }

        $scripts = array();
        $dir = _PS_MODULE_DIR_ . $name . '/upgrade/';
        foreach (glob($dir . '*.php') ?: array() as $file) {
            if (preg_match('/^upgrade-([0-9][0-9.]*)\.php$/i', basename($file), $m)) {
                $version = rtrim($m[1], '.');
                if (version_compare($version, $dbVersion, '>') && version_compare($version, $fileVersion, '<=')) {
                    $scripts[$version] = $file;
                }
            }
        }
        uksort($scripts, 'version_compare');

        foreach ($scripts as $version => $file) {
            $function = 'upgrade_module_' . str_replace('.', '_', $version);
            if (!function_exists($function)) {
                include_once $file;
            }
            if (!function_exists($function)) {
                return $this->module->displayError('El script upgrade-' . $version . '.php no define la función ' . $function . '().');
            }
            if (!call_user_func($function, $this->module)) {
                // La versión registrada se queda en el último script que funcionó
                return $this->module->displayError('Ha fallado el script de actualización ' . $version . '. Los archivos ya son de la versión ' . $fileVersion . '; revisa el registro de PrestaShop y vuelve a abrir esta configuración para reintentarlo.');
            }
            Module::upgradeModuleVersion($name, $version);
            $dbVersion = $version;
        }

        Module::upgradeModuleVersion($name, $fileVersion);

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Utilidades                                                          */
    /* ------------------------------------------------------------------ */

    protected function backup($work)
    {
        $name = $this->module->name;
        $source = _PS_MODULE_DIR_ . $name . '/';
        $file = $work . $name . '-' . $this->module->version . '-copia-' . date('Ymd-His') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return 'No se pudo crear la copia de seguridad en ' . $work . ' (permisos). No se ha cambiado nada.';
        }
        foreach ($this->listFiles($source) as $relative) {
            $zip->addFile($source . $relative, $name . '/' . $relative);
        }
        if (!$zip->close()) {
            return 'No se pudo guardar la copia de seguridad en ' . $work . ' (espacio en disco o permisos). No se ha cambiado nada.';
        }

        // Conserva solo las 3 copias más recientes de este módulo
        $copies = glob($work . $name . '-*-copia-*.zip') ?: array();
        rsort($copies);
        foreach (array_slice($copies, 3) as $old) {
            @unlink($old);
        }

        return true;
    }

    protected function listFiles($dir)
    {
        $files = array();
        $dir = rtrim($dir, '/') . '/';
        if (!is_dir($dir)) {
            return $files;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[] = str_replace('\\', '/', substr($item->getPathname(), strlen($dir)));
            }
        }

        return $files;
    }

    protected function firstExistingParent($dir)
    {
        while ($dir !== '' && $dir !== '/' && !file_exists($dir)) {
            $dir = dirname($dir);
        }

        return $dir;
    }

    protected function removeDir($dir)
    {
        if (is_dir($dir)) {
            Tools::deleteDirectory($dir, true);
        }
    }

    /**
     * @return array|string JSON decodificado o motivo del fallo
     */
    protected function httpPost($url, array $data, $timeout)
    {
        $body = http_build_query($data);
        $code = 0;
        $raw = false;
        $error = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'josra-module/' . $this->module->name,
            ));
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($raw === false) {
                $error = curl_error($ch);
            }
            curl_close($ch);
        } else {
            $context = stream_context_create(array('http' => array(
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: josra-module/" . $this->module->name . "\r\n",
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            )));
            $raw = @file_get_contents($url, false, $context);
            if ($raw === false) {
                $error = 'sin respuesta';
            }
            $code = $raw !== false ? 200 : 0;
        }

        if ($raw === false) {
            return 'No se pudo contactar con el servidor de actualizaciones (' . $error . ').';
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return 'El servidor de actualizaciones no respondió correctamente (código ' . $code . '). Revisa la URL configurada en el módulo.';
        }

        return $json;
    }

    /**
     * @return true|string
     */
    protected function httpDownload($url, $file)
    {
        if (function_exists('curl_init')) {
            $fp = @fopen($file, 'wb');
            if (!$fp) {
                return 'No se puede escribir en ' . dirname($file) . ' (permisos).';
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_FILE => $fp,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'josra-module/' . $this->module->name,
            ));
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $error = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if (!$ok || $code !== 200 || strpos($type, 'zip') === false) {
                $detail = $error !== '' ? $error : 'código ' . $code;
                if ($code >= 400 && filesize($file) < 4096) {
                    $json = json_decode((string) Tools::file_get_contents($file), true);
                    if (isset($json['error'])) {
                        $detail = $json['error'];
                    }
                }
                @unlink($file);

                return 'No se pudo descargar la versión nueva: ' . $detail . '.';
            }

            return true;
        }

        $data = @file_get_contents($url, false, stream_context_create(array('http' => array('timeout' => 120))));
        if ($data === false || substr($data, 0, 2) !== 'PK') {
            return 'No se pudo descargar la versión nueva desde el servidor de actualizaciones.';
        }

        return file_put_contents($file, $data) !== false ? true : 'No se puede escribir en ' . dirname($file) . ' (permisos).';
    }
}
