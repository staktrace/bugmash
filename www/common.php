<?php

function fail( $message ) {
    error_log( $message );
    header( 'HTTP/500 Error!' );
    print $message;
    exit( 0 );
}

$BUGMASH_DIR = $_SERVER['DOCUMENT_ROOT'] . '/../scraper';
include_once( $BUGMASH_DIR . '/config.php' );
$_GH_BASE_URL = "https://github.com/";
$_PHAB_BASE_URL = "https://phabricator.services.mozilla.com";

function updateTags( $newTags ) {
    global $_DB;

    $stmt = $_DB->prepare( 'DELETE FROM tags WHERE bug=?' );
    if ($_DB->errno) fail( 'Error preparing tag deletion: ' . $_DB->error );
    foreach ($newTags AS $bug => $tagList) {
        $stmt->bind_param( 's', $bug );
        $stmt->execute();
        if ($stmt->errno) fail( 'Error inserting to metadata: ' . $stmt->error );
    }
    $stmt->close();

    $stmt = $_DB->prepare( 'INSERT INTO tags (bug, tag) VALUES (?, ?)' );
    if ($_DB->errno) fail( 'Error preparing tag insertion: ' . $_DB->error );
    foreach ($newTags AS $bug => $tagList) {
        foreach (explode( ',', $tagList ) AS $tag) {
            $tag = trim( $tag );
            if (strlen( $tag ) > 0) {
                $stmt->bind_param( 'ss', $bug, $tag );
                $stmt->execute();
                if ($stmt->errno) fail( 'Error inserting to metadata: ' . $stmt->error );
            }
        }
    }
    $stmt->close();
}

function escapeHTML( $stuff ) {
    $stuff = str_replace( '&', '&amp;', $stuff );
    $stuff = str_replace( array( '<', '>', '"' ), array( '&lt;', '&gt;', '&quot;' ), $stuff );
    return $stuff;
}

function isGithubIssue( $bugid ) {
    return (strpos( $bugid, '#' ) !== FALSE);
}

function isGithubCommit( $bugid ) {
    return (strlen( $bugid ) - strpos( $bugid, '#' )) >= 40;
}

function isPhabDiff( $bugid ) {
    return (strval($bugid)[0] == 'D');
}

function makeBugLink( $bugid ) {
    global $_BASE_URL, $_GH_BASE_URL, $_PHAB_BASE_URL;

    if (isGithubIssue( $bugid )) {
	$type = isGithubCommit( $bugid ) ? '/commit/' : '/issues/';
	return sprintf( '<a href="%s">%s</a>',
                        $_GH_BASE_URL . str_replace( '#', $type, $bugid ),
		        $bugid );
    } else if (isPhabDiff( $bugid )) {
        return sprintf( '<a href="%s/%s">%s</a>',
		        $_PHAB_BASE_URL, $bugid, $bugid );
    } else {
        return sprintf( '<a href="%s">%s</a>',
                        $_BASE_URL . '/show_bug.cgi?id=' . $bugid,
		        "Bug " . $bugid );
    }
}

?>
