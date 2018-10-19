CREATE TABLE `requests` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `bug` int(10) unsigned NOT NULL,
    `stamp` datetime NOT NULL,
    `viewed` tinyint(1) NOT NULL DEFAULT 0,
    `attachment` int(10) unsigned NOT NULL,
    `title` varchar(255) NOT NULL,
    `flag` varchar(255) NOT NULL,
    `cancelled` tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY (`bug`),
    KEY (`stamp`),
    KEY (`viewed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `reviews` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `bug` int(10) unsigned NOT NULL,
    `stamp` datetime NOT NULL,
    `viewed` tinyint(1) NOT NULL DEFAULT 0,
    `attachment` int(10) unsigned NOT NULL,
    `title` varchar(255) NOT NULL,
    `flag` varchar(255) NOT NULL,
    `author` varchar(255) NOT NULL,
    `authoremail` varchar(255) NOT NULL,
    `granted` tinyint(1) NOT NULL,
    `comment` mediumtext NOT NulL,
    PRIMARY KEY (`id`),
    KEY (`bug`),
    KEY (`stamp`),
    KEY (`viewed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `changes` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `bug` int(10) unsigned NOT NULL,
    `stamp` datetime NOT NULL,
    `viewed` tinyint(1) NOT NULL DEFAULT 0,
    `reason` varchar(10) NOT NULL,
    `field` varchar(255) NOT NULL,
    `oldval` varchar(1024) NOT NULL,
    `newval` varchar(1024) NOT NULL,
    PRIMARY KEY (`id`),
    KEY (`bug`),
    KEY (`stamp`),
    KEY (`viewed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `comments` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `bug` int(10) unsigned NOT NULL,
    `stamp` datetime NOT NULL,
    `viewed` tinyint(1) NOT NULL DEFAULT 0,
    `reason` varchar(10) NOT NULL,
    `commentnum` int(10) unsigned NOT NULL,
    `author` varchar(255) NOT NULL,
    `comment` mediumtext NOT NULL,
    PRIMARY KEY (`id`),
    KEY (`bug`),
    KEY (`stamp`),
    KEY (`viewed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `newbugs` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `bug` int(10) unsigned NOT NULL,
    `stamp` datetime NOT NULL,
    `viewed` tinyint(1) NOT NULL DEFAULT 0,
    `reason` varchar(10) NOT NULL,
    `title` varchar(1024) NOT NULL,
    `author` varchar(255) NOT NULL,
    `description` mediumtext NOT NULL,
    PRIMARY KEY (`id`),
    KEY (`bug`),
    KEY (`stamp`),
    KEY (`viewed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `gh_issues` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `repo` varchar(100) NOT NULL,
    `issue` int(10) unsigned NOT NULL,
    `stamp` datetime NOT NULL,
    `viewed` tinyint(1) NOT NULL DEFAULT 0,
    `reason` varchar(10) NOT NULL,
    `hash` varchar(255) NOT NULL,
    `author` varchar(255) NOT NULL,
    `comment` mediumtext NOT NULL,
    PRIMARY KEY (`id`),
    KEY (`repo`, `issue`),
    KEY (`stamp`),
    KEY (`viewed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `metadata` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `bug` varchar(128) NOT NULL,
    `stamp` datetime NOT NULL,
    `title` varchar(1024) NOT NULL DEFAULT '',
    `secure` tinyint(1) NOT NULL DEFAULT 0,
    `note` mediumtext NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    UNIQUE KEY (`bug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tags` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `bug` varchar(128) NOT NULL,
    `tag` varchar(100) NOT NULL,
    PRIMARY KEY (`id`),
    KEY (`bug`),
    KEY (`tag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
