<?php

/* Google Login/Signup configuration.
 *
 * Paste the Client ID & Secret from Google Cloud Console
 * (APIs & Services -> Credentials -> OAuth 2.0 Client ID, type "Web application").
 *
 * Authorized JavaScript origins:
 *   https://lyaideu.com            (live)
 *   http://localhost               (local XAMPP testing)
 *
 * Authorized redirect URIs (must match Google Cloud Console exactly):
 *   https://lyaideu.com/google_callback.php          (live)
 *   http://localhost/lyaideu/google_callback.php     (local XAMPP testing)
 */

define('GOOGLE_CLIENT_ID', '174897112856-rd3gtj53506gdbh0062a7bpa45ad61t4.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');

// Live site: https://lyaideu.com/google_callback.php | Local XAMPP: http://localhost/lyaideu/google_callback.php
define('GOOGLE_REDIRECT_URI', 'http://localhost/lyaideu/google_callback.php');
