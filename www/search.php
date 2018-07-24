<?php

include_once( 'common.php' );

if (! isset( $_POST['q'] )) {
    header( 'Content-Type: text/html; charset=utf8' );
    header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
    ?>
    <html>
     <head>
      <title>Bugmash Search</title>
     </head>
     <body>
      <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
       Search: <input type="text" name="q" value="" />
      </form>
     </body>
    </html>
    <?php
    exit;
}

$_DB = new mysqli( $_MYSQL_HOST, $_MYSQL_USER, $_MYSQL_PASS, $_MYSQL_DB );
if (mysqli_connect_errno()) {
    fail( 'Error connecting to db: ' . mysqli_connect_error() );
}

$meta_titles = array();

$result = $_DB->query( "SELECT bug,title FROM metadata ORDER BY id ASC" );
if (! $result) {
    fail( "Unable to load metadata" );
}
while ($row = $result->fetch_assoc()) {
    if (strlen( $row['title'] ) > 0) {
        $meta_titles[ $row['bug'] ] = $row['title'];
    }
}

$_SEARCH_COLUMNS = array(
    'requests' => array( 'title' ),
    'reviews' => array( 'title', 'author', 'authoremail', 'comment' ),
    'changes' => array( 'field', 'oldval', 'newval' ),
    'comments' => array( 'author', 'comment' ),
    'newbugs' => array( 'title', 'author', 'description' ),
    'metadata' => array( 'title' )
);

function lengthSort( $a, $b ) {
    // longest term first
    $dlen = strlen( $b ) - strlen( $a );
    return ($dlen == 0 ? strcmp( $a, $b ) : $dlen);
}

function bugMapper( $bugnumber ) {
    return "bug='$bugnumber'";
}

$terms = array_unique( preg_split( '/\s+/', $_POST['q'] ) );
usort( $terms, 'lengthSort' );

$finalBuglist = array();
$matches = array();

$firstTerm = true;
foreach ($terms AS $term) {
    $escapedTerm = $_DB->real_escape_string( $term );
    $termMapper = create_function( '$column', 'return "$column LIKE \'%' . $escapedTerm . '%\'";' );
    $bugfilter = '';
    if (! $firstTerm && count( $finalBuglist ) < 20) {
        $bugfilter = '(' . implode( ' OR ', array_map( "bugMapper", $finalBuglist ) ) . ') AND';
    }

    $buglist = array();
    foreach ($_SEARCH_COLUMNS AS $table => $columns) {
        $datefilter = '(NOW() - INTERVAL 15 DAY <= stamp) AND';
        $query = "SELECT * FROM $table WHERE $datefilter $bugfilter (" . implode( ' OR ', array_map( $termMapper, $columns ) ) . ')';
        $result = $_DB->query( $query );
        if (! $result) {
            fail( "Unable to run query: $query; error: " . $_DB->error );
        }
        while ($row = $result->fetch_assoc()) {
            $row['table'] = $table;
            $matches[] = $row;
            $buglist[] = $row['bug'];
        }
    }
    if ($firstTerm) {
        $finalBuglist = $buglist;
        $firstTerm = false;
    } else {
        $finalBuglist = array_intersect( $finalBuglist, $buglist );
    }
    if (count( $finalBuglist ) == 0) {
        break;
    }
}

// blow away bugs that didn't match all the terms
for ($i = count( $matches ) - 1; $i >= 0; $i--) {
    if (! in_array( $matches[$i]['bug'], $finalBuglist )) {
        unset( $matches[$i] );
    }
}

function union_range( &$ranges, $start, $end ) {
    for ($i = 0; $i < count( $ranges ); $i++) {
        if ($ranges[$i][0] > $end) {
            array_splice( $ranges, $i, 0, array( $start, $end ) );
            return;
        }
        if ($end >= $ranges[$i][0] && $start <= $ranges[$i][1]) {
            $start = min( $ranges[$i][0], $start );
            $end = max( $ranges[$i][1], $end );
            array_splice( $ranges, $i, 1 );
            $i--;
        }
    }
    $ranges[] = array( $start, $end );
}

function formatPreHit( $text, $start, $context ) {
    if ($start > $context) {
        return '... ' . escapeHTML( substr( $text, $start - ($context - 3), ($context - 3) ) );
    } else {
        return escapeHTML( substr( $text, 0, $start ) );
    }
}

function formatHit( $text, $range ) {
    return '<b>' . escapeHTML( substr( $text, $range[0], $range[1] - $range[0] ) ) . '</b>';
}

