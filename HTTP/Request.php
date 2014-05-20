<?php

    namespace Maestro\HTTP;

    use Maestro\Utils\CookieSignature;
    use Maestro\Utils\HttpCommons;

    /**
     * Class Request
     * @package Maestro\HTTP
     * @author  Mathieu Amiot <m.amiot@otak-arts.com>
     * @version 1.0
     * @changelog
     *      1.0: Stable version
     */
    class Request extends HttpCommons implements \ArrayAccess
    {
        /** @var array - Class clusters container */
        protected $_containers;

        /** @var string - HTTP Method used */
        public $method;
        /** @var array - Query params assoc array */
        public $params;
        /** @var array - Parsed request body */
        public $body;
        /** @var array - Headers assoc array, lowercase normalized header names */
        public $headers;
        /** @var string - Website base url */
        public $url;
        /** @var string - Original URI */
        public $uri;
        /** @var string - Host component */
        public $host;
        /** @var string - Path component */
        public $path;
        /** @var string - Raw query */
        public $query;
        /** @var string - Protocol/scheme used (http/https/etc) */
        public $protocol;
        /** @var string - User agent field */
        public $ua;
        /** @var string - Req content-type (beware, not INFERRED or DETECTED) */
        public $contentType;
        /** @var array - Raw cookies */
        public $cookies;
        /** @var array - Signed cookies if detected */
        public $signedCookies;
        /** @var bool - True if request is made through $.ajax or similar */
        public $xhr;
        /** @var bool - True if request is made through pjax or similar */
        public $pjax;
        /** @var bool - True if uses HTTPS */
        public $secure;
        /** @var string - User IP */
        public $ip;
        /** @var array - Used if X-Forwarded-For is present, array of detected ips through proxy(ies) */
        public $ips;
        /** @var \Maestro\Utils\CookieSignature - Signed Cookie parser */
        private $_cookieParser;

        /**
         * CTOR
         * @param bool $autoInit true if you want a fresh request according to webserver
         */
        public function __construct($autoInit = false)
        {
            $this->_cookieParser = new CookieSignature();

            if ($autoInit)
            {
                $this->refresh();
                return;
            }

            $this->method  = self::HTTP_VERB_GET;
            $this->params  = array();
            $this->body    = array();
            $this->headers = array();

            $this->ua       = '';
            $this->url      = 'http://localhost';
            $this->uri      = 'http://localhost';
            $this->host     = 'localhost';
            $this->query    = '';
            $this->path     = '/';
            $this->protocol = self::PROTOCOL_HTTP;

            $this->contentType   = 'text/html';
            $this->cookies       = array();
            $this->signedCookies = array();
            $this->xhr           = false;
            $this->pjax          = false;
            $this->secure        = false;
            $this->ip            = '127.0.0.1';
            $this->ips           = array($this->ip);
        }

        /**
         * Refreshes the request according to webserver vars/env
         * @return self
         */
        public function refresh()
        {
            $this->method  = $_SERVER['REQUEST_METHOD'];
            $this->headers = $this->_parseHeaders();

            $this->protocol = self::PROTOCOL_HTTP;
            $this->secure   = !empty($_SERVER['HTTPS']);

            $this->url = $this->_baseUrl();
            $this->uri = $_SERVER['REQUEST_URI'];

            $queryElements  = parse_url($this->url);
            $this->query    = isset($queryElements['query']) ? $queryElements['query'] : null;
            $this->path     = isset($queryElements['path']) ? $queryElements['path'] : null;
            $this->host     = isset($queryElements['host']) ? $queryElements['host'] : null;
            $this->protocol = isset($queryElements['scheme']) ? $queryElements['scheme'] : null;

            $this->contentType   = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : null;
            $this->ua            = isset($this->headers['user-agent']) ? $this->headers['user-agent'] : null;
            $this->cookies       = $_COOKIE;
            $this->signedCookies = $this->_cookieParser->parse($_COOKIE);
            $this->xhr           = isset($this->headers['x-requested-with'])
                && !!$this->headers['x-requested-with']
                && strtolower($this->headers['x-requested-with']) === 'xmlhttprequest';

            $this->pjax = isset($this->headers['x-pjax']) && !!$this->headers['x-pjax'];

            $this->ip = $_SERVER['REMOTE_ADDR'];
            if (isset($this->headers['x-forwarded-for'])) // Proxy support
                $this->ips = explode(', ', $this->headers['x-forwarded-for']);
            else
                $this->ips = array($this->ip);

            parse_str($this->query, $this->params);
            $this->body    = $this->_requestBody();

            return $this;
        }

        /**
         * Gives back request body according to request method & content-type headers for parsing
         * @return array
         */
        private function _requestBody()
        {
            $data = null;
            switch ($this->method)
            {
                case self::HTTP_VERB_PUT:
                case self::HTTP_VERB_DELETE:
                    $data = parent::_parseString(file_get_contents('php://input'), $this->contentType);
                    break;
                case self::HTTP_VERB_POST:
                    $data = $_POST;
                    break;
                case self::HTTP_VERB_GET:
                    $data = $_GET;
                    break;
                case self::HTTP_VERB_HEAD: // HEAD requests...no request body per HTTP/1.1 spec, so let's ignore it
                default: // Or unknown verb
                    $data = array();
            }

            return $data;
        }

        /**
         * Parses and reformats HTTP headers
         * @return array
         */
        private function _parseHeaders()
        {
            $res = array();

            foreach ($_SERVER as $k => $v)
                if (strtoupper(substr($k, 0, 5)) === 'HTTP_')
                    $res[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;

            return $res;
        }

        /**
         * Get website base URL
         * @return string
         */
        private function _baseUrl()
        {
            return 'http' . ($this->secure ? 's' : '') . '://' . $_SERVER['SERVER_NAME'] .
            ($_SERVER['SERVER_PORT'] != 80 ? ':' . $_SERVER['SERVER_PORT'] : '') .
            $_SERVER['REQUEST_URI'];
        }

        /**
         * Set params. Usually from router which captures patterns in routes.
         * @param array $params
         */
        public function setParams(array $params)
        {
            $this->params = $params;
        }

        /**
         * Checks for content type
         * @param $type
         * @return bool
         */
        public function is($type)
        {
            return $this->contentType === $type;
        }

        /**
         * @param string $name
         * @param mixed  $default
         * @return mixed
         */
        public function param($name, $default = null)
        {
            if (isset($this->params[$name]))
                return $this->params[$name];

            if (isset($this->body[$name]))
                return $this->body[$name];

            if (isset($this->query[$name]))
                return $this->query[$name];

            return $default;
        }

        /**
         * Getsetter for object extension container
         * @param      $name
         * @param null $value
         * @return self
         */
        final public function container($name, $value = null)
        {
            if ($value === null)
            {
                if (isset($this->_containers[$name]))
                    return $this->_containers[$name];

                return null;
            }


            $this->_containers[$name] = $value;
            return $this;
        }

        /**
         * (PHP 5 &gt;= 5.0.0)<br/>
         * Whether a offset exists
         * @link http://php.net/manual/en/arrayaccess.offsetexists.php
         * @param mixed $offset <p>
         *                      An offset to check for.
         *                      </p>
         * @return boolean true on success or false on failure.
         *                      </p>
         *                      <p>
         *                      The return value will be casted to boolean if non-boolean was returned.
         */
        public function offsetExists($offset)
        {
            return isset($this->_containers[$offset]);
        }

        /**
         * (PHP 5 &gt;= 5.0.0)<br/>
         * Offset to retrieve
         * @link http://php.net/manual/en/arrayaccess.offsetget.php
         * @param mixed $offset <p>
         *                      The offset to retrieve.
         *                      </p>
         * @return mixed Can return all value types.
         */
        public function offsetGet($offset)
        {
            return $this->container($offset);
        }

        /**
         * (PHP 5 &gt;= 5.0.0)<br/>
         * Offset to set
         * @link http://php.net/manual/en/arrayaccess.offsetset.php
         * @param mixed $offset <p>
         *                      The offset to assign the value to.
         *                      </p>
         * @param mixed $value  <p>
         *                      The value to set.
         *                      </p>
         * @return void
         */
        public function offsetSet($offset, $value)
        {
            $this->container($offset, $value);
        }

        /**
         * (PHP 5 &gt;= 5.0.0)<br/>
         * Offset to unset
         * @link http://php.net/manual/en/arrayaccess.offsetunset.php
         * @param mixed $offset <p>
         *                      The offset to unset.
         *                      </p>
         * @return void
         */
        public function offsetUnset($offset)
        {
            if (isset($this->_containers[$offset]))
                unset($this->_containers[$offset]);
        }
    }