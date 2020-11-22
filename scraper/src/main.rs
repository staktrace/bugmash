extern crate mailparse;
#[macro_use]
extern crate mysql;
extern crate regex;
extern crate victoria_dom;

use std::collections::hash_map::DefaultHasher;
use std::env;
use std::fs;
use std::fs::File;
use std::hash::Hasher;
use std::io::{Read, stdin, Write};
use std::process;
use std::time::{SystemTime, UNIX_EPOCH};

use mailparse::{dateparse, MailHeaderMap, MailParseError, ParsedMail};

use regex::Regex;

use victoria_dom::DOM;

fn save_file(data: &[u8]) -> String {
    let time = SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0);
    let mut hasher = DefaultHasher::new();
    hasher.write(data);
    let uniq_name = format!("{}.{:x}", time, hasher.finish());
    {
        let mut file = File::create(&uniq_name).unwrap();
        file.write(data).ok();
    }
    uniq_name
}

fn fail(data_file: &str, msg: &str, err: String) -> ! {
    let err_name = format!("{}.err", data_file);
    {
        let mut file = File::create(err_name).unwrap();
        writeln!(file, "{}", msg).ok();
        writeln!(file, "{:?}", err).ok();
    }
    process::exit(0);
}

fn get_db() -> Result<mysql::Pool, String> {
    let mut mycnf = match env::home_dir() {
        Some(path) => path,
        None => return Err("Home dir not found".to_string()),
    };
    mycnf.push(".bugmash");
    mycnf.push("scraper.mysql.cnf");
    let mut file = File::open(mycnf).map_err(|e| format!("{:?}", e))?;
    let mut connstr = String::new();
    let _ = file.read_to_string(&mut connstr).map_err(|e| format!("{:?}", e))?;
    mysql::Pool::new(connstr).map_err(|e| format!("{:?}", e))
}

fn get_body_with_type(mail: &ParsedMail, body_type: &'static str) -> Result<Option<String>, MailParseError> {
    if mail.ctype.mimetype == body_type {
        return mail.get_body().map(Some);
    }
    for subpart in &mail.subparts {
        if let Some(x) = get_body_with_type(subpart, body_type)? {
            return Ok(Some(x));
        }
    }
    Ok(None)
}

fn get_plain_body(mail: &ParsedMail) -> Result<Option<String>, MailParseError> {
    get_body_with_type(mail, "text/plain")
}

fn get_html_body(mail: &ParsedMail) -> Result<Option<String>, MailParseError> {
    get_body_with_type(mail, "text/html")
}

fn url_parts(footer: String) -> Option<(String, String, Option<String>)> {
    let prefix = "https://github.com/";
    let types = ["/issues/", "/pull/", "/commit/"];
    let hash = "#";
    let slash = "/";

    let repo_ix = footer.find(prefix)? + prefix.len();
    let mut issues_ix = None;
    let mut issues_len = None;
    for t in types.iter() {
        match footer[repo_ix..].find(t) {
            Some(x) => {
                issues_ix = Some(x);
                issues_len = Some(t.len());
                break;
            }
            None => continue,
        }
    }
    let issues_ix = issues_ix? + repo_ix;
    let issues_len = issues_len.unwrap();
    let issuenum_ix = issues_ix + issues_len;
    let hash_ix = footer[issuenum_ix..].find(hash).or_else(|| footer[issuenum_ix..].find(slash)).map(|ix| ix + issuenum_ix);
    let end_ix = footer[repo_ix..].find("\n").map(|ix| ix + repo_ix).unwrap_or(footer.len());
    let hash = match hash_ix {
        Some(ix) => Some(String::from(footer[ix..end_ix].trim())),
        None => None,
    };

    return Some((String::from(&footer[repo_ix..issues_ix]),
                 String::from(footer[issuenum_ix..hash_ix.unwrap_or(end_ix)].trim()),
                 hash));
}

