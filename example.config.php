<?php

// copy this file to bugmash.config.php and fill in with
// appropriate values. See README for details

// MySQL access info
$_MYSQL_HOST = 'bugdb.example.com';
$_MYSQL_USER = 'bugdb_user';
$_MYSQL_PASS = 'bugdb_pass'
$_MYSQL_DB = 'bugmash';

// My bugmail addresses
$_ME = array( 'bugmail@bugdb.example.com' );

// The extension on the final email address that is receiving the email. You can always leave this unset to disable extension checking.
// $_MY_EXTENSION = 'bugmash';

// Check the SENDER environment variable to see if the mail came from bugzilla.
// If legit-looking bugmail are ending up in your spam folder you might want to
// set this to 0 or comment it out.
$_CHECK_SENDER = 1;

// directory where emails that are not bugzilla emails will be dropped
$_UNFILTERED_DIR = $_SERVER['HOME'] . '/Maildir/new';

// bugzilla installation URL base
$_BASE_URL = 'https://bugzilla.mozilla.org';

?>
