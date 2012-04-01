<?php

include_once( 'common.php' );

$_DB = new mysqli( $_MYSQL_HOST, $_MYSQL_USER, $_MYSQL_PASS, $_MYSQL_DB );
if (mysqli_connect_errno()) {
    fail( 'Error connecting to db: ' . mysqli_connect_error() );
}

//
// handle note and tag updates
//

$stmt = $_DB->prepare( 'INSERT INTO metadata (bug, note) VALUES (?, ?) ON DUPLICATE KEY UPDATE note=VALUES(note)' );
if ($_DB->errno) fail( 'Error preparing metadata insert: ' . $_DB->error );
foreach ($_POST AS $key => $value) {
    if (strncmp( $key, 'note', 4 ) == 0) {
        $stmt->bind_param( 'is', intval( substr( $key, 4 ) ), trim( $value ) );
        $stmt->execute();
    }
}
$stmt->close();

$tagUpdates = array();
foreach ($_POST AS $key => $value) {
    if (strncmp( $key, 'tags', 4 ) == 0) {
        $tagUpdates[ intval( substr( $key, 4 ) ) ] = $value;
    }
}
updateTags( $tagUpdates );

//
// read metadata and tags
//

$meta_titles = array();
$meta_secure = array();
$meta_notes = array();
$meta_tags = array();

$result = $_DB->query( "SELECT * FROM metadata ORDER BY id ASC" );
if (! $result) {
    fail( "Unable to load metadata" );
}
while ($row = $result->fetch_assoc()) {
    if (strlen( $row['title'] ) > 0) {
        $meta_titles[ $row['bug'] ] = $row['title'];
    }
    if (strlen( $row['note'] ) > 0) {
        $meta_notes[ $row['bug'] ] = $row['note'];
    }
    if ($row['secure']) {
        $meta_secure[ $row['bug'] ] = 1;
    }
}
$result = $_DB->query( "SELECT bug, GROUP_CONCAT(tag ORDER BY id SEPARATOR ', ') AS taglist FROM tags GROUP BY bug ORDER BY id ASC" );
if (! $result) {
    fail( "Unable to load tags" );
}
while ($row = $result->fetch_assoc()) {
    $meta_tags[ $row['bug'] ] = $row['taglist'];
}

$bugsWithNotes = array_unique( array_merge( array_keys( $meta_notes ), array_keys( $meta_tags ) ) );

//
// main helpers and rendering code
//

function loadTable( $table ) {
    global $_DB;
    $result = $_DB->query( "SELECT * FROM $table WHERE viewed=0 ORDER BY stamp, id ASC" );
    if (! $result) {
        fail( "Unable to load $table" );
    }
    return $result;
}

function abbrevFlag( $flag ) {
    if ($flag == 'review') {
        return 'r';
    } else if ($flag == 'feedback') {
        return 'f';
    } else if ($flag == 'approval-mozilla-aurora') {
        return 'aurora';
    } else if ($flag == 'approval-mozilla-beta') {
        return 'beta';
    } else if ($flag == 'approval-mozilla-release') {
        return 'release';
    } else {
        return $flag;
    }
}

function stripWhitespace( $stuff ) {
    return preg_replace( '/\s/', '', $stuff );
}

function linkify( $text, $bug ) {
    global $_BASE_URL;
    $text = preg_replace( '#(https?://\S+)#i', '<a class="linkified" href="$1">$1</a>', $text );
    $text = preg_replace( '/(bug\s+)(\d+)/i', '<a class="linkified" href="' . $_BASE_URL . '/show_bug.cgi?id=$2">$1$2</a>', $text );
    $text = preg_replace( '/(bug-)(\d+)/i', '<a class="linkified" href="' . $_BASE_URL . '/show_bug.cgi?id=$2">$1$2</a>', $text );
    $text = preg_replace( '/(Attachment #?)(\d+)/i', '<a class="linkified" href="' . $_BASE_URL . '/page.cgi?id=splinter.html&bug=' . $bug . '&attachment=$2">$1$2</a>', $text );
    return $text;
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
    } else if (array_search( 'Voter', $reasons ) !== FALSE) {
        return 2;
    } else if (array_search( 'Watch', $reasons ) !== FALSE) {
        return 3;
    } else {
        return 3;
    }
}

$filterComments = array();
$filterFlags = array();
$numRows = 0;
$bblocks = array();
$columns = array();

