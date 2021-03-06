<?php

    namespace FastRoute;

    /**
     * @param \Closure $routeDefinitionCallback
     * @param array    $options
     *
     * @return Dispatcher
     */
    function simpleDispatcher(\Closure $routeDefinitionCallback, array $options = array())
    {
        $options += array(
            'routeParser'   => 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher'    => 'FastRoute\\Dispatcher\\GroupCountBased',
        );

        $routeCollector = new RouteCollector(
            new $options['routeParser'], new $options['dataGenerator']
        );
        $routeDefinitionCallback($routeCollector);

        return new $options['dispatcher']($routeCollector->getData());
    }

    /**
     * @param \Closure $routeDefinitionCallback
     * @param array    $options
     *
     * @throws \LogicException
     * @return Dispatcher
     */
    function cachedDispatcher(\Closure $routeDefinitionCallback, array $options = array())
    {
        $options += array(
            'routeParser'   => 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher'    => 'FastRoute\\Dispatcher\\GroupCountBased',
            'cacheDisabled' => false,
        );

        if (!isset($options['cacheFile']))
        {
            throw new \LogicException('Must specify "cacheFile" option');
        }

        if (!$options['cacheDisabled'] && file_exists($options['cacheFile']))
        {
            /** @noinspection PhpIncludeInspection */
            $dispatchData = require $options['cacheFile'];

            return new $options['dispatcher']($dispatchData);
        }

        $routeCollector = new RouteCollector(
            new $options['routeParser'], new $options['dataGenerator']
        );
        $routeDefinitionCallback($routeCollector);

        $dispatchData = $routeCollector->getData();
        file_put_contents(
            $options['cacheFile'],
            '<?php return ' . var_export($dispatchData, true) . ';'
        );

        return new $options['dispatcher']($dispatchData);
    }
