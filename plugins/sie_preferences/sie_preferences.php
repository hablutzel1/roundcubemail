<?php

/**
 * SIE Preferences Plugin for Roundcube.
 */
class sie_preferences extends rcube_plugin
{
    function init()
    {
        $this->add_texts('localization/');
        $this->add_hook('identity_form', array($this, 'identity_form'));
        $this->add_hook('identity_update', array($this, 'identity_update'));
    }

    function identity_form($args)
    {
        $rcmail = rcmail::get_instance();
        $prefs = $rcmail->user->get_prefs();

        $args['form']['addressing']['content']['regularemail'] = array(
            'type' => 'text',
            'label' => $this->gettext('regularemail'),
            'size' => '40'
        );

        $args['form']['addressing']['content']['notifyregularemail'] = array(
            'type' => 'checkbox',
            'label' => $this->gettext('notifyregularemail')
        );

        $args['record']['regularemail'] = $prefs['regular_email'];
        $args['record']['notifyregularemail'] = isset($prefs['notify_regular_email']) ? $prefs['notify_regular_email'] : true; // 'true' because this setting is visually enabled by default if it doesn't exists in users.preferences.

        return $args;
    }

    function identity_update($args)
    {
        $rcmail = rcmail::get_instance();

        $regular_email = $_POST['_regularemail'];

        if (empty($regular_email)) {
            $args['message'] = $this->gettext('noregularemail');
            $args['abort'] = true;
        } elseif (!rcube_utils::check_email($regular_email)) {
            $args['message'] = $this->gettext('regularemailincorrect');
            $args['abort'] = true;
        } else {
            $success = $rcmail->user->save_prefs(array(
                'regular_email' => $regular_email,
                'notify_regular_email' => isset($_POST['_notifyregularemail'])
            ), true);

            if (!$success) {
                rcube::write_log('errors', sprintf("Plugin sie_preference: preferences not saved %s", $rcmail->get_user_name()));
                $args['abort'] = true;
            }
        }

        return $args;
    }
}