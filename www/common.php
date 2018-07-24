<?php

if (get_magic_quotes_gpc()) {
    foreach ($_POST AS $key => $value) {
        $_POST[$key] = stripslashes($value);
    }
    foreach ($_GET AS $key => $value) {
        $_GET[$key] = stripslashes($value);
    }
    foreach ($_COOKIE AS $key => $value) {
        $_COOKIE[$key] = stripslashes($value);
    }
}

function fail( $message ) {
    header( 'HTTP/500 Error!' );
    print $message;
    exit( 0 );
}

$BUGMASH_DIR = $_SERVER['DOCUMENT_ROOT'] . '/../scraper';
include_once( $BUGMASH_DIR . '/config.php' );

function updateTags( $newTags ) {
    global $_DB;

    $stmt = $_DB->prepare( 'DELETE FROM tags WHERE bug=?' );
    if ($_DB->errno) fail( 'Error preparing tag deletion: ' . $_DB->error );
    foreach ($newTags AS $bug => $tagList) {
        $stmt->bind_param( 'i', $bug );
        $stmt->execute();
    }
    $stmt->close();

    $stmt = $_DB->prepare( 'INSERT INTO tags (bug, tag) VALUES (?, ?)' );
    if ($_DB->errno) fail( 'Error preparing tag insertion: ' . $_DB->error );
    foreach ($newTags AS $bug => $tagList) {
        foreach (explode( ',', $tagList ) AS $tag) {
            $tag = trim( $tag );
            if (strlen( $tag ) > 0) {
                $stmt->bind_param( 'is', $bug, $tag );
                $stmt->execute();
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

?>
