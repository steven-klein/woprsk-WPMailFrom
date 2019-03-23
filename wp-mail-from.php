<?php
/**
 * Plugin Name:  woprsk/WPMailFrom
 * Description:  Simply set the wp mail from args for smtp
 */

namespace woprsk;

class WPMailFrom
{
    // username for email from address.
    public $username = 'no-reply';

    // reply_to that can be set programatically
    public $emailFrom = '';

    // the from name used in emails sent out.
    public $fromName = '';

    // email home url.  when set, urls in the email message that match the current home_url are replaced with this.
    public $emailHomeUrl = '';

    // setup the domain for this site.
    public $domain = '';

    // self - singleton pattern.
    private static $instance;

    /**
     * Return the instance.
     * @return object FilterMail instance
     */
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * add email from filters during construct. apply them late.
     */
    public function __construct()
    {
        if (defined('WP_MAIL_FROM') && filter_var(WP_MAIL_FROM, FILTER_VALIDATE_EMAIL)) {
            $this->emailFrom = WP_MAIL_FROM;
            add_filter('wp_mail_from', [$this, 'wpMailFrom'], 999);
            add_filter('wp_mail_from_name', [$this, 'wpMailFromName'], 999);
            add_filter('wp_mail', [$this, 'setServerVar'], 999);
        }

        if (defined('WP_MAIL_HOME') && filter_var(WP_MAIL_HOME, FILTER_VALIDATE_URL)) {
            $this->emailHomeUrl = WP_MAIL_HOME;
            add_filter('wp_mail', [$this, 'wpMailBody'], 999);
        }

        // setup are commonly used variables.
        add_action('wp_loaded', [$this, 'setupVars']);
    }

    /**
     * determine the root domain of the site we are working on and the from name.
     *
     * @return void
     */
    public function setupVars()
    {
        $this->domain = (is_multisite()) ? get_current_site()->domain : preg_replace('/^www\./', '', parse_url(get_bloginfo('url'), PHP_URL_HOST));
        $this->fromName = (is_multisite()) ? get_current_site()->site_name : get_bloginfo('name');
        $this->emailFrom = (!empty($this->emailFrom)) ? $this->emailFrom : $this->username . "@" . $this->domain;
    }

    /**
     * Set a no-reply from email address... sometimes wp leaves it empty.
     * @hooked wp_mail_from
     * @param  string $email original from email address passed by apply_filters('wp_mail_from')
     * @return string        replacement from email derived from site domain and no-reply.
     */
    public function wpMailFrom($email)
    {
        return $this->emailFrom;
    }

    /**
     * Set a from name based on the site name.
     * @hooked wp_mail_from_name
     * @param  string $name from name passed by apply_filter('wp_mail_from_name').
     * @return string       replacement from name derived from the site name.
     */
    public function wpMailFromName($name)
    {
        return $this->fromName;
    }

    /**
     * filter out references to wp_home in the email message
     * @hooked wp_mail
     * @param  array $atts email attributes - 'to', 'subject', 'message', 'headers', 'attachments'
     * @return array $atts
     */
    public function wpMailBody($atts)
    {
        if (!empty($this->emailHomeUrl) && isset($atts['message']) && is_string($atts['message'])) {
            // replace any uses of the home url with $emailHomeUrl
            $atts['message'] = str_replace(home_url(), $this->emailHomeUrl, $atts['message']);
        }

        return $atts;
    }

    /**
     * WordPress insists on using the $_SERVER['SERVER_NAME'] variable for establishing the from email.
     * This is problematic since server name isn't set in environments like wp-cli.
     * Nastily, we will hit the wp_mail filter to update the $_SERVER var if necessary.
     * And provide an appropriate action to remove our changes as soon as we know we've cleared the error.
     * @hooked wp_mail
     *
     * @param array $atts
     * @return array $atts
     */
    public function setServerVar($atts)
    {
        global $_SERVER;

        if (!isset($_SERVER['SERVER_NAME'])) {
            // set the server var to our domain.
            // doesn't matter what it is, we'll unset it as soon as possible, and override the from email anyway, which is what wordpress uses it for.
            $_SERVER['SERVER_NAME'] = $this->domain;

            // hit the next available action/filter to reset our manipulation of the server var.
            add_filter('wp_mail_from', [$this, 'unsetServerVar'], 1);
        }

        // return the attributes untouched.
        return $atts;
    }

    /**
     * undoes the changes made by $this->setServerVar().
     * wp_mail can be called at any given point in execution, it would be unwise to leave a globally modified variable when the rest of the application my also use the server_name in some way.
     * Nastily, we are hooking a filter like an action to cause a major side effect as no other actions are available.
     * @hooked wp_mail_from
     *
     * @param string $from_email
     * @return string $from_email
     */
    public function unsetServerVar($from_email)
    {
        // reset the $_SERVER var
        global $_SERVER;
        if (isset($_SERVER['SERVER_NAME'])) {
            unset($_SERVER['SERVER_NAME']);
        }

        // return $from_email untouched.
        return $from_email;
    }
}

/**
 * Instantiate instance during muplugins_loaded action
 * @var [type]
 */
add_action('muplugins_loaded', 'Woprsk\WPMailFrom::instance');
