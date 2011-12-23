<?php

include_once( 'pass.bugdb.php' );
$_DB = new mysqli( $_MYSQL_HOST, $_MYSQL_USER, $_MYSQL_PASS, $_MYSQL_DB );
if (mysqli_connect_errno()) {
    fail( 'Error connecting to db: ' . mysql_connect_error() );
}

function loadTable( $table ) {
    global $_DB;
    $result = $_DB->query( "SELECT * FROM $table ORDER BY stamp, id ASC" );
    if (! $result) {
        fail( "Unable to load $table" );
    }
    return $result;
}

$_BASE_URL = 'https://bugzilla.mozilla.org';

function escapeHTML( $stuff ) {
    $stuff = str_replace( '&', '&amp;', $stuff );
    $stuff = str_replace( array( '<', '>', '"' ), array( '&lt;', '&gt;', '&quot;' ), $stuff );
    return $stuff;
}

function column( &$reasons ) {
    if (array_search( 'review', $reasons ) !== FALSE) {
        return 0;
    } else if (array_search( 'request', $reasons ) !== FALSE) {
        return 0;
    } else if (array_search( 'AssignedTo', $reasons ) !== FALSE) {
        return 1;
    } else if (array_search( 'Reporter', $reasons ) !== FALSE) {
        return 1;
    } else if (array_search( 'CC', $reasons ) !== FALSE) {
        return 2;
    } else if (array_search( 'Watch', $reasons ) !== FALSE) {
        return 3;
    } else {
        return 3;
    }
}

$result = loadTable( 'reviews' );
while ($row = $result->fetch_assoc()) {
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div id="r%d">r%s <a href="%s/attachment.cgi?id=%d&action=edit">%s</a></div>',
                                                $row['id'],
                                                ($row['granted'] ? '+' : '-'),
                                                $_BASE_URL,
                                                $row['attachment'],
                                                escapeHTML( $row['title'] ) ) . "\n";
    $reasons[ $row['bug'] ][] = 'review';
}

$result = loadTable( 'requests' );
while ($row = $result->fetch_assoc()) {
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div id="q%d">r? <a href="%s/attachment.cgi?id=%d&action=edit">%s</a> [<a href="%s/attachment.cgi?id=%d&action=diff">Diff</a>]</div>',
                                                $row['id'],
                                                $_BASE_URL,
                                                $row['attachment'],
                                                escapeHTML( $row['title'] ),
                                                $_BASE_URL,
                                                $row['attachment'] ) . "\n";
    $reasons[ $row['bug'] ][] = 'request';
}

$result = loadTable( 'newbugs' );
while ($row = $result->fetch_assoc()) {
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div id="n%d">New: <a href="%s/show_bug.cgi?id=%d">%s</a></div><div class="desc">%s</div>',
                                                $row['id'],
                                                $_BASE_URL,
                                                $row['bug'],
                                                escapeHTML( $row['title'] ),
                                                escapeHTML( $row['description'] ) ) . "\n";
    $reasons[ $row['bug'] ][] = $row['reason'];
}

$result = loadTable( 'changes' );
while ($row = $result->fetch_assoc()) {
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div id="d%d">%s: %s &rarr; %s</div>',
                                                $row['id'],
                                                escapeHTML( $row['field'] ),
                                                escapeHTML( $row['oldval'] ),
                                                escapeHTML( $row['newval'] ) ) . "\n";
    $reasons[ $row['bug'] ][] = $row['reason'];
}

$result = loadTable( 'comments' );
while ($row = $result->fetch_assoc()) {
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div id="c%d">%s <a href="%s/show_bug.cgi?id=%d#c%d">said</a>:<br/>%s</div>',
                                                $row['id'],
                                                escapeHTML( $row['author'] ),
                                                $_BASE_URL,
                                                $row['bug'],
                                                $row['commentnum'],
                                                escapeHTML( $row['comment'] ) ) . "\n";
    $reasons[ $row['bug'] ][] = $row['reason'];
}

foreach ($bblocks AS $bug => &$block) {
    ksort( $block, SORT_NUMERIC );
    $touchTime = key( $block );
    $block = sprintf( '<div id="bug%d"><div class="title"><a href="%s/show_bug.cgi?id=%d">Bug %d</a></div>%s</div>',
                      $bug,
                      $_BASE_URL,
                      $bug,
                      $bug,
                      implode( "\n", $block ) ) . "\n";
    $columns[ column( $reasons[ $bug ] ) ][ $touchTime ] .= $block;
}
$_DB->close();

// render
?>
<!DOCTYPE html>
<html>
 <head>
  <title>Bugmash Dashboard</title>
  <style type="text/css">
.column {
    width: 25%;
    float: left;
    border: 1px solid;
}
  </style>
 </head>
 <body>
<?php
foreach ($columns AS $column => &$buglist) {
    ksort( $buglist, SORT_NUMERIC );
    echo '  <div class="column">', "\n";
    foreach ($buglist AS $time => &$block) {
        echo $block, "\n";
    }
    echo '  </div>', "\n";
}
?>
 </body>
</html>