fn split_footer(msg: &str) -> (String, Option<String>) {
    // Avoid trying to match newlines directly since they can be either \r\n or \n
    let ix = msg.rfind("You are receiving this because")
                .and_then(|ix| msg[0..ix].rfind("-- "));
    match ix {
        Some(ix) => (String::from(&msg[0..ix]), Some(String::from(&msg[ix..]))),
        None => (String::from(msg), None),
    }
}

fn first_github_url(body: &str) -> Option<(String, String, Option<String>)> {
    let prefix = "https://github.com/";
    let ix = body.find(prefix)? + prefix.len();
    let end_ix = ix + body[ix..].find(char::is_whitespace)?;
    let urlpath = &body[ix..end_ix];
    let slash = "/";
    let org_end_ix = urlpath.find(slash)?;
    let repo_end_ix = org_end_ix + urlpath[org_end_ix..].find(slash)?;
    Some((String::from(&urlpath[0..repo_end_ix]), String::from(&urlpath[repo_end_ix + 1..]), None))
}

fn scrape_github_mail(mail: &ParsedMail) -> Result<(), String> {
    let mut title = mail.headers.get_first_value("Subject").map_err(|e| format!("{:?}", e))?.unwrap_or("(no subject)".to_string());
    title = title.trim_start_matches("Re: ").to_string();
    if title.starts_with("[") {
        if let Some(close_bracket) = title.find("]") {
            title = title[close_bracket + 1..].trim().to_string();
        }
    }
    if title.ends_with(")") {
        if let Some(issue_start) = title.rfind("(#") {
            title = title[0..issue_start].trim().to_string();
        }
    }

    let sender = mail.headers.get_first_value("X-GitHub-Sender").map_err(|e| format!("{:?}", e))?.unwrap_or("Unknown".to_string());
    let reason = match mail.headers.get_first_value("X-GitHub-Reason").map_err(|e| format!("{:?}", e))? {
        Some(ref s) if s == "review_requested" => "review",
        Some(ref s) if s == "ci_activity" => "review",
        Some(ref s) if s == "author" => "Reporter",
        Some(ref s) if s == "mention" => "CC",
        _ => "Watch",
    };
    let stamp = mail.headers.get_first_value("Date").map_err(|e| format!("{:?}", e))?.map(|v| dateparse(&v).unwrap_or(0)).unwrap_or(0);
    let plain_body = match get_plain_body(mail).map_err(|e| format!("{:?}", e))? {
        Some(x) => x,
        None => return Err("No plaintext body found".to_string()),
    };
    let (comment, footer) = split_footer(&plain_body);
    let footer = footer.ok_or("Unable to find footer".to_string())?;
    let (repo, issue, hash) = url_parts(footer)
        .or_else(|| first_github_url(&plain_body))
        .ok_or("Unable to extract URL parts".to_string())?;
    let hash = hash.unwrap_or(String::from(""));

    let id = format!("{}#{}", repo, issue);
    let db = get_db()?;
    db.prep_exec(r#"INSERT INTO metadata (bug, stamp, title, secure, note)
                                 VALUES (:id, FROM_UNIXTIME(:stamp), :title, 0, "")
                                 ON DUPLICATE KEY UPDATE stamp=VALUES(stamp), title=VALUES(title)"#, params! {
        id,
        stamp,
        title,
    }).map_err(|e| format!("{:?}", e))?;

    let result = db.prep_exec(r#"INSERT INTO gh_issues (repo, issue, stamp, reason, hash, author, comment)
                                 VALUES (:repo, :issue, FROM_UNIXTIME(:stamp), :reason, :hash, :sender, :comment)"#, params! {
        repo,
        issue,
        stamp,
        reason,
        hash,
        sender,
        comment,
    }).map_err(|e| format!("{:?}", e))?;
    if result.affected_rows() != 1 {
        return Err(format!("Affected row count for gh_issues was {}, not 1", result.affected_rows()));
    }

    Ok(())
}

fn mail_header(mail: &ParsedMail, header: &str) -> Result<Option<String>, String> {
    mail.headers.get_first_value(header).map_err(|e| format!("Unable to read mail header: {:?}", e))
}

