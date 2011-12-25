CREATE TABLE `requests` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `bug` int(10) unsigned NOT NULL,
    `stamp` datetime NOT NULL,
    `attachment` int(10) unsigned NOT NULL,
    `title` varchar(255) NOT NULL,
    `cancelled` tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY (`bug`),
    KEY (`stamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `reviews` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `bug` int(10) unsigned NOT NULL,
    `stamp` datetime NOT NULL,
    `attachment` int(10) unsigned NOT NULL,
    `title` varchar(255) NOT NULL,
    `feedback` tinyint(1) NOT NULL,
    `granted` tinyint(1) NOT NULL,
    `comment` mediumtext NOT NulL,
    PRIMARY KEY (`id`),
    KEY (`bug`),
    KEY (`stamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `changes` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `bug` int(10) unsigned NOT NULL,
    `stamp` datetime NOT NULL,
    `reason` varchar(10) NOT NULL,
    `field` varchar(255) NOT NULL,
    `oldval` varchar(255) NOT NULL,
    `newval` varchar(255) NOT NULL,
    PRIMARY KEY (`id`),
    KEY (`bug`),
    KEY (`stamp`),
    KEY (`reason`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `comments` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `bug` int(10) unsigned NOT NULL,
    `stamp` datetime NOT NULL,
    `reason` varchar(10) NOT NULL,
    `commentnum` int(10) unsigned NOT NULL,
    `author` varchar(255) NOT NULL,
    `comment` mediumtext NOT NULL,
    PRIMARY KEY (`id`),
    KEY (`bug`),
    KEY (`stamp`),
    KEY (`reason`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `newbugs` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `bug` int(10) unsigned NOT NULL,
    `stamp` datetime NOT NULL,
    `reason` varchar(10) NOT NULL,
    `title` varchar(255) NOT NULL,
    `author` varchar(255) NOT NULL,
    `description` mediumtext NOT NULL,
    PRIMARY KEY (`id`),
    KEY (`bug`),
    KEY (`stamp`),
    KEY (`reason`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
