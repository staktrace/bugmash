// ==UserScript==
// @id bugtags@staktrace.com
// @name BugTags
// @namespace https://staktrace.com
// @author Kartikaya Gupta <kgupta@mozilla.com> https://staktrace.com/
// @version 1.0
// @description Allows you tag bugs; the tags are then shown on Bugzilla pages
// @updateURL https://staktrace.com/apps/bugmash/bugtags.user.js
// @domain staktrace.com
// @domain bugzilla.mozilla.org
// @match https://bugzilla.mozilla.org/*
// @run-at document-end
// ==/UserScript==

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

function getBugNumbers() {
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

function insertBugTags(user, bugnumbers) {
    var reqData = new FormData();
    reqData.append( "user", user );
    reqData.append( "action", "get" );
    reqData.append( "bugs", bugnumbers.join( "," ) );

    GM_xmlhttpRequest({
        method: "POST",
        url: "https://staktrace.com/apps/bugmash/tags.php",
        data: reqData,
        onload: function(res) {
            var response = res.responseJSON;
            var rows = document.getElementsByClassName( "bz_buglist" )[0].getElementsByClassName( "bz_bugitem" );
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var bugnumber = row.id.substring( 1 );
                if (response[ bugnumber ]) {
                    var cell = row.cells[ row.cells.length - 1 ];
                    cell.innerHTML = '<span style="font-size: smaller; color: blue">' + response[ bugnumber ].join( "," ) + '</span>' + cell.innerHTML;
                }
            }
        }
    });
}

var user = getUser();
if (user) {
    var bugnumbers = getBugNumbers();
    if (bugnumbers.length > 0) {
        insertBugTags( user, bugnumbers );
    }
}