fn bugmail_header(mail: &ParsedMail, header: &str) -> Result<Option<String>, String> {
    mail_header(mail, &format!("X-Bugzilla-{}", header))
}

fn bugzilla_normalized_reason(mail: &ParsedMail) -> Result<String, String> {
    let reason = bugmail_header(mail, "Reason")?.ok_or("Unable to find Reason header")?;
    let watch_reason = bugmail_header(mail, "Watch-Reason")?;
    if reason.find("AssignedTo").is_some() {
        Ok("AssignedTo".to_string())
    } else if reason.find("Reporter").is_some() {
        Ok("Reporter".to_string())
    } else if reason.find("CC").is_some() {
        Ok("CC".to_string())
    } else if reason.find("Voter").is_some() {
        Ok("Voter".to_string())
    } else if reason.find("None").is_some() {
        if watch_reason.is_some() {
            Ok("Watch".to_string())
        } else {
            Err(format!("Empty watch reason with reason {}", reason))
        }
    } else {
        Err(format!("Unrecognized reason {}", reason))
    }
}

fn insert_changes(db: &mysql::Pool, id: &str, stamp: i64, reason: &str, changes: &[(String, String, String)]) -> Result<(), String> {
    let mut stmt = db.prepare(r#"INSERT INTO changes (bug, stamp, reason, field, oldval, newval)
            VALUES (:id, FROM_UNIXTIME(:stamp), :reason, :field, :oldval, :newval)"#).map_err(|e| format!("{:?}", e))?;
    for (field, oldval, newval) in changes {
        stmt.execute(params!{
            id,
            stamp,
            reason,
            field,
            oldval,
            newval
        }).map_err(|e| format!("{:?}", e))?;
    }
    Ok(())
}

fn insert_comments(db: &mysql::Pool, id: &str, stamp: i64, reason: &str, comments: &[(i32, String, String)]) -> Result<(), String> {
    let mut stmt = db.prepare(r#"INSERT INTO comments (bug, stamp, reason, commentnum, author, comment)
            VALUES (:id, FROM_UNIXTIME(:stamp), :reason, :commentnum, :author, :comment)"#).map_err(|e| format!("{:?}", e))?;
    for (commentnum, author, comment) in comments {
        stmt.execute(params!{
            id,
            stamp,
            reason,
            commentnum,
            author,
            comment
        }).map_err(|e| format!("{:?}", e))?;
    }
    Ok(())
}

fn bugmail_body(mail: &ParsedMail) -> Result<DOM, String> {
    let body = match get_html_body(mail).map_err(|e| format!("{:?}", e))? {
        Some(x) => x,
        None => return Err("No html body found".to_string()),
    };
    Ok(DOM::new(&body))
}

fn bugmail_body_text(mail: &ParsedMail) -> Result<String, String> {
    get_plain_body(mail)
        .map_err(|e| format!("{:?}", e))?
        .map(|t| t.replace("\r", ""))
        .ok_or("No plaintext body found".to_string())
}

fn scrape_bugmail_request(mail: &ParsedMail, db: &mysql::Pool, id: &str, stamp: i64) -> Result<(), String> {
    let requestee = bugmail_header(mail, "Flag-Requestee")?;
    let flag = mail_header(mail, "Subject")?
        .and_then(|s| s.split_ascii_whitespace().next().map(str::to_string))
        .ok_or("Got a request type bugmail with no subject, not implemented yet".to_string())?;
    if requestee.is_none() {
        let action = mail_header(mail, "Subject")?
            .and_then(|s| s.split_ascii_whitespace().nth(1).map(str::to_string))
            .ok_or("Got a request type bugmail with no requestee and unexpected subject".to_string())?;
        if action.starts_with("canceled") {
            db.prep_exec(r#"UPDATE requests SET cancelled=1 WHERE bug=:id and attachment=:attachment AND flag=:flag"#, params! {
                id,
                "attachment" => 0,
                flag
            }).map_err(|e| format!("{:?}", e))?;
            return Ok(());
        }
        if action.starts_with("granted:") {
            let author_email = bugmail_header(mail, "Who")?;
            db.prep_exec(r#"INSERT INTO reviews (bug, stamp, attachment, title, flag, author, authoremail, granted, comment)
                            VALUES (:id, FROM_UNIXTIME(:stamp), :attachment, :title, :flag, :author, :author_email, :granted, :comment)"#, params! {
                id,
                stamp,
                "attachment" => 0,
                "title" => "",
                flag,
                "author" => "",
                author_email,
                "granted" => 1,
                "comment" => "",
            }).map_err(|e| format!("{:?}", e))?;
            return Ok(());
        }
        if action == "not" {
            let author_email = bugmail_header(mail, "Who")?;
            db.prep_exec(r#"INSERT INTO reviews (bug, stamp, attachment, title, flag, author, authoremail, granted, comment)
                            VALUES (:id, FROM_UNIXTIME(:stamp), :attachment, :title, :flag, :author, :author_email, :granted, :comment)"#, params! {
                id,
                stamp,
                "attachment" => 0,
                "title" => "",
                flag,
                "author" => "",
                author_email,
                "granted" => 0,
                "comment" => "",
            }).map_err(|e| format!("{:?}", e))?;
            return Ok(());
        }
        return Err("Got a request type bugmail with no requestee, not implemented yet".to_string());
    }
    db.prep_exec(r#"INSERT INTO requests (bug, stamp, attachment, title, flag)
                    VALUES (:id, FROM_UNIXTIME(:stamp), :attachment, :title, :flag)"#, params! {
        id,
        stamp,
        "attachment" => 0,
        "title" => "",
        flag
    }).map_err(|e| format!("{:?}", e))?;
    Ok(())
}

