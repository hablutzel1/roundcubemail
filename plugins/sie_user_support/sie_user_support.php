<?php

/**
 * This plugin has been tested with 'kolab_2fa' rev. ab9f33fb (see https://inversionesrc.atlassian.net/wiki/x/HoAvKg#SIE-Installation-kolab_2fa_installation) from https://git.kolab.org/diffusion/RPK/. TODO check if we could jump to an stable 'kolab_2fa' version.
 */

class sie_user_support extends rcube_plugin
{
    /**
     * Instance of rcube_user class
     *
     * @var rcube_user $user
     */
    private $user;

    function init()
    {
        $this->load_config();
        $this->add_texts('localization/');
        $this->add_hook('startup', array($this, 'startup'));
        $this->add_hook('ready', array($this, 'ready'));
        $this->register_handler('plugin.activationform', array($this, 'activation_form'));
        $this->register_handler('plugin.tos', array($this,'tos_content'));
    }

    function startup($args)
    {
        $rcmail = rcmail::get_instance();
        $method = $_SERVER['REQUEST_METHOD'];

        $this->include_stylesheet($this->local_skin_path() . '/sie_user_support.css');
        // kill active session
        if ($args['action'] == 'plugin.activation' && !empty($rcmail->user->ID)) {
            $rcmail->kill_session();
            header('Location: ' . $_SERVER['REQUEST_URI']);
        } elseif ($args['action'] == 'plugin.activation') {
            $_SESSION['hostname'] = $rcmail->config->get('default_host');
            $username = $_GET['_username'];
            $token = $_GET['_token'];
            $this->syncUser($username);
            if ($this->user && $this->validateActivationRequest($this->user, $token)) {
                if ($method == 'GET') { // show activation form
                    $_SESSION['activation_username'] = $username;
                    $rcmail->output->send('sie_user_support.activationform');
                } elseif ($method == 'POST' && isset($_SESSION['activation_username'])) { // perform activation process
                    $this->activate();
                }
            }
            $rcmail->output->send('sie_user_support.invalidrequest');
        } elseif ($args['action'] == 'plugin.termsofservice') {
            $rcmail->output->send('sie_user_support.termsofservice');
        }
    }

    private function syncUser($username)
    {
        $rcmail = rcmail::get_instance();
        $this->user = rcube_user::query($username, $rcmail->config->get('default_host'));
    }

    private function validateActivationRequest($user, $token)
    {
        $curTime = time();
        $maxAge = 3 * 24 * 60 * 60; // 3 days.
        $preferences = $user->get_prefs();
        $valid =  isset($preferences['activation_token']) && $preferences['activation_token'] == $token && $curTime - $preferences['activation_token_created_at'] <= $maxAge;
        return $valid;
    }

    public function activation_form()
    {
        $rcmail = rcmail::get_instance();
        $rcmail->output->add_label(
            'sie_user_support.alreadycopied'
        );

        $input_username = new html_inputfield(array(
            'name' => '_username',
            'id' => '_username',
            'readonly' => 'true',
            'disabled' => 'true',
            'value' => $this->user->get_username(),
            'size' => 30,
        ));
        $out = html::div(array(
            'class' => 'form-group'
        ), html::label('_username', rcube::Q($this->gettext('username'))) . $input_username->show());

        $input_password = new html_passwordfield(array(
            'name' => '_password',
            'id' => '_password',
            'size' => 30,
        ));
        $out .= html::div(array(
            'class' => 'form-group'
        ), html::label('_password',rcube::Q($this->gettext('password'))) . $input_password->show());

        $input_confirmpassword = new html_passwordfield(array(
            'name' => '_confirmpassword',
            'id' => '_confirmpassword',
            'size' => 30,
        ));
        $out .= html::div(array(
            'class' => 'form-group'
        ), html::label('_confirmpassword',rcube::Q($this->gettext('confirmpassword'))) . $input_confirmpassword->show());

        $out .= html::div(array(
            'class' => 'form-group'
        ), html::label(null, $this->gettext('add2fa')) . $this->factor2fa_form());

        $checkbox_accept = new html_checkbox(array(
            'name' => '_accept',
            'id' => '_accept',
        ));
        $url_tos = $rcmail->url(array('_task'=>'support', '_action'=>'plugin.termsofservice'));
        $out .= html::div(array(
            'class' => 'form-group'
        ), html::label('_accept', $checkbox_accept->show('false') . str_replace('$urltos', $url_tos, $this->gettext('accepttos'))));

        $out .= html::div(array('id' => 'message', 'name' => 'message'));
        $submit_button = $rcmail->output->button(array(
            'command' => 'plugin.activate',
            'type' => 'input',
            'class' => 'button mainaction',
            'value' => $this->gettext('activate'),
            'id' => '_activatebutton'
        ));
        $out .= $submit_button;

        $rcmail->output->add_gui_object('activationform', 'activation-form');

        $this->include_script('sie_user_support.js');
        return $rcmail->output->form_tag(array(
            'id' => 'activation-form',
            'name' => 'activation-form',
            'method' => 'post',
            'action' => '.' . $_SERVER['REQUEST_URI']
        ), $out);
    }

