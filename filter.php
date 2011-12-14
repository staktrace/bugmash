#!/usr/local/bin/php
<?php

// directory where emails that are not bugzilla emails will be dropped
$_UNFILTERED_DIR = $_SERVER['HOME'] . '/Maildir/new';
$_ME = 'bugmail.mozilla@staktrace.com';
$_DB = null;

$mail = file( 'php://stdin', FILE_IGNORE_NEW_LINES );
$mailString = implode( "\n", $mail );
$mailText = implode( '', $mail );

$time = (isset( $_SERVER['REQUEST_TIME'] ) ? $_SERVER['REQUEST_TIME'] : time());
$filename = $time . '.' . sha1( $mailString );

if ((! isset( $_SERVER['EXTENSION'] ))
    || (! isset( $_SERVER['SENDER'] ))
    || (strcmp( $_SERVER['EXTENSION'], 'bugmash' ) != 0)
    || (strpos( $_SERVER['SENDER'], 'bugzilla-daemon@' ) !== 0))
{
    // doesn't look like a bugmail, probably spam but possible bounce notifications. toss it in maildir
    file_put_contents( $_UNFILTERED_DIR . '/' . $filename, $mailString );
    exit( 0 );
}

function fail( $message ) {
    // don't know what to do with this bugmail, so save it for manual review
    global $filename, $mailString, $_DB;
    file_put_contents( dirname( $_SERVER['PHP_SELF'] ) . '/' . $filename, $mailString );
    file_put_contents( dirname( $_SERVER['PHP_SELF'] ) . '/' . $filename . '.err', $message );
    print "$message\n";
    if ($_DB) {
        $_DB->close();
    }
    exit( 0 );
}

function success() {
    global $_DB;
    if ($_DB) {
        $_DB->close();
    }
    exit( 0 );
}

// bugmail, let's process it

// collapse headers that are wrapped to multiple lines
$merged = array();
$body = false;
for ($i = 0; $i < count( $mail ); $i++) {
    $line = $mail[$i];
    if ($body) {
        $merged[] = $line;
        continue;
    } else if (strlen( $line ) == 0) {
        $body = true;
        $merged[] = $line;
        continue;
    }
    if ($line{0} == ' ' || $line{0} == "\t") {
        $merged[ count( $merged ) - 1 ] .= ' ' . ltrim( $line );
    } else {
        $merged[] = $line;
    }
}
$mail = $merged;

$fromDomain = substr( $_SERVER['SENDER'], strpos( $_SERVER['SENDER'], '@' ) + 1 );
$bugzillaHeaders = array();
foreach ($mail as $mailLine) {
    if (strlen( $mailLine ) == 0) {
        break;
    }
    if (strpos( $mailLine, 'X-Bugzilla-' ) === 0) {
        $header = substr( $mailLine, strlen( 'X-Bugzilla-' ) );
        list( $key, $value ) = explode( ':', $header );
        $bugzillaHeaders[ strtolower( trim( $key ) ) ] = trim( $value );
    } else if (strpos( $mailLine, 'Message-ID:' ) === 0) {
        $matches = array();
        if (preg_match( '/bug-(\d+)-/', $mailLine, $matches ) > 0) {
            $bugzillaHeaders[ 'id' ] = $matches[1];
        }
    } else if (strpos( $mailLine, 'Date:' ) === 0) {
        $bugzillaHeaders[ 'date' ] = strtotime( substr( $mailLine, 5 ) );
    }
}

function getField( $key ) {
    global $bugzillaHeaders;
    $key = strtolower( $key );
    if (! isset( $bugzillaHeaders[ $key ] )) {
        fail( "No field $key" );
    }
    return $bugzillaHeaders[ $key ];
}

function normalizeReason( $reason ) {
    if (stripos( $reason, 'AssignedTo' ) !== FALSE) {
        return 'AssignedTo';
    } else if (stripos( $reason, 'CC' ) !== FALSE) {
        return 'CC';
    } else {
        fail( "Unknown reason $reason" );
    }
}

function prepare( $query ) {
    global $_DB;
    include_once( 'pass.bugdb.php' );
    $_DB = new mysqli( $_MYSQL_HOST, $_MYSQL_USER, $_MYSQL_PASS, $_MYSQL_DB );
    if (mysqli_connect_errno()) {
        fail( 'Error connecting to db: ' . mysql_connect_error() );
    }
    return $_DB->prepare( $query );
}

$bug = getField( 'id' );
$type = getField( 'type' );
$date = date( 'Y-m-d H:i:s', getField( 'date' ) );
if ($type == 'request') {
    $requestee = getField( 'flag-requestee' );
    if ($requestee != $_ME) {
        fail( 'Requestee is not me' );
    }

    $matches = array();
    if (preg_match( '/\[Attachment (\d+)\]/', $mailText, $matches ) == 0) {
        fail( 'No attachment id' );
    }
    $attachment = $matches[1];
    if (preg_match( "/Attachment $attachment: ([^\n]*)/", $mailString, $matches ) == 0) {
        fail( 'No attachment title' );
    }
    $title = $matches[1];
    $stmt = prepare( 'INSERT INTO requests (bug, stamp, attachment, title) VALUES (?, ?, ?, ?)' );
    $stmt->bind_param( 'isis', $bug, $date, $attachment, $title );
    $stmt->execute();
    if ($stmt->affected_rows != 1) {
        fail( 'Unable to insert request into DB: ' . $stmt->error );
    }
    success();
} else if ($type == 'new') {
    $reason = normalizeReason( getField( 'reason' ) );
    $matches = array();
    if (preg_match( "/Summary: (.*) Classification:/", $mailText, $matches ) == 0) {
        fail( 'No summary for new bug' );
    }
    $title = trim( $matches[1] );
    $author = getField( 'who' );
    $matches = array();
    if (preg_match( "/CC: .*\n(.*)\n--/", $mailString, $matches ) == 0) {
        fail( 'No description' );
    }
    $desc = trim( $matches[1] );
    $stmt = prepare( 'INSERT INTO newbugs (bug, stamp, reason, title, author, description) VALUES (?, ?, ?, ?, ?, ?)' );
    $stmt->bind_param( 'isssss', $bug, $date, $reason, $title, $author, $desc );
    $stmt->execute();
    if ($stmt->affected_rows != 1) {
        fail( 'Unable to insert new bug into DB: ' . $stmt->error );
    }
    success();
// else if ($type == 'changed') 
} else {
    fail( 'Unknown type' );
}

print "Fall through\n";
exit( 0 );

/*
X-Bugzilla-Assigned-To
X-Bugzilla-Changed-Fields
X-Bugzilla-Classification
X-Bugzilla-Component
X-Bugzilla-Flag-Requestee
X-Bugzilla-Keywords
X-Bugzilla-Priority
X-Bugzilla-Product
X-Bugzilla-Reason
X-Bugzilla-Severity
X-Bugzilla-Status
X-Bugzilla-Target-Milestone
X-Bugzilla-Type
X-Bugzilla-URL
X-Bugzilla-Watch-Reason
X-Bugzilla-Who
*/

?>