$result = loadTable( 'reviews' );
while ($row = $result->fetch_assoc()) {
    $numRows++;
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div class="row" style="white-space: pre-line" id="r%d">%s: %s%s <a href="%s/page.cgi?id=splinter.html&bug=%d&attachment=%d">%s</a>%s</div>',
                                                $row['id'],
                                                escapeHTML( $row['author'] ),
                                                abbrevFlag( $row['flag'] ),
                                                ($row['granted'] ? '+' : '-'),
                                                $_BASE_URL,
                                                $row['bug'],
                                                $row['attachment'],
                                                escapeHTML( $row['title'] ),
                                                (strlen( $row['comment'] ) > 0 ? ' with comments: ' . escapeHTML( $row['comment'] ) : '') ) . "\n";
    $reasons[ $row['bug'] ][] = 'review';

    $filterComments[ $row['attachment'] ][] = $row['comment'];
    $filterFlags[ $row['attachment'] ][] = array( "{$row['flag']}?({$row['authoremail']})", "{$type}" . ($row['granted'] ? '+' : '-') );
}

$result = loadTable( 'requests' );
while ($row = $result->fetch_assoc()) {
    $numRows++;
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

    $filterFlags[ $row['attachment'] ][] = array( '', "{$row['flag']}?({$_ME})" );
}

$result = loadTable( 'newbugs' );
while ($row = $result->fetch_assoc()) {
    $numRows++;
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div class="row" id="n%d">New: <a href="%s/show_bug.cgi?id=%d">%s</a> by %s<br/>%s</div>',
                                                $row['id'],
                                                $_BASE_URL,
                                                $row['bug'],
                                                escapeHTML( $row['title'] ),
                                                escapeHTML( $row['author'] ),
                                                linkify( escapeHTML( $row['description'] ), $row['bug'] ) ) . "\n";
    $reasons[ $row['bug'] ][] = $row['reason'];
}

$result = loadTable( 'changes' );
while ($row = $result->fetch_assoc()) {
    $hide = false;
    // hide duplicated review flag changes (one from Type=request email and one from Type=changed email)
    if (strpos( $row['field'], 'Flags' ) !== FALSE) {
        $matches = array();
        if (preg_match( "/^Attachment #(\d+) Flags/", $row['field'], $matches ) > 0) {
            if (isset( $filterFlags[ $matches[1] ] )) {
                foreach ($filterFlags[ $matches[1] ] AS $filterFlag) {
                    if (stripWhitespace( $row['oldval'] ) == $filterFlag[0] && stripWhitespace( $row['newval'] ) == $filterFlag[1]) {
                        $hide = true;
                        break;
                    }
                }
            }
        }
    }

    $numRows++;
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div class="row"%s id="d%d">%s: %s &rarr; %s</div>',
                                                ($hide ? ' style="display: none"' : ''),
                                                $row['id'],
                                                linkify( escapeHTML( $row['field'] ), $row['bug'] ),
                                                escapeHTML( $row['oldval'] ),
                                                escapeHTML( $row['newval'] ) ) . "\n";
    $reasons[ $row['bug'] ][] = $row['reason'];
}

$result = loadTable( 'comments' );
while ($row = $result->fetch_assoc()) {
    $hide = false;
    // Hide duplicated review comments (one from Type=request email and one from Type=changed email)
    if (strpos( $row['comment'], "Review of attachment" ) !== FALSE) {
        $matches = array();
        if (preg_match( "/^Comment on attachment (\d+)\n  -->.*\n.*(\n.+)*\n\nReview of attachment \d+:\n -->.*\n--*-\n\n/", $row['comment'], $matches ) > 0) {
            if (isset( $filterComments[ $matches[1] ] )) {
                foreach ($filterComments[ $matches[1] ] AS $filterComment) {
                    // strip whitespace before comparison because sometimes the emails are formatted differently. stupid bugzilla
                    if (strpos( stripWhitespace( $row['comment'] ), stripWhitespace( $filterComment ) ) !== FALSE) {
                        $hide = true;
                        break;
                    }
                }
            }
        }
    }

    $numRows++;
    $stamp = strtotime( $row['stamp'] );
    $isTbplRobot = ($row['author'] == 'TinderboxPushlog Robot');
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div class="row" style="%s%s" id="c%d">%s <a href="%s/show_bug.cgi?id=%d#c%d">said</a>:<br/>%s</div>',
                                                ($hide ? 'display: none;' : 'white-space: pre-line;'),
                                                ($isTbplRobot ? 'opacity: 0.5;' : ''),
                                                $row['id'],
                                                escapeHTML( $row['author'] ),
                                                $_BASE_URL,
                                                $row['bug'],
                                                $row['commentnum'],
                                                linkify( escapeHTML( $row['comment'] ), $row['bug'] ) ) . "\n";
    $reasons[ $row['bug'] ][] = $row['reason'];
}