    private function factor2fa_form()
    {
        $out = '';
        $rcmail = rcmail::get_instance();
        $Kolab2FAPlugin = $this->getKolab2FAPluginInstance();
        $methods = (array)$rcmail->config->get('kolab_2fa_drivers', array());

        $select = new html_select(array(
            'id' => '_methodAvailable',
            'disabled' => 'true'
        ));

        foreach ($methods as $key => $method) {
            $select->add($Kolab2FAPlugin->gettext($method), $method);

            $method_content = '';
            $_method = rcube_utils::get_input_value('_method', rcube_utils::INPUT_POST);
            $_timestamp = rcube_utils::get_input_value('_timestamp', rcube_utils::INPUT_POST);

            $data = $this->settings_data(isset($_method) ? $_method : $method);
            $input_timestamp = new html_hiddenfield(array(
                'name' => '_timestamp',
                'id'=> '_timestamp',
                'value'=> isset($_timestamp) ? $_timestamp : ''
            ));
            $method_content .= $input_timestamp->show();
            $input_method = new html_hiddenfield(array(
                'name' => '_method',
                'id'=> '_method',
                'value' => $data['id']
            ));
            $method_content .= $input_method->show();
            $link_code = html::a(array('id'=>'linkcode','href'=>'#'), $this->gettext('code'));
            $method_content .= html::p(null, $data['explain']) . html::p(null, str_replace('$linkcode', $link_code, $this->gettext('mobileinstruction')));
            $input_secret = new html_inputfield(array(
                'id' => '_secret',
                'readonly' => 'true',
                'disabled' => 'true',
                'size' => 30,
                'style' => 'display:none',
                'value' => $data['secret'],
            ));
            $method_content .= $input_secret->show();
            $image_qr = html::img(array(
                'id' => '_qrcode',
                'width' => '200',
                'height' => '200',
                'src'=> 'data:image/png;base64,' . $data['qrcode']
            ));
            $method_content .= $image_qr;
            $input_verifycode = new html_inputfield(array(
                'name' => '_verifycode',
                'id' => '_verifycode',
                'size' => 30,
                'autocomplete' => 'false'
            ));
            $method_content .= $input_verifycode->show();
            $out .= html::div(array('id'=> 'content_'. $method), $method_content);
        }
        $out = $select->show() . $out;
        return $out;
    }

    private function activate()
    {
        $newpwd = rcube_utils::get_input_value('_password', rcube_utils::INPUT_POST);
        $conpwd = rcube_utils::get_input_value('_confirmpassword', rcube_utils::INPUT_POST);
        $accept_tos = rcube_utils::get_input_value('_accept', rcube_utils::INPUT_POST);
        $verify_code = rcube_utils::get_input_value('_verifycode', rcube_utils::INPUT_POST);
        $timestamp = intval(rcube_utils::get_input_value('_timestamp', rcube_utils::INPUT_POST));
        $method = rcube_utils::get_input_value('_method', rcube_utils::INPUT_POST);

        $rcmail = rcmail::get_instance();
        // Validate inputs as required for activation
        if (($message = $this->validatePassword($newpwd, $conpwd)) !== null) {
            $rcmail->output->command('display_message', $message, 'error');
        } elseif (($message = $this->validateTOTP($method, $verify_code, $timestamp)) !== null) {
            $rcmail->output->command('display_message', $message, 'error');
        } elseif (!isset($accept_tos)) {
            $rcmail->output->command('display_message', $this->gettext('tosnotaccepted'), 'error');
        } else {
            $username = $this->user->get_username();
            $command = "sudo /usr/sbin/useradd -m -s /usr/sbin/nologin -p `openssl passwd -1 " . escapeshellarg($newpwd) . "` " . escapeshellarg($username) . " 2>&1";
            exec($command, $output, $return_var);
            if ($return_var != 0) {
                rcube::write_log('errors', sprintf('Plugin sie_user_support: Unexpected problem creating user account %s', $username));
            } else {
                $kolab2FaPlugin = $this->getKolab2FAPluginInstance();
                $driver = $kolab2FaPlugin->get_driver($method);
                $driver->set('active', true);
                if($driver->commit()){
                    $this->syncUser($username);
                    $data = array(
                        'activation_token' => null,
                        'activation_token_created_at' => null,
                        'notifyregularemail' => true
                    );
                    if($this->user->save_prefs($data)){
                        // Login after activation process
                        $rcmail->login($username, $newpwd);
                        $rcmail->session->set_auth_cookie();
                        $rcmail->output->redirect(array('_task' => 'mail'));
                    }
                }
            }
            $rcmail->output->command('display_message', $this->gettext('erroractivation'));
        }
        $rcmail->output->send('sie_user_support.activationform');
    }

