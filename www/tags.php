<?php

include_once( 'common.php' );

if (! (isset( $_POST['user'] ) && in_array( $_POST['user'], $_ME ))) {
    fail( 'Incorrect user: ' . $_POST['user'] );
}

if (! (isset( $_POST['bugs'] ) && isset( $_POST['action'] ))) {
    fail( 'Required parameters not set' );
}

$bugs = explode( ',', $_POST['bugs'] );
if (count( $bugs ) == 0) {
    fail( 'No bugs specified' );
}

// sanitize input
$bugs = array_map( 'intval', $bugs );

$_DB = new mysqli( $_MYSQL_HOST, $_MYSQL_USER, $_MYSQL_PASS, $_MYSQL_DB );
if (mysqli_connect_errno()) {
    fail( 'Error connecting to db: ' . mysqli_connect_error() );
}

if (strcmp( $_POST['action'], 'get' ) == 0) {
    $result = $_DB->query( 'SELECT * FROM tags WHERE bug=' . implode( ' OR bug=', $bugs ) );
    if (! $result) {
        fail( 'Unable to load tags' );
    }
    $tags = array();
    while ($row = $result->fetch_assoc()) {
        $tags[ $row['bug'] ][] = $row['tag'];
    }
    header( 'Content-Type: application/json' );
    print json_encode( $tags );
} else if (strcmp( $_POST['action'], 'set' ) == 0) {
    if (! isset( $_POST['tags'] )) {
        fail( 'Required parameter tags not set' );
    }
    $tagUpdates = array();
    foreach ($bugs AS $bug) {
        $tagUpdates[ $bug ] = $_POST['tags'];
    }
    updateTags( $tagUpdates );
    header( 'Content-Type: text/plain' );
    print 'OK';
} else {
    fail( 'Unknown action: ' . $_POST['action'] );
}

$_DB->close();

?>
