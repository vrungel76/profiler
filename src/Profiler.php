<?php

/**
 *
 */
class Profiler
{
    /**
     * @var Profiler
     */
    private static $instance;

    /**
     * @return bool
     */
    public static function start()
    {
        $instance = self::getInstance();
        if (!$instance->enabled) {
            return false;
        }

        return $instance->started ? true : $instance->startProfiling();
    }

    /**
     * @return bool
     */
    public static function stop()
    {
        $instance = self::getInstance();
        if (!$instance->enabled) {
            return false;
        }

        return $instance->started ? $instance->stopProfiling() : false;
    }

    /**
     * @return Profiler
     */
    private static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @var bool
     */
    private $enabled;

    /**
     * @var bool
     */
    private $started;

    /**
     *
     */
    private function __construct()
    {
        $this->started = false;
        $this->enabled = defined('XHPROF_COLLECTOR_DIR')
            && (extension_loaded('xhprof') || extension_loaded('tideways_xhprof'));
        if (!$this->enabled) {
            return;
        }

        require_once XHPROF_COLLECTOR_DIR . '/src/Xhgui/Config.php';
        $configDir = defined('XHGUI_CONFIG_DIR') ? XHGUI_CONFIG_DIR : $dir . '/config/';
        if (file_exists($configDir . 'config.php')) {
            Xhgui_Config::load($configDir . 'config.php');
        } else {
            Xhgui_Config::load($configDir . 'config.default.php');
        }
        unset($dir, $configDir);

        if (Xhgui_Config::read('save.handler') === 'mongodb') {
            $this->enabled = extension_loaded('mongo') || extension_loaded('mongodb');
            if (!$this->enabled) {
                return;
            }
        }

        $this->enabled = Xhgui_Config::shouldRun();
        if ($this->enabled && !isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        }
    }

    /**
     *
     */
    public function __destruct()
    {
        if ($this->enabled && $this->started) {
            ignore_user_abort(true);
            if (function_exists('session_write_close')) {
                session_write_close();
            }
            flush();

            if (Xhgui_Config::read('fastcgi_finish_request') && function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            $this->stopProfiling();
        }
    }

    /**
     * @return bool
     */
    private function startProfiling()
    {
        $options = Xhgui_Config::read('profiler.options');
        if (extension_loaded('tideways_xhprof')) {
            tideways_xhprof_enable(TIDEWAYS_XHPROF_FLAGS_CPU | TIDEWAYS_XHPROF_FLAGS_MEMORY);
        } else {
            if (PHP_MAJOR_VERSION == 5 && PHP_MINOR_VERSION > 4) {
                xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY | XHPROF_FLAGS_NO_BUILTINS, $options);
            } else {
                xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY, $options);
            }
        }
        $this->started = true;

        return $this->started;
    }

    /**
     * @return bool
     */
    private function stopProfiling()
    {
        if (extension_loaded('tideways_xhprof')) {
            $data['profile'] = tideways_xhprof_disable();
        } else {
            $data['profile'] = xhprof_disable();
        }
        $this->started = false;

        if (!defined('XHGUI_ROOT_DIR')) {
            require XHPROF_COLLECTOR_DIR . '/src/bootstrap.php';
        }

        $uri = array_key_exists('REQUEST_URI', $_SERVER) ? $_SERVER['REQUEST_URI'] : null;
        if (empty($uri) && isset($_SERVER['argv'])) {
            $cmd = basename($_SERVER['argv'][0]);
            $uri = $cmd . ' ' . implode(' ', array_slice($_SERVER['argv'], 1));
        }

        $replace_url = Xhgui_Config::read('profiler.replace_url');
        if (is_callable($replace_url)) {
            $uri = $replace_url($uri);
        }

        $time = array_key_exists('REQUEST_TIME', $_SERVER) ? $_SERVER['REQUEST_TIME'] : time();

        // In some cases there is comma instead of dot
        $delimiter = (strpos($_SERVER['REQUEST_TIME_FLOAT'], ',') !== false) ? ',' : '.';
        $requestTimeFloat = explode($delimiter, $_SERVER['REQUEST_TIME_FLOAT']);
        if (!isset($requestTimeFloat[1])) {
            $requestTimeFloat[1] = 0;
        }

        $requestTs = ['sec' => $time, 'usec' => 0];
        $requestTsMicro = ['sec' => $requestTimeFloat[0], 'usec' => $requestTimeFloat[1]];

        $data['meta'] = [
            'url' => $uri,
            'SERVER' => $_SERVER,
            'get' => $_GET,
            'env' => $_ENV,
            'simple_url' => Xhgui_Util::simpleUrl($uri),
            'request_ts' => $requestTs,
            'request_ts_micro' => $requestTsMicro,
            'request_date' => date('Y-m-d', $time),
        ];

        try {
            $config = Xhgui_Config::all();
            $config += array('db.options' => array());
            $saver = Xhgui_Saver::factory($config);

            $saver->save($data);
        } catch (Exception $e) {
            error_log('xhgui - ' . $e->getMessage());
        }

        return !$this->started;
    }
}
