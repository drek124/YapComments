/* YapComments 1.0.6 - registry of non-article items that carry a thread */
CREATE TABLE IF NOT EXISTS `#__yapcomments_items` (
  `context` varchar(100) NOT NULL DEFAULT '',
  `item_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL DEFAULT '',
  `url` varchar(1024) NOT NULL DEFAULT '',
  `language` char(7) NOT NULL DEFAULT '*',
  `access` int unsigned NOT NULL DEFAULT 0,
  `author_id` int unsigned NOT NULL DEFAULT 0,
  `created` datetime NULL DEFAULT NULL,
  `mode` varchar(10) NOT NULL DEFAULT '',
  PRIMARY KEY (`context`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