    private function validatePassword(&$newpwd, $conpwd, $curpwd = '', $change = false)
    {
        $message = null;
        // based on plugin password validation
        $rcmail = rcmail::get_instance();
        $username = $this->user->get_username();
        $confirm = $rcmail->config->get('password_confirm_current');
        $required_length = intval($rcmail->config->get('password_minimum_length'));
        $check_strength  = $rcmail->config->get('password_require_nonalpha');

        $plugin_password = $rcmail->plugins->get_plugin("password");
        $plugin_password->add_texts('localization/');

        if (($change && $confirm && empty($curpwd) ) || empty($newpwd)) {
            $message = $plugin_password->gettext('nopassword');
        } else {
            $charset    = strtoupper($rcmail->config->get('password_charset', 'ISO-8859-1'));
            $rc_charset = strtoupper($rcmail->output->get_charset());

            $orig_pwd = $newpwd;
            $chk_pwd = rcube_charset::convert($orig_pwd, $rc_charset, $charset);
            $chk_pwd = rcube_charset::convert($chk_pwd, $charset, $rc_charset);

            $newpwd = rcube_charset::convert($newpwd, $rc_charset, $charset);
            $conpwd = rcube_charset::convert($conpwd, $rc_charset, $charset);

            $storage = $rcmail->get_storage();

            if ($chk_pwd != $orig_pwd) {
                $message = $plugin_password->gettext('passwordforbidden');
            } elseif ($conpwd != $newpwd) {
                $message = $plugin_password->gettext('passwordinconsistency');
            } elseif ($change && $confirm
                && !$storage->connect($rcmail->config->get('default_host'), $username, $curpwd)) {
                $message = $plugin_password->gettext('passwordincorrect');
            } elseif ($required_length && strlen($newpwd) < $required_length) {
                $message = $plugin_password->gettext(array('name' => 'passwordshort', 'vars' => array('length' => $required_length)));
            } elseif ($check_strength && (!preg_match("/[0-9]/", $newpwd) || !preg_match("/[^A-Za-z0-9]/", $newpwd))) {
                $message = $plugin_password->gettext('passwordweak');
            } elseif ($change && !$rcmail->config->get('password_force_save') && $storage->connect($rcmail->config->get('default_host'), $username, $newpwd)) {
                $message = $plugin_password->gettext('samepasswd');
            }
        }

        return $message;
    }

    private function validateTOTP($method, $verify_code, $timestamp)
    {
        $message = null;
        $rcmail = rcmail::get_instance();
        $kolab2FaPlugin = $this->getKolab2FAPluginInstance();

        if (!isset($verify_code) || $verify_code == '') {
            $message = $kolab2FaPlugin->gettext('verifycodemissing');
        } else {
            $driver = $kolab2FaPlugin->get_driver($method);
            if (!$driver->verify($verify_code, $timestamp)) {
                $message = str_replace('$method', $kolab2FaPlugin->gettext($driver->method), $kolab2FaPlugin->gettext('codeverificationfailed'));
            }
        }

        return $message;
    }

    public function settings_data($method)
    {
        $kolab2FaPlugin = $this->getKolab2FAPluginInstance();
        // based on settings_data method from kolab_2fa plugin
        $data = null;
        if ($driver = $kolab2FaPlugin->get_driver($method)) {
            $data = array('method' => $method, 'id' => $driver->id);
            $driver->username = $this->user->get_username();

            foreach ($driver->props(true) as $field => $prop) {
                $data[$field] = $prop['text'] ?: $prop['value'];
            }

            if (method_exists($driver, 'get_provisioning_uri')) {
                try {
                    $uri = $driver->get_provisioning_uri();

                    $qr = new Endroid\QrCode\QrCode();
                    $qr->setText($uri)
                        ->setSize(240)
                        ->setPadding(10)
                        ->setErrorCorrection('high')
                        ->setForegroundColor(array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 0))
                        ->setBackgroundColor(array('r' => 255, 'g' => 255, 'b' => 255, 'a' => 0));
                    $data['qrcode'] = base64_encode($qr->get());
                    $data['secret'] = $kolab2FaPlugin->get_storage()->read($driver->id)['secret'];
                    $data['explain'] = $kolab2FaPlugin->gettext("qrcodeexplaintotp");
                } catch (Exception $e) {
                    rcube::raise_error($e, true, false);
                }
            }
        }
        return $data;
    }

    private function getKolab2FAPluginInstance()
    {
        $rcmail = rcmail::get_instance();
        $kolab2FaPlugin = $rcmail->plugins->get_plugin("kolab_2fa");
        $storage = $kolab2FaPlugin->get_storage($this->user->get_username());
        return $kolab2FaPlugin;
    }

    function ready($args)
    {
        $rcmail = rcmail::get_instance();
        // successful activation message after login
        if (isset($_SESSION['activation_username']) && !empty($_SESSION['activation_username'])) {
            $rcmail->output->command('display_message', $this->gettext("accountActivated"), 'confirmation');
            $_SESSION['activation_username'] = '';
        }
    }

    function tos_content()
    {
        $rcmail = rcmail::get_instance();
        $filename = $rcmail->config->get('sie_user_support_tos_content', 'default.html');
        $out = file_get_contents($this->home . '/resources/' . $filename);
        $rcmail->output->add_gui_object('tos', 'tos-content');
        return $out;
    }
}