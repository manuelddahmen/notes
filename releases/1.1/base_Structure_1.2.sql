-- phpMyAdmin SQL Dump
-- version 3.5.8.1
-- http://www.phpmyadmin.net
--
-- Host: manudahmen.be.mysql:3306
-- Generation Time: Feb 23, 2017 at 10:57 PM
-- Server version: 5.5.53-MariaDB-1~wheezy
-- PHP Version: 5.4.45-0+deb7u7

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

--
-- Database: `manudahmen_be`
--

-- --------------------------------------------------------

--
-- Table structure for table comm_terre`
--

CREATE TABLE IF NOT EXISTS `comm_terre` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
  `description` INT(11)          NOT NULL,
  `tablename` VARCHAR(64)  NOT NULL,
  `target_id` INT(11)          NOT NULL,
  `source_id` INT(11)          NOT NULL,
  PRIMARY KEY (`id`),
  KEY `source_id` (`source_id`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = latin1;

-- --------------------------------------------------------

--
-- Table structure for table contacts`
--

CREATE TABLE IF NOT EXISTS `contacts` (
  `id`     INT(11)          NOT NULL AUTO_INCREMENT,
  `list_id` INT(11)                    DEFAULT NULL,
  `contact_name` VARCHAR(140)              DEFAULT NULL,
  `owner_id`     INT(11)          NOT NULL,
  `email  VARCHAR(100)     NOT NULL,
  `created_at`   TIMESTAMP        NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at`   TIMESTAMP        NOT NULL DEFAULT '0000-00-00 00:00:00',
  `persona_id`   INT(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`id`)
)
  ENGINE = InnoDB
  DEFAULT CHARSET = latin1
  AUTO_INCREMENT = 36;

-- --------------------------------------------------------

--
-- Table structure for table data`
--

CREATE TABLE IF NOT EXISTS `data` (
  `id`         INT(10) UNSIGNED        NOT NULL AUTO_INCREMENT,
  `filename_id` BIGINT(20)              NOT NULL,
  `hashset_filename` VARCHAR(255)
                     COLLATE utf8_unicode_ci NOT NULL,
  `filename   VARCHAR(255)
                     COLLATE utf8_unicode_ci NOT NULL,
  `content_file`     BLOB                    NOT NULL,
  `isHashed   TINYINT(1)              NOT NULL,
  `isClear    TINYINT(1)              NOT NULL,
  `isCrypted  VARCHAR(255)
                     COLLATE utf8_unicode_ci NOT NULL,
  `user_id`    BIGINT(20)              NOT NULL,
  `mime       VARCHAR(255)
                     COLLATE utf8_unicode_ci NOT NULL,
  `isDirectoryTINYINT(1)              NOT NULL,
  `created_at TIMESTAMP               NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at TIMESTAMP               NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = utf8
  COLLATE = utf8_unicode_ci
  AUTO_INCREMENT = 1;

-- --------------------------------------------------------

