<?php

function fail( $message ) {
    header( 'HTTP/500 Error!' );
    print $message;
    exit( 0 );
}

if (! isset( $_POST['ids'] )) {
    fail( 'Required parameter not set' );
}

include_once( $_SERVER['DOCUMENT_ROOT'] . '/../mailfilters/' . $_SERVER['SERVER_NAME'] . '/bugmash/bugmash.config.php' );

$_DB = new mysqli( $_MYSQL_HOST, $_MYSQL_USER, $_MYSQL_PASS, $_MYSQL_DB );
if (mysqli_connect_errno()) {
    fail( 'Error connecting to db: ' . mysqli_connect_error() );
}

$ids = explode( ',', $_POST['ids'] );
foreach ($ids AS $id) {
    switch ($id{0}) {
        case 'r':
            $table = 'reviews';
            break;
        case 'q':
            $table = 'requests';
            break;
        case 'n':
            $table = 'newbugs';
            break;
        case 'd':
            $table = 'changes';
            break;
        case 'c':
            $table = 'comments';
            break;
    }
    $rowId = intval( substr( $id, 1 ) );
    $_DB->query( "UPDATE {$table} SET viewed=1 WHERE id={$rowId}" );
}

$_DB->close();

?>
