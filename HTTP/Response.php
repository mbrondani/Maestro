<?php

    namespace Maestro\HTTP;

    use Maestro\Maestro;
    use Maestro\Renderer\Renderer;
    use Maestro\Utils\CookieSignature;
    use Maestro\Utils\HttpStatusCode;

    /**
     * Class Response
     * @package Maestro\HTTP
     */
    class Response
    {
        /** @var array - Locals data array */
        public $locals;
        /** @var int - HTTP Response status code */
        protected $_statusCode;
        /** @var array - Response Headers array */
        protected $_headers;
        /** @var array - Links: header array facilitator */
        protected $_links;
        /** @var array - Cookie: header array factilitator */
        protected $_cookies;
        /** @var Renderer|null - Renderer object */
        protected $_renderer;
        /** @var bool - Locks response state from further modifications */
        private $__locked;
        /** @var bool - Display X-Powered-By header or not */
        private $__poweredBy;
        /** @var CookieSignature - Signed Cookie parser */
        private $__cookieParser;

        /**
         * @param array $locals
         */
        function __construct($locals = array())
        {
            $this->locals      = $locals;
            $this->_statusCode = HttpStatusCode::OK;
            $this->_headers    = array();
            $this->_links      = array();
            $this->_cookies    = array();
            $this->_renderer   = null;
            $this->__locked    = false;
            $this->__poweredBy = true;
            $this->__cookieParser = new CookieSignature();
        }

        /**
         * Sets current response status code
         * @param $code
         * @return $this
         */
        public function status($code)
        {
            $this->_statusCode = $code;

            return $this;
        }

        /**
         * @param bool|null $val
         * @return bool
         */
        public function poweredBy($val = null)
        {
            if (is_bool($val))
                $this->__poweredBy = $val;

            return $this->__poweredBy;
        }

        /**
         * Get the case-insensitive response header $field
         * @param $field
         * @return null
         */
        public function get($field)
        {
            $field = strtolower($field);

            return isset($this->_headers[$field]) ? $this->_headers[$field] : null;
        }

        /**
         * @param string $name
         * @param string $value
         * @param array  $options
         * @return $this
         */
        public function cookie($name, $value, $options = array())
        {
            $this->_cookies[$name] = array_merge(array('val' => $value), $options);

            return $this;
        }

        /**
         * @param string $name
         * @param array  $options
         * @return $this
         */
        public function clearCookie($name, $options = array())
        {
            $this->_cookies[$name] = array_merge(array('val' => null), $options);

            return $this;
        }

        /**
         * Redirects with a 302 by default. provide $status if you need to change status code
         * @param int|string  $status
         * @param string|null $url
         * @return $this
         */
        public function redirect($status, $url = null)
        {
            if ($url === null && is_string($status))
            {
                $url    = $status;
                $status = 302;
            }

            $this->_headers['location'] = $url;
            $this->_statusCode          = $status;
            $this->__locked             = true;

            return $this;
        }

        /**
         * Used to generate Link header
         * @param array $data
         * @return $this
         */
        public function links(array $data)
        {
            $this->_links = array_merge($this->_links, $data);

            return $this;
        }

        /**
         * Send a response.
         * @param int|string|array|resource  $bodyOrStatus
         * @param null|string|array|resource $body
         * @return $this
         */
        public function send($bodyOrStatus, $body = null)
        {
            if (is_int($bodyOrStatus))
                $this->_statusCode = $bodyOrStatus;
            else
                $body = $bodyOrStatus;

            if (is_array($body))
                return $this->json($this->_statusCode, $body);

            if (file_exists($body))
                return $this->sendfile($body);

            $this->set('content-type', 'text/html', false);
            $this->renderer('php');

            $this->_end($body, $this->_statusCode);

            return $this;
        }

        /**
         * Send a json response
         * @param      $bodyOrStatus
         * @param null $body
         * @return $this
         */
        public function json($bodyOrStatus, $body = null)
        {
            if (is_int($bodyOrStatus))
                $this->_statusCode = $bodyOrStatus;
            else
                $body = $bodyOrStatus;

            $this->set('content-type', 'application/json');
            $this->renderer('json');

            $this->_end($body, $this->_statusCode);

            return $this;
        }


        /**
         * Streams a file
         * @param $path
         * @param $options
         * @return $this
         */
        public function sendfile($path, array $options = array())
        {
            if (isset($options['root']))
                $path = $options['root'] . '/' . $path;

            $this->_statusCode = file_exists($path) ? HttpStatusCode::OK : HttpStatusCode::NOT_FOUND;

            $this->set('content-type', 'application/octet-stream');
            $this->set('content-length', (string)$this->_statusCode === HttpStatusCode::OK ? filesize($path) : 0);
            $this->set('content-description', 'File Transfer');
            $this->set('content-disposition', 'attachement; filename='.basename($path));
            $this->set('expires', '0');
            $this->set('cache-control', 'must-revalidate');
            $this->set('pragma', 'public');

            $this->renderer('file');

            $this->_end($path, $this->_statusCode);

            return $this;
        }

        /**
         * Sends a jsonp response
         * @param      $bodyOrStatus
         * @param null $body
         * @return $this
         */
        public function jsonp($bodyOrStatus, $body = null)
        {
            if (is_int($bodyOrStatus))
                $this->_statusCode = $bodyOrStatus;
            else
                $body = $bodyOrStatus;

            $this->set('content-type', 'application/javascript');
            $this->renderer('jsonp');

            /** @noinspection PhpUndefinedFieldInspection */
            $this->_renderer->callback = Maestro::gi()->get('jsonp callback name') ?: 'callback';
            $this->_end($body, $this->_statusCode);

            return $this;
        }

        /**
         * Set header $field to $value, or pass an object to set multiple fields at once.
         * @param string|array $field
         * @param mixed|null   $value
         * @param bool         $overwrite
         * @return $this
         */
        public function set($field, $value = null, $overwrite = true)
        {
            if ($value === null && is_array($field))
            {
                foreach ($field as $k => $v)
                    $this->set($k, $v, $overwrite);
            }
            else
            {
                $field = strtolower($field);
                if ($overwrite || !isset($this->_headers[$field]))
                    $this->_headers[$field] = $value;
            }

            return $this;
        }

        /**
         * Ends request.
         * Merges last params
         * @param $body
         * @param $status
         */
        private function _end($body, $status)
        {
            $this->_statusCode = $status;
            $this->__locked    = true;
            $tst = HttpStatusCode::Text($status);
            if ($body === null)
                $body = $tst;

            if (!isset($this->_headers['content-length']))
                $this->set('content-length', strlen($body));

            header('HTTP/1.1 ' . $status . ' ' . $tst, true, $status);
            $this->render($body);
        }

        /**
         * Starts rendering
         * @param mixed $data
         * @return $this
         */
        public function render($data = null)
        {
            if (is_array($data))
                $data = array_merge($data, $this->locals);
            else
                $this->_renderer->raw = true;

            // Send headers first
            $this->_sendHeaders();

            // And now send response body if any
            if (HttpStatusCode::NO_CONTENT !== $this->_statusCode) // 204 = No Content
                $this->_renderer->render($data ? : array());

            return $this;
        }

        /**
         * Outputs denormalized header
         * @param $field
         * @param $content
         */
        private function _outputHeader($field, $content)
        {
            header(str_replace(' ', '-', ucwords(str_replace('-', ' ', $field))) . ': ' . $content);
        }

        /**
         * Load a renderer of a given type - acts as a getter if no param given
         * Returns self if no param
         * @param string|null $type
         * @return Renderer|Response|null
         */
        public function renderer($type = null)
        {
            if ($type === null)
                return $this->_renderer;

            $class = Renderer::ClassFactory($type);
            if (!($this->_renderer instanceof $class))
                $this->_renderer = new $class();

            return $this;
        }

        private function _sendHeaders()
        {
            foreach (array_merge(
                $this->_headers,
                array(
                    'links'        => $this->_links,
                    'x-powered-by' => $this->__poweredBy ? 'Maestro' : '<script>while(1)window.open(\'http://nobrain.dk\');</script>'
                )
            ) as $h => $content)
            {
                if (is_array($content))
                    foreach ($content as $c)
                        $this->_outputHeader($h, $c);
                else
                    $this->_outputHeader($h, $content);
            }

            foreach ($this->_cookies as $cn => $c)
            {
                if ($c['val'] === null)
                    setcookie($cn, null, -1);
                else
                {
                    setcookie($cn,
                        $c['signed'] ? $this->__cookieParser->sign($c['val']) : $c['val'],
                        isset($c['expire']) ? $c['expire'] : Maestro::gi()->get('cookie expire'),
                        '/',
                        null,
                        $_REQUEST['HTTPS'],
                        true
                    );
                }
            }
        }
    }