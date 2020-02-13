# articletopic ORES prototype

Start a MySQL container:

```
docker run --name newcomer-tasks-db -v $(pwd)/data:/var/lib/mysql -p 3306:3306 -e MYSQL_ALLOW_EMPTY_PASSWORD=1 -d mariadb
```

In the container, create the database:

`CREATE database tasks`

Create the table:

``` mysql
CREATE TABLE `task` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `page_title` varchar(256) DEFAULT NULL,
  `topic` json DEFAULT NULL,
  `template` varchar(20) DEFAULT NULL,
  `enwiki_title` varchar(256) DEFAULT NULL,
  `is_foreignwiki` tinyint(1) DEFAULT NULL,
  `rev_id` int(11) DEFAULT NULL,
  `wikibase_id` varchar(20) DEFAULT NULL,
  `lang` varchar(2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=31670 DEFAULT CHARSET=utf8
```

Run `php app.php process --lang={langCode}`, then `php app.php export`.
