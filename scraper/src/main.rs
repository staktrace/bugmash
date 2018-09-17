extern crate mailparse;
#[macro_use]
extern crate mysql;

use std::collections::hash_map::DefaultHasher;
use std::env;
use std::fs::File;
use std::hash::Hasher;
use std::io::{Read, stdin, Write};
use std::process;
use std::time::{SystemTime, UNIX_EPOCH};

use mailparse::{dateparse, MailHeaderMap, MailParseError, ParsedMail};

fn fail(data: &[u8], msg: &str, err: String) -> ! {
    let time = SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0);
    let mut hasher = DefaultHasher::new();
    hasher.write(data);
    let uniq_name = format!("{}.{:x}", time, hasher.finish());
    {
        let mut file = File::create(&uniq_name).unwrap();
        file.write(data).ok();
    }
    let err_name = format!("{}.err", uniq_name);
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

fn get_plain_body(mail: &ParsedMail) -> Result<Option<String>, MailParseError> {
    if mail.ctype.mimetype == "text/plain" {
        return mail.get_body().map(Some);
    }
    for subpart in &mail.subparts {
        if let Some(x) = get_plain_body(subpart)? {
            return Ok(Some(x));
        }
    }
    Ok(None)
}

fn url_parts(footer: String) -> Option<(String, String, String)> {
    let prefix = "https://github.com/";
    let issues = "/issues/";
    let comment = "#issuecomment-";

    let repo_ix = footer.find(prefix)? + prefix.len();
    let issues_ix = footer[repo_ix..].find(issues)? + repo_ix;
    let comment_ix = footer[issues_ix..].find(comment)? + issues_ix;
    let end_ix = footer[comment_ix..].find("\r\n")? + comment_ix;

    return Some((String::from(&footer[repo_ix..issues_ix]),
                 String::from(&footer[issues_ix + issues.len()..comment_ix]),
                 String::from(&footer[comment_ix + comment.len()..end_ix])));
}

fn split_footer(msg: String) -> (String, Option<String>) {
    match msg.find("\r\n-- \r\nYou are receiving this because") {
        Some(ix) => (String::from(&msg[0..ix]), Some(String::from(&msg[ix..]))),
        None => (msg, None),
    }
}

fn scrape_github_mail(mail: &ParsedMail) -> Result<(), String> {
    let sender = mail.headers.get_first_value("X-GitHub-Sender").map_err(|e| format!("{:?}", e))?.unwrap_or("Unknown".to_string());
    let reason = match mail.headers.get_first_value("X-GitHub-Reason").map_err(|e| format!("{:?}", e))? {
        Some(ref s) if s == "review_requested" => "review",
        Some(ref s) if s == "mention" => "CC",
        _ => "Watch",
    };
    let stamp = mail.headers.get_first_value("Date").map_err(|e| format!("{:?}", e))?.map(|v| dateparse(&v).unwrap_or(0)).unwrap_or(0);
    let (comment, footer) = match get_plain_body(mail).map_err(|e| format!("{:?}", e))? {
        Some(x) => split_footer(x),
        None => return Err("No plaintext body found".to_string()),
    };
    let footer = footer.ok_or("Unable to find footer".to_string())?;
    let (repo, issue, commentnum) = url_parts(footer).ok_or("Unable to extract URL parts".to_string())?;

    let db = get_db()?;
    let result = db.prep_exec(r"INSERT INTO gh_issues (repo, issue, stamp, reason, commentnum, author, comment)
                                VALUES (:repo, :issue, FROM_UNIXTIME(:stamp), :reason, :commentnum, :sender, :comment)", params! {
        repo,
        issue,
        stamp,
        reason,
        commentnum,
        sender,
        comment,
    }).map_err(|e| format!("{:?}", e))?;
    if result.affected_rows() != 1 {
        return Err(format!("Affected row count was {}, not 1", result.affected_rows()));
    }
    Ok(())
}

fn main() {
    let mut input = Vec::new();
    {
        let stdin = stdin();
        let mut handle = stdin.lock();
        let len = handle.read_to_end(&mut input);
        len.err().map(|e| fail(&input, "Reading stdin failed", format!("{:?}", e)));
    }

    let mail = mailparse::parse_mail(&input).unwrap_or_else(|e| fail(&input, "Unable to parse mail", format!("{:?}", e)));

    let github_reason = mail.headers.get_first_value("X-GitHub-Reason").unwrap_or_else(|e| fail(&input, "Unable to read mail header", format!("{:?}", e)));
    if github_reason.is_some() {
        return scrape_github_mail(&mail).unwrap_or_else(|e| fail(&input, "Error while scraping mail", e));
    }
}
