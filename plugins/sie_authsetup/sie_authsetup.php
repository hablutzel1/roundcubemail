<?php

/**
 * This plugin has been tested with 'kolab_2fa' rev. ab9f33f from https://git.kolab.org/diffusion/RPK/. TODO check if we could jump to an stable 'kolab_2fa' version.
 */
class sie_authsetup extends rcube_plugin
{
    private $sslLogin;

    function init()
    {
        $this->load_config();
        // Loading current plugin i18n.
        $this->add_texts('localization/');
        $this->add_hook('startup', array($this, 'startup'));
        $this->add_hook('authenticate', array($this, 'authenticate_with_mutual_ssl'));
        $this->add_hook('storage_connect', array($this, 'dovecot_masteruser_login'));
        $this->add_hook('managesieve_connect', array($this, 'dovecot_masteruser_login'));
        $this->add_hook('ready', array($this, 'ready'));
        $this->add_hook('logout_after', array($this, 'logout_after'));
        // Hooks required for interactions with 'password' plugin.
        $this->add_hook('password_change', array($this, 'password_change'));
        // Hooks required for interactions with 'kolab_2fa' plugin.
        $this->register_action('plugin.kolab-2fa', array($this, 'kolab_2fa_settings_view_override'));
        $this->register_handler('plugin.factoradder', array($this, 'kolab_2fa_settings_factoradder_override'));
        if (isset($_SESSION['sie_authsetup_ssllogin'])) { // We store this state in an attribute because only this way it will survive the fact that the session is killed before 'logout_after' is called.
           $this->sslLogin = true;
        }
    }

    public function startup($args)
    {
        $this->api->output->add_handler('loginform', array($this, 'login_form_with_ssl_login'));

        if ($args['task'] == 'login' || $this->_isKolab2FaSettingsAction($args)) {
            $this->_kolab2FaAddTexts();
        }
    }

    public function login_form_with_ssl_login($attrib = array())
    {
        $rcmail = rcmail::get_instance();
        /** @var rcmail_output_html $output */
        $output = $this->api->output;
        $rcmail_output_htmlClass = new ReflectionClass($output);
        $login_formMethod = $rcmail_output_htmlClass->getMethod('login_form');
        $login_formMethod->setAccessible(true);
        $out = $login_formMethod->invoke($output, $attrib);
        $rcmail = rcmail::get_instance();
        $mobilePlugin = $rcmail->plugins->get_plugin("mobile");
        if (!$mobilePlugin->isMobile()) { // Currently not supporting eID authentication from mobile devices.
            $sslLoginUrl = $rcmail->url(array(
                'task' => 'login',
                'action' => 'login',
                'ssllogin' => 1,
            ));
            $out .= \html::a(array("href" => $sslLoginUrl, 'style' => 'text-align: right; display: block;', 'title' => $this->gettext("ssllinkwarning")), $this->gettext("ssllinktext"));
        }
        return $out;
    }

    public function authenticate_with_mutual_ssl($args)
    {
        if (isset($_GET['_ssllogin'])) {
            $rcmail = rcmail::get_instance();
            $clientCert = $_SERVER['SSL_CLIENT_CERT'];
            if (empty($clientCert)){
                $args['abort'] = true;
                $args['error'] = $this->gettext("clientcertificateunavailable");
                return $args;
            }
            $clientCert = openssl_x509_read($clientCert);
            $clientCert = openssl_x509_parse($clientCert);
            $serialNumber = $clientCert['subject']['serialNumber'];
            // TODO confirm the actual separator in the peruvian eID. I think that sometime I observed that a '-' was used. Note that there might be several eID versions with different certificate profiles.
            $serialNumber = explode(":", $serialNumber);
            $nid = $serialNumber[1];
            $args['user'] = $rcmail->config->get('sie_authsetup_pn_username_prefix', 'dni_') . $nid;
            if (!(rcube_user::query($args['user'], $args['host']))) {
                $args['error'] = str_replace('$nid', $nid ,$this->gettext('authenticatedcitizennotregistered'));
                $args['abort'] = true;
                return $args;
            }
            $args['pass']  =  $rcmail->config->get('sie_authsetup_dovecot_master_password');

            // Disable 2FA for this login session.
            $rcmail = rcmail::get_instance();
            $kolab2FaPlugin = $rcmail->plugins->get_plugin("kolab_2fa");
            $kolab_2faClass = new ReflectionClass($kolab2FaPlugin);
            $login_verifiedProperty = $kolab_2faClass->getProperty("login_verified");
            $login_verifiedProperty->setAccessible(true);
            $login_verifiedProperty->setValue($kolab2FaPlugin, true);

            $_SESSION['sie_authsetup_ssllogin'] = true;
            $this->sslLogin = true;
        }
        return $args;
    }

    function dovecot_masteruser_login($args) {
        if ($this->sslLogin) {
            $rcmail = rcmail::get_instance();
            $separator = $rcmail->config->get('sie_authsetup_dovecot_master_user_separator', '*');
            $masterUser = $rcmail->config->get('sie_authsetup_dovecot_master_username');
            $args['user'] = $args['user'] . $separator . $masterUser;
        }
        return $args;
    }

    function ready($args)
    {
        if ($args['action'] == 'refresh') { // TODO check if there is an standard way to ignore 'refresh' AJAX calls from 'ready' events or if there is maybe another hook which ignores 'refresh' calls.
            // NOTE that this way we are ignoring the 'first login password/2fa change/set policy' enforcement for 'refresh' calls. TODO determine if this is really ok in security terms.
            return $args;
        }
        $rcmail = rcmail::get_instance();
        $userPrefs = $rcmail->user->get_prefs();
        if (!$this->sslLogin) {
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
        }
        return $args;
    }

    public function logout_after($args) {
        if ($this->sslLogin) {
            $this->api->output->show_message($this->gettext('clientcertificatelogout'), 'warning', null, true, PHP_INT_MAX);
        }
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
            if (!$this->sslLogin) {
                $this->_redirectTo2FA();
            }
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
