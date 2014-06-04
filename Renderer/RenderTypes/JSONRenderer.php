<?php

    namespace Maestro\Renderer\RenderTypes;

    use Maestro\Renderer\Renderer;

    /**
     * Class JSONRenderer
     * @package Maestro\Renderer\RenderTypes
     */
    class JSONRenderer extends Renderer
    {
        /**
         * Render function.
         * @param array $vars New variables to merge just in time
         */
        public function render($vars = array())
        {
            $this->_data = array_merge($this->_data, $vars);
            echo json_encode($this->_data);
        }
    }