fn scrape_bugmail_newbug(mail: &ParsedMail, db: &mysql::Pool, id: &str, stamp: i64) -> Result<(), String> {
    let reason = bugzilla_normalized_reason(mail)?;
    let dom = bugmail_body(mail)?;
    let mut title = None;
    let author = bugmail_header(mail, "Who")?.ok_or("Couldn't find new bug author".to_string())?;

    let body = bugmail_body_text(mail)?;
    let body_re = Regex::new(r"(?s)Bug ID: .*?\n\n(.*\n\n)?-- \n").unwrap();
    let body_m = body_re.captures(&body).ok_or("Unable to capture new bug description".to_string())?;
    let description = body_m.get(1).map_or("", |m| m.as_str().trim());

    let mut tr_opt = dom.at("div.new table tr");
    while let Some(tr) = tr_opt {
        let field = tr.at("td.c1")
            .map(|d| d.text_all())
            .ok_or("Couldn't find new bug details".to_string())?;
        if field.contains("Summary") {
            title = tr.at("td.c2").map(|d| d.text_all());
            break;
        }
        tr_opt = tr.next();
    }
    let title = title.ok_or("Couldn't find bug summary in new bug details table".to_string())?;

    db.prep_exec(r#"INSERT INTO newbugs (bug, stamp, reason, title, author, description)
                    VALUES (:id, FROM_UNIXTIME(:stamp), :reason, :title, :author, :description)"#, params! {
        id,
        stamp,
        "reason" => &reason,
        title,
        author,
        description
    }).map_err(|e| format!("{:?}", e))?;

    let changes = scrape_change_table(&dom, "")?;
    insert_changes(db, id, stamp, &reason, &changes)?;

    Ok(())
}

