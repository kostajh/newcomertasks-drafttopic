# Drafttopic ORES data generator

Create the table:

``` mysql
CREATE TABLE `task` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `page_title` varchar(64) DEFAULT NULL,
  `topic` varchar(64) DEFAULT NULL,
  `template` varchar(20) DEFAULT NULL,
  `enwiki_title` varchar(64) DEFAULT NULL,
  `category_derived` tinyint(1) DEFAULT NULL,
  `rev_id` int(11) DEFAULT NULL,
  `wikibase_id` varchar(20) DEFAULT NULL,
  `lang` varchar(2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3661 DEFAULT CHARSET=utf8
```

Run `php app.php process --lang={langCode}`, then `php app.php export`.