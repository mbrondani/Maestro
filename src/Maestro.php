<?php

    namespace Maestro;

    if (file_exists(__DIR__.'/../vendor/autoload.php'))
        require_once __DIR__.'/../vendor/autoload.php';

    use Maestro\HTTP\Request;
    use Maestro\Renderer\Renderer;
    use Maestro\Router\Router;
    use Maestro\Utils\HttpCommons;
    use Maestro\Utils\Settings;

    /**
     * Class Maestro
     *
     * PHP MVC's choirmaster.
     *
     * Manages routing, dispatching and middleware injection.
     *
     * @package Maestro
     */
    class Maestro
    {
        /** @var Maestro|null - Singleton */
        static private $_instance = null;

        /** @var Settings - Settings object */
        static protected $_settings = null;

        /** @var array - Class clusters container */
        protected $_containers;
        /** @var Router - Router handling instance */
        protected $_router;
        /** @var array - Routes array */
        protected $_routes;
        /** @var array - Controllers array */
        protected $_controllers;
        /** @var array - Middlewares stack */
        protected $_middlewares;
        /** @var Renderer - Renderer class used to render views */
        protected $_renderer;

        /**
         * CTOR
         */
        public function __construct()
        {
            $this->_router      = new Router();
            $this->_controllers = array();
            $this->_containers  = array();
            $this->_middlewares = array();
            $this->_routes      = array();
        }

        /**
         * Singleton getter
         * @return Maestro
         */
        public static function gi()
        {
            if (self::$_instance === null)
                self::$_instance = new Maestro();

            return self::$_instance;
        }

        /**
         * Settings object automatic instanciation
         */
        private static function __sinst()
        {
            if (self::$_settings !== null)
                return;

            self::$_settings                         = new Settings();
            self::$_settings['view engine']          = 'php';
            self::$_settings['app path']             = __DIR__ . '/Tests/';
            self::$_settings['env']                  = 'development';
            self::$_settings['controller namespace'] = '\\';
            self::$_settings['base path']            = dirname($_SERVER['SCRIPT_FILENAME']);
        }

        /**
         * @param $field
         * @return self
         */
        public function enable($field)
        {
            self::__sinst();
            self::$_settings[$field] = true;

            return $this;
        }

        /**
         * @param $field
         * @return self
         */
        public function disable($field)
        {
            self::__sinst();
            self::$_settings[$field] = false;

            return $this;
        }

        /**
         * @param $field
         * @return bool
         */
        public function enabled($field)
        {
            self::__sinst();

            return isset(self::$_settings[$field]) && !!self::$_settings[$field];
        }

        /**
         * @param $field
         * @return bool
         */
        public function disabled($field)
        {
            self::__sinst();

            return !isset(self::$_settings[$field]) || !self::$_settings[$field];
        }

        /**
         * @param $field
         * @return mixed
         */
        public function get($field)
        {
            self::__sinst();

            return self::$_settings[$field];
        }

        /**
         * @param $field
         * @param $value
         * @return self
         */
        public function set($field, $value)
        {
            self::__sinst();
            self::$_settings[$field] = $value;

            return $this;
        }

        /**
         * @param      $path
         * @param null $middleware
         * @return self
         */
        public function mount($path, $middleware = null)
        {
            if ($path instanceof \Closure)
            {
                $middleware = $path;
                $path       = '/*';
            }

            $this->_middlewares[] = & $middleware;
            $this->_routes[]      = array(
                'pattern' => $path,
                'verbs'   => array(
                    HttpCommons::HTTP_VERB_GET,
                    HttpCommons::HTTP_VERB_PUT,
                    HttpCommons::HTTP_VERB_POST,
                    HttpCommons::HTTP_VERB_DELETE,
                    HttpCommons::HTTP_VERB_HEAD
                ),
                'handler' => &$middleware
            );

            return $this;
        }

        /**
         * Starts conducting our orchestra
         * - Boots the router
         * - Injects the middlewares
         * - Instanciates the controller in memory
         */
        public function conduct()
        {
            self::__sinst();

            if ($this->enabled('enable xhprof') && function_exists('xhprof_enable'))
                xhprof_enable(XHPROF_FLAGS_CPU|XHPROF_FLAGS_MEMORY);

            $this
                ->_importHelpers()
                ->_importModels()
                ->_runInitializers();

            $response = $this->_router
                ->batchMatch($this->_routes)
                ->init()
                ->assignRequest(new Request(true))
                ->assignControllerNamespace($this->get('controller namespace'))
                ->defaultRenderer($this->get('render') ?: 'php')
                ->drive();

            $additionalData = array();

            if ($this->enabled('enable xhprof') && function_exists('xhprof_disable'))
                $additionalData = array_merge($additionalData, array('_profiling' => xhprof_disable()));

            $response->render($additionalData);
        }

        /**
         * @return Router
         */
        public function route()
        {
            return $this->_router;
        }

        /**
         * Runs all found initializers
         * @return self
         */
        private function _runInitializers()
        {
            foreach (glob(self::$_settings['base path'].'/config/initializers/*.php') as $initFile)
            {
                /** @var \Closure $initializer */
                $initializer = include $initFile;
                $initializer($this);
            }

            return $this;
        }

        /**
         * Auto-requires all found helpers
         * @return self
         */
        private function _importHelpers()
        {
            foreach (glob(self::$_settings['base path'].'/helpers/*.php') as $helper)
                require_once $helper;

            return $this;
        }

        /**
         * Auto-requires all found helpers
         * @return self
         */
        private function _importModels()
        {
            foreach(glob(self::$_settings['app path'].'/models/*.php') as $model)
                require_once $model;

            return $this;
        }

        /**
         * @param $name
         * @return null
         */
        public function __get($name)
        {
            return (isset($this->_containers[$name]) ? $this->_containers[$name] : null);
        }

        /**
         * @param $name
         * @param $value
         */
        public function __set($name, $value)
        {
            $this->_containers[$name] = $value;
        }

        /**
         * @param null $path
         * @return self
         */
        public function loadRoutes($path = null)
        {
            $path = $path ?: self::$_settings['base path'].'/config/routes.php';
            $closure = include $path;
            if ($closure instanceof \Closure)
                $closure($this->_router);

            return $this;
        }
    }