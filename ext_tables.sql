#
# Inhaltsindex des barrierefreien Chatbots (Konzept 4.4).
#
# Diese Tabelle hat bewusst KEIN TCA: sie wird nie im Backend bearbeitet,
# sondern ausschliesslich vom IndexService geschrieben. TYPO3 ergaenzt
# Standardspalten (uid, pid, ...) aber nur bei Tabellen mit TCA-"ctrl"-
# Abschnitt - deshalb steht hier ALLES vollstaendig, inklusive uid und
# PRIMARY KEY. Der Core macht das bei seinen TCA-losen Tabellen genauso.
#
# Bewusst KEIN FULLTEXT-Index: den kennt nur MySQL/MariaDB. Die Suche laeuft
# datenbank-unabhaengig mit LIKE plus Bewertung in PHP (Konzept 4.4).
#
CREATE TABLE tx_accessiblechatbot_index (
	uid int(11) unsigned NOT NULL auto_increment,
	pid int(11) unsigned DEFAULT '0' NOT NULL,

	site_identifier varchar(255) DEFAULT '' NOT NULL,
	page_uid int(11) unsigned DEFAULT '0' NOT NULL,
	language_uid int(11) DEFAULT '0' NOT NULL,

	title varchar(255) DEFAULT '' NOT NULL,
	nav_title varchar(255) DEFAULT '' NOT NULL,
	abstract text,
	keywords text,
	content mediumtext,

	fe_groups varchar(255) DEFAULT '' NOT NULL,
	updated_at int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY page_language (page_uid,language_uid),
	KEY site (site_identifier)
);