function formatBetweenHits( $text, $start, $end, $postContext, $preContext ) {
    if ($end - $start > $postContext + $preContext) {
        return escapeHTML( substr( $text, $start, $postContext ) ) . ' ...<br/>... ' . escapeHTML( substr( $text, $end - ($preContext - 3), ($preContext - 3) ) );
    } else {
        return escapeHTML( substr( $text, $start, $end - $start ) );
    }
}

function formatPostHit( $text, $end, $context ) {
    if (strlen( $text ) - $end > $context) {
        return escapeHTML( substr( $text, $end, ($context - 3) ) ) . ' ... ';
    } else {
        return escapeHTML( substr( $text, $end, strlen( $text ) - $end ) );
    }
}

function formatHits( $text, $terms, $isTitle ) {
    $ranges = array();
    foreach ($terms AS $term) {
        $ix = stripos( $text, $term );
        while ($ix !== FALSE) {
            union_range( $ranges, $ix, $ix + strlen( $term ) );
            $ix = stripos( $text, $term, $ix + 1 );
        }
    }
    if (count( $ranges ) == 0) {
        return false;
    }
    if ($isTitle) {
        $preContext = 100;
        $postContext = 100;
    } else {
        $preContext = 30;
        $postContext = 70;
    }
    $formatted = formatPreHit( $text, $ranges[0][0], $preContext ) . formatHit( $text, $ranges[0] );
    for ($i = 1; $i < count( $ranges ); $i++) {
        $formatted .= formatBetweenHits( $text, $ranges[$i-1][1], $ranges[$i][0], $postContext, $preContext ) . formatHit( $text, $ranges[$i] );
    }
    $formatted .= formatPostHit( $text, $ranges[ count( $ranges ) - 1 ][1], $postContext );
    return $formatted;
}

$results = array();
$timestamps = array();
foreach ($matches AS $matchRow) {
    $timestamp = strtotime( $matchRow['stamp'] );
    if (! isset( $results[ $matchRow['bug'] ] )) {
        $results[ $matchRow['bug'] ] = array();
        $timestamps[ $matchRow['bug'] ] = $timestamp;
    } else {
        $timestamps[ $matchRow['bug'] ] = max( $timestamps[ $matchRow['bug'] ], $timestamp );
    }
    foreach ($_SEARCH_COLUMNS[ $matchRow['table'] ] AS $column) {
        $hit = formatHits( $matchRow[ $column ], $terms, false );
        if ($hit) {
            $results[ $matchRow['bug'] ][] = $hit;
        }
    }
}

if (! arsort( $timestamps, SORT_NUMERIC )) {
    fail( "Unable to sort timestamps" );
}

header( 'Content-Type: text/html; charset=utf8' );
header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
?>
<!DOCTYPE html>
<html>
 <head>
  <title>Bugmash Search Results - <?php echo escapeHTML( $_POST['q'] ); ?></title>
  <base target="_blank"/>
  <style type="text/css">
.bug {
    margin: 2px;
    padding: 2px;
    border: 1px solid;
}
div.title {
    background-color: lightblue;
    margin-bottom: 2px;
    word-wrap: break-word;  /* deprecated by css3-text, but the one that firefox picks up */
    overflow-wrap: break-word; /* what i can do with the lastest version of CSS3 text */
    overflow-wrap: break-word hyphenate; /* what i really want as per old css3-text (http://www.w3.org/TR/2011/WD-css3-text-20110901/#overflow-wrap0) */
}
.row {
    border-bottom: dashed 1px;
    word-wrap: break-word;  /* deprecated by css3-text, but the one that firefox picks up */
    overflow-wrap: break-word; /* what i can do with the lastest version of CSS3 text */
    overflow-wrap: break-word hyphenate; /* what i really want as per old css3-text (http://www.w3.org/TR/2011/WD-css3-text-20110901/#overflow-wrap0) */
}
.row:last-child {
    border-bottom: none;
}
  </style>
 </head>
 <body>
<?php
foreach ($timestamps AS $bug => $stamp) {
    $hits = $results[ $bug ];
    echo '  <div class="bug">', "\n";
    $formattedTitle = formatHits( $meta_titles[ $bug ], $terms, true );
    if (! $formattedTitle) {
        $formattedTitle = escapeHTML( $meta_titles[ $bug ] );
    }
    echo sprintf( '   <div class="title"><a href="%s/show_bug.cgi?id=%d">Bug %d</a> %s</div>',
                  $_BASE_URL,
                  $bug,
                  $bug,
                  $formattedTitle ), "\n";
    foreach ($hits AS $hit) {
        echo '   <div class="row">', $hit, '</div>', "\n";
    }
    echo '  </div>', "\n";
}
?>
 </body>
</html>
