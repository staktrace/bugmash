// ==UserScript==
// @id bugtags@staktrace.com
// @name BugTags
// @namespace https://staktrace.com
// @author Kartikaya Gupta <kgupta@mozilla.com> https://staktrace.com/
// @version 1.0
// @description Allows you tag bugs; the tags are then shown on Bugzilla pages
// @match https://bugzilla.mozilla.org/*
// @run-at document-end
// ==/UserScript==

var TAGS_SERVER = 'https://example.com/path/to/tags.php';    // point this to your tags.php

function getUser() {
    var links = document.links;
    for (var i = 0; i < links.length; i++) {
        if (links[i].href.indexOf( "logout" ) > 0) {
            var logoutLink = links[i];
            return logoutLink.nextSibling.textContent.trim();
        }
    }
    return null;
}

function getListBugNumbers() {
    var bugnumbers = new Array();

    var table = document.getElementsByClassName( "bz_buglist" );
    if (table.length != 1) {
        return bugnumbers;
    }
    table = table[0];

    var rows = table.getElementsByClassName( "bz_bugitem" );
    for (var i = 0; i < rows.length; i++) {
        bugnumbers.push( rows[i].id.substring( 1 ) );
    }
    return bugnumbers;
}

function getTreeBugNumbers() {
    var bugnumbers = new Array();

    var tree = document.getElementsByClassName( "tree" );
    if (tree.length != 1) {
        return bugnumbers;
    }
    tree = tree[0];

    var nodes = tree.getElementsByClassName( "summ_deep" );
    for (var i = 0; i < nodes.length; i++) {
        bugnumbers.push( nodes[i].id );
    }
    nodes = tree.getElementsByClassName( "summ" );
    for (var i = 0; i < nodes.length; i++) {
        bugnumbers.push( nodes[i].id );
    }
    return bugnumbers;
}

function buildTag( response, bugnumber ) {
    var color = 'blue';
    var tags = '+';
    if (response[ bugnumber ]) {
        tags = response[ bugnumber ].join( ", " );
        if (tags.charAt( 0 ) == '!') {
            tags = tags.substring( 1 );
            color = 'red';
        }
    }
    var tag = document.createElement( 'a' );
    tag.id = 'bugmash' + bugnumber;
    tag.href = '#';
    tag.style.fontSize = 'smaller';
    tag.style.color = color;
    tag.textContent = tags;
    tag.addEventListener( 'click', updateBugTag, false );
    return tag;
}

function insertListBugTags( user, bugnumbers ) {
    var reqData = new FormData();
    reqData.append( "user", user );
    reqData.append( "action", "get" );
    reqData.append( "bugs", bugnumbers.join( "," ) );

    GM_xmlhttpRequest({
        method: "POST",
        url: TAGS_SERVER,
        data: reqData,
        onload: function( res ) {
            var response = res.responseJSON;
            var rows = document.getElementsByClassName( "bz_buglist" )[0].getElementsByClassName( "bz_bugitem" );
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var bugnumber = row.id.substring( 1 );
                var tag = buildTag( response, bugnumber );
                var cell = row.cells[ row.cells.length - 1 ];
                cell.insertBefore( tag, cell.firstChild );
            }
        },
        onerror: function( res ) {
            GM_log( "Error fetching bug tags!" );
            GM_log( res.statusText );
            GM_log( res.responseText );
        }
    });
}

function insertTreeBugTags( user, bugnumbers ) {
    var reqData = new FormData();
    reqData.append( "user", user );
    reqData.append( "action", "get" );
    reqData.append( "bugs", bugnumbers.join( "," ) );

    GM_xmlhttpRequest({
        method: "POST",
        url: TAGS_SERVER,
        data: reqData,
        onload: function( res ) {
            var response = res.responseJSON;
            var nodes = document.getElementsByClassName( "tree" )[0].getElementsByClassName( "summ_deep" );
            for (var i = 0; i < nodes.length; i++) {
                var node = nodes[i];
                var bugnumber = node.id;
                var tag = buildTag( response, bugnumber );
                node.insertBefore( tag, node.lastElementChild );
            }
            nodes = document.getElementsByClassName( "tree" )[0].getElementsByClassName( "summ" );
            for (var i = 0; i < nodes.length; i++) {
                var node = nodes[i];
                var bugnumber = node.id;
                var tag = buildTag( response, bugnumber );
                node.insertBefore( tag, node.lastElementChild );
            }
        },
        onerror: function( res ) {
            GM_log( "Error fetching bug tags!" );
            GM_log( res.statusText );
            GM_log( res.responseText );
        }
    });
}

function updateBugTag( e ) {
    e.preventDefault();

    var bugtag = e.target;
    var bugnumber = bugtag.id.substring( 7 ); // strip "bugmash"
    var tags = bugtag.textContent;
    if (tags == '+') {
        tags = '';
    }
    var origColor = bugtag.style.color;
    if (origColor == 'red') {
        tags = '!' + tags;
    }
    tags = prompt( "Enter new tags:", tags );
    if (tags == null) {
        return false;
    }

    bugtag.style.color = 'yellow';

    var reqData = new FormData();
    reqData.append( "user", user );
    reqData.append( "action", "set" );
    reqData.append( "bugs", bugnumber );
    reqData.append( "tags", tags );

    GM_xmlhttpRequest({
        method: "POST",
        url: TAGS_SERVER,
        data: reqData,
        onload: function() {
            var color = 'blue';
            if (tags.length > 0) {
                if (tags.charAt( 0 ) == '!') {
                    tags = tags.substring( 1 );
                    color = 'red';
                }
                bugtag.textContent = tags;
            } else {
                bugtag.textContent = '+';
            }
            bugtag.style.color = color;
        },
        onerror: function() {
            bugtag.style.color = origColor;
        }
    });
}

var user = getUser();
if (user) {
    var bugnumbers = getListBugNumbers();
    if (bugnumbers.length > 0) {
        insertListBugTags( user, bugnumbers );
    }
    bugnumbers = getTreeBugNumbers();
    if (bugnumbers.length > 0) {
        insertTreeBugTags( user, bugnumbers );
    }
}
