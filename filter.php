#!/usr/local/bin/php
<?php

// directory where emails that are not bugzilla emails will be dropped
$_UNFILTERED_DIR = $_SERVER['HOME'] . '/Maildir/new';

$mail = file( 'php://stdin' );
$mailString = implode( '', $mail );

$filename = $_SERVER['REQUEST_TIME'] . '.' . sha1( $mailString );

if (strcmp( $_SERVER['EXTENSION'], 'bugmash' ) != 0)
    || (strpos( $_SERVER['SENDER'], 'bugzilla-daemon@' ) !== 0)
{
    // doesn't look like a bugmail, probably spam but possible bounce notifications. toss it in maildir

    file_put_contents( $_UNFILTERED_DIR . '/' . $filename, $mailString );
    exit( 0 );
}

// bugmail, let's process it
$fromDomain = substr( $_SERVER['SENDER'], strpos( $_SERVER['SENDER'], '@' ) + 1 );
$bugzillaHeaders = array();
foreach ($mail as $mailLine) {
    if (strpos( $mailLine, 'X-Bugzilla-' ) === 0) {
        $header = substr( $mailLine, strlen( 'X-Bugzilla-' ) );
        list( $key, $value ) = explode( ':', $header );
        $bugzillaHeaders[ trim( $key ) ] = trim( $value );
    }
    if (strlen( $mailLine ) == 0) {
        break;
    }
}

$insufficientInfo = true;
//if (isset( $bugzillaHeaders[ 'Type' ] )) {
//    $insufficientInfo = false;
//}

if ($insufficientInfo) {
    // don't know what to do with this bugmail, so save it for manual review
    file_put_contents( dirname( $_SERVER['PHP_SELF'] ) . '/' . $filename, $mailString );
    exit( 0 );
}

exit( 0 );

?>