fn scrape_change_table(dom: &DOM, field_prefix: &str) -> Result<Vec<(String, String, String)>, String> {
    let mut changes = Vec::new();
    let mut change_row = dom.at("div.diffs tr.head").and_then(|d| d.next());
    while let Some(tr) = change_row {
        let field = tr.at("td.c1")
            .map(|d| d.text_all())
            .ok_or("Couldn't find bug change table field".to_string())?;
        let old = tr.at("td.c2")
            .map(|d| d.text_all())
            .ok_or("Couldn't find bug change table old value".to_string())?;
        let new = tr.at("td.c2")
            .and_then(|d| d.next())
            .map(|d| d.text_all())
            .ok_or("Couldn't find bug change table new value".to_string())?;
        changes.push((format!("{}{}", field_prefix, field), old, new));
        change_row = tr.next();
    }
    Ok(changes)
}

fn scrape_bugmail_depchange(mail: &ParsedMail, db: &mysql::Pool, id: &str, stamp: i64) -> Result<(), String> {
    let reason = bugzilla_normalized_reason(mail)?;
    let dom = bugmail_body(mail)?;
    let depbug_text = dom.at("body > b").map(|d| d.text_all()).ok_or("Unable to find bug dependency sentence")?;
    let depbug_re = Regex::new(r"(?i)bug (\d+) depends on bug(?: |&nbsp;)(\d+), which changed state.").unwrap();
    let depbug_m = depbug_re.captures(&depbug_text).ok_or("Dependency sentence didn't match expected regex".to_string())?;
    if &depbug_m[1] != id {
        return Err("Dependency sentence referred to wrong bug id".to_string());
    }

    let depbug = &depbug_m[2];
    let changes = scrape_change_table(&dom, &format!("depbug-{}-", depbug))?;
    insert_changes(db, id, stamp, &reason, &changes)
}

fn scrape_comments(mail: &ParsedMail) -> Result<Vec<(i32, String, String)>, String> {
    let mut comment_tuples = Vec::new();
    let body = bugmail_body_text(mail)?;
    let body_re = Regex::new(r"(?sU)-- Comment #(\d+) from ([^\n]*) ---\n(.*)\n\n--").unwrap();
    for capture in body_re.captures_iter(&body) {
        let comment_num = &capture[1];
        let mut author = String::from(&capture[2]);
        let comment_text = &capture[3];
        let stripped_len = author.len() - " YYYY-mm-dd HH:ii:ss ZZZ".len();
        author.truncate(stripped_len);
        if let Some(idx) = author.find('<') {
            author.truncate(idx);
        }
        comment_tuples.push((comment_num.parse::<i32>().map_err(|e| format!("{:?}", e))?, author, comment_text.to_string()));
    }
    Ok(comment_tuples)
}

fn scrape_bugmail_change(mail: &ParsedMail, db: &mysql::Pool, id: &str, stamp: i64) -> Result<(), String> {
    let reason = bugzilla_normalized_reason(mail)?;
    let dom = bugmail_body(mail)?;

    let changes = scrape_change_table(&dom, "")?;
    insert_changes(db, id, stamp, &reason, &changes)?;

    let comments = scrape_comments(mail)?;
    insert_comments(db, id, stamp, &reason, &comments)?;

    if changes.len() == 0 && comments.len() == 0 {
        return Err("Unable to extract meaningful data from changed email".to_string());
    }
    Ok(())
}

