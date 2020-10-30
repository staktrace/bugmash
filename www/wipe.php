<?php

include_once( 'common.php' );

if (! isset( $_POST['ids'] )) {
    fail( 'Required parameter not set' );
}

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
        case 'g':
            $table = 'gh_issues';
            break;
        case 'p':
            $table = 'phab_diffs';
            break;
    }
    $rowId = intval( substr( $id, 1 ) );
    $_DB->query( "UPDATE {$table} SET viewed=1 WHERE id={$rowId}" );
}

$_DB->close();

?>
