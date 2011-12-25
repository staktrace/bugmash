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
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div class="row" id="r%d">%s%s <a href="%s/page.cgi?id=splinter.html&bug=%d&attachment=%d">%s</a>%s</div>',
                                                $row['id'],
                                                ($row['feedback'] ? 'f' : 'r'),
                                                ($row['granted'] ? '+' : '-'),
                                                $_BASE_URL,
                                                $row['bug'],
                                                $row['attachment'],
                                                escapeHTML( $row['title'] ),
                                                (strlen( $row['comment'] ) > 0 ? ' with comments: ' . escapeHTML( $row['comment'] ) : '') ) . "\n";
    $reasons[ $row['bug'] ][] = 'review';
}

$result = loadTable( 'requests' );
while ($row = $result->fetch_assoc()) {
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div class="row" id="q%d">%sr? <a href="%s/page.cgi?id=splinter.html&bug=%d&attachment=%d">%s</a>%s</div>',
                                                $row['id'],
                                                ($row['cancelled'] ? '<strike>' : ''),
                                                $_BASE_URL,
                                                $row['bug'],
                                                $row['attachment'],
                                                escapeHTML( $row['title'] ),
                                                ($row['cancelled'] ? '</strike>' : '') ) . "\n";
    $reasons[ $row['bug'] ][] = 'request';
}

$result = loadTable( 'newbugs' );
while ($row = $result->fetch_assoc()) {
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div class="row" id="n%d">New: <a href="%s/show_bug.cgi?id=%d">%s</a></div><div class="desc">%s</div>',
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
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div class="row" id="d%d">%s: %s &rarr; %s</div>',
                                                $row['id'],
                                                escapeHTML( $row['field'] ),
                                                escapeHTML( $row['oldval'] ),
                                                escapeHTML( $row['newval'] ) ) . "\n";
    $reasons[ $row['bug'] ][] = $row['reason'];
}

$result = loadTable( 'comments' );
while ($row = $result->fetch_assoc()) {
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div class="row" id="c%d">%s <a href="%s/show_bug.cgi?id=%d#c%d">said</a>:<br/>%s</div>',
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
    $block = sprintf( '<div class="bug" id="bug%d"><div class="title"><a href="%s/show_bug.cgi?id=%d">Bug %d</a><a class="wipe" href="#">X</a></div>%s</div>',
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
body {
    font-family: sans-serif;
    font-size: 10pt;
}
.column {
    width: 25%;
    float: left;
}
.bug {
    margin: 2px;
    padding: 2px;
    border: 1px solid;
}
.row {
    border-bottom: dashed 1px;
}
.row:last-child {
    border-bottom: none;
}
div.title {
    background-color: lightblue;
    margin-bottom: 2px;
}
a.wipe {
    float: right;
}
  </style>
  <script type="text/javascript">
    function wipe(e) {
        var block = e.target;
        while (block.className != "bug") {
            block = block.parentNode;
        }
        var items = block.querySelectorAll( "div.row" );
        var ids = new Array();
        for (var i = 0; i < items.length; i++) {
            ids.push( items[i].id );
        }
        block.style.display = 'none';
        // TODO: XHR send ids to remove; on success do:
        // block.parentNode.removeChild(block);
        // on failure do:
        // block.style.display = 'block';
        // e.target.textContent = "[Error]";
    }

    document.addEventListener( "DOMContentLoaded", function() {
        var wipers = document.querySelectorAll( "a.wipe" );
        for (var i = 0; i < wipers.length; i++) {
            wipers[i].addEventListener( "click", wipe, true );
        }
    }, true );
  </script>
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