fn scrape_bugzilla_mail(bz_type: &str, mail: &ParsedMail) -> Result<(), String> {
    if bz_type == "nag" {
        return Ok(());
    }

    let secure = bugmail_header(mail, "Secure-Email")?.is_some();
    let id = bugmail_header(mail, "ID")?.ok_or("Unable to find bug id".to_string())?;
    let stamp = mail_header(mail, "Date")?.map(|v| dateparse(&v).unwrap_or(0)).unwrap_or(0);

    let subject = mail_header(mail, "Subject")?.ok_or("Unable to find subject header".to_string())?;
    let subject_re = Regex::new(r"(?sU)\[Bug \d+\] (.*)( : \[Attachment.*)?$").unwrap();
    let subject_m = subject_re.captures(&subject).ok_or("Subject header didn't match expected regex".to_string())?;
    let title = &subject_m[1];

    let db = get_db()?;
    db.prep_exec(r#"INSERT INTO metadata (bug, stamp, title, secure, note)
                                 VALUES (:id, FROM_UNIXTIME(:stamp), :title, :secure, "")
                                 ON DUPLICATE KEY UPDATE stamp=VALUES(stamp), title=VALUES(title), secure=VALUES(secure)"#, params! {
        "id" => &id,
        stamp,
        title,
        secure,
    }).map_err(|e| format!("{:?}", e))?;

    if bz_type == "request" {
        scrape_bugmail_request(mail, &db, &id, stamp)
    } else if secure {
        // you haven't set a PGP/GPG key and this is for a secure bug, so there's no data in it.
        let reason = bugzilla_normalized_reason(mail)?;
        let unknown = "Unknown";
        let changes = vec![ (unknown.to_string(), unknown.to_string(), unknown.to_string()) ];
        insert_changes(&db, &id, stamp, &reason, &changes)?;
        Ok(())
    } else if bz_type == "new" {
        scrape_bugmail_newbug(mail, &db, &id, stamp)
    } else if bz_type == "dep_changed" {
        scrape_bugmail_depchange(mail, &db, &id, stamp)
    } else if bz_type == "changed" {
        scrape_bugmail_change(mail, &db, &id, stamp)
    } else {
        Err(format!("Unknown bugmail type {}", bz_type))
    }
}

fn scrape_phabricator_mail(mail: &ParsedMail) -> Result<(), String> {
    let stamp = mail_header(mail, "Date")?.map(|v| dateparse(&v).unwrap_or(0)).unwrap_or(0);

    let stamps = mail_header(mail, "X-Phabricator-Stamps")?.ok_or("Unable to get stamps header".to_string())?;
    let actor_re = Regex::new(r"actor\((.*?)\)").unwrap();
    let actor_m = actor_re.captures(&stamps).ok_or("Stamps header didn't match actor regex".to_string())?;
    let actor = &actor_m[1];

    let reason = match &stamps {
        s if s.contains("reviewer(@kats)") => "review",
        s if s.contains("author(@kats)") => "Reporter",
        _ => "CC",
    };

    let mut plain_body = match get_plain_body(mail).map_err(|e| format!("{:?}", e))? {
        Some(x) => x,
        None => return Err("No plaintext body found".to_string()),
    };
    if let Some(ix) = plain_body.find("\nREVISION DETAIL") {
        plain_body = plain_body[0..ix].to_string();
    }

    let subject = mail_header(mail, "Subject")?.ok_or("Unable to find subject header".to_string())?;

    let mut bugzilla = false;
    let (phab, title) = if stamps.contains("application(Diffusion)") {
        let subject_re = Regex::new(r"Diffusion[^:]*:(.*)").unwrap();
        let subject_m = subject_re.captures(&subject).ok_or("Subject header didn't match diffusion regex".to_string())?;
        let diff_re = Regex::new(r"Differential Revision: https://phabricator.services.mozilla.com/(D\d+)").unwrap();
        if let Some(diff_m) = diff_re.captures(&plain_body) {
            (diff_m[1].to_string(), subject_m[1].to_string())
        } else {
            let bug_re = Regex::new(r"(?i)bug (\d+)").unwrap();
            let bug_m = bug_re.captures(&subject_m[1]).ok_or("Unrecognized diffusion email type".to_string())?;
            bugzilla = true;
            if let Some(ix) = plain_body.find("\nBRANCHES") {
                plain_body = plain_body[0..ix].to_string();
            }
            (bug_m[1].to_string(), subject_m[1].to_string())
        }
    } else {
        let subject_re = Regex::new(r"Differential.* (D\d+): (.*)").unwrap();
        let subject_m = subject_re.captures(&subject).ok_or("Subject header didn't match differential regex".to_string())?;
        (subject_m[1].to_string(), subject_m[2].to_string())
    };

    let db = get_db()?;
    if bugzilla {
        // Don't update anything on duplicate key, leave bugmail as source of truth
        db.prep_exec(r#"INSERT IGNORE INTO metadata (bug, stamp, title, secure, note)
                                     VALUES (:id, FROM_UNIXTIME(:stamp), :title, :secure, "")"#, params! {
            "id" => &phab,
            stamp,
            "title" => &title,
            "secure" => false,
        }).map_err(|e| format!("{:?}", e))?;
        let result = db.prep_exec(r#"INSERT INTO comments (bug, stamp, reason, commentnum, author, comment)
                        VALUES (:id, FROM_UNIXTIME(:stamp), :reason, 0, :actor, :comment)"#, params! {
            "id" => &phab,
            stamp,
            reason,
            actor,
            "comment" => &plain_body,
        }).map_err(|e| format!("{:?}", e))?;
        if result.affected_rows() != 1 {
            return Err(format!("Affected row count for comments was {}, not 1", result.affected_rows()));
        }
    } else {
        db.prep_exec(r#"INSERT INTO metadata (bug, stamp, title, secure, note)
                                     VALUES (:id, FROM_UNIXTIME(:stamp), :title, :secure, "")
                                     ON DUPLICATE KEY UPDATE stamp=VALUES(stamp), title=VALUES(title), secure=VALUES(secure)"#, params! {
            "id" => &phab,
            stamp,
            "title" => &title,
            "secure" => false,
        }).map_err(|e| format!("{:?}", e))?;
        let result = db.prep_exec(r#"INSERT INTO phab_diffs (revision, stamp, reason, author, comment)
                                     VALUES (:revision, FROM_UNIXTIME(:stamp), :reason, :actor, :comment)"#, params! {
            "revision" => &phab,
            stamp,
            reason,
            actor,
            "comment" => &plain_body,
        }).map_err(|e| format!("{:?}", e))?;
        if result.affected_rows() != 1 {
            return Err(format!("Affected row count for phab_diffs was {}, not 1", result.affected_rows()));
        }
    }

    Ok(())
}

