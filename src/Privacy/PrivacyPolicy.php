<?php
declare(strict_types=1);

namespace Convermetry\Privacy;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;

/**
 * Suggested privacy-policy text for Settings → Privacy → Policy Guide.
 *
 * Generated from the site's CURRENT settings — whether IP addresses are stored,
 * whether Do Not Track / Global Privacy Control is honored, the retention
 * window, whether webhooks and email notifications are configured — so the
 * suggestion describes what this installation actually does. WordPress
 * compares the text on every admin load and flags the Policy Guide when it
 * changes, which is how a site owner who later switches IP storage on finds
 * out their policy text is stale.
 *
 * It is a starting point, not legal advice, and it says so in the tutorial
 * notes (which WordPress shows to the administrator but does not copy into the
 * policy).
 */
final class PrivacyPolicy
{
    /**
     * Registers the text. Hooked to admin_init, the only point at which
     * wp_add_privacy_policy_content() accepts it.
     *
     * @return void
     */
    public static function register(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        wp_add_privacy_policy_content('Convermetry', self::content());
    }

    /**
     * The policy HTML.
     *
     * @return string
     */
    public static function content(): string
    {
        $retention     = Options::retentionDays();
        $storesIp      = Options::storeIpAddress();
        $honorsSignals = Options::respectDnt();
        $webhooks      = Options::webhooksActive() && Options::endpoints() !== [];
        $notifications = Options::notificationsEnabled();

        $tutorial = static fn(string $text): string => '<p class="privacy-policy-tutorial">' . $text . '</p>';
        $para     = static fn(string $text): string => '<p>' . $text . '</p>';

        $html  = $tutorial(esc_html__('This text is generated from your current Convermetry settings and changes when they do. It is a starting point for your own policy, not legal advice: review it, adapt it to how you actually use the data, and state your legal basis for processing it. Convermetry does not by itself make a site compliant with any privacy law.', 'convermetry'));
        $html .= $tutorial(esc_html__('Convermetry sets no cookies, but it does store identifiers in the visitor\'s browser (localStorage and sessionStorage). In the EU and UK, the rules that govern cookies also apply to that kind of browser storage, and analytics may need consent. Convermetry has no consent banner of its own: if you need consent, have your consent tool block the "cvmtry-tracker" script until it is given, or return false from the convermetry_should_enqueue_tracker filter.', 'convermetry'));
        $html .= '<strong class="privacy-policy-tutorial">' . esc_html__('Suggested text:', 'convermetry') . ' </strong>';

        $html .= '<h3>' . esc_html__('Website analytics', 'convermetry') . '</h3>';
        $html .= $para(esc_html__('When you visit this website we record how it is used: the pages you view, the links and buttons you click or rest your pointer on, how far you scroll, and how you interact with forms (for example, that a form was viewed, started, or could not be submitted — but never what you typed into a form you did not submit). With each of these we record the page address without its query string, the address of the site that referred you, any campaign tags in the link you arrived by, and the general type of device you use (desktop, tablet, or mobile).', 'convermetry'));
        $html .= $para(esc_html__('To group the pages you view into one visit, your browser stores a random visit identifier and the details of how you arrived in its local storage, and briefly holds analytics data waiting to be sent in its session storage. The visit identifier is replaced after 30 minutes of inactivity. We do not use cookies for analytics.', 'convermetry'));

        if ($storesIp) {
            $html .= $para(esc_html__('We also record your IP address with each analytics event.', 'convermetry'));
        } else {
            $html .= $para(esc_html__('We do not record your IP address with analytics events.', 'convermetry'));
        }

        if ($honorsSignals) {
            $html .= $para(esc_html__('If your browser sends a Do Not Track or Global Privacy Control signal, we do not record analytics about your visit, and we do not store your IP address with any form you submit.', 'convermetry'));
        }

        if (Options::excludeLoggedIn()) {
            $html .= $para(esc_html__('Visitors who are logged in to this website are not tracked.', 'convermetry'));
        }

        $html .= '<h3>' . esc_html__('Form submissions', 'convermetry') . '</h3>';
        $html .= $para(
            $storesIp
                ? esc_html__('When you submit a form on this website, we keep a copy of the information you entered, together with your IP address, the page you submitted it from, and details of the visit that led to it (such as the campaign or website that referred you and the pages you viewed), so that we can respond to you and understand which of our marketing works.', 'convermetry')
                : esc_html__('When you submit a form on this website, we keep a copy of the information you entered, together with the page you submitted it from and details of the visit that led to it (such as the campaign or website that referred you and the pages you viewed), so that we can respond to you and understand which of our marketing works.', 'convermetry')
        );

        if ($notifications) {
            $html .= $para(esc_html__('A copy of each form submission, which may include details of your visit, is emailed to members of our staff.', 'convermetry'));
        }

        if ($webhooks) {
            $html .= $tutorial(esc_html__('You have webhook endpoints configured. Name the services that receive your analytics reports and form submissions (for example your CRM or marketing automation platform), and link to their privacy policies, in the paragraph below.', 'convermetry'));
            $html .= $para(esc_html__('We send form submissions and analytics reports to the following services, which process them on our behalf: [list the services and link to their privacy policies].', 'convermetry'));
        }

        $html .= '<h3>' . esc_html__('How long we keep this data', 'convermetry') . '</h3>';
        $html .= $para(esc_html(sprintf(
            /* translators: %d: number of days analytics data and form submissions are kept. */
            _n(
                'Analytics data and form submissions are deleted automatically after %d day.',
                'Analytics data and form submissions are deleted automatically after %d days.',
                $retention,
                'convermetry'
            ),
            $retention
        )));

        if ($webhooks || $notifications) {
            $html .= $para(esc_html__('Copies we have already sent to other services or by email are kept according to those services\' own retention policies.', 'convermetry'));
        }

        $html .= '<h3>' . esc_html__('Your rights', 'convermetry') . '</h3>';
        $html .= $para(esc_html__('You can ask us for a copy of the form submissions we hold that contain your email address, together with the analytics recorded during the visit in which you submitted them, or ask us to erase them. Analytics recorded during visits in which you did not submit a form cannot be linked to you by email address; it is deleted automatically at the end of the retention period.', 'convermetry'));

        return $html;
    }
}
