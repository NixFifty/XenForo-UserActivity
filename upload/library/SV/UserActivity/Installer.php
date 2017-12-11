<?php

class SV_UserActivity_Installer
{
    public static function install($existingAddOn, /** @noinspection PhpUnusedLocalVariableInspection */
                                   array $addOnData, /** @noinspection PhpUnusedLocalVariableInspection */
                                   SimpleXMLElement $xml)
    {
        /** @noinspection PhpUnusedLocalVariableInspection */
        $version = isset($existingAddOn['version_id']) ? $existingAddOn['version_id'] : 0;
        $db = XenForo_Application::getDb();

        $db->query(
            "
            CREATE TABLE IF NOT EXISTS xf_sv_user_activity (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `content_type` varbinary(25) NOT NULL,
              `content_id` int(10) unsigned NOT NULL,
              `timestamp` int(10) unsigned NOT NULL,
              `blob` VARBINARY(255) NOT NULL,
              PRIMARY KEY (`id`),
              KEY `timestamp` (`timestamp`,`content_type`,`content_id`),
              UNIQUE KEY `content` (`content_type`,`content_id`,`blob`(255))
            ) ENGINE = InnoDB CHARACTER SET utf8 COLLATE utf8_general_ci
        "
        );

        return true;
    }

    public static function uninstall()
    {
        $db = XenForo_Application::getDb();

        $db->query(
            "
            DROP TABLE IF EXISTS xf_sv_user_activity
        "
        );

        return true;
    }
}