fn main() {
    let mut input = Vec::new();
    {
        let stdin = stdin();
        let mut handle = stdin.lock();
        let len = handle.read_to_end(&mut input);
        len.err().map(|e| fail("stdin-read", "Reading stdin failed", format!("{:?}", e)));
    }

    let saved_file = save_file(&input);
    let mail = mailparse::parse_mail(&input).unwrap_or_else(|e| fail(&saved_file, "Unable to parse mail", format!("{:?}", e)));

    let github_reason = mail.headers.get_first_value("X-GitHub-Reason").unwrap_or_else(|e| fail(&saved_file, "Unable to read mail header", format!("{:?}", e)));
    if github_reason.is_some() {
        scrape_github_mail(&mail).unwrap_or_else(|e| fail(&saved_file, "Error while scraping github mail", e));
        fs::remove_file(&saved_file).unwrap_or_else(|e| fail(&saved_file, "Error removing file after processing", format!("{:?}", e)));
    }

    let bugzilla_type = mail.headers.get_first_value("X-Bugzilla-Type").unwrap_or_else(|e| fail(&saved_file, "Unable to read mail header", format!("{:?}", e)));
    if let Some(bz_type) = bugzilla_type {
        scrape_bugzilla_mail(&bz_type, &mail).unwrap_or_else(|e| fail(&saved_file, "Error while scraping bugzilla mail", e));
        fs::remove_file(&saved_file).unwrap_or_else(|e| fail(&saved_file, "Error removing file after processing", format!("{:?}", e)));
    }

    let phabricator = mail.headers.get_first_value("X-Phabricator-Sent-This-Message").unwrap_or_else(|e| fail(&saved_file, "Unable to read mail header", format!("{:?}", e)));
    if phabricator.is_some() {
        scrape_phabricator_mail(&mail).unwrap_or_else(|e| fail(&saved_file, "Error while scraping phab mail", e));
        fs::remove_file(&saved_file).unwrap_or_else(|e| fail(&saved_file, "Error removing file after processing", format!("{:?}", e)));
    }
}