--
-- Table structure for table filesdata`
--

CREATE TABLE IF NOT EXISTS `filesdata` (
  `id`         INT(11)          NOT NULL AUTO_INCREMENT,
  `filename_on_disk` VARCHAR(4048)    NOT NULL,
  `filename_id` INT(11)                   DEFAULT NULL,
  `hachset_filename` VARCHAR(1024)             DEFAULT NULL,
  `hash_key   VARCHAR(2048)             DEFAULT NULL,
  `filename   VARCHAR(1024)             DEFAULT NULL,
  `folder_id`  INT(11)                   DEFAULT NULL,
  `content_file`     LONGBLOB,
  `isHach     TINYINT(1)                DEFAULT '1',
  `isClear    TINYINT(1)                DEFAULT '1',
  `isCrypted  TINYINT(1)                DEFAULT '0',
  `username   VARCHAR(1024)    NOT NULL,
  `mime       VARCHAR(1024)    NOT NULL,
  `isDirectoryINT(11)          NOT NULL DEFAULT '0',
  `quand      TIMESTAMP        NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE CURRENT_TIMESTAMP,
  `quandNouveau`     TIMESTAMP        NOT NULL DEFAULT '0000-00-00 00:00:00',
  `isRoot     TINYINT(1)       NOT NULL,
  `isDeleted  INT(11)          NOT NULL DEFAULT '0',
  `updated_at TIMESTAMP        NOT NULL DEFAULT '0000-00-00 00:00:00',
  `created_at TIMESTAMP        NOT NULL DEFAULT '0000-00-00 00:00:00',
  `revof      INT(10) UNSIGNED NOT NULL,
  `status     INT(11)          NOT NULL,
  PRIMARY KEY (`id`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = latin1
  AUTO_INCREMENT = 526;

--
-- Triggers filesdata`
--







-- --------------------------------------------------------

--
-- Table structure for table group`
--

CREATE TABLE IF NOT EXISTS `group` (
  `id`   INT(11)   NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)   NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = latin1
  AUTO_INCREMENT = 1;

-- --------------------------------------------------------

--
-- Table structure for table guests`
--

CREATE TABLE IF NOT EXISTS `guests` (
  `id`                  INT(11)   NOT NULL AUTO_INCREMENT,
  `user_owner_id`       INT(11)   NOT NULL,
  `user_guest_id`       INT(11)   NOT NULL,
  `confirmed_email_guest_ref` INT(11)   NOT NULL,
  `state               INT(11)   NOT NULL,
  `updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at          TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = latin1
  AUTO_INCREMENT = 6;

-- --------------------------------------------------------

--
-- Table structure for table guest_filesdata`
--

CREATE TABLE IF NOT EXISTS `guest_filesdata` (
  `id`     INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `filesdata_id` INT(10) UNSIGNED NOT NULL,
  `guest_id`     INT(10) UNSIGNED NOT NULL,
  `updated_at`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at`   TIMESTAMP        NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  KEY `guest_id` (`guest_id`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = latin1
  AUTO_INCREMENT = 1;

-- --------------------------------------------------------

--
-- Table structure for table lien`
--

CREATE TABLE IF NOT EXISTS `lien` (
  `id`       INT(10) UNSIGNED        NOT NULL AUTO_INCREMENT,
  `name     VARCHAR(255)
                   COLLATE utf8_unicode_ci NOT NULL,
  `user_id`  INT(11)                 NOT NULL,
  `note_id`  INT(11)                 NOT NULL,
  `created_at`     TIMESTAMP               NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at`     TIMESTAMP               NOT NULL DEFAULT '0000-00-00 00:00:00',
  `linked_note_id` INT(11)                 NOT NULL,
  PRIMARY KEY (`id`),
  KEY `lien_user_id_foreign` (`user_id`),
  KEY `lien_note_id_foreign` (`note_id`),
  KEY `linked_note_id` (`linked_note_id`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = utf8
  COLLATE = utf8_unicode_ci
  AUTO_INCREMENT = 9;

-- --------------------------------------------------------

--
-- Table structure for table migrations`
--

CREATE TABLE IF NOT EXISTS `migrations` (
  `migration` VARCHAR(255)
              COLLATE utf8_unicode_ci NOT NULL,
  `batch`     INT(11)                 NOT NULL
)
  ENGINE = MyISAM
  DEFAULT CHARSET = utf8
  COLLATE = utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table password_resets`
--

CREATE TABLE IF NOT EXISTS `password_resets` (
  `emailVARCHAR(255)
               COLLATE utf8_unicode_ci NOT NULL,
  `tokenVARCHAR(255)
               COLLATE utf8_unicode_ci NOT NULL,
  `created_at` TIMESTAMP               NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` TIMESTAMP               NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY password_resets_email_index` (`email`),
  KEY password_resets_token_index` (`token`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = utf8
  COLLATE = utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table persona`
--

CREATE TABLE IF NOT EXISTS `persona` (
  `id`    INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `firstname`   VARCHAR(50)      NOT NULL,
  `lastname`    VARCHAR(50)      NOT NULL,
  `phonenumber` VARCHAR(100)              DEFAULT NULL,
  `email VARCHAR(100)     NOT NULL,
  `updated_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `quota BIGINT(20)       NOT NULL,
  `created_at`  TIMESTAMP        NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = latin1
  AUTO_INCREMENT = 27;

-- --------------------------------------------------------

--
-- Table structure for table reminderpwd`
--

CREATE TABLE IF NOT EXISTS `reminderpwd` (
  `id`    INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT(10) UNSIGNED NOT NULL,
  `created_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP        NOT NULL DEFAULT '0000-00-00 00:00:00',
  `hasBeenUsed` TINYINT(1)       NOT NULL DEFAULT '0',
  `hache VARCHAR(1024)    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `hasBeenUsed` (`hasBeenUsed`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = latin1
  AUTO_INCREMENT = 56;

-- --------------------------------------------------------

--
-- Table structure for table seat`
--

CREATE TABLE IF NOT EXISTS `seat` (
  `id`    INT(11) NOT NULL AUTO_INCREMENT,
  `owner` INT(11) NOT NULL,
  `thing` INT(11) NOT NULL,
  PRIMARY KEY (`id`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = utf8
  COLLATE = utf8_spanish_ci
  AUTO_INCREMENT = 1;

-- --------------------------------------------------------

--
-- Table structure for table share`
--

CREATE TABLE IF NOT EXISTS `share` (
  `id`   INT(11)                                                                                                             NOT NULL AUTO_INCREMENT,
  `note_id`    INT(11)                                                                                                             NOT NULL,
  `type ENUM ('CONFIDENTIAL', 'WITHFRIEND', 'WFRIENDBYEMAIL', 'WFRIENDBYEMAILWATTACHMENT', 'GROUP', 'ALLFRIENDS', 'PUBLIC') NOT NULL,
  `giveeVARCHAR(500)                                                                                                        NOT NULL,
  `share_id`   INT(11)                                                                                                             NOT NULL,
  `updated_at` TIMESTAMP                                                                                                           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP                                                                                                           NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  KEY `note_id` (`note_id`, `givee`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = latin1
  AUTO_INCREMENT = 1;

-- --------------------------------------------------------

--
-- Table structure for table share_contact_lists`
--

CREATE TABLE IF NOT EXISTS `share_contact_lists` (
  `id`   INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `list_id`    INT(10) UNSIGNED NOT NULL
  COMMENT 'List Id',
  `contact_id` INT(11)          NOT NULL
  COMMENT 'user_id',
  `updated_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP        NOT NULL DEFAULT '0000-00-00 00:00:00',
  `owner_id`   INT(11)          NOT NULL,
  PRIMARY KEY (`id`),
  KEY `list_id` (`list_id`),
  KEY `list_id_2` (`list_id`),
  KEY `contact_id` (`contact_id`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = latin1
  AUTO_INCREMENT = 1;

-- --------------------------------------------------------

--
-- Table structure for table share_socialnetworks`
--

CREATE TABLE IF NOT EXISTS `share_socialnetworks` (
  `id`      INT(11)                                                               NOT NULL,
  `table_name`    ENUM ('00-public', '01-persona-share', '02-list-share', '03-private') NOT NULL,
  `ref_id`  INT(11)                                                               NOT NULL,
  `sn_href VARCHAR(2048)                                                         NOT NULL,
  `internal_href` VARCHAR(2048)                                                         NOT NULL,
  `updated_at`    TIMESTAMP                                                             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at`    TIMESTAMP                                                             NOT NULL DEFAULT '0000-00-00 00:00:00'
)
  ENGINE = MyISAM
  DEFAULT CHARSET = latin1;

-- --------------------------------------------------------

--
-- Table structure for table status`
--

CREATE TABLE IF NOT EXISTS `status` (
  `id`     INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name   VARCHAR(10)      NOT NULL,
  `descritption` VARCHAR(200)     NOT NULL,
  `updated_at`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at`   TIMESTAMP        NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = latin1
  AUTO_INCREMENT = 4;

-- --------------------------------------------------------

--
-- Table structure for table users`
--

CREATE TABLE IF NOT EXISTS `users` (
  `id`       INT(10) UNSIGNED        NOT NULL AUTO_INCREMENT,
  `name     VARCHAR(255)
                   COLLATE utf8_unicode_ci NOT NULL,
  `email    VARCHAR(255)
                   COLLATE utf8_unicode_ci NOT NULL,
  `password VARCHAR(60)
                   COLLATE utf8_unicode_ci NOT NULL,
  `remember_token` VARCHAR(100)
                   COLLATE utf8_unicode_ci          DEFAULT NULL,
  `created_at`     TIMESTAMP               NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at`     TIMESTAMP               NOT NULL DEFAULT '0000-00-00 00:00:00',
  `persona_id`     INT(10) UNSIGNED        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY users_email_unique` (`email`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = utf8
  COLLATE = utf8_unicode_ci
  AUTO_INCREMENT = 3;

-- --------------------------------------------------------

--
-- Table structure for table user_on_note_right`
--

CREATE TABLE IF NOT EXISTS `user_on_note_right` (
  `id`    INT(11)      NOT NULL AUTO_INCREMENT,
  `description` VARCHAR(255) NOT NULL,
  `read  TINYINT(1)   NOT NULL,
  `write TINYINT(1)   NOT NULL,
  `deleteTINYINT(1)   NOT NULL,
  `appendINT(11)      NOT NULL,
  `note_id`     INT(11)      NOT NULL,
  `list_id`     INT(11)      NOT NULL,
  PRIMARY KEY (`id`)
)
  ENGINE = MyISAM
  DEFAULT CHARSET = latin1;
