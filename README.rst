Cm_Cache_Backend_Mongo
============
:Author: Colin Mollenhour

About
=====
**Cm_Cache_Backend_Mongo** is a `Zend Framework <http://zendframework.com/> backend using `MongoDB <http://www.mongodb.org/>.
It supports tags and does not need autocleaning thanks to MongoDb's TTL collections.

Dependencies
============
**Cm_Cache_Backend_Mongo** requires the MongoDB database version 2.2 or newer and the PECL mongo driver 1.4 or newer.

Installation
============

Use modman, composer or manually copy Mongo.php into a path in the include path under Cm/Cache/Backend/.

Configuration
=============

Constructor options for this backend:

* server         => (string) MongoClient server connection string
* dbname         => (string) Name of the database to use
* collection     => (string) Name of the collection to use
* ensure_tag_index => (bool) Ensure tag index exists after each connection. Default is FALSE.
* ensure_expire_index => (bool) Ensure tag index exists after each connection Default is TRUE. (can disable after indexes exist)

The expire index is required for automatic cleaning to occur using MongoDB's TTL feature. The tag index is not required
and in fact has very poor performance when keys have a large number of high-cardinality tags. So, depending on your use-case
you may not want the tag index so it is disabled by default. Both "ensure" options can be disabled after the first run since the
index only needs to be created once. Disabling the "ensure" flags does not delete the index so to remove them you must do so
manually; for example using the mongo client and the dropIndex command.

Credits
=======

:Original Author: Olivier Bregeras <olivier.bregeras@gmail.com>
:Original Author: Anton Stöckl <anton@stoeckl.de>

Changes against original version from Stunti/Anton
============================================

- Completely rewritten to use fewer and more efficient queries and to pass unit tests.

License
=======
**Cm_Cache_Backend_Mongo** is licensed under the New BSD License http://framework.zend.com/license/new-bsd
See *LICENSE* for details.
