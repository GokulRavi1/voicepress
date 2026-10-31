<?php
/**
 * Plugin Name: Dev mail sink
 * Description: Local-dev only. There is no mail server on this machine, so pretend every email was sent
 *              (keeps "Email failed to send" notes off demo orders). Installed by dev/setup.sh into mu-plugins.
 */
add_filter( 'pre_wp_mail', '__return_true' );