foreach ($bblocks AS $bug => &$block) {
    ksort( $block, SORT_NUMERIC );
    $touchTime = key( $block );
    $block = sprintf( '<div class="%sbug" id="bug%d"><div class="title">'
                    . '<a class="wipe" href="#">X</a>'
                    . '<a class="noteify" href="#" title="%s" onclick="return noteify(this, %d)">%s</a>'
                    . '<a href="%s/show_bug.cgi?id=%d">Bug %d</a> %s'
                    . '</div>'
                    . '<div>%s</div>'
                    . '<div class="footer"><a href="#" onclick="scrollTo(0,document.getElementById(\'bug%d\').offsetTop);return false">Back to top</a></div>'
                    . '</div>',
                      ($meta_secure[ $bug ] ? 'secure ' : ''),
                      $bug,
                      (in_array($bug, $bugsWithNotes) ? escapeHTML( $meta_notes[ $bug ] . ' | ' . $meta_tags[ $bug ] ) : ''),
                      $bug,
                      (in_array($bug, $bugsWithNotes) ? 'U' : 'N'),
                      $_BASE_URL,
                      $bug,
                      $bug,
                      escapeHTML( $meta_titles[ $bug ] ),
                      implode( "\n", $block ),
                      $bug ) . "\n";
    $columns[ column( $reasons[ $bug ] ) ][ $touchTime ] .= $block;
}
$_DB->close();

$errors = 0;
$files = scandir( $BUGMASH_DIR );
foreach ($files AS $file) {
    if (strpos( strrev( $file ), "rre." ) === 0) {
        $errors++;
    }
}

// render
header( 'Content-Type: text/html; charset=utf8' );
header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
?>
<!DOCTYPE html>
<html>
 <head>
  <title>Bugmash Dashboard (<?php echo $numRows, ' unviewed, ', $errors, ' errors'; ?>)</title>
  <base target="_blank"/>
  <style type="text/css">
