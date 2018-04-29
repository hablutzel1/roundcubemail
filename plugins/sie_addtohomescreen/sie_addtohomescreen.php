<?php

class sie_addtohomescreen extends rcube_plugin
{

    function init()
    {
        // Following code based on \rcube_plugin_api::include_script. TODO check if there is an standard way to add a 'link' tag to the html head.
        if (is_object($this->api->output) && $this->api->output->type == 'html') {
            $src = $this->url("manifest.json");
            $this->api->output->add_header(html::tag('link',
                array('rel' => "manifest", 'href' => $src)));
        }

    }

}