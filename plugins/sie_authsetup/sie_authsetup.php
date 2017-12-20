<?php

/**
 * This plugin has been tested with 'kolab_2fa' rev. ab9f33f from https://git.kolab.org/diffusion/RPK/. TODO check if we could jump to an stable 'kolab_2fa' version.
 */
class sie_authsetup extends rcube_plugin
{
    function init()
    {
        // Loading current plugin i18n.
        $this->add_texts('localization/');
        $this->add_hook('startup', array($this, 'load_kolab_2fa_i18_overrides'));
        $this->add_hook('ready', array($this, 'ready'));
        // Hooks required for interactions with 'password' plugin.
        $this->add_hook('password_change', array($this, 'password_change'));
        // Hooks required for interactions with 'kolab_2fa' plugin.
        $this->register_action('plugin.kolab-2fa', array($this, 'kolab_2fa_settings_view_override'));
        $this->register_handler('plugin.factoradder', array($this, 'kolab_2fa_settings_factoradder_override'));
    }

    public function load_kolab_2fa_i18_overrides($args)
    {
        if ($args['task'] == 'login' || $this->_isKolab2FaSettingsAction($args)) {
            $this->_kolab2FaAddTexts();
        }
    }

    function ready($args)
    {
        if ($args['action'] == 'refresh') { // TODO check if there is an standard way to ignore 'refresh' AJAX calls from 'ready' events or if there is maybe another hook which ignores 'refresh' calls.
            // NOTE that this way we are ignoring the 'first login password/2fa change/set policy' enforcement for 'refresh' calls. TODO determine if this is really ok in security terms.
            return $args;
        }
        $rcmail = rcmail::get_instance();
        $userPrefs = $rcmail->user->get_prefs();
        if (isset($userPrefs['firstlogin_password_unchanged']) && $userPrefs['firstlogin_password_unchanged']) {
            if (!($args['task'] == 'settings' &&
                ($args['action'] == 'plugin.password' || $args['action'] == 'plugin.password-save'))) {
                // Copied from \password::login_after.
                $args['_task'] = 'settings';
                $args['_action'] = 'plugin.password';
                $args['_first'] = 'true';
                $rcmail = rcmail::get_instance();
                $url = $rcmail->url($args);
                header('Location: ' . $url);
            }
        } else {
            if (!$this->_isKolab2FaSettingsAction($args)) {
                $rcmail = rcmail::get_instance();
                $kolab2FaPlugin = $rcmail->plugins->get_plugin("kolab_2fa");
                // We need to start up the plugin explicitely as it could have been filtered. See \kolab_2fa::$task.
                $kolab2FaPlugin->startup(array('task' => null));
                $registeredFactors = $this->_getFactorTypesRegisteredForCurrentUser($kolab2FaPlugin);
                if (count($registeredFactors) == 0) {
                    $this->_redirectTo2FA();
                }
            }
        }
        return $args;
    }

    //region Interactions with 'password' plugin.
    function password_change($args)
    {
        $rcmail = rcmail::get_instance();
        $userPrefs = $rcmail->user->get_prefs();
        // 'firstlogin_password_unchanged' has been set during account activation in 'management' app.
        if (isset($userPrefs['firstlogin_password_unchanged']) && $userPrefs['firstlogin_password_unchanged']) {
            $rcmail = rcmail::get_instance();
            $userPrefs = $rcmail->user->get_prefs();
            // TODO check if this user preference can be deleted, instead of just changing its value.
            $userPrefs['firstlogin_password_unchanged'] = false;
            $rcmail->user->save_prefs($userPrefs);
            $this->_redirectTo2FA();
        }
    }
    //endregion

    //region Interactions with 'kolab_2fa' plugin.

    // TODO in the 'add factor' form, evaluate to provide as default name something that relates better to an actual device, e.g. "Galaxy S7", instead of something like "Mobile App (TOTP)".

    /**
     * Created to display a message indicating that it is required to set at least one 2FA if none is configured yet.
     */
    public function kolab_2fa_settings_view_override()
    {
        $this->include_script('sie_authsetup.js');
        $rcmail = rcmail::get_instance();
        /** @var kolab_2fa $kolab2FaPlugin */
        $kolab2FaPlugin = $rcmail->plugins->get_plugin("kolab_2fa");
        $registeredFactors = $this->_getFactorTypesRegisteredForCurrentUser($kolab2FaPlugin);
        if (count($registeredFactors) == 0) {
            $rcmail->output->command('display_message', $this->gettext("2fa_required"), 'notice');
        }
        $kolab2FaPlugin->settings_view();
    }

    /**
     * Same that \kolab_2fa::settings_factoradder with the particularity that this implementation excludes 2FA factor types already being used by the current user.
     *
     */
    public function kolab_2fa_settings_factoradder_override($attrib)
    {
        $rcmail = rcmail::get_instance();
        $select = new html_select(array('id' => 'kolab2fa-add'));
        $kolab2FaPlugin = $rcmail->plugins->get_plugin("kolab_2fa");
        $select->add($kolab2FaPlugin->gettext('addfactor') . '...', '');
        $alreadyRegisteredFactorTypes = $this->_getFactorTypesRegisteredForCurrentUser($kolab2FaPlugin);
        foreach ((array)$rcmail->config->get('kolab_2fa_drivers', array()) as $method) {
            if (!in_array($method, $alreadyRegisteredFactorTypes)) {
                $select->add($kolab2FaPlugin->gettext($method), $method);
            }
        }
        return $select->show();
    }

    private function _isKolab2FaSettingsAction($args)
    {
        return $args['task'] == 'settings' && strpos($args['action'], 'plugin.kolab-2fa') === 0;
    }

    private function _getFactorTypesRegisteredForCurrentUser($kolab2FaPlugin)
    {
        $rcmail = rcmail::get_instance();
        $storage = $kolab2FaPlugin->get_storage($rcmail->get_user_name());
        $factors = $storage ? (array)$storage->enumerate() : array();
        $alreadyRegisteredFactorTypes = array();
        foreach ($factors as $factor) {
            $alreadyRegisteredFactorTypes[] = $this->_toFactorType($factor);
        }
        return $alreadyRegisteredFactorTypes;
    }

    private function _toFactorType($factor)
    {
        $factorType = substr($factor, 0, strpos($factor, ":"));
        return $factorType;
    }

    public function _redirectTo2FA()
    {
        $args['_task'] = 'settings';
        $args['_action'] = 'plugin.kolab-2fa';
        $rcmail = rcmail::get_instance();
        $url = $rcmail->url($args);
        header('Location: ' . $url);
    }

    /**
     * Simplified version of \rcube_plugin::add_texts.
     * TODO try to perform this using Roundcube API instead of implementing this by myself, i.e. determine if there is any standard method to override other plugin messages instead of doing it manually.
     */
    private function _kolab2FaAddTexts()
    {
        $rcmail = rcmail::get_instance();
        $lang = $_SESSION['language'];
        $langs = array_unique(array('en_US', $lang));
        $locdir = $this->home . '/localization/';
        $texts = array();
        foreach ($langs as $lng) {
            $fpath = $locdir . $lng . '.inc';
            if (is_file($fpath)) {
                include $fpath;
                $texts = (array)$kolab2FaLabels + $texts;
            }
        }
        $merge = array();
        foreach ($texts as $key => $value) {
            $merge['kolab_2fa' . '.' . $key] = $value;
        }
        $rcmail->load_language($lang, null, $merge);
        $js_labels = array_keys($merge);
        $rcmail->output->add_label($js_labels);
    }
    //endregion
}