@media (min-width:801px) {
    html {
        overflow-y: scroll;
    }
}
body {
    font-family: sans-serif;
    font-size: 10pt;
}
.column {
    width: 25%;
    float: left;
}
@media (max-width:800px) {
    .column {
        width: 100%;
        float: left;
    }
}
.bug {
    margin: 2px;
    padding: 2px;
    border: 1px solid;
}
.secure {
    border-color: red;
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
div.title {
    background-color: lightblue;
    margin-bottom: 2px;
    word-wrap: break-word;  /* deprecated by css3-text, but the one that firefox picks up */
    overflow-wrap: break-word; /* what i can do with the lastest version of CSS3 text */
    overflow-wrap: break-word hyphenate; /* what i really want as per old css3-text (http://www.w3.org/TR/2011/WD-css3-text-20110901/#overflow-wrap0) */
}
.secure > div.title {
    background-color: red;
    color: white;
}
a.wipe {
    float: right;
    margin-left: 3px;
    vertical-align: top;
}
a.noteify {
    float: right;
    margin-left: 3px;
    vertical-align: top;
}
div.footer {
    background-color: lightblue;
    margin-top: 2px;
}
.noteinput {
    width: 350px;
}
a.linkified {
    color: black;
    text-decoration: none;
}
a.linkified:hover {
    text-decoration: underline;
}
  </style>
  <script type="text/javascript">
    function wipe(e) {
        var block = e.target;
        while (! block.classList.contains("bug")) {
            block = block.parentNode;
        }
        var items = block.querySelectorAll( "div.row" );
        var ids = new Array();
        for (var i = 0; i < items.length; i++) {
            ids.push( items[i].id );
        }
        block.style.display = 'none';
        var xhr = new XMLHttpRequest();
        xhr.onreadystatechange = function() {
            if (xhr.readyState != 4) {
                return;
            }
            if (xhr.status == 200) {
                block.parentNode.removeChild(block);
                document.title = document.title.replace( /\d+ unviewed/, function(unviewed) { return (unviewed.split(" ")[0] - ids.length) + " unviewed"; } );
            } else {
                block.style.display = 'block';
                e.target.textContent = "[E]";
            }
        };
        var body = "ids=" + ids.join( "," );
        xhr.open( "POST", "wipe.php", true );
        xhr.setRequestHeader( "Content-Type", "application/x-www-form-urlencoded" );
        xhr.setRequestHeader( "Content-Length", body.length );
        xhr.send( body );
        e.preventDefault();
    }

    document.addEventListener( "DOMContentLoaded", function() {
        var wipers = document.querySelectorAll( "a.wipe" );
        for (var i = 0; i < wipers.length; i++) {
            wipers[i].addEventListener( "click", wipe, true );
        }
    }, true );

    function addNote( bugnumber ) {
        var notediv = document.createElement( "div" );
        notediv.className = "newnote";
        var sibling = document.getElementById( "notebuttons" );
        sibling.parentNode.insertBefore( notediv, sibling );
        notediv.innerHTML = '<span>Bug <input type="text" size="7" maxlength="10" value="' + bugnumber + '"/></span>: <input class="noteinput" type="text"/><input class="tagsinput" type="text""/>';
        if (bugnumber) {
            notediv.getElementsByTagName( "input" )[1].focus();
        } else {
            notediv.getElementsByTagName( "input" )[0].focus();
        }
    }

    function setNoteNames() {
        var newnotes = document.getElementsByClassName( "newnote" );
        while (newnotes.length > 0) {
            var newnote = newnotes[0];
            var bugnumbertext = newnote.getElementsByTagName( "input" )[0].value;
            var bugnumber = parseInt( bugnumbertext );
            if (isNaN( bugnumber )) {
                if (window.confirm( "Unable to parse " + bugnumbertext + " as a bug number; replace with 0 and continue anyway?" )) {
                    bugnumber = 0;
                } else {
                    return false;
                }
            }
            var anchor = document.createElement( "a" );
            anchor.setAttribute( "href", "<?php echo $_BASE_URL; ?>/show_bug.cgi?id=" + bugnumber );
            anchor.textContent = "Bug " + bugnumber;
            newnote.replaceChild( anchor, newnote.getElementsByTagName( "span" )[0] );
            newnote.getElementsByTagName( "input" )[0].setAttribute( "name", "note" + bugnumber );
            newnote.getElementsByTagName( "input" )[1].setAttribute( "name", "tags" + bugnumber );
            newnote.className = "note";
        }
        return true;
    }

    function noteify( linkElement, bugnumber ) {
        var notes = document.getElementsByClassName( "note" );
        // see if we can find a note already for this bug and just give it focus
        var search = "Bug " + bugnumber;
        for (var i = 0; i < notes.length; i++) {
            if (notes[i].firstChild.textContent == search) {
                notes[i].getElementsByTagName( "input" )[0].focus();
                return false;
            }
        }
        // also search through the newly-added notes that are in a different format
        notes = document.getElementsByClassName( "newnote" );
        for (var i = 0; i < notes.length; i++) {
            if (notes[i].getElementsByTagName( "input" )[0].value == bugnumber) {
                notes[i].getElementsByTagName( "input" )[1].focus();
                return false;
            }
        }
        // couldn't find it, so add a new one
        addNote( bugnumber );
        linkElement.textContent = 'U';
        return false;
    }
  </script>
 </head>
 <body>
<?php
for ($i = 0; $i < 4; $i++) {
    $buglist = $columns[$i];
    echo '  <div class="column">', "\n";
    if (count( $buglist ) > 0) {
        ksort( $buglist, SORT_NUMERIC );
        foreach ($buglist AS $time => &$block) {
            echo $block, "\n";
        }
    }
    echo '   &nbsp;';   // so that after wiping all the blocks there is still space reserved
    echo '  </div>', "\n";
}
?>
  <form onsubmit="return setNoteNames()" method="POST" target="_self" style="clear: both">
   <fieldset>
    <legend>Bug notes</legend>
<?php
foreach ($bugsWithNotes AS $bug) {
    echo sprintf( '    <div class="note"><a href="%s/show_bug.cgi?id=%d">Bug %d</a>: '
                . '<input class="noteinput" type="text" name="note%d" value="%s"/>'
                . '<input class="tagsinput" type="text" name="tags%d" value="%s"/> '
                . '%s</div>',
                  $_BASE_URL,
                  $bug,
                  $bug,
                  $bug,
                  escapeHTML( $meta_notes[ $bug ] ),
                  $bug,
                  escapeHTML( $meta_tags[ $bug ] ),
                  escapeHTML( $meta_titles[ $bug ] ) ),
        "\n";
}
?>
    <div id="notebuttons">
     <input type="button" value="Add note" onclick="addNote('')"/>
     <input type="submit" id="savenotes" value="Save notes"/>
    </div>
   </fieldset>
  </form>
  <form method="POST" action="search.php">
   <fieldset>
    <legend>Recent bug search</legend>
    <input type="text" name="q" />
    <input type="submit" value="Search" />
   </fieldset>
  </form>
 </body>
</html>